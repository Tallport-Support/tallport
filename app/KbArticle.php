<?php

namespace App;

use App\Ai\Document;
use Illuminate\Database\Eloquent\Model;

/**
 * A knowledge base article: for agents, of a mailbox or of all mailboxes
 * (no mailbox_id). Agents insert it in replies; the AI Assistant drafts
 * replies with it (each article is a private document of its mailboxes).
 */
class KbArticle extends Model
{
    const TITLE_MAX_LENGTH = 191;

    const CATEGORY_MAX_LENGTH = 100;

    /**
     * AI Assistant documents of articles.
     */
    const DOCUMENT_SOURCE_TYPE = 'kb';

    protected static function boot()
    {
        parent::boot();

        static::saved(function ($article) {
            self::syncSafely($article);
        });
        static::deleted(function ($article) {
            self::syncSafely($article);
        });
    }

    /**
     * A new mailbox gets the articles of all mailboxes.
     */
    public static function listen()
    {
        Mailbox::created(function () {
            foreach (self::whereNull('mailbox_id')->get() as $article) {
                self::syncSafely($article);
            }
        });
    }

    protected static function syncSafely(KbArticle $article)
    {
        try {
            $article->syncDocuments();
        } catch (\Throwable $e) {
            // Before the AI Assistant's tables.
            \Helper::logException($e, '[Knowledge Base]');
        }
    }

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    public function createdBy()
    {
        return $this->belongsTo('App\User', 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo('App\User', 'updated_by_user_id');
    }

    /**
     * Articles a user can read: of their mailboxes and of all mailboxes.
     */
    public static function visibleTo(User $user)
    {
        return self::where(function ($query) use ($user) {
            $query->whereNull('mailbox_id')->orWhereIn('mailbox_id', $user->mailboxesIdsCanView());
        });
    }

    /**
     * Articles of a mailbox (and of all mailboxes).
     */
    public static function forMailbox($mailbox_id)
    {
        return self::where(function ($query) use ($mailbox_id) {
            $query->whereNull('mailbox_id')->orWhere('mailbox_id', $mailbox_id);
        });
    }

    public static function canManage(?User $user)
    {
        return $user && ($user->isAdmin() || $user->hasPermission(User::PERM_EDIT_KB));
    }

    public function canBeEditedBy(?User $user)
    {
        return self::canManage($user) && (!$this->mailbox_id || $user->isAdmin() || in_array($this->mailbox_id, $user->mailboxesIdsCanView()));
    }

    /**
     * Its text for the AI Assistant: the title and the body, as plain text.
     */
    public function documentContent()
    {
        $html = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', (string) $this->body);
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h\d|/blockquote|/pre)\b[^>]*>#i', "\n", $html);
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(["/[ \t\x{00A0}]+/u", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], $text);

        return trim('# '.$this->title."\n\n".trim($text));
    }

    public static function documentIdentifier($id)
    {
        return 'kb:'.$id;
    }

    /**
     * Keep its AI Assistant documents (one per mailbox it's of) like it.
     */
    public function syncDocuments()
    {
        $identifier = self::documentIdentifier($this->id);
        $mailbox_ids = $this->exists ? ($this->mailbox_id ? [$this->mailbox_id] : Mailbox::pluck('id')->all()) : [];
        $removed = Document::where('source_identifier', $identifier)->whereNotIn('mailbox_id', $mailbox_ids ?: [0])->get();
        foreach ($removed as $document) {
            $document->chunks()->delete();
            $document->delete();
        }
        if (!$mailbox_ids) {
            return;
        }
        $content = $this->documentContent();
        foreach ($mailbox_ids as $mailbox_id) {
            $document = Document::firstOrNew(['mailbox_id' => $mailbox_id, 'source_identifier' => $identifier]);
            $document->source_type = self::DOCUMENT_SOURCE_TYPE;
            $document->source_url = 'kb://'.$this->id;
            $document->title = mb_substr($this->title, 0, 191);
            $document->enabled = true;
            $document->setContent($content);
            $document->save();
            if (\App\Ai\Documents::needsIndexing($document) && \App\Ai\Settings::isConfigured() && \App\Ai\Documents::available()) {
                \App\Jobs\AiIndexDocument::dispatch($document->id);
            }
        }
    }
}
