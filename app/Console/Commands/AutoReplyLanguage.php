<?php

namespace App\Console\Commands;

use App\AutoReply\AutoReplies;
use App\AutoReply\LanguageDetector;
use App\Conversation;
use Illuminate\Console\Command;

class AutoReplyLanguage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:auto-reply-language
        {conversation? : Conversation ID}
        {--text= : A text instead of a conversation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show which language a conversation (or text) is recognised as, and which auto reply it gets';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $conversation = null;
        if ($this->argument('conversation')) {
            $conversation = Conversation::find($this->argument('conversation'));
            if (!$conversation) {
                $this->error('Conversation not found: '.$this->argument('conversation'));

                return 1;
            }
            $text = AutoReplies::text($conversation);
        } elseif ($this->option('text') !== null) {
            $text = $this->option('text');
        } else {
            $this->error('Pass a conversation ID or --text="..."');

            return 1;
        }

        $detector = new LanguageDetector();
        $analysis = $detector->analyze($text);

        $this->line('Text looked at:');
        $this->line('  '.str_replace("\n", "\n  ", mb_substr($detector->prepareText($text), 0, 1000)));
        $this->line('');
        $this->table(['Script', 'Characters'], [
            ['Kana (Japanese)', $analysis['counts']['kana']],
            ['Hangul (Korean)', $analysis['counts']['hangul']],
            ['Han (Chinese, kanji, hanja)', $analysis['counts']['han']],
            ['Latin', $analysis['counts']['latin']],
            ['Other letters', $analysis['counts']['other']],
        ]);
        $this->line(sprintf(
            'CJK share: %d%%, kana share of CJK: %d%%, Hangul share of CJK: %d%%',
            round($analysis['cjk_ratio'] * 100),
            round($analysis['kana_ratio'] * 100),
            round($analysis['hangul_ratio'] * 100)
        ));
        $language = $analysis['language'];
        if ($language == LanguageDetector::LANG_CHINESE) {
            $language = $detector->chineseVariant($text) ?: 'zh (install PHP intl to tell Simplified and Traditional apart)';
        }
        $this->info('From the characters: '.($language == LanguageDetector::LANG_ENGLISH ? 'not Chinese, Japanese or Korean' : $language));

        if ($conversation) {
            $auto_reply = AutoReplies::forConversation($conversation, $conversation->mailbox);
            $this->info('Auto reply: '.($auto_reply['language'] ? $auto_reply['language'] : 'default').' - "'.$auto_reply['subject'].'"');
        }

        return 0;
    }
}
