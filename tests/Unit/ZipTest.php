<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * Zip archives: the translations download (Helper::createZipArchive) and
 * module installation (Helper::unzip), which must never write outside the
 * Modules folder.
 */
class ZipTest extends TestCase
{
    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tallport-zip-'.uniqid();
        mkdir($this->dir.'/out', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->dir);
        (new Filesystem())->delete(storage_path('app/tallport-test/test.zip'));
        @rmdir(storage_path('app/tallport-test'));
        parent::tearDown();
    }

    protected function makeZip(array $entries)
    {
        $path = $this->dir.'/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $contents === null ? $zip->addEmptyDir($name) : $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * The layout chumper/zipper produced: the folder's contents under $folder,
     * without hidden files.
     */
    public function testCreateZipArchiveLayout()
    {
        $lang = $this->dir.'/lang';
        mkdir($lang.'/en', 0777, true);
        mkdir($lang.'/nl/sub', 0777, true);
        mkdir($lang.'/.hidden');
        file_put_contents($lang.'/en/messages.php', 'a');
        file_put_contents($lang.'/nl/sub/x.php', 'b');
        file_put_contents($lang.'/nl.json', 'c');
        file_put_contents($lang.'/.hidden/h', 'd');
        file_put_contents($lang.'/.dotfile', 'e');

        $path = \Helper::createZipArchive($lang, 'test.zip', 'lang', 'tallport-test/test.zip');

        $zip = new \ZipArchive();
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['lang/en/messages.php', 'lang/nl.json', 'lang/nl/sub/x.php'], $names);
        $this->assertSame('b', $zip->getFromName('lang/nl/sub/x.php'));
    }

    public function testUnzipModule()
    {
        \Helper::unzip($this->makeZip([
            'TpModule/' => null,
            'TpModule/module.json' => '{}',
            'TpModule/Http/routes.php' => '<?php',
        ]), $this->dir.'/out');

        $this->assertSame('{}', file_get_contents($this->dir.'/out/TpModule/module.json'));
        $this->assertFileExists($this->dir.'/out/TpModule/Http/routes.php');
    }

    public static function unsafeNames()
    {
        return [
            'parent folder' => ['../evil.php'],
            'nested parent' => ['TpModule/../../evil.php'],
            'absolute'      => ['/tmp/evil.php'],
            'drive letter'  => ['C:/evil.php'],
        ];
    }

    /**
     * @dataProvider unsafeNames
     */
    public function testUnsafeNamesAreRefused($name)
    {
        $archive = $this->makeZip([$name => 'x']);

        try {
            \Helper::unzip($archive, $this->dir.'/out');
            $this->fail('Unsafe entry extracted: '.$name);
        } catch (\Exception $e) {
            $this->assertStringContainsString('Unsafe file name', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/evil.php');
    }

    public function testSymlinkedFolderIsNotFollowed()
    {
        mkdir($this->dir.'/elsewhere');
        symlink($this->dir.'/elsewhere', $this->dir.'/out/TpModule');

        try {
            \Helper::unzip($this->makeZip(['TpModule/evil.php' => 'x']), $this->dir.'/out');
            $this->fail('Extracted through a symlink.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('outside', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/elsewhere/evil.php');
    }
}
