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

        return $config;
    }
}
