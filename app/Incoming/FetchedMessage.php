<?php

namespace App\Incoming;

/**
 * An email fetched with the webklex 6 client that webklex couldn't make a
 * message of (an unusual structure or date; App\Incoming\Parser reads it):
 * its raw source, and what tallport:fetch-emails needs to mark it as seen.
 */
class FetchedMessage
{
    protected $raw;

    /**
     * The client it was fetched with (none when read from a string).
     *
     * @var \Webklex\PHPIMAP\Client|null
     */
    protected $client;

    protected $folder_path;

    protected $uid;

    public function __construct($raw, $client = null, $folder_path = null, $uid = null)
    {
        $this->raw = preg_replace("/\r?\n/", "\r\n", $raw);
        $this->client = $client;
        $this->folder_path = $folder_path;
        $this->uid = $uid;
    }

    /**
     * Fetch the messages of a query that webklex couldn't make (soft_fail).
     *
     * @return self[] By Message-ID, as the query's messages.
     */
    public static function fetchFailed($query, $folder)
    {
        $messages = [];
        $client = $query->getClient();
        foreach (array_keys($query->errors()) as $uid) {
            $client->openFolder($folder->path);
            $connection = $client->getConnection();
            $header = $connection->headers([$uid])->validatedData()[$uid] ?? '';
            $body = $connection->content([$uid])->validatedData()[$uid] ?? '';

            $message = new self(rtrim($header, "\r\n")."\r\n\r\n".$body, $client, $folder->path, $uid);
            $messages[$message->getMessageId()] = $message;
        }

        return $messages;
    }

    public function rawSource()
    {
        return $this->raw;
    }

    /**
     * The header section, as the libraries' messages give it (->raw).
     */
    public function getHeader()
    {
        $end = strpos($this->raw, "\r\n\r\n");

        return (object) ['raw' => $end === false ? $this->raw : substr($this->raw, 0, $end)];
    }

    public function getMessageId()
    {
        return trim((string) HeaderText::value($this->raw, 'Message-ID'), '<> ');
    }

    public function getSubject()
    {
        return HeaderText::decode(HeaderText::value($this->raw, 'Subject'));
    }

    public function getDate()
    {
        try {
            return \Carbon\Carbon::parse(HeaderText::value($this->raw, 'Date'));
        } catch (\Throwable $e) {
            return now();
        }
    }

    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set a flag on the server, e.g. ['Seen'].
     *
     * @param  array|string  $flag
     */
    public function setFlag($flag)
    {
        $this->client->openFolder($this->folder_path);
        $flag = '\\'.trim(is_array($flag) ? implode(' \\', $flag) : $flag);

        return (bool) $this->client->getConnection()->store([$flag], $this->uid, $this->uid, '+', true, \Webklex\PHPIMAP\IMAP::ST_UID)->validatedData();
    }
}
