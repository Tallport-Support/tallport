<?php

namespace FruitUI;

use FruitUI\Testing\LivewireAssertions;
use FruitUI\View\Components\Field;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportTesting\Testable;

class FruitUIServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $views = __DIR__.'/../../resources/views';

        // Anonymous adapters resolve as fruit::components.*, so published overrides apply too.
        $this->loadViewsFrom($views, 'fruit');
        Blade::component(Field::class, 'fruit::field');

        // Livewire's pagination_theme 'fruit' resolves livewire::fruit and livewire::simple-fruit.
        $this->callAfterResolving('view', fn ($factory) => $factory->addNamespace('livewire', "{$views}/pagination/livewire"));

        $this->publishes([$views => resource_path('views/vendor/fruit')], 'fruit-views');

        // Compiled CSS and scripts for hosts without a bundler: link them from public/vendor/fruitui.
        // laravel-assets is the group Laravel's skeleton re-publishes after every composer update.
        $this->publishes([__DIR__.'/../../build' => public_path('vendor/fruitui')], ['fruit-assets', 'laravel-assets']);

        // English text is the key. Published copies override the shipped ones; the app's own lang/{locale}.json overrides both.
        $lang = __DIR__.'/../../lang';
        $this->loadJsonTranslationsFrom($lang);
        $this->loadJsonTranslationsFrom($this->app->langPath('vendor/fruit'));
        $this->publishes([$lang => $this->app->langPath('vendor/fruit')], 'fruit-lang');

        // Livewire is optional; with it, Livewire::test() gains assertToasted() and the dialog assertions.
        if (class_exists(Testable::class)) {
            LivewireAssertions::register();
        }
    }
}
