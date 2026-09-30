<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\ProviderRepository;
use Tests\TestCase;

/**
 * After an update removes a package, the cached provider lists in
 * bootstrap/cache still name its service provider. Those are skipped (and
 * logged) instead of stopping the app (patched ProviderRepository).
 */
class StaleProviderCacheTest extends TestCase
{
    protected $manifest_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifest_path = tempnam(sys_get_temp_dir(), 'tallport-services');
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest_path);
        parent::tearDown();
    }

    protected function repository()
    {
        return new ProviderRepository($this->app, new Filesystem(), $this->manifest_path);
    }

    public function testRemovedProviderInCachedManifestIsSkipped()
    {
        $gone = 'Fideloper\Proxy\TrustedProxyServiceProvider';
        file_put_contents($this->manifest_path, '<?php return '.var_export([
            'providers' => [$gone],
            'eager'     => [$gone],
            'deferred'  => [],
            'when'      => [],
        ], true).';');

        $this->repository()->load([$gone]);

        $this->assertFalse(class_exists($gone, false));
    }

    public function testRemovedProviderIsSkippedWhenCompiling()
    {
        @unlink($this->manifest_path);

        $this->repository()->load(['Gone\Package\GoneServiceProvider']);

        $manifest = include $this->manifest_path;
        $this->assertSame([], $manifest['eager']);
    }
}
