<?php

namespace App\Ai;

use App\Conversation;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI call (aiassistant_usage): its tokens (what a conversation cost, and what the daily
 * budget and hourly caps count), and for the AI log (Manage » Logs » AI) its provider, model,
 * duration and outcome. Calls without reported tokens keep them unknown, not zero.
 */
class Usage extends Model
{
    const FEATURE_TRANSLATION = 'translation';
    const FEATURE_REPLY_TRANSLATION = 'reply_translation';
    const FEATURE_SUMMARY = 'summary';
    const FEATURE_DRAFT = 'draft';
    const FEATURE_LANGUAGE = 'language';
    const FEATURE_EMBEDDING = 'embedding';

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
        // Some gateways produce an empty usage object when the provider reported no counts.
        $known = $usage && ($usage->inputTokens || $usage->outputTokens);
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
            'input_tokens'    => $known ? (int) $usage->inputTokens : null,
            'output_tokens'   => $known ? (int) $usage->outputTokens : null,
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
        foreach ((array) \Option::get('aiassistant.customer_context_secret_key', []) as $secret) {
            $keys[] = \Helper::decrypt($secret);
        }
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
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
     * Calls that worked, including ones whose token counts were not reported.
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
        return (int) self::where('conversation_id', $conversation->id)->sum(\DB::raw('COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)'));
    }

    /**
     * The tokens a mailbox used today (the daily budget).
     */
    public static function mailboxToday($mailbox_id)
    {
        $recorded = self::where('mailbox_id', $mailbox_id)->where('created_at', '>=', now()->startOfDay())
            ->sum(\DB::raw('COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)'));
        $reserved = \DB::table('aiassistant_reservations')->where('mailbox_id', $mailbox_id)->where('created_at', '>=', now()->startOfDay())->sum('tokens');

        return (int) ($recorded + $reserved);
    }

    /**
     * A customer's messages translated in the last hour (the hourly cap).
     */
    public static function customerTranslationsLastHour($customer_id)
    {
        $translated = self::succeeded()->where('customer_id', $customer_id)->where('feature', self::FEATURE_TRANSLATION)
            ->where('created_at', '>=', now()->subHour())->sum('items');
        $reserved = \DB::table('aiassistant_reservations')->where('customer_id', $customer_id)->where('created_at', '>=', now()->subHour())->sum('items');

        return (int) ($translated + $reserved);
    }

    /**
     * Reserve daily tokens and hourly customer messages before starting an AI request.
     * The mailbox and customer rows serialize claims even when there are no earlier usage rows.
     * Returns [reservation ID, limit reached], with nulls when no limit is configured.
     */
    public static function reserve($mailbox_id, $customer_id = null, $items = 0, $token_estimate = null)
    {
        $daily_limit = Settings::dailyTokens();
        $hourly_limit = Settings::translationsPerCustomerHour();
        if ((!$mailbox_id || !$daily_limit) && (!$customer_id || !$hourly_limit || !$items)) {
            return [null, null];
        }

        return \DB::transaction(function () use ($mailbox_id, $customer_id, $items, $token_estimate, $daily_limit, $hourly_limit) {
            $tokens = 0;
            if ($mailbox_id && $daily_limit) {
                \DB::table('mailboxes')->where('id', $mailbox_id)->lockForUpdate()->first();
                $remaining = $daily_limit - self::mailboxToday($mailbox_id);
                if ($remaining <= 0) {
                    return [null, 'budget'];
                }
                $tokens = max(1, (int) ($token_estimate ?? $remaining));
                if ($tokens > $remaining) {
                    return [null, 'budget'];
                }
            }
            $reserved_items = 0;
            if ($customer_id && $hourly_limit && $items) {
                \DB::table('customers')->where('id', $customer_id)->lockForUpdate()->first();
                if (self::customerTranslationsLastHour($customer_id) + $items > $hourly_limit) {
                    return [null, 'customer'];
                }
                $reserved_items = $items;
            }

            $id = \DB::table('aiassistant_reservations')->insertGetId([
                'mailbox_id'  => $mailbox_id,
                'customer_id' => $customer_id,
                'tokens'      => $tokens,
                'items'       => $reserved_items,
                'created_at'  => now(),
            ]);

            return [$id, null];
        }, 3);
    }

    /**
     * A completed or rejected call releases its claim. Unknown billable usage keeps the token
     * claim until the daily reset; customer messages only count after a successful translation.
     */
    public static function releaseReservation($id, $unknown = false)
    {
        if (!$id) {
            return;
        }
        if ($unknown) {
            \DB::table('aiassistant_reservations')->where('id', $id)->where('tokens', '>', 0)->update(['items' => 0]);
            \DB::table('aiassistant_reservations')->where('id', $id)->where('tokens', 0)->delete();
        } else {
            \DB::table('aiassistant_reservations')->where('id', $id)->delete();
        }
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
            self::FEATURE_EMBEDDING         => __('Documentation'),
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
