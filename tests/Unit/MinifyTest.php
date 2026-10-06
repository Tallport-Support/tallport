<?php

namespace Tests\Unit;

use App\Misc\Minify;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * \Minify (App\Misc\Minify): JS and CSS files joined into minified build
 * files, or linked one by one where minifying is off (local, tests).
 */
class MinifyTest extends TestCase
{
    protected $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public = sys_get_temp_dir().'/tallport-public-'.uniqid();
        mkdir($this->public.'/css', 0777, true);
        mkdir($this->public.'/js', 0777, true);
        file_put_contents($this->public.'/css/a.css', ":root {\n    --brand: #123456;\n}\n/* comment */\nbody {\n    color: var(--brand);\n    background: url(../img/bg.png);\n}\n");
        file_put_contents($this->public.'/css/b.css', "p { margin: 0px 0px; }\n");
        file_put_contents($this->public.'/js/a.js', "function hello(name) {\n    // greet\n    return 'Hello ' + name;\n}\n");
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->public);
        parent::tearDown();
    }

    protected function minify($environment = 'production')
    {
        return new Minify([
            'css_build_path'      => '/css/builds/',
            'js_build_path'       => '/js/builds/',
            'ignore_environments' => ['local', 'testing'],
        ], $environment);
    }

    /**
     * JShrink mangles a template literal inside another's ${…}: already-minified vendor
     * builds (FruitUI's) are joined as they are, ours are minified.
     */
    public function testVendorBuildsAreJoinedAsTheyAre()
    {
        mkdir($this->public.'/vendor/lib', 0777, true);
        $vendor = 'var c=`f-dialog f-dialog--scroll${t===`large`?` f-dialog--large`:``}`;';
        file_put_contents($this->public.'/vendor/lib/lib.global.js', $vendor);

        $html = (string) $this->minify()->javascript(['/vendor/lib/lib.global.js', '/js/a.js']);
        preg_match('#/js/builds/([0-9a-f]+\.js)#', $html, $m);
        $js = file_get_contents($this->public.'/js/builds/'.$m[1]);

        $this->assertStringContainsString($vendor, $js);
        $this->assertStringNotContainsString('// greet', $js);
    }

    public function testFilesOneByOneWhereMinifyingIsOff()
    {
        $this->assertSame(
            '<link href="/css/a.css" rel="stylesheet">'.PHP_EOL.'<link href="/css/b.css" rel="stylesheet">'.PHP_EOL,
            (string) $this->minify('testing')->stylesheet(['/css/a.css', '/css/b.css'])
        );
        $this->assertSame('<script src="/js/a.js" defer="defer"></script>'.PHP_EOL, (string) $this->minify('local')->javascript('/js/a.js', ['defer']));
    }

    /**
     * The build's URL, as the page links it (a conversation opened in place compares it with
     * the page's); none where minifying is off.
     */
    public function testBuildUrl()
    {
        $styles = $this->minify()->stylesheet(['/css/a.css', '/css/b.css']);
        $this->assertStringContainsString('href="'.$styles->url().'"', (string) $styles);
        $this->assertNull($this->minify('testing')->stylesheet(['/css/a.css'])->url());
    }

    public function testStylesheetBuild()
    {
        $html = (string) $this->minify()->stylesheet(['/css/a.css', '/css/b.css']);

        $this->assertMatchesRegularExpression('#^<link href="/css/builds/([0-9a-f]+\.css)" rel="stylesheet">#', $html);
        preg_match('#/css/builds/([0-9a-f]+\.css)#', $html, $m);
        $css = file_get_contents($this->public.'/css/builds/'.$m[1]);
        $this->assertStringNotContainsString('comment', $css);
        $this->assertStringContainsString('--brand:#123456', $css, 'CSS custom properties are kept.');
        $this->assertStringContainsString('var(--brand)', $css);
        $this->assertStringContainsString('url(../img/bg.png)', $css, 'URLs are not rewritten.');
        $this->assertStringContainsString('p{margin:0', $css);
    }

    public function testCssFunctionsAreKept()
    {
        file_put_contents($this->public.'/css/b.css', ".sidebar { width: clamp(280px, 25vw, var(--sidebar-width)); }\n.a { width: calc(100% - 10px); }\n");

        $css = file_get_contents($this->public.$this->minify()->stylesheet('/css/b.css')->onlyUrl());

        $this->assertSame('.sidebar{width:clamp(280px, 25vw, var(--sidebar-width))}.a{width:calc(100% - 10px)}', $css);
    }

    public function testJavascriptBuildIsRemadeWhenAFileChanges()
    {
        $url = (string) $this->minify()->javascript('/js/a.js')->onlyUrl();
        $this->assertStringStartsWith('/js/builds/', $url);
        $first = $this->public.$url;
        $this->assertStringContainsString("return'Hello '+name", file_get_contents($first));
        $this->assertSame($url, (string) $this->minify()->javascript('/js/a.js')->onlyUrl(), 'Built once.');

        file_put_contents($this->public.'/js/a.js', "var changed = 1;\n");
        touch($this->public.'/js/a.js', time() + 10);
        clearstatcache();
        $second = $this->public.$this->minify()->javascript('/js/a.js')->onlyUrl();

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($first, 'The old build is removed.');
        $this->assertStringContainsString('var changed=1', file_get_contents($second));
    }

    public function testMissingFile()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->minify()->javascript('/js/missing.js');
    }

    public function testFacade()
    {
        $this->assertInstanceOf(Minify::class, \Minify::getFacadeRoot());
        $this->assertStringContainsString('<script src="/js/a.js">', (string) \Minify::javascript('/js/a.js'));
    }
}
