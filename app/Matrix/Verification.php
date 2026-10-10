<?php

namespace App\Matrix;

use App\Matrix\Crypto\Account;
use App\Matrix\Crypto\SasVerification;
use Illuminate\Support\Facades\DB;

class Verification
{
    private $identity;

    public function __construct(MatrixMailbox $identity)
    {
        $this->identity = $identity;
    }

    public function receive(array $event)
    {
        if (($event['sender'] ?? null) !== $this->identity->user_id) {
            return;
        }
        $content = $event['content'] ?? [];
        $saved = CryptoRecord::read($this->identity->id, 'verification', 'active');
        if ($event['type'] === 'm.key.verification.request') {
            if ($saved && $saved['expires'] > time() && $saved['phase'] !== 'cancelled') {
                return;
            }
            $keys = (new CryptoManager($this->identity))->query($this->identity->user_id);
            $peer = $keys['devices'][$content['from_device'] ?? ''] ?? null;
            if (!$peer || !$peer['cross_signed']) {
                throw new MatrixException('Start verification from a verified Matrix device.');
            }
            $sas = SasVerification::request($this->identity->user_id, $this->identity->device_id, $peer, $keys['master'], $content, time());
            CryptoRecord::write($this->identity->id, 'verification', 'active', $sas->export());

            return;
        }
        if (!$saved || ($content['transaction_id'] ?? null) !== $saved['transaction']) {
            return;
        }
        $sas = SasVerification::restore($saved);
        $response = $sas->handle($event['sender'], $event['type'], $content, time());
        $this->save($sas, $response);
    }

    public function act($action, $transaction)
    {
        $saved = CryptoRecord::read($this->identity->id, 'verification', 'active');
        if (!$saved || $saved['transaction'] !== $transaction) {
            throw new MatrixException('No Matrix verification is waiting.');
        }
        $sas = SasVerification::restore($saved);
        if ($action === 'accept') {
            $response = $sas->ready(time());
        } elseif ($action === 'confirm') {
            $account = Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device'));
            $response = $sas->confirm($account->signingKey(), time());
        } elseif ($action === 'cancel') {
            $response = $sas->cancel();
        } else {
            throw new MatrixException('Invalid Matrix verification action.');
        }
        DB::transaction(fn () => $this->save($sas, $response));
    }

    private function save(SasVerification $sas, $response)
    {
        $state = $sas->export();
        CryptoRecord::write($this->identity->id, 'verification', 'active', $state);
        if ($response) {
            MatrixEvent::outgoing($this->identity->id, 'verification:'.$state['transaction'].':'.$response['type'], 'to_device', [
                'type' => $response['type'], 'messages' => [$this->identity->user_id => [$state['peer'] => $response['content']]],
            ]);
        }
        if ($sas->complete()) {
            MatrixEvent::outgoing($this->identity->id, 'verification:'.$state['transaction'].':m.key.verification.done', 'to_device', [
                'type' => 'm.key.verification.done', 'messages' => [$this->identity->user_id => [$state['peer'] => ['transaction_id' => $state['transaction']]]],
            ]);
        }
    }

    public function activate()
    {
        if ($this->identity->isVerified()) {
            return;
        }
        $saved = CryptoRecord::read($this->identity->id, 'verification', 'active');
        if (!$saved || !SasVerification::restore($saved)->complete()) {
            return;
        }
        $keys = (new CryptoManager($this->identity))->query($this->identity->user_id);
        $device = $keys['devices'][$this->identity->device_id] ?? null;
        $account = Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device'));
        if (!isset($saved['peer_keys']['ed25519:'.$keys['master']]) || !$device || !$device['cross_signed']
            || $device['signing'] !== $account->signingKey() || $device['curve'] !== $account->curveKey()) {
            return;
        }
        $this->markReady($keys);
    }

    public function activateWithMaster($master)
    {
        $keys = (new CryptoManager($this->identity))->query($this->identity->user_id);
        $device = $keys['devices'][$this->identity->device_id] ?? null;
        $account = Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device'));
        if ($keys['master'] !== $master || !$device || !$device['cross_signed']
            || $device['signing'] !== $account->signingKey() || $device['curve'] !== $account->curveKey()) {
            return false;
        }
        $this->markReady($keys);

        return true;
    }

    private function markReady(array $keys)
    {
        DB::transaction(function () use ($keys) {
            $known = CryptoRecord::read($this->identity->id, 'trust', $this->identity->user_id);
            if ($known && $known['master'] === $keys['master']) {
                $keys['devices'] = $known['devices'] + $keys['devices'];
                $keys['approved'] = $known['approved'] ?? [];
            }
            CryptoRecord::write($this->identity->id, 'trust', $this->identity->user_id, $keys);
            CryptoRecord::record($this->identity->id, 'trust_review', $this->identity->user_id)->delete();
            MatrixEvent::where('matrix_mailbox_id', $this->identity->id)->whereIn('kind', ['incoming', 'device_in'])->where('status', 'pending')->update(['retry_at' => null]);
            $this->identity->status = 'ready';
            $this->identity->error = null;
            $this->identity->save();
        });
    }
}
