<?php

namespace Tests\Unit;

use Nwidart\Modules\Support\Stub;
use Tests\TestCase;

/**
 * module:make uses FreeScout's stubs where it changed them, nwidart's own
 * for the rest.
 */
class ModuleStubsTest extends TestCase
{
    public function testStubPaths()
    {
        $this->assertSame(resource_path('stubs/modules/json.stub'), realpath((new Stub('/json.stub'))->getPath()));
        $this->assertSame(resource_path('stubs/modules/scaffold/provider.stub'), realpath((new Stub('/scaffold/provider.stub'))->getPath()));
        $this->assertSame(base_path('vendor/nwidart/laravel-modules/src/Commands/stubs/command.stub'), realpath((new Stub('/command.stub'))->getPath()));
    }
}
