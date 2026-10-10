<?php

namespace App\Livewire;

use App\Mailbox;
use App\Matrix\Connection;
use App\Matrix\Crypto\Account;
use App\Matrix\Crypto\SasVerification;
use App\Matrix\CryptoManager;
use App\Matrix\CryptoRecord;
use App\Matrix\MatrixMailbox;
use App\Matrix\Outbox;
use App\Matrix\Syncer;
use App\Matrix\Verification;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MatrixSettings extends Component
{
    #[Locked]
    public $mailbox_id;

    #[Locked]
    public $verification_transaction;

    #[Locked]
    public $checked_homeserver;

    public $homeserver = '';
    public $matrix_user = '';
    public $error = false;

    public function mount($mailbox_id)
    {
        $this->mailbox_id = $mailbox_id;
        $this->mailbox();
        $identity = MatrixMailbox::forMailbox($mailbox_id);
        $this->homeserver = $identity ? $identity->homeserver : '';
        $this->matrix_user = $identity ? $identity->user_id : '';
    }

    protected function mailbox()
    {
        $mailbox = Mailbox::findOrFail($this->mailbox_id);
        $this->authorize('update', $mailbox);

        return $mailbox;
    }

    public function checkHomeserver()
    {
        $this->mailbox();
        $this->checked_homeserver = null;
        $this->validate(['homeserver' => 'required|string|max:1024']);
        $this->attempt(function () {
            $this->checked_homeserver = (new Connection())->checkHomeserver($this->homeserver);
            $this->homeserver = $this->checked_homeserver;
        });
    }

    public function changeHomeserver()
    {
        $this->mailbox();
        $this->checked_homeserver = null;
        $this->error = false;
    }

    public function connect(#[\SensitiveParameter] $password)
    {
        $this->mailbox();
        $this->validate(['homeserver' => 'required|string|max:1024', 'matrix_user' => 'required|string|max:255']);
        if (!$this->checked_homeserver || \App\Matrix\Client::homeserverUrl($this->homeserver) !== $this->checked_homeserver) {
            $this->checked_homeserver = null;
            $this->error = true;

            return;
        }
        if (!is_string($password) || $password === '' || strlen($password) > 4096) {
            $this->error = true;

            return;
        }
        $this->attempt(function () use ($password) {
            (new Connection())->connect($this->mailbox_id, $this->homeserver, $this->matrix_user, $password);
        });
    }

    public function poll()
    {
        $this->mailbox();
        $identity = MatrixMailbox::forMailbox($this->mailbox_id);
        if (!$identity || !\Cache::add('matrix:settings-poll:'.$this->mailbox_id, true, 3)) {
            return;
        }
        if ($identity->status === 'verification') {
            $this->attempt(fn () => (new Syncer())->run($identity));
        } elseif ($identity->isReady()) {
            \App\Jobs\SyncMatrixMailbox::dispatch($identity->id);
        }
    }

    public function verify($action)
    {
        $this->mailbox();
        $this->attempt(function () use ($action) {
            $identity = MatrixMailbox::forMailbox($this->mailbox_id);
            if (!$identity) {
                return;
            }
            $identity->locked(function () use ($identity, $action) {
                (new Verification($identity))->act($action, $this->verification_transaction);
                Outbox::flush($identity);
            });
        });
    }

    public function approveDevices($record_id, $fingerprint)
    {
        $this->mailbox();
        $this->attempt(function () use ($record_id, $fingerprint) {
            $identity = MatrixMailbox::forMailbox($this->mailbox_id);
            if (!$identity || !$identity->isReady()) {
                return;
            }
            $identity->locked(function () use ($identity, $record_id, $fingerprint) {
                $review = CryptoRecord::where('matrix_mailbox_id', $identity->id)->where('kind', 'trust_review')->findOrFail($record_id);
                abort_unless(hash_equals($review->value['fingerprint'], (string) $fingerprint), 409);
                (new CryptoManager($identity))->approve($review->value['user'], $fingerprint);
            });
        });
    }

    public function toggleEnabled()
    {
        $this->mailbox();
        $this->attempt(function () {
            $identity = MatrixMailbox::forMailbox($this->mailbox_id);
            if ($identity) {
                $identity->locked(function () use ($identity) {
                    $credentials = $identity->credentials;
                    if ($identity->status === 'disabled') {
                        $identity->status = $credentials['resume_status'] ?? 'verification';
                        unset($credentials['resume_status']);
                    } else {
                        $credentials['resume_status'] = $identity->status;
                        $identity->status = 'disabled';
                    }
                    $identity->credentials = $credentials;
                    $identity->save();
                });
            }
        });
    }

    public function disconnect()
    {
        $this->mailbox();
        $this->attempt(function () {
            $identity = MatrixMailbox::forMailbox($this->mailbox_id);
            if ($identity) {
                $identity->locked(function () use ($identity) {
                    try {
                        $identity->client()->call('POST', 'v3/logout');
                    } catch (\App\Matrix\MatrixException $e) {
                        if ($e->getCode() !== 401) {
                            throw $e;
                        }
                    }
                    (new Connection())->resetDevice($identity);
                    $identity->active_mailbox_id = null;
                    $identity->save();
                });
            }
        });
    }

    public function resetDevice()
    {
        $this->mailbox();
        $this->attempt(function () {
            $identity = MatrixMailbox::forMailbox($this->mailbox_id);
            if (!$identity) {
                return;
            }
            $identity->locked(function () use ($identity) {
                (new Connection())->resetDevice($identity);
            });
        });
    }

    protected function attempt($callback)
    {
        $this->error = false;
        try {
            $callback();
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // The scheduler is already syncing this mailbox.
        } catch (\App\Matrix\MatrixException | \InvalidArgumentException | \JsonException | \SodiumException $e) {
            \App\Misc\ChatLog::failure('matrix', $this->mailbox_id, 'connection', $e);
            \Log::error('Matrix settings failed.', ['mailbox_id' => $this->mailbox_id, 'exception' => get_class($e), 'code' => $e->getCode(),
                'reason' => $e instanceof \App\Matrix\MatrixException ? $e->getMessage() : 'Matrix cryptographic validation failed.']);
            $this->error = $e->getCode() === \Helper::EXCEPTION_UNSAFE_URL ? $e->getMessage() : true;
            if ($e->getCode() === 401) {
                MatrixMailbox::where('active_mailbox_id', $this->mailbox_id)->update(['status' => 'login']);
            }
        }
    }

    public function render()
    {
        $this->mailbox();
        $identity = MatrixMailbox::forMailbox($this->mailbox_id);
        $verification = $identity ? CryptoRecord::read($identity->id, 'verification', 'active') : null;
        $numbers = null;
        if ($verification && ($verification['expires'] <= time() || $verification['phase'] === 'cancelled')) {
            $verification = null;
        }
        $this->verification_transaction = $verification['transaction'] ?? null;
        if ($verification && $verification['phase'] === 'compare') {
            $numbers = SasVerification::restore($verification)->decimals(time());
        }
        $reviews = $identity ? CryptoRecord::where('matrix_mailbox_id', $identity->id)->where('kind', 'trust_review')->get() : collect();

        $account = $identity ? CryptoRecord::read($identity->id, 'account', 'device') : null;
        $fingerprint = $account ? Account::restore($account)->signingKey() : null;

        return view('livewire.matrix-settings', compact('identity', 'verification', 'numbers', 'reviews', 'fingerprint'));
    }
}
