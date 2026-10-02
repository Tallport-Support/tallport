<?php

namespace Tests\Feature;

use App\Incoming\FetchedMessage;
use App\Incoming\ImapClient;
use Mockery;
use Tests\FeatureTestCase;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * Fetching with webklex/php-imap 6 (APP_FETCH_CLIENT=webklex6). Saving the
 * fetched emails is covered by IncomingMailSnapshotWebklex6ClientTest.
 */
class WebklexClientTest extends FeatureTestCase
{
    protected function imapMailbox()
    {
        $mailbox = $this->createMailbox([$this->createUser()]);
        $mailbox->fill(['in_protocol' => 1, 'in_server' => '127.0.0.1', 'in_port' => 1, 'in_username' => 'u', 'in_password' => 'p', 'in_encryption' => \App\Mailbox::IN_ENCRYPTION_NONE])->save();

        return $mailbox;
    }

    public function testSwitch()
    {
        $mailbox = $this->imapMailbox();

        $this->assertInstanceOf(\App\LegacyImap\Client::class, \MailHelper::getMailboxClient($mailbox));

        config(['app.fetch_client' => 'webklex6']);
        $client = \MailHelper::getMailboxClient($mailbox);

        $this->assertInstanceOf(ImapClient::class, $client);
        $this->assertSame('127.0.0.1', $client->client()->host);
        $this->assertSame('u', $client->client()->username);
        $this->assertTrue($client->client()->getConfig()->get('options.soft_fail'));
        $this->assertSame('id', $client->client()->getConfig()->get('options.message_key'));
    }

    public function testFolderPathIsNotEncodedTwice()
    {
        $webklex = Mockery::mock(\Webklex\PHPIMAP\Client::class);
        $webklex->shouldReceive('getFolderByPath')->with('Entw&APw-rfe', true)->once()->andReturn(null);

        \MailHelper::getImapFolder(new ImapClient($webklex), 'Entwürfe');
    }

    public function testConnectionTestReportsTheError()
    {
        config(['app.fetch_client' => 'webklex6']);
        $level = ob_get_level();

        $result = \MailHelper::fetchTest($this->imapMailbox());

        $this->assertSame('error', $result['result']);
        $this->assertNotEmpty($result['msg']);
        $this->assertSame($level, ob_get_level(), 'Output buffering is ended.');
    }

    public function testProtocolResults()
    {
        $this->assertSame([3, 5], \MailHelper::protocolResult(Response::empty()->setResult([3, 5])));
        $this->assertSame([3, 5], \MailHelper::protocolResult([3, 5]), 'The legacy client returns arrays.');
    }

    public function testMessageWebklexCouldNotMakeIsFetchedRaw()
    {
        $raw = file_get_contents(base_path('tests/Messages/issue-4567.eml'));
        [$header, $body] = explode("\n\n", str_replace("\r\n", "\n", $raw), 2);
        $header = "Message-ID: <Webklex/php-imap/issues/4567@github.com>\n".$header;
        $connection = Mockery::mock();
        $connection->shouldReceive('headers')->with([7])->andReturn(Response::empty()->setResult([7 => $header]));
        $connection->shouldReceive('content')->with([7])->andReturn(Response::empty()->setResult([7 => $body]));
        $connection->shouldReceive('store')->with(['\\Seen'], 7, 7, '+', true, \Webklex\PHPIMAP\IMAP::ST_UID)->once()->andReturn(Response::empty()->setResult(true));
        $client = Mockery::mock();
        $client->shouldReceive('openFolder')->with('INBOX');
        $client->shouldReceive('getConnection')->andReturn($connection);
        $query = Mockery::mock();
        $query->shouldReceive('getClient')->andReturn($client);
        $query->shouldReceive('errors')->andReturn([7 => new \Exception('no content found')]);

        $messages = FetchedMessage::fetchFailed($query, (object) ['path' => 'INBOX']);

        $this->assertSame(['Webklex/php-imap/issues/4567@github.com'], array_keys($messages));
        $message = $messages['Webklex/php-imap/issues/4567@github.com'];
        $this->assertStringContainsString("\r\nFrom: ", $message->getHeader()->raw);
        $this->assertSame('2017-09-13', $message->getDate()->format('Y-m-d'));
        $this->assertStringContainsString('132', $message->getSubject());
        $this->assertSame('Hi!', \App\Incoming\Parser::parse($message->rawSource())->attachments()[0]->getContent());
        $this->assertTrue($message->setFlag(['Seen']));
    }
}
