<?php

namespace Tests\Unit;

use App\Misc\Minify;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * The rest of the \Minify facade that FreeScout modules may use (as with
 * devfactory/minify): whole folders, full URLs; and a build that can't be
 * written.
 */
class MinifyDirectoriesTest extends TestCase
{
    protected $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public = sys_get_temp_dir().'/tallport-public-'.uniqid();
        mkdir($this->public.'/module/js/sub', 0777, true);
        file_put_contents($this->public.'/module/js/a.js', "var a = 1;\n");
        file_put_contents($this->public.'/module/js/sub/b.js', "var b = 2;\n");
        file_put_contents($this->public.'/module/js/notes.txt', "not a script\n");
        file_put_contents($this->public.'/module/a.css', "p { margin: 0; }\n");
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        @chmod($this->public.'/js/builds', 0755);
        (new Filesystem())->deleteDirectory($this->public);
        parent::tearDown();
    }

    protected function minify($environment = 'production', array $config = [])
    {
        return new Minify(array_merge([
            'css_build_path'      => '/css/builds/',
            'js_build_path'       => '/js/builds/',
            'ignore_environments' => ['local', 'testing'],
        ], $config), $environment);
    }

    /**
     * The scripts or stylesheets of a folder and its subfolders, sorted (or in reverse).
     */
    public function testFolders()
    {
        $this->assertSame(
            '<script src="/module/js/a.js"></script>'.PHP_EOL.'<script src="/module/js/sub/b.js"></script>'.PHP_EOL,
            (string) $this->minify('testing')->javascriptDir('/module/js')
        );
        $this->assertSame(
            '<script src="/module/js/sub/b.js"></script>'.PHP_EOL.'<script src="/module/js/a.js"></script>'.PHP_EOL,
            (string) $this->minify('testing', ['reverse_sort' => true])->javascriptDir('/module/js')
        );
        $this->assertSame('<link href="/module/a.css" rel="stylesheet">'.PHP_EOL, (string) $this->minify('testing')->stylesheetDir('/module'));

        $build = file_get_contents($this->public.$this->minify()->javascriptDir('/module/js')->onlyUrl());
        $this->assertSame("var a=1;;\nvar b=2;;\n", $build);
    }

    public function testFullUrl()
    {
        $this->assertSame(
            '<script src="'.request()->root().'/module/js/a.js"></script>'.PHP_EOL,
            (string) $this->minify('testing')->javascript('/module/js/a.js')->withFullUrl()
        );
        $this->assertMatchesRegularExpression(
            '#^'.preg_quote(request()->root()).'/js/builds/[0-9a-f]+\.js$#',
            (string) $this->minify()->javascript('/module/js/a.js')->withFullUrl()->onlyUrl()
        );
    }

    public function testBuildFolderThatCannotBeMade()
    {
        file_put_contents($this->public.'/js', 'a file, not a folder');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Buildpath '".$this->public."/js/builds/' does not exist");

        (string) $this->minify()->javascript('/module/js/a.js')->onlyUrl();
    }

    public function testBuildThatCannotBeSaved()
    {
        mkdir($this->public.'/js');
        mkdir($this->public.'/js/builds', 0555);
        if (is_writable($this->public.'/js/builds')) {
            $this->markTestSkipped('Running as a user who can write anywhere.');
        }

        // Laravel turns file_put_contents()'s warning into an ErrorException.
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Permission denied');

        $this->minify()->javascript('/module/js/a.js')->url();
    }
}
