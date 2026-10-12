<?php

namespace Tests\Feature;

use Tests\FeatureTestCase;

/**
 * Error pages use FruitUI's empty state, with its colors in light and dark.
 */
class ErrorPagesTest extends FeatureTestCase
{
    public function testErrorPagesUseFruitUi()
    {
        $this->get('/no-such-page')->assertStatus(404)
            ->assertSee('vendor/fruitui/core.compat.css', false)->assertSee('class="f-empty-state"', false)
            ->assertSee('Sorry, the page you are looking for could not be found.')->assertSee('Homepage');

        // Laravel's own error pages, in the same layout.
        $html = view('errors::419', ['exception' => new \Symfony\Component\HttpKernel\Exception\HttpException(419)])->render();
        $this->assertStringContainsString('class="f-empty-state"', $html);
        $this->assertStringContainsString('Page Expired', $html);
    }
}
