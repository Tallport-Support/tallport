<?php

namespace App\Foundation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application as BaseApplication;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Foundation\ProviderRepository;
use Illuminate\Support\Collection;

/**
 * Laravel's application with FreeScout's URL generator, surviving stale
 * provider caches after an update.
 */
class Application extends BaseApplication
{
    /**
     * Laravel's base providers, then FreeScout's URL generator in place of
     * Laravel's (App\Routing\UrlGenerator), before anything can resolve it.
     * Laravel's extend('url') setup (session and key resolvers, route
     * rebinding) still applies to it.
     *
     * @return void
     */
    protected function registerBaseServiceProviders()
    {
        parent::registerBaseServiceProviders();

        $this->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            $app->instance('routes', $routes);

            return new \App\Routing\UrlGenerator(
                $routes,
                $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url']
            );
        });
    }

    /**
     * Register the configured providers, skipping ones whose class no longer
     * exists. After an update removes a package, cached config and package
     * lists in bootstrap/cache still name its provider until the cache is
     * cleared; that must not stop the application. A provider that
     * config/app.php itself lists still fails as before.
     *
     * @return void
     */
    public function registerConfiguredProviders()
    {
        (new ProviderRepository($this, new Filesystem, $this->getCachedServicesPath()))
            ->load($this->providersToRegister());

        $this->fireAppCallbacks($this->registeredCallbacks);
    }

    /**
     * The providers to register, as Laravel lists them, without stale ones.
     *
     * @return array
     */
    public function providersToRegister()
    {
        $providers = (new Collection($this->make('config')->get('app.providers')))
            ->partition(fn ($provider) => str_starts_with($provider, 'Illuminate\\'));

        $providers->splice(1, 0, [$this->make(PackageManifest::class)->providers()]);

        $configured = null;

        return $providers->collapse()->filter(function ($provider) use (&$configured) {
            if (class_exists($provider)) {
                return true;
            }
            $configured ??= (include $this->configPath('app.php'))['providers'] ?? [];
            if (in_array($provider, $configured)) {
                return true;
            }
            $this->make('log')->error('Service provider '.$provider.' not found and skipped; clear the cache (php artisan freescout:clear-cache).');

            return false;
        })->values()->toArray();
    }
}
