<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Http;

/**
 * The Telegram Bot API calls Tallport makes (https://core.telegram.org/bots/api).
 * The bot token is part of every URL, so it is kept out of error messages.
 */
class Client
{
    const API_URL = 'https://api.telegram.org';

    /**
     * Telegram's limit for files bots download.
     */
    const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    protected $token;

    public function __construct($token)
    {
        $this->token = (string) $token;
    }

    /**
     * Call a method; returns its result, or throws a TelegramException.
     * $files: [field => [contents, file name]] (sent as multipart).
     */
    public function call($method, array $params = [], array $files = [], $timeout = 30)
    {
        if ($this->token === '') {
            throw new TelegramException('The bot token is not set.');
        }

        try {
            $request = Http::withOptions(\Helper::setGuzzleDefaultOptions(['timeout' => $timeout, 'connect_timeout' => 10]))
                ->acceptJson();
            foreach ($files as $field => $file) {
                $request = $request->attach($field, $file[0], $file[1]);
            }
            if ($files) {
                // Multipart fields are strings.
                $params = array_map(function ($value) {
                    return is_array($value) ? json_encode($value) : $value;
                }, $params);
            }
            $response = $request->post($this->url('/bot'.$this->token.'/'.$method), $params);
        } catch (\Throwable $e) {
            throw new TelegramException($this->scrub($e->getMessage()));
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['ok'])) {
            $description = is_array($data) ? (string) ($data['description'] ?? '') : '';
            throw new TelegramException(
                $this->scrub($description ?: 'HTTP '.$response->status()),
                (int) (is_array($data) ? ($data['error_code'] ?? $response->status()) : $response->status()),
                (int) (is_array($data) ? ($data['parameters']['retry_after'] ?? 0) : 0)
            );
        }

        return $data['result'] ?? null;
    }

    /**
     * The bot: id, first_name, username.
     */
    public function getMe()
    {
        return $this->call('getMe');
    }

    public function setWebhook($url, $secret)
    {
        return $this->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'edited_message'],
        ]);
    }

    public function deleteWebhook()
    {
        return $this->call('deleteWebhook');
    }

    /**
     * url, pending_update_count, last_error_date, last_error_message.
     */
    public function getWebhookInfo()
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * Send text; $html: Telegram's HTML formatting. Returns the message.
     */
    public function sendMessage($chat_id, $text, $html = false)
    {
        $params = ['chat_id' => $chat_id, 'text' => $text];
        if ($html) {
            $params['parse_mode'] = 'HTML';
        }

        return $this->call('sendMessage', $params);
    }

    /**
     * Upload a file: as a photo (images Telegram can show) or a document.
     */
    public function sendFile($chat_id, $contents, $file_name, $as_photo = false)
    {
        $field = $as_photo ? 'photo' : 'document';

        return $this->call($as_photo ? 'sendPhoto' : 'sendDocument', ['chat_id' => $chat_id], [$field => [$contents, $file_name]], 120);
    }

    /**
     * Download a file sent to the bot: [contents, file name].
     */
    public function downloadFile($file_id)
    {
        $file = $this->call('getFile', ['file_id' => $file_id]);
        if (empty($file['file_path'])) {
            throw new TelegramException('The file is not available.');
        }
        if (($file['file_size'] ?? 0) > self::MAX_DOWNLOAD_BYTES) {
            throw new TelegramException('The file is larger than Telegram lets bots download.');
        }

        try {
            $response = Http::withOptions(\Helper::setGuzzleDefaultOptions(['timeout' => 120, 'connect_timeout' => 10]))
                ->get($this->url('/file/bot'.$this->token.'/'.$file['file_path']));
        } catch (\Throwable $e) {
            throw new TelegramException($this->scrub($e->getMessage()));
        }
        if (!$response->successful()) {
            throw new TelegramException('Could not download the file: HTTP '.$response->status());
        }

        return [$response->body(), basename($file['file_path'])];
    }

    /**
     * The user's current profile photo, if they have one (largest size).
     */
    public function downloadProfilePhoto($user_id)
    {
        $photos = $this->call('getUserProfilePhotos', ['user_id' => $user_id, 'limit' => 1]);
        $sizes = $photos['photos'][0] ?? [];
        if (!$sizes) {
            return null;
        }

        return $this->downloadFile(end($sizes)['file_id']);
    }

    protected function url($path)
    {
        return self::API_URL.$path;
    }

    protected function scrub($message)
    {
        return $this->token !== '' ? str_replace($this->token, '***', $message) : $message;
    }
}
