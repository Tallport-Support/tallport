<?php

namespace App\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * Documentation the AI Assistant drafts replies with, for one mailbox:
 * fetched from a URL (as Markdown) or pushed through the documentation API.
 */
class Document extends Model
{
    const SOURCE_TYPE_URL = 'url';
    const SOURCE_TYPE_API = 'api';

    const STATUS_PENDING = 'pending';
    const STATUS_INDEXED = 'indexed';
    const STATUS_FAILED = 'failed';

    const CANONICAL_LOCALE = 'en';

    /**
     * Locales of documentation sites with /en/ in their URLs: the same page
     * in another language is at /ja/, /zh/ or /ko/.
     */
    const SUPPORTED_LOCALES = ['en', 'ja', 'zh', 'ko'];

    protected $table = 'aiassistant_documents';

    protected $fillable = ['mailbox_id', 'source_identifier'];

    protected $casts = [
        'enabled'         => 'boolean',
        'localized_urls'  => 'array',
        'metadata'        => 'array',
        'last_indexed_at' => 'datetime',
    ];

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    public function chunks()
    {
        return $this->hasMany(DocumentChunk::class, 'document_id');
    }

    public function isPrivate()
    {
        return str_starts_with((string) $this->source_url, 'api://') || str_starts_with((string) $this->source_url, 'kb://');
    }

    /**
     * The page in a locale, else the page itself (none for a private one).
     */
    public function localizedUrl($locale)
    {
        $urls = (array) $this->localized_urls;
        if (!empty($urls[$locale])) {
            return $urls[$locale];
        }

        return $this->isPrivate() ? '' : (string) $this->source_url;
    }

    public static function normalizeSourceUrl($url)
    {
        return rtrim(preg_replace('/\.md$/i', '', trim($url)), '/');
    }

    /**
     * Only http(s) URLs are fetched and linked to.
     */
    public static function isHttpUrl($url)
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https']);
    }

    /**
     * For a URL with /en/: the same page in the other supported locales.
     */
    public static function localizedUrlsFor($url)
    {
        $url = self::normalizeSourceUrl($url);
        if (!preg_match('#/en(/|$)#', $url)) {
            return [];
        }
        $urls = [];
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $urls[$locale] = preg_replace('#/en(/|$)#', '/'.$locale.'$1', $url, 1);
        }

        return $urls;
    }

    public static function markdownUrlFor($url)
    {
        return self::normalizeSourceUrl($url).'.md';
    }

    public static function apiSourceIdentifier($identifier)
    {
        return hash('sha256', 'api:'.trim($identifier));
    }
}
