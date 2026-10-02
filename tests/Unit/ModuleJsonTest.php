<?php

namespace Tests\Unit;

use App\Modules\Json;
use Tests\TestCase;

/**
 * A module's module.json (App\Modules\Json): read once, contents can be
 * passed in, and set() values (the "active" flag from the database) stay.
 */
class ModuleJsonTest extends TestCase
{
    protected $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/tallport-module-'.uniqid().'.json';
        file_put_contents($this->file, json_encode(['name' => 'Example', 'alias' => 'example', 'active' => 1]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testReadsTheFile()
    {
        $json = new Json($this->file);

        $this->assertSame('Example', $json->get('name'));
    }

    public function testSetValuesStay()
    {
        $json = Json::make($this->file);
        $json->set('active', 0);

        $this->assertSame(0, $json->get('active'));
        $this->assertSame(0, $json->getAttributes()['active'], 'Not read from the file again.');
    }

    public function testContentsCanBePassedIn()
    {
        unlink($this->file);

        $json = new Json($this->file, null, ['name' => 'From the manifest']);

        $this->assertSame('From the manifest', $json->get('name'));
    }
}
