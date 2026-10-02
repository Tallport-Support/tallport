<?php

namespace App\Telegram;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;

/**
 * Updates from a mailbox's bot: a customer's message (or its edited
 * version) is added to their latest Telegram conversation in the mailbox,
 * or starts a new one. Private chats only.
 */
class Incoming
{
    /**
     * Process an update. Throws when processing failed and Telegram should
     * send the update again.
     */
    public static function handle(Mailbox $mailbox, array $update)
    {
        $edited = isset($update['edited_message']);
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)
            || ($message['chat']['type'] ?? '') != 'private'
            || empty($message['from']['id'])
            || !empty($message['from']['is_bot'])
        ) {
            return;
        }

        $settings = Telegram::settings($mailbox);
        $client = Telegram::client($mailbox);
        $chat_id = $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        $is_start = !$edited && preg_match('#^/start(\s|$)#', $text);
        if ($is_start && $settings['ignore_start']) {
            self::autoReply($client, $chat_id, Telegram::autoReply($settings, $message['from']['language_code'] ?? ''), $mailbox);

            return;
        }

        $body = self::body($message, $text);
        $files = self::files($message);
        if ($body === '' && !$files) {
            Telegram::log('Message of a type Tallport does not take ('.implode(', ', array_keys($message)).') from Telegram user '.$message['from']['id'].' ignored.', $mailbox);

            return;
        }

        $attachments = [];
        foreach ($files as $file) {
            try {
                [$contents, $file_name] = $client->downloadFile($file['file_id']);
                $attachments[] = [
                    'file_name' => $file['file_name'] ?: $file_name,
                    'data'      => base64_encode($contents),
                ];
            } catch (TelegramException $e) {
                $body .= '<p><em>'.htmlspecialchars(__('A file could not be downloaded from Telegram').': '.($file['file_name'] ?: $file['type']).' ('.$e->getMessage().')').'</em></p>';
            }
        }
        if ($edited) {
            $body = '<p><em>'.htmlspecialchars(__('Edited message')).'</em></p>'.$body;
        }
        if ($body === '') {
            // Threads need a body; a file on its own gets its name.
            $body = htmlspecialchars(implode(', ', array_column($attachments, 'file_name')));
        }

        $customer = self::customer($mailbox, $client, $message['from']);

        $conversation = Conversation::where('mailbox_id', $mailbox->id)
            ->where('customer_id', $customer->id)
            ->where('channel', Telegram::CHANNEL)
            ->orderBy('created_at', 'desc')
            ->first();
        $thread = [
            'type'        => Thread::TYPE_CUSTOMER,
            'customer_id' => $customer->id,
            'body'        => $body,
            'attachments' => $attachments,
        ];
        if ($conversation && !$conversation->chatShouldStartNew($mailbox)) {
            Thread::createExtended($thread, $conversation, $customer);
        } else {
            Conversation::create([
                'type'        => Conversation::TYPE_CHAT,
                'subject'     => self::subject($text, $body),
                'mailbox_id'  => $mailbox->id,
                'source_type' => Conversation::SOURCE_TYPE_WEB,
                'channel'     => Telegram::CHANNEL,
            ], [$thread], $customer);
        }

        if ($is_start) {
            self::autoReply($client, $chat_id, Telegram::autoReply($settings, $message['from']['language_code'] ?? ''), $mailbox);
        }
    }

    /**
     * The start of the message (Conversation::subjectFromText() would take
     * text in angle brackets for tags).
     */
    protected static function subject($text, $body)
    {
        $plain = $text !== '' ? $text : html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
        $plain = trim(preg_replace('/\s+/u', ' ', $plain));

        return mb_strlen($plain) > Conversation::SUBJECT_LENGTH ? mb_substr($plain, 0, Conversation::SUBJECT_LENGTH - 1).'…' : $plain;
    }

    /**
     * The message as HTML: its text (or caption), a shared location or a
     * shared contact.
     */
    protected static function body(array $message, $text)
    {
        $parts = [];
        if ($text !== '') {
            $parts[] = nl2br(htmlspecialchars($text));
        }
        if (!empty($message['venue'])) {
            $parts[] = htmlspecialchars(trim(($message['venue']['title'] ?? '').', '.($message['venue']['address'] ?? ''), ', '));
        }
        if (!empty($message['location']) && isset($message['location']['latitude'], $message['location']['longitude'])) {
            $url = 'https://www.openstreetmap.org/?mlat='.(float) $message['location']['latitude'].'&mlon='.(float) $message['location']['longitude'];
            $parts[] = htmlspecialchars(__('Location')).': <a href="'.htmlspecialchars($url).'">'.htmlspecialchars($url).'</a>';
        }
        if (!empty($message['contact'])) {
            $contact = $message['contact'];
            $parts[] = htmlspecialchars(__('Contact').': '.trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? '')).', '.($contact['phone_number'] ?? ''));
        }

        return implode('<br>', $parts);
    }

    /**
     * Files in the message: [type, file_id, file_name].
     */
    protected static function files(array $message)
    {
        $files = [];
        if (!empty($message['photo']) && is_array($message['photo'])) {
            // Sizes, smallest first.
            $photo = end($message['photo']);
            $files[] = ['type' => 'photo', 'file_id' => $photo['file_id'] ?? '', 'file_name' => ''];
        }
        foreach (['document', 'video', 'audio', 'voice', 'video_note', 'animation', 'sticker'] as $type) {
            if (!empty($message[$type]['file_id'])) {
                $files[] = ['type' => $type, 'file_id' => $message[$type]['file_id'], 'file_name' => (string) ($message[$type]['file_name'] ?? '')];
            }
        }

        return array_values(array_filter($files, function ($file) {
            return $file['file_id'] !== '';
        }));
    }

    /**
     * The customer with this Telegram user ID; else one whose profile has
     * their Telegram username; else a new customer.
     */
    protected static function customer(Mailbox $mailbox, Client $client, array $user)
    {
        $user_id = (string) $user['id'];
        $username = (string) ($user['username'] ?? '');

        $customer = Customer::getCustomerByChannel(Telegram::CHANNEL, $user_id);
        $new_link = false;
        if (!$customer && $username !== '') {
            $customer = Customer::findCustomersBySocialProfile(Customer::SOCIAL_TYPE_TELEGRAM, $username, Telegram::CHANNEL)->first();
            if ($customer) {
                $customer->addChannel(Telegram::CHANNEL, $user_id);
                $new_link = true;
            }
        }
        if (!$customer) {
            $customer = Customer::createWithoutEmail([
                // The observer links the channel.
                'channel'         => Telegram::CHANNEL,
                'channel_id'      => $user_id,
                'first_name'      => mb_substr((string) ($user['first_name'] ?? '') ?: $user_id, 0, 255),
                'last_name'       => mb_substr((string) ($user['last_name'] ?? ''), 0, 255),
                'social_profiles' => $username !== '' ? Customer::formatSocialProfiles([['type' => Customer::SOCIAL_TYPE_TELEGRAM, 'value' => $username]]) : [],
            ]);
            $new_link = true;
        } elseif ($username !== '') {
            $profiles = $customer->getSocialProfiles();
            $has_telegram = collect($profiles)->contains(function ($profile) {
                return ($profile['type'] ?? null) == Customer::SOCIAL_TYPE_TELEGRAM;
            });
            if (!$has_telegram) {
                $profiles[] = ['type' => Customer::SOCIAL_TYPE_TELEGRAM, 'value' => $username];
                $customer->setSocialProfiles($profiles);
                $customer->save();
            }
        }

        if ($new_link && !$customer->photo_url) {
            self::photo($customer, $client, $user_id, $mailbox);
        }

        return $customer;
    }

    protected static function photo(Customer $customer, Client $client, $user_id, Mailbox $mailbox)
    {
        try {
            $photo = $client->downloadProfilePhoto($user_id);
            if ($photo) {
                $path = tempnam(sys_get_temp_dir(), 'telegram');
                file_put_contents($path, $photo[0]);
                $photo_url = $customer->savePhoto($path, mime_content_type($path));
                if ($photo_url) {
                    $customer->photo_url = $photo_url;
                    $customer->save();
                }
                @unlink($path);
            }
        } catch (\Throwable $e) {
            // The photo is a nicety.
            Telegram::log('Profile photo of Telegram user '.$user_id.' not saved: '.$e->getMessage(), $mailbox);
        }
    }

    protected static function autoReply(Client $client, $chat_id, $text, Mailbox $mailbox)
    {
        if (trim($text) === '') {
            return;
        }
        try {
            $client->sendMessage($chat_id, $text);
        } catch (TelegramException $e) {
            Telegram::log('Auto reply to /start not sent: '.$e->getMessage(), $mailbox);
        }
    }
}
