<?php

namespace App\Console\Commands;

use App\Incoming\Parser;
use Illuminate\Console\Command;

class ParseEml extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:parse-eml {file? : An email (.eml) file; storage/logs/email.eml if omitted}';

    /**
     * The name FreeScout used, still accepted (modules, scripts, older updaters).
     *
     * @var array
     */
    protected $aliases = ['freescout:parse-eml'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show how Tallport reads an email file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $email = (string) @file_get_contents($this->argument('file') ?: storage_path('logs/email.eml'));

        if (!str_contains($email, "\r\n")) {
            $email = str_replace("\n", "\r\n", $email);
        }

        $message = Parser::parse($email);
        $address = function ($addresses) {
            return json_encode($addresses[0] ?? [], JSON_UNESCAPED_UNICODE);
        };

        $this->line('Headers: ');
        $this->info($message->headers());
        $this->line('From: ');
        $this->info($address($message->from()));
        $this->line('Reply-To: ');
        $this->info($address($message->replyTo()));
        $this->line('In-Reply-To: ');
        $this->info($message->inReplyTo());
        $this->line('References: ');
        $this->info(json_encode(array_values(array_filter(preg_split('/[, <>]/', $message->references()))), JSON_UNESCAPED_UNICODE));
        $this->line('Date: ');
        $this->info((string) $message->date());
        $this->line('Subject: ');
        $this->info($message->subject());
        $this->line('Text Body: ');
        $this->info((string) $message->textBody());
        $this->line('HTML Body: ');
        $html_body = $message->htmlBody();
        $this->info($html_body);

        $attachments = $message->attachments();
        if (count($attachments)) {
            $this->line('Attachments: ');
            foreach ($attachments as $attachment) {
                $this->info('— '.$attachment->getName().($attachment->id && strstr($html_body, 'cid:'.$attachment->id) ? ' (embedded)' : ''));
            }
        }

        return 0;
    }
}
