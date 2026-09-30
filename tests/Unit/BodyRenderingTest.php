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

    public function testNonAsciiTextSurvives()
    {
        $html = $this->render('unicode');

        foreach (['Grüße', 'München', '日本語のテキスト', '🎉', 'Здравствуйте'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }
}
