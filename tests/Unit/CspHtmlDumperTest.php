<?php

namespace Tests\Unit;

use App\Misc\CspHtmlDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Tests\TestCase;

class CspHtmlDumperTest extends TestCase
{
    public function testInlineScriptsCarryTheCspNonce()
    {
        $dumper = new CspHtmlDumper(base_path(), config('view.compiled'));
        $html = $dumper->dump((new VarCloner())->cloneVar(['a' => 1]), true);

        $this->assertGreaterThanOrEqual(2, substr_count($html, '<script nonce="'.\Helper::cspNonce().'">'));
        $this->assertStringNotContainsString('<script>', $html);
    }
}
