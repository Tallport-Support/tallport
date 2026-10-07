<?php

namespace App\Ai;

use App\Conversation;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI call (aiassistant_usage): its tokens (what a conversation cost, and what the daily
 * budget and hourly caps count), and for the AI log (Manage » Logs » AI) its provider, model,
 * duration and outcome. Failed calls are recorded too, without tokens; only succeeded ones count.
 */
class Usage extends Model
{
    const FEATURE_TRANSLATION = 'translation';
    const FEATURE_REPLY_TRANSLATION = 'reply_translation';
    const FEATURE_SUMMARY = 'summary';
    const FEATURE_DRAFT = 'draft';
    const FEATURE_LANGUAGE = 'language';

    /**
     * The calls of each feature in the settings (Settings::MODEL_FEATURES).
     */
    const SETTING_FEATURES = [
        'summaries'    => [self::FEATURE_SUMMARY],
        'translations' => [self::FEATURE_TRANSLATION, self::FEATURE_REPLY_TRANSLATION],
        'drafts'       => [self::FEATURE_DRAFT],
        'language'     => [self::FEATURE_LANGUAGE],
    ];

    const STATUS_OK = 'ok';
    // Failed, and nothing left to try: the error shows.
    const STATUS_FAILED = 'failed';
    // Failed, the backup model was tried next.
    const STATUS_FAILED_THEN_BACKUP = 'failed_then_backup';
    // The model refused the fast options (less reasoning) and was called again without them.
    const STATUS_FAST_REFUSED = 'fast_refused';
    // The model refused fast mode (the provider's faster tier) and was called again without it.
    const STATUS_FAST_TIER_REFUSED = 'fast_tier_refused';

    const ERROR_LENGTH = 1000;

    /**
     * Days the log keeps failed calls, and calls whose tokens no conversation shows (Retention).
     */
    const LOG_DAYS = 90;

    const UPDATED_AT = null;

    protected $table = 'aiassistant_usage';

    protected $guarded = [];

    protected $casts = [
        'backup'    => 'boolean',
        'fast'      => 'boolean',
        'fast_tier' => 'boolean',
        'streamed'  => 'boolean',
    ];

    public function mailbox()
    {
        return $this->belongsTo(\App\Mailbox::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Record a call: a response's tokens (laravel/ai's $response->usage), for a conversation or a
     * mailbox, and $call: how it went (TallportAgent: provider_id, provider, model, backup, fast,
     * fast_tier, streamed, duration_ms, status, error). Without either, nothing.
     */
    public static function record($response, $feature, ?Conversation $conversation = null, $mailbox_id = null, $user_id = null, $items = 1, array $call = [])
    {
        $usage = $response->usage ?? null;
        if (!$usage && !$call) {
            return null;
        }
        if (isset($call['error'])) {
            $call['error'] = self::redact($call['error']);
        }

        return self::create(array_merge([
            'mailbox_id'      => $conversation ? $conversation->mailbox_id : $mailbox_id,
            'conversation_id' => $conversation ? $conversation->id : null,
            'customer_id'     => $conversation ? $conversation->customer_id : null,
            'user_id'         => $user_id,
            'feature'         => $feature,
            'input_tokens'    => $usage ? (int) $usage->inputTokens : 0,
            'output_tokens'   => $usage ? (int) $usage->outputTokens : 0,
            'items'           => max(1, (int) $items),
        ], $call));
    }

    /**
     * An error message for the log: without API keys (the ones set up, and anything that looks
     * like one or like an Authorization header), at most ERROR_LENGTH characters.
     */
    public static function redact($message)
    {
        $message = (string) $message;
        $keys = [\Helper::decrypt(\Option::get('aiassistant.documentation.embedding_api_key', ''))];
        foreach (Settings::providers() as $provider) {
            $keys[] = \Helper::decrypt($provider['api_key']);
        }
        foreach ($keys as $key) {
            if (is_string($key) && strlen($key) >= 8) {
                $message = str_replace($key, '[redacted]', $message);
            }
        }
        $message = preg_replace([
            '/\b(Bearer|Basic)\s+[^\s"\',]+/i',
            '/\b((?:x-)?api[-_]?key|authorization|access[-_]?token|secret|password|key)(["\']?\s*[:=]\s*["\']?)(?!Bearer\b|Basic\b|\[redacted\])[^\s"\',&}]+/i',
            '/\b(sk|xai|gsk|csk|pplx|hf|fw|r8|pk|rk)[-_][A-Za-z0-9_\-*]{8,}/',
            '/\bAIza[A-Za-z0-9_\-]{20,}/',
            '/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{32,}\b/',
        ], [
            '$1 [redacted]',
            '$1$2[redacted]',
            '[redacted]',
            '[redacted]',
            '[redacted]',
        ], $message);

        return mb_substr(trim($message), 0, self::ERROR_LENGTH);
    }

    /**
     * Calls that worked: the ones with tokens, which the budgets and caps count.
     */
    public function scopeSucceeded($query)
    {
        return $query->where('status', self::STATUS_OK);
    }

    /**
     * All the tokens a conversation used.
     */
    public static function forConversation(Conversation $conversation)
    {
        return (int) self::succeeded()->where('conversation_id', $conversation->id)->sum(\DB::raw('input_tokens + output_tokens'));
    }

    /**
     * The tokens a mailbox used today (the daily budget).
     */
    public static function mailboxToday($mailbox_id)
    {
        return (int) self::succeeded()->where('mailbox_id', $mailbox_id)->where('created_at', '>=', now()->startOfDay())->sum(\DB::raw('input_tokens + output_tokens'));
    }

    /**
     * A customer's messages translated in the last hour (the hourly cap).
     */
    public static function customerTranslationsLastHour($customer_id)
    {
        return (int) self::succeeded()->where('customer_id', $customer_id)->where('feature', self::FEATURE_TRANSLATION)->where('created_at', '>=', now()->subHour())->sum('items');
    }

    /**
     * What the log no longer keeps after LOG_DAYS: failed calls, and calls whose tokens no
     * conversation's sidebar shows (none, or deleted). The budgets and caps only look at today
     * and the last hour; a conversation's own calls stay for its token count.
     */
    public static function expiredLog()
    {
        return self::where('created_at', '<', now()->subDays(self::LOG_DAYS))
            ->where(function ($query) {
                $query->where('status', '!=', self::STATUS_OK)
                    ->orWhereNull('conversation_id')
                    ->orWhereNotExists(function ($query) {
                        $query->selectRaw('1')->from('conversations')->whereColumn('conversations.id', 'aiassistant_usage.conversation_id');
                    });
            });
    }

    public static function featureName($feature)
    {
        return [
            self::FEATURE_TRANSLATION       => __('Translations'),
            self::FEATURE_REPLY_TRANSLATION => __('Reply Translations'),
            self::FEATURE_SUMMARY           => __('Summaries'),
            self::FEATURE_DRAFT             => __('Drafts'),
            self::FEATURE_LANGUAGE          => __('Language Detection'),
        ][$feature] ?? $feature;
    }

    /**
     * The outcome in words, and its badge's tone.
     */
    public function statusName()
    {
        return [
            self::STATUS_OK                 => [__('Succeeded'), 'success'],
            self::STATUS_FAILED             => [__('Failed'), 'danger'],
            self::STATUS_FAILED_THEN_BACKUP => [__('Failed, Backup Tried'), 'warning'],
            self::STATUS_FAST_REFUSED       => [__('Less Reasoning Refused'), 'warning'],
            self::STATUS_FAST_TIER_REFUSED  => [__('Fast Mode Refused'), 'warning'],
        ][$this->status] ?? [$this->status, 'neutral'];
    }

    public function isError()
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_FAILED_THEN_BACKUP]);
    }

    /**
     * Seconds, one decimal ("1.2 s").
     */
    public static function formatDuration($ms)
    {
        return $ms === null ? '' : number_format($ms / 1000, 1).' s';
    }

    /**
     * A model's recent calls for a feature in the settings (Settings › AI): when it was last
     * called and how long that took, or its failures. Null before its first call.
     *
     * @return array|null ['text' => ..., 'failing' => bool]
     */
    public static function modelStatus($setting_feature, $provider_id, $model)
    {
        $calls = self::whereIn('feature', self::SETTING_FEATURES[$setting_feature] ?? [$setting_feature])
            ->where('provider_id', $provider_id)
            ->where('model', $model)
            ->whereNotIn('status', [self::STATUS_FAST_REFUSED, self::STATUS_FAST_TIER_REFUSED]);
        $last = (clone $calls)->orderBy('id', 'desc')->first();
        if (!$last) {
            return null;
        }
        if (!$last->isError()) {
            return ['text' => __('Last call :time · :duration', ['time' => $last->created_at->diffForHumans(), 'duration' => self::formatDuration($last->duration_ms)]), 'failing' => false];
        }
        $failures = (clone $calls)->whereIn('status', [self::STATUS_FAILED, self::STATUS_FAILED_THEN_BACKUP])->where('created_at', '>=', now()->subHour())->count();
        $text = $failures
            ? trans_choice('1 failure in the last hour|:count failures in the last hour', $failures)
            : __('Last call failed :time', ['time' => $last->created_at->diffForHumans()]);
        $error = trim((string) $last->error);
        if ($error !== '') {
            $text .= ' · '.\Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $error), 80);
        }

        return ['text' => $text, 'failing' => true];
    }
}
