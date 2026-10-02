<?php

namespace Tests\Unit;

use App\Incoming\ParserComparison;
use Tests\Concerns\AssertsSnapshots;
use Tests\TestCase;

/**
 * Where webklex/php-imap 6 reads the test emails differently from
 * FreeScout's patched 4.1.1 (App\LegacyImap): each field shows which of
 * FreeScout's changes still make a difference. Untangling App\LegacyImap
 * should make this list shorter; what remains should be differences where
 * the new code is intentionally better (see HeaderTextTest, AddressListTest).
 */
class ParserDifferencesTest extends TestCase
{
    use AssertsSnapshots;

    public function testDifferences()
    {
        $files = array_merge(glob(__DIR__.'/../Messages/*.eml'), glob(__DIR__.'/../Messages/webklex/*.eml'));
        sort($files);

        $differences = [];
        foreach ($files as $file) {
            $name = (str_contains($file, '/webklex/') ? 'webklex/' : '').basename($file, '.eml');
            foreach (ParserComparison::compareRaw(file_get_contents($file)) as $field) {
                $differences[$field][] = $name;
            }
        }
        ksort($differences);

        $this->assertMatchesSnapshot('parser_differences', $differences);
    }

    /**
     * tallport:compare-parsers runs the comparison on the kept raw sources
     * and prints field names and thread IDs only.
     */
    public function testCompareParsersCommand()
    {
        $storage = sys_get_temp_dir().'/tallport-compare-'.uniqid();
        mkdir($storage.'/app/incoming-mail', 0777, true);
        copy(__DIR__.'/../Messages/webklex/plain.eml', $storage.'/app/incoming-mail/101.eml');
        copy(__DIR__.'/../Messages/message-5-iso-2022-jp.eml', $storage.'/app/incoming-mail/102.eml');
        $this->app->useStoragePath($storage);

        try {
            $this->artisan('tallport:compare-parsers')
                ->expectsOutput('Emails compared: 2')
                ->expectsOutputToContain('(threads 102)')
                ->assertExitCode(0);
        } finally {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($storage);
        }
    }
}
