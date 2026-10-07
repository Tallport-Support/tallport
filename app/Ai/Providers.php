<?php

namespace App\Ai;

use Laravel\Ai\Ai;

/**
 * The AI providers to choose from in Manage » Settings » AI Assistant, and
 * their laravel/ai configuration, built from the settings (App\Ai\Settings)
 * at runtime: config ai.providers.tallport (text) and
 * ai.providers.tallport-embeddings.
 */
class Providers
{
    const TEXT = 'tallport';

    const EMBEDDINGS = 'tallport-embeddings';

    /**
     * Presets: laravel/ai driver, default base URL (OpenAI-compatible ones),
     * whether a key is needed, embeddings support and default embedding model.
     */
    const PRESETS = [
        'openai' => [
            'name' => 'OpenAI', 'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1',
            'requires_api_key' => true, 'embedding_model' => 'text-embedding-3-small',
        ],
        'anthropic' => [
            'name' => 'Anthropic', 'driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1',
            'requires_api_key' => true, 'embedding_model' => null,
        ],
        'gemini' => [
            'name' => 'Google Gemini', 'driver' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/',
            'requires_api_key' => true, 'embedding_model' => 'gemini-embedding-001',
        ],
        'openrouter' => [
            'name' => 'OpenRouter', 'driver' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1',
            'requires_api_key' => true, 'embedding_model' => null,
        ],
        'groq' => [
            'name' => 'Groq', 'driver' => 'groq', 'base_url' => 'https://api.groq.com/openai/v1',
            'requires_api_key' => true, 'embedding_model' => null,
        ],
        'together' => [
            'name' => 'Together AI', 'driver' => 'openai-compatible', 'base_url' => 'https://api.together.xyz/v1',
            'requires_api_key' => true, 'embedding_model' => 'BAAI/bge-base-en-v1.5',
        ],
        'fireworks' => [
            'name' => 'Fireworks AI', 'driver' => 'openai-compatible', 'base_url' => 'https://api.fireworks.ai/inference/v1',
            'requires_api_key' => true, 'embedding_model' => 'nomic-ai/nomic-embed-text-v1.5',
        ],
        'mistral' => [
            'name' => 'Mistral AI', 'driver' => 'mistral', 'base_url' => 'https://api.mistral.ai/v1',
            'requires_api_key' => true, 'embedding_model' => 'mistral-embed',
        ],
        'deepseek' => [
            'name' => 'DeepSeek', 'driver' => 'deepseek', 'base_url' => 'https://api.deepseek.com',
            'requires_api_key' => true, 'embedding_model' => null,
        ],
        'xai' => [
            'name' => 'xAI', 'driver' => 'xai', 'base_url' => 'https://api.x.ai/v1',
            'requires_api_key' => true, 'embedding_model' => null,
        ],
        'digitalocean' => [
            'name' => 'DigitalOcean Serverless Inference', 'driver' => 'openai-compatible', 'base_url' => 'https://inference.do-ai.run/v1',
            'requires_api_key' => true, 'embedding_model' => 'qwen3-embedding-0.6b',
        ],
        'perplexity' => [
            'name' => 'Perplexity', 'driver' => 'openai-compatible', 'base_url' => 'https://api.perplexity.ai',
            'requires_api_key' => true, 'embedding_model' => null,
        ],
        'ollama' => [
            'name' => 'Ollama', 'driver' => 'openai-compatible', 'base_url' => 'http://localhost:11434/v1',
            'requires_api_key' => false, 'embedding_model' => 'nomic-embed-text',
        ],
        'lmstudio' => [
            'name' => 'LM Studio', 'driver' => 'openai-compatible', 'base_url' => 'http://localhost:1234/v1',
            'requires_api_key' => false, 'embedding_model' => 'text-embedding-nomic-embed-text-v1.5',
        ],
        'custom' => [
            'name' => 'Custom OpenAI-compatible', 'driver' => 'openai-compatible', 'base_url' => 'https://api.openai.com/v1',
            'requires_api_key' => false, 'embedding_model' => 'text-embedding-3-small',
        ],
    ];

    public static function names()
    {
        return array_map(function ($preset) {
            return $preset['name'];
        }, self::PRESETS);
    }

    public static function normalize($provider, $default = 'openai')
    {
        $provider = strtolower(trim((string) $provider));

        return isset(self::PRESETS[$provider]) ? $provider : $default;
    }

    public static function supportsEmbeddings($provider)
    {
        return !empty(self::PRESETS[self::normalize($provider)]['embedding_model']);
    }

    /**
     * Put the configured providers into laravel/ai's configuration.
     */
    public static function configure()
    {
        // Each provider set up; the agents name the provider and model to use (TallportAgent::prompt()).
        foreach (Settings::providers() as $id => $provider) {
            config(['ai.providers.'.self::textName($id) => self::providerConfig($provider['provider'], \Helper::decrypt($provider['api_key']), $provider['base_url'], [
                'text' => ['default' => Settings::DEFAULT_MODEL],
            ])]);
            Ai::forgetInstance(self::textName($id));
        }
        // The first, for anything that names no provider.
        if ($first = array_key_first(Settings::providers())) {
            config(['ai.providers.'.self::TEXT => config('ai.providers.'.self::textName($first))]);
        }

        $embeddings = Settings::embeddingProvider();
        config(['ai.providers.'.self::EMBEDDINGS => self::providerConfig($embeddings, Settings::embeddingApiKey(), Settings::embeddingBaseUrl(), [
            'embeddings' => ['default' => Settings::embeddingModel()],
        ])]);

        config(['ai.default' => self::TEXT, 'ai.default_for_embeddings' => self::EMBEDDINGS]);

        Ai::forgetInstance(self::TEXT);
        Ai::forgetInstance(self::EMBEDDINGS);
    }

    /**
     * A provider set up, as people tell it apart: its name, and its address when it isn't the usual.
     */
    public static function label(array $provider)
    {
        $host = $provider['base_url'] ? parse_url($provider['base_url'], PHP_URL_HOST) : '';

        return self::PRESETS[$provider['provider']]['name'].($host ? ' · '.$host : '');
    }

    /**
     * laravel/ai's name for a provider set up in the settings.
     */
    public static function textName($id)
    {
        return self::TEXT.'-'.$id;
    }

    /**
     * The id of a provider set up in the settings, from laravel/ai's name for it (textName()).
     */
    public static function idFromName($name)
    {
        return str_starts_with((string) $name, self::TEXT.'-') ? substr($name, strlen(self::TEXT) + 1) : null;
    }

    /**
     * Request options that keep a model's reasoning to the least it allows ("fast mode", for
     * simple work such as translations: TallportAgent::fast()), by provider (PRESETS) and model;
     * [] where a model has none. A model fails a request with an option it doesn't take, so only
     * models known to take it get it (and one that refuses it anyway is called without it from
     * then on: fastRejected()). Never a paid priority tier (OpenAI service_tier, Anthropic speed).
     */
    public static function fastOptions($provider, $model)
    {
        $model = strtolower(trim((string) $model));
        switch ($provider) {
            // OpenAI (Responses API): reasoning.effort, the least each reasoning model takes.
            case 'openai':
                $effort = self::openAiEffort($model);

                return $effort ? ['reasoning' => ['effort' => $effort]] : [];
            // Gemini (Interactions API): generation_config.thinking_level; "minimal" where a model
            // has it, else "low" (Gemini 3 Pro, 3.7/3.8 Flash, 2.5 Pro and Flash). Thinking can't be off.
            case 'gemini':
                $level = self::geminiThinkingLevel($model);

                return $level ? ['generation_config' => ['thinking_level' => $level]] : [];
            // xAI (Responses API): reasoning.effort on the Grok models that take it; others refuse it.
            case 'xai':
                $effort = self::xaiEffort($model);

                return $effort ? ['reasoning' => ['effort' => $effort]] : [];
            // Groq: reasoning_effort; Qwen 3 can skip reasoning, GPT-OSS reasons least at "low".
            case 'groq':
                if (str_contains($model, 'qwen3') || str_contains($model, 'qwen-3')) {
                    return ['reasoning_effort' => 'none'];
                }

                return str_contains($model, 'gpt-oss') && !str_contains($model, 'safeguard') ? ['reasoning_effort' => 'low'] : [];
            // Mistral: reasoning_effort "none" on the models with adjustable reasoning (Medium 3.5, Small 4).
            case 'mistral':
                return preg_match('/^mistral-(medium-3[.-]5|small-4|medium-latest|small-latest)/', $model) ? ['reasoning_effort' => 'none'] : [];
            // DeepSeek V4 thinks unless told not to; deepseek-chat doesn't think, deepseek-reasoner always does.
            case 'deepseek':
                return str_starts_with($model, 'deepseek-v4') ? ['thinking' => ['type' => 'disabled']] : [];
            // OpenRouter: its reasoning.effort, passed on to the model's provider: as for OpenAI, Gemini
            // and xAI models (others, e.g. Claude, would start reasoning when given an effort).
            case 'openrouter':
                [$vendor, $name] = array_pad(explode('/', $model, 2), 2, '');
                $effort = match ($vendor) {
                    'openai' => self::openAiEffort($name),
                    'google' => self::geminiThinkingLevel($name),
                    'x-ai'   => self::xaiEffort($name),
                    default  => null,
                };

                return $effort ? ['reasoning' => ['effort' => $effort]] : [];
            // Anthropic: extended thinking is off unless asked for. OpenAI-compatible servers
            // (Together, Fireworks, Ollama, ...): too varied to send anything.
            default:
                return [];
        }
    }

    /**
     * GPT-5: "minimal"; GPT-5.1 and later, GPT-6: "none"; o3 and o4-mini: "low". Chat, Codex and
     * Pro models take none of these, non-reasoning models (GPT-4.1, GPT-4o) no effort at all.
     */
    protected static function openAiEffort($model)
    {
        if (preg_match('/-(chat|codex|pro|search|audio|realtime|deep-research)\b/', $model)) {
            return null;
        }
        if (preg_match('/^gpt-5(-mini|-nano)?(-\d{4}-\d{2}-\d{2})?$/', $model)) {
            return 'minimal';
        }
        if (preg_match('/^gpt-(5\.\d+|6)/', $model)) {
            return 'none';
        }

        return preg_match('/^(o3|o4-mini)(-mini)?(-\d{4}-\d{2}-\d{2})?$/', $model) ? 'low' : null;
    }

    protected static function geminiThinkingLevel($model)
    {
        if (!str_starts_with($model, 'gemini-') || preg_match('/image|tts|embedding|audio|live/', $model)) {
            return null;
        }

        return preg_match('/^gemini-(2\.5-flash-lite|3-flash|3\.5-flash|3\.6-flash)/', $model) ? 'minimal' : 'low';
    }

    /**
     * Grok 4.3: "none"; Grok 4.5 and later: "low" (their least); Grok 3 Mini: "low". Other Grok
     * models (Grok 4, 4.1 Fast, 4.20, Code) refuse the parameter.
     */
    protected static function xaiEffort($model)
    {
        if (str_contains($model, 'multi-agent') || str_contains($model, 'non-reasoning')) {
            return null;
        }
        if (preg_match('/^grok-4\.3(\D|$)/', $model)) {
            return 'none';
        }

        return preg_match('/^grok-(4\.[5-9](\D|$)|[5-9](\D|$)|3-mini)/', $model) ? 'low' : null;
    }

    /**
     * A model refused the fast options (HTTP 400/422): it's called without them for a while.
     */
    const FAST_REJECTED_DAYS = 30;

    public static function fastRejected($name, $model)
    {
        return (bool) \Cache::get(self::fastRejectedKey($name, $model));
    }

    public static function rememberFastRejected($name, $model)
    {
        \Cache::put(self::fastRejectedKey($name, $model), true, now()->addDays(self::FAST_REJECTED_DAYS));
    }

    protected static function fastRejectedKey($name, $model)
    {
        return 'ai_fast_rejected_'.md5($name.'|'.$model);
    }

    protected static function providerConfig($provider, $key, $base_url, array $models)
    {
        $preset = self::PRESETS[$provider];
        $config = [
            'driver' => $preset['driver'],
            'key'    => $key ?: ($preset['requires_api_key'] ? null : 'none'),
            'models' => $models,
        ];
        // Native drivers know their URL; OpenAI-compatible ones need one.
        if ($base_url || $preset['driver'] == 'openai-compatible') {
            $config['url'] = rtrim($base_url ?: $preset['base_url'], '/');
        }
        // Streamed answers' tokens (the usage and budgets), which these servers send when asked.
        if ($preset['driver'] == 'openai-compatible') {
            $config['stream_options'] = ['include_usage' => true];
        }

        return $config;
    }
}
