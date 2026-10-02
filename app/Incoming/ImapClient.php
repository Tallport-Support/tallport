<?php

namespace App\Incoming;

use Webklex\PHPIMAP\Client;

/**
 * A webklex/php-imap 6 client with what Tallport uses from the client
 * FreeScout had (its patched webklex 4.1). Webklex 6 throws exceptions where
 * that one collected errors, so there are none to report here.
 */
class ImapClient
{
    /**
     * The webklex 6 client.
     *
     * @var Client
     */
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * The webklex 6 client.
     */
    public function client(): Client
    {
        return $this->client;
    }

    public function connect()
    {
        $this->client->connect();

        return $this;
    }

    public function disconnect()
    {
        $this->client->disconnect();

        return $this;
    }

    public function isConnected()
    {
        return $this->client->isConnected();
    }

    public function getFolder($folder_name)
    {
        return $this->client->getFolder($folder_name);
    }

    /**
     * A folder by its path, which Tallport converts to UTF7-IMAP already
     * (MailHelper::getImapFolder(), the Sent folder).
     */
    public function getFolderByPath($folder_path)
    {
        return $this->client->getFolderByPath($folder_path, true);
    }

    public function getFolders()
    {
        return $this->client->getFolders();
    }

    public function openFolder($folder_path)
    {
        return $this->client->openFolder($folder_path);
    }

    public function getConnection()
    {
        return $this->client->getConnection();
    }

    public function getLastError()
    {
        return '';
    }

    public function getErrors()
    {
        return [];
    }
}
