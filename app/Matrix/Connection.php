<?php

namespace App\Matrix;

use App\Matrix\Crypto\Account;
use Illuminate\Support\Facades\DB;

class Connection
{
    public function checkHomeserver($homeserver)
    {
        $client = new Client($homeserver);
        $versions = $client->call('GET', 'versions');
        if (!in_array('v1.11', $versions['versions'] ?? [], true)) {
            throw new MatrixException('Matrix homeserver must support authenticated media (v1.11).');
        }
        $flows = $client->call('GET', 'v3/login');
        if (!in_array('m.login.password', array_column($flows['flows'] ?? [], 'type'), true)) {
            throw new MatrixException('Matrix homeserver does not support password login.');
        }

        return Client::homeserverUrl($homeserver);
    }

    public function connect($mailbox_id, $homeserver, $user, #[\SensitiveParameter] $password)
    {
        $homeserver = Client::homeserverUrl($homeserver);
        $user = trim($user);
        $full_id = str_starts_with($user, '@');
        if (!extension_loaded('sodium') || !extension_loaded('openssl') || !extension_loaded('curl')) {
            throw new MatrixException('Matrix requires Sodium, OpenSSL and cURL.');
        }
        if (!preg_match($full_id ? '/\A@[^\s:]+:[^\s]+\z/uD' : '/\A[^\s:@]+\z/uD', $user) || strlen($user) > 255) {
            throw new MatrixException('Invalid Matrix account ID.');
        }

        return MatrixMailbox::withLock($mailbox_id, function () use ($mailbox_id, $homeserver, $user, $full_id, $password) {
            $identity = $full_id ? MatrixMailbox::where('user_hash', hash('sha256', $user))->first()
                : MatrixMailbox::where('homeserver', $homeserver)->get()->first(fn ($row) => strstr(substr($row->user_id, 1), ':', true) === $user);
            if ($identity && (int) $identity->mailbox_id !== (int) $mailbox_id) {
                throw new MatrixException('Matrix account is already used by another mailbox.');
            }
            if ($identity && !CryptoRecord::read($identity->id, 'account', 'device')) {
                $this->resetDevice($identity);
            }
            $client = new Client($homeserver);
            $this->checkHomeserver($homeserver);
            $device = $identity ? $identity->device_id : 'TALLPORT'.strtoupper(bin2hex(random_bytes(8)));
            $login = $client->call('POST', 'v3/login', ['type' => 'm.login.password', 'identifier' => ['type' => 'm.id.user', 'user' => $user],
                'password' => $password, 'device_id' => $device, 'initial_device_display_name' => 'Tallport', 'refresh_token' => true]);
            $canonical_user = $login['user_id'] ?? '';
            if (!is_string($canonical_user) || !preg_match('/\A@[^\s:]+:[^\s]+\z/uD', $canonical_user) || strlen($canonical_user) > 255
                || ($full_id && $canonical_user !== $user) || ($identity && $canonical_user !== $identity->user_id)
                || ($login['device_id'] ?? null) !== $device || !is_string($login['access_token'] ?? null) || $login['access_token'] === '') {
                throw new MatrixException('Matrix login identity mismatch.');
            }
            if (!$identity && MatrixMailbox::where('user_hash', hash('sha256', $canonical_user))->exists()) {
                throw new MatrixException('Matrix account is already connected. Reconnect using its full Matrix ID.');
            }
            $user = $canonical_user;
            $identity = DB::transaction(function () use ($identity, $mailbox_id, $homeserver, $user, $device, $login) {
                MatrixMailbox::where('active_mailbox_id', $mailbox_id)->where('id', '!=', $identity ? $identity->id : 0)->update(['active_mailbox_id' => null]);
                $row = $identity ?: new MatrixMailbox();
                $row->mailbox_id = $mailbox_id;
                $row->active_mailbox_id = $mailbox_id;
                $row->homeserver = rtrim($homeserver, '/');
                $row->user_id = $user;
                $row->user_hash = hash('sha256', $user);
                $row->device_id = $device;
                $row->credentials = self::credentials($login);
                $row->error = null;
                $row->status = 'login';
                $row->save();
                if (!$identity) {
                    CryptoRecord::write($row->id, 'account', 'device', Account::create($user, $device)->export());
                }

                return $row;
            });
            $this->initialize($identity);

            return $identity;
        });
    }

    public function initialize(MatrixMailbox $identity)
    {
        $this->uploadKeys($identity, null);
        (new CrossSigning())->connect($identity);
        if (!$identity->isVerified()) {
            $known = CryptoRecord::read($identity->id, 'trust', $identity->user_id);
            if (!$known || !(new Verification($identity))->activateWithMaster($known['master'])) {
                $identity->status = 'verification';
                $identity->save();
            }
        }
    }

    public function resetDevice(MatrixMailbox $identity)
    {
        DB::transaction(function () use ($identity) {
            $identity->device_id = 'TALLPORT'.strtoupper(bin2hex(random_bytes(8)));
            $identity->credentials = [];
            $identity->sync_token = null;
            $identity->status = 'login';
            $identity->save();
            CryptoRecord::where('matrix_mailbox_id', $identity->id)->whereIn('kind', ['account', 'olm', 'outbound', 'verification', 'sync'])->delete();
            CryptoRecord::write($identity->id, 'account', 'device', Account::create($identity->user_id, $identity->device_id)->export());
            MatrixEvent::where('matrix_mailbox_id', $identity->id)->whereIn('kind', ['outgoing', 'to_device'])->where('status', 'pending')->update(['status' => 'cancelled']);
        });
    }

    public function uploadKeys(MatrixMailbox $identity, $count)
    {
        $account = Account::restore(CryptoRecord::read($identity->id, 'account', 'device'));
        if ($count === null) {
            $response = $identity->client()->call('POST', 'v3/keys/upload', ['device_keys' => $account->deviceKeys()]);
            $count = $response['one_time_key_counts']['signed_curve25519'] ?? 0;
        }
        $keys = $account->uploadKeys($count);
        CryptoRecord::write($identity->id, 'account', 'device', $account->export());
        $identity->client()->call('POST', 'v3/keys/upload', $keys);
        $account->markPublished(array_keys((array) $keys['one_time_keys']));
        CryptoRecord::write($identity->id, 'account', 'device', $account->export());
    }

    public function refresh(MatrixMailbox $identity)
    {
        $credentials = $identity->credentials;
        if (empty($credentials['expires_at']) || $credentials['expires_at'] > time() + 60) {
            return;
        }
        if (empty($credentials['refresh_token'])) {
            throw new MatrixException('Matrix login has expired.', 401);
        }
        $response = (new Client($identity->homeserver))->call('POST', 'v3/refresh', ['refresh_token' => $credentials['refresh_token']]);
        if (empty($response['access_token']) || !is_string($response['access_token'])) {
            throw new MatrixException('Invalid Matrix token refresh response.');
        }
        $response['refresh_token'] = $response['refresh_token'] ?? $credentials['refresh_token'];
        $identity->credentials = self::credentials($response);
        $identity->save();
    }

    private static function credentials(#[\SensitiveParameter] array $response)
    {
        return ['access_token' => $response['access_token'], 'refresh_token' => $response['refresh_token'] ?? null,
            'expires_at' => isset($response['expires_in_ms']) ? time() + max(0, (int) floor($response['expires_in_ms'] / 1000)) : null];
    }
}
