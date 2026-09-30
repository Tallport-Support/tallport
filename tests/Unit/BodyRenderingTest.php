<?php

namespace Tests\Unit;

use App\Thread;
use Tests\Concerns\AssertsSnapshots;
use Tests\TestCase;

/**
 * How message bodies are cleaned and rendered in the conversation view
 * (Thread::getCleanBody() and getBodyWithFormatedLinks(), i.e. HTML
 * Purifier plus linkify). Golden masters: each fixture in
 * tests/Fixtures/bodies/ has its rendered output in
 * tests/Snapshots/body_rendering/, so changes to purifier settings or
 * rendering show up as HTML diffs.
 */
class BodyRenderingTest extends TestCase
{
    use AssertsSnapshots;

    public function fixtures()
    {
        $fixtures = [];
        // Data providers run before the application exists: no base_path().
        foreach (glob(__DIR__.'/../Fixtures/bodies/*.html') as $file) {
            $fixtures[basename($file, '.html')] = [basename($file, '.html')];
        }

        return $fixtures;
    }

    protected function render($fixture)
    {
        $thread = new Thread();
        $thread->body = file_get_contents(base_path('tests/Fixtures/bodies/'.$fixture.'.html'));

        return $thread->getBodyWithFormatedLinks();
    }

    /**
     * @dataProvider fixtures
     */
    public function testRenderedBody($fixture)
    {
        $this->assertMatchesTextSnapshot('body_rendering/'.$fixture.'.html', $this->render($fixture));
    }

    public function testHostileHtmlIsNeutralised()
    {
        $html = strtolower($this->render('hostile'));

        foreach (['<script', 'onclick', 'onerror', 'javascript:', '<iframe', '<form', '<style', '<object', '<svg'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
        $this->assertStringContainsString('click', $html, 'Harmless content is kept.');
    }

    public function testLinksOpenInNewWindow()
    {
        $html = $this->render('links');

        $this->assertStringContainsString('href="https://example.org/docs?page=2&amp;q=a"', $html);
        $this->assertStringContainsString('href="mailto:support@example.org"', $html);
        $this->assertMatchesRegularExpression('#<a[^>]+target="_blank"[^>]+href="https://example.org"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a[^>]+target="_blank"[^>]+href="\#section"#', $html, 'Anchors stay in the page.');
        $this->assertStringNotContainsString('&gt;"', $html, 'A trailing > is not part of the URL (issue 5423).');
    }

    public function testOutlookMarkupIsCleanedUp()
    {
        $html = $this->render('outlook');

        $this->assertStringNotContainsString('MSO only', $html);
        $this->assertStringContainsString('Not MSO', $html);
        $this->assertStringContainsString('Collapsed quote', $html, '"collapse" would hide it.');
        $this->assertStringNotContainsString('class="collapse"', $html);
        $this->assertStringContainsString('background-color:', $html);
    }

    /**
     * AutoParagraph made purifying super-linear: a 1 MB pasted log took ~4 s
     * on every view (E1). Large bodies skip it.
     */
    public function testLargeBodyRendersQuickly()
    {
        $lines = [];
        for ($i = 0; $i < 8000; $i++) {
            $lines[] = '2026-09-29 12:00:'.sprintf('%02d', $i % 60).' [error] worker#'.$i.' GET https://api.example.com/v1/items/'.$i.'?x=1 failed trace=0x'.dechex($i * 7919);
        }
        $thread = new Thread();
        $thread->body = '<div>'.implode('<br>', $lines).'</div>';
        $this->assertGreaterThan(800000, strlen($thread->body));

        $start = microtime(true);
        $html = $thread->getBodyWithFormatedLinks();
        $seconds = microtime(true) - $start;

        $this->assertLessThan(1.5, $seconds, 'Rendering a 1 MB body took '.round($seconds, 2).' s.');
        $this->assertStringContainsString('worker#7999', $html);
    }

    /**
     * Customer emails escape non-ASCII characters; the conversation view
     * doesn't. Each call must get what it asked for (E2).
     */
    public function testNonAsciiEscapingPerCall()
    {
        $thread = new Thread();
        $thread->body = '<p>Grüße</p>';

        $this->assertStringContainsString('Grüße', $thread->getCleanBody());
        $this->assertStringContainsString('Gr&#252;&#223;e', $thread->getCleanBody('', true));
        $this->assertStringContainsString('Grüße', $thread->getCleanBody(), 'Escaping leaked into later calls.');
    }

    public function testNonAsciiTextSurvives()
    {
        $html = $this->render('unicode');

        foreach (['Grüße', 'München', '日本語のテキスト', '🎉', 'Здравствуйте'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }
}
