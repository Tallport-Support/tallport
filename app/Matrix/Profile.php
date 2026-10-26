<?php

namespace App\Matrix;

use App\Mailbox;
use Illuminate\Support\Facades\Storage;

/**
 * The bot's picture on Matrix: the mailbox's image, as on every channel (not one of its own),
 * uploaded once per image and set on its profile; taken away when the mailbox's is. Kept in step
 * by the sync (Syncer). A picture set on the account by hand stays while the mailbox has none.
 */
class Profile
{
    public static function sync(MatrixMailbox $identity)
    {
        $mailbox = $identity->mailbox;
        if (!$mailbox || !$identity->isReady()) {
            return;
        }
        $photo = (string) $mailbox->photo_url;
        $synced = CryptoRecord::read($identity->id, 'profile', 'avatar')['photo'] ?? null;
        if ($synced === $photo) {
            return;
        }
        $avatar = '';
        if ($photo !== '') {
            $bytes = Storage::disk('local')->get(Mailbox::PHOTO_DIRECTORY.'/'.$photo);
            if (!is_string($bytes) || $bytes === '') {
                return;
            }
            // Mailbox::savePhoto() stores JPEG.
            $avatar = $identity->client()->upload($bytes, 'image/jpeg');
        }
        if ($avatar !== '' || $synced !== null) {
            $identity->client()->call('PUT', 'v3/profile/'.rawurlencode($identity->user_id).'/avatar_url', ['avatar_url' => $avatar]);
        }
        CryptoRecord::write($identity->id, 'profile', 'avatar', ['photo' => $photo]);
    }
}
