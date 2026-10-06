<?php

namespace App\Ai;

/**
 * AI Assistant settings (Manage » Settings » AI Assistant), stored as
 * aiassistant.* options. Languages: an installation default, overridden per
 * mailbox and per user.
 */
class Settings
{
    const DEFAULT_MODEL = 'gpt-4.1-nano';

    const DEFAULT_LANGUAGE = 'en';

    const DEFAULT_DRAFTS_PER_DAY = 50;

    const DEFAULT_TRANSLATIONS_PER_CUSTOMER_HOUR = 60;

    /**
     * Languages for summaries and translations (as the module offered).
     */
    const LANGUAGES = [
        'en'         => 'English',
        'ms-Arab'    => 'Bahasa Melayu (Arab)',
        'ms-Latn'    => 'Bahasa Melayu (Latin)',
        'de'         => 'Deutsch',
        'es'         => 'Español',
        'fr'         => 'Français',
        'el-monoton' => 'Ελληνικά (μονοτονικό)',
        'el-polyton' => 'Ελληνικά (πολυτονικό)',
        'it'         => 'Italiano',
        'mt'         => 'Malti',
        'nl'         => 'Nederlands',
        'no'         => 'Norsk',
        'pl'         => 'Polski',
        'pt-PT'      => 'Português (PT)',
        'pt-BR'      => 'Português (BR)',
        'ro'         => 'Română',
        'sk'         => 'Slovenčina',
        'sl'         => 'Slovenščina',
        'sv'         => 'Svenska',
        'th'         => 'ไทย',
        'tr'         => 'Türkçe',
        'uk'         => 'Українська',
        'vi'         => 'Tiếng Việt',
        'ru'         => 'Русский',
        'ja'         => '日本語',
        'ko'         => '한국어',
        'zh-Hans'    => '简体中文',
        'zh-Hant'    => '繁體中文',
    ];

    public static function provider()
    {
        return Providers::normalize(\Option::get('aiassistant.provider', 'openai'));
    }

    public static function apiKey()
    {
        return (string) \Helper::decrypt(\Option::get('aiassistant.api_key', ''));
    }

    public static function baseUrl()
    {
        return rtrim(trim((string) \Option::get('aiassistant.base_url', '')), '/');
    }

    public static function model()
    {
        return trim((string) \Option::get('aiassistant.model', '')) ?: self::DEFAULT_MODEL;
    }

    /**
     * The model for translations (often a cheaper one), else the model.
     */
    public static function translationModel()
    {
        return trim((string) \Option::get('aiassistant.translation_model', '')) ?: self::model();
    }

    /**
     * Whether a provider has been set up (an API key where one is needed).
     */
    public static function isConfigured()
    {
        return self::apiKey() !== '' || !Providers::PRESETS[self::provider()]['requires_api_key'];
    }

    public static function embeddingProvider()
    {
        $provider = strtolower(trim((string) \Option::get('aiassistant.documentation.embedding_provider', 'same')));

        return $provider == 'same' || $provider == '' ? self::provider() : Providers::normalize($provider);
    }

    public static function embeddingProviderIsSame()
    {
        $provider = strtolower(trim((string) \Option::get('aiassistant.documentation.embedding_provider', 'same')));

        return $provider == 'same' || $provider == '';
    }

    public static function embeddingApiKey()
    {
        $key = (string) \Helper::decrypt(\Option::get('aiassistant.documentation.embedding_api_key', ''));

        return $key === '' && self::embeddingProviderIsSame() ? self::apiKey() : $key;
    }

    public static function embeddingBaseUrl()
    {
        $url = rtrim(trim((string) \Option::get('aiassistant.documentation.embedding_base_url', '')), '/');

        return $url === '' && self::embeddingProviderIsSame() ? self::baseUrl() : $url;
    }

    public static function embeddingModel()
    {
        return trim((string) \Option::get('aiassistant.documentation.embedding_model', ''))
            ?: (string) Providers::PRESETS[self::embeddingProvider()]['embedding_model'];
    }

    public static function embeddingsAvailable()
    {
        return Providers::supportsEmbeddings(self::embeddingProvider());
    }

    public static function chunkSize()
    {
        return max(500, min(20000, (int) \Option::get('aiassistant.documentation.chunk_size', 3000)));
    }

    public static function chunkOverlap()
    {
        return max(0, min(5000, (int) \Option::get('aiassistant.documentation.chunk_overlap', 400)));
    }

    public static function retrievalLimit()
    {
        return max(1, min(20, (int) \Option::get('aiassistant.documentation.retrieval_limit', 5)));
    }

    /**
     * Conversations with more messages than this get a summary.
     */
    public static function summaryThreshold()
    {
        return max(0, min(10, (int) \Option::get('aiassistant.summary_conversation_threshold', 3)));
    }

    /**
     * The language summaries and translations are in: the user's, else the
     * mailbox's, else the installation's.
     */
    public static function language($mailbox = null, $user = null)
    {
        if ($user && self::isLanguage($user->ai_language)) {
            return $user->ai_language;
        }
        if ($mailbox && self::isLanguage($code = self::mailboxLanguage($mailbox))) {
            return $code;
        }

        return self::defaultLanguage();
    }

    public static function defaultLanguage()
    {
        $code = \Option::get('aiassistant.translation_language', self::DEFAULT_LANGUAGE);

        return self::isLanguage($code) ? $code : self::DEFAULT_LANGUAGE;
    }

    public static function mailboxLanguage($mailbox)
    {
        $languages = (array) \Option::get('aiassistant.mailbox_language', []);

        return $languages[$mailbox->id] ?? null;
    }

    public static function isLanguage($code)
    {
        return is_string($code) && isset(self::LANGUAGES[$code]);
    }

    public static function languageName($code)
    {
        return self::LANGUAGES[$code] ?? $code;
    }

    /**
     * A language's name in the viewer's language (with PHP's intl; else its
     * own name).
     */
    public static function displayName($code, $locale = null)
    {
        if (class_exists('Locale')) {
            $name = \Locale::getDisplayName($code, str_replace('-', '_', $locale ?: app()->getLocale()));
            if ($name && $name != $code) {
                return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
            }
        }

        return self::languageName($code);
    }

    /**
     * For a list to choose from: the name in the viewer's language, and its
     * own name if that is different.
     */
    public static function optionName($code)
    {
        $name = self::displayName($code);

        return $name == self::languageName($code) ? $name : $name.' ('.self::languageName($code).')';
    }

    /**
     * The languages, code => name in the viewer's language, in alphabetical
     * order.
     */
    public static function displayNames()
    {
        $names = [];
        foreach (array_keys(self::LANGUAGES) as $code) {
            $names[$code] = self::displayName($code);
        }
        if (class_exists('Collator')) {
            (new \Collator(app()->getLocale()))->asort($names);
        } else {
            asort($names);
        }

        return $names;
    }

    /**
     * Features that can be turned off per mailbox (all on by default).
     */
    const FEATURES = ['summaries', 'translations', 'drafts'];

    public static function enabled($feature, $mailbox)
    {
        if (!$mailbox) {
            return false;
        }
        $off = (array) (((array) \Option::get('aiassistant.mailbox_features_off', []))[$mailbox->id] ?? []);

        return !in_array($feature, $off);
    }

    /**
     * Whether a mailbox's chats are translated both ways: the agent reads and writes in their
     * own language, the customer in theirs (off unless turned on for the mailbox).
     */
    public static function chatTranslation($mailbox)
    {
        return $mailbox && !empty(((array) \Option::get('aiassistant.mailbox_chat_translation', []))[$mailbox->id]);
    }

    /**
     * Tokens each mailbox may use per day (all AI features); 0: no limit.
     */
    public static function dailyTokens()
    {
        return max(0, (int) \Option::get('aiassistant.daily_tokens', 0));
    }

    /**
     * Whether a mailbox still has tokens left today; when not, the AI Assistant is
     * unavailable there until tomorrow.
     */
    public static function withinBudget($mailbox)
    {
        $limit = self::dailyTokens();

        return !$limit || !$mailbox || Usage::mailboxToday($mailbox->id) < $limit;
    }

    /**
     * Messages of one customer translated per hour, against floods; 0: no limit.
     */
    public static function translationsPerCustomerHour()
    {
        return max(0, (int) \Option::get('aiassistant.translations_per_customer_hour', self::DEFAULT_TRANSLATIONS_PER_CUSTOMER_HOUR));
    }

    /**
     * Drafts a user may make per day: their own limit, else the
     * installation's. 0 turns drafting off.
     */
    public static function draftsPerDay($user)
    {
        if ($user && $user->ai_drafts_per_day !== null) {
            return max(0, (int) $user->ai_drafts_per_day);
        }

        return max(0, (int) \Option::get('aiassistant.drafts_per_day', self::DEFAULT_DRAFTS_PER_DAY));
    }
}
