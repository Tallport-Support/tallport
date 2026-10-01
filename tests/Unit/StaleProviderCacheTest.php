<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * After an update removes a package, cached config and package lists in
 * bootstrap/cache still name its service provider. Those are skipped (and
 * logged) instead of stopping the app (App\Foundation\Application).
 */
class StaleProviderCacheTest extends TestCase
{
    public function testAppUsesTallportApplication()
    {
        $this->assertInstanceOf(\App\Foundation\Application::class, $this->app);
    }

    public function testRemovedProviderIsSkipped()
    {
        $gone = 'Fideloper\Proxy\TrustedProxyServiceProvider';
        config(['app.providers' => array_merge(config('app.providers'), [$gone])]);
        Log::shouldReceive('error')->once()->withArgs(function ($message) use ($gone) {
            return str_contains($message, $gone);
        });

        $providers = $this->app->providersToRegister();

        $this->assertNotContains($gone, $providers);
        $this->assertContains(\App\Providers\AppServiceProvider::class, $providers);
    }

    public function testExistingProvidersAreAllKept()
    {
        $this->assertContains(\Illuminate\Mail\MailServiceProvider::class, $this->app->providersToRegister());
        $this->assertContains(\Nwidart\Modules\LaravelModulesServiceProvider::class, $this->app->providersToRegister());
    }
}
