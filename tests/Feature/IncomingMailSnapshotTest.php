<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use Tests\Concerns\AssertsSnapshots;
use Tests\FeatureTestCase;

/**
 * What Tallport saves for real-world emails (tests/Messages, including
 * webklex/php-imap's own test messages in tests/Messages/webklex): sender,
 * recipients, subject, body and attachments of the conversation and its
 * threads. The incoming-mail code is being reorganised; these snapshots
 * must not change unless a change in behaviour is intended.
 */
class IncomingMailSnapshotTest extends FeatureTestCase
{
    use AssertsSnapshots;

    public static function messages()
    {
        $files = array_merge(glob(__DIR__.'/../Messages/*.eml'), glob(__DIR__.'/../Messages/webklex/*.eml'));
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            $name = (str_contains($file, '/webklex/') ? 'webklex/' : '').basename($file, '.eml');
            $cases[$name] = [$name, $file];
        }

        return $cases;
    }

    /**
     * @dataProvider messages
     */
    public function testIncomingMail($name, $file)
    {
        $mailbox = $this->createMailbox([$this->createUser(['email' => 'agent@snapshot.example'])], ['email' => 'support@snapshot.example']);

        try {
            $this->receiveEmail($mailbox, file_get_contents($file));
            $error = null;
        } catch (\Throwable $e) {
            $error = get_class($e).': '.$e->getMessage();
        }

        $conversations = Conversation::where('mailbox_id', $mailbox->id)->orderBy('id')->get()->map(function ($conversation) {
            return [
                'subject'        => $conversation->subject,
                'type'           => $conversation->type,
                'status'         => $conversation->status,
                'customer_email' => $conversation->customer_email,
                'cc'             => $conversation->cc,
                'bcc'            => $conversation->bcc,
                'threads'        => $conversation->threads()->orderBy('id')->get()->map(function (Thread $thread) {
                    return [
                        'type'        => $thread->type,
                        'from'        => $thread->from,
                        'to'          => $thread->to,
                        'cc'          => $thread->cc,
                        'bcc'         => $thread->bcc,
                        'body'        => $this->normalize($thread->body),
                        'headers'     => md5((string) $thread->headers),
                        'attachments' => $thread->attachments()->orderBy('id')->get()->map(function ($attachment) {
                            return [
                                'file_name' => $attachment->file_name,
                                'mime_type' => $attachment->mime_type,
                                'size'      => $attachment->size,
                                'embedded'  => (bool) $attachment->embedded,
                            ];
                        })->all(),
                    ];
                })->all(),
            ];
        })->all();

        // libxml2 2.14+ serialises HTML differently (line endings, Outlook's
        // <o:p> tags), so each libxml2 version has its own snapshots.
        // Production and CI use 2.9: CI uploads its snapshots as an artifact
        // when they don't match.
        $libxml = implode('.', array_slice(explode('.', LIBXML_DOTTED_VERSION), 0, 2));
        $this->assertMatchesSnapshot('incoming/libxml'.$libxml.'/'.$name, ['error' => $error, 'conversations' => $conversations]);
    }

    /**
     * Attachment URLs contain database IDs and hashes that differ per run.
     */
    protected function normalize($body)
    {
        return preg_replace('#(/storage/attachment/)[^"\'\s<>]+#', '$1…', (string) $body);
    }
}
