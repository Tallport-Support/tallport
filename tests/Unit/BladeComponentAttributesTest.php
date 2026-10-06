<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Blade directives aren't compiled inside a component tag's attributes: @js()
 * in <x-fruit::button x-on:click="…@js(…)…"> reaches the browser as is, and the
 * click handler is broken JavaScript. Use a plain element there, or a bound
 * attribute (:data-label="…") the handler reads.
 */
class BladeComponentAttributesTest extends TestCase
{
    public function testNoDirectivesInComponentTags()
    {
        $found = [];
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views'));
        foreach ($views as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            preg_match_all('/<x-[\w.:-]+(?:[^>"]|"[^"]*")*>/', $source, $tags, PREG_OFFSET_CAPTURE);
            foreach ($tags[0] as [$tag, $offset]) {
                if (str_contains($tag, '@js(')) {
                    $found[] = $file->getFilename().':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $found);
    }
}
