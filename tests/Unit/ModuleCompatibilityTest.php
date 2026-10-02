<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Input;
use Tests\TestCase;

/**
 * Laravel 5.5 APIs that later versions removed but FreeScout modules use.
 */
class ModuleCompatibilityTest extends TestCase
{
    public function testInputFacade()
    {
        $this->app->instance('request', Request::create('/x', 'GET', ['folder_id' => '7']));

        $this->assertSame('7', Input::get('folder_id'));
        $this->assertSame('none', Input::get('missing', 'none'));
    }

    public function testEventFire()
    {
        $received = null;
        Event::listen('tallport.compat', function ($value) use (&$received) {
            $received = $value;
        });

        Event::fire('tallport.compat', ['fired']);
        $this->app['events']->fire('tallport.compat', ['fired again']);

        $this->assertSame('fired again', $received);
    }

    public function testMacroableModels()
    {
        \MacroableModels::addMacro(\App\Customer::class, 'tallportCompat', function () {
            return 'macro';
        });

        try {
            $this->assertSame('macro', (new \App\Customer())->tallportCompat());
        } finally {
            \MacroableModels::removeMacro(\App\Customer::class, 'tallportCompat');
        }
    }

    public function testMailFailures()
    {
        $this->assertSame([], \Mail::failures());
        $this->assertSame([], $this->app['mail.manager']->mailer()->failures());
    }

    public function testModelDatesSerializeInDatabaseFormat()
    {
        $customer = new \App\Customer();
        $customer->created_at = \Carbon\Carbon::create(2026, 3, 5, 14, 7, 9);

        $this->assertSame('2026-03-05 14:07:09', $customer->toArray()['created_at']);
        $this->assertStringContainsString('"created_at":"2026-03-05 14:07:09"', $customer->toJson());
    }

    /**
     * Laravel 10 dropped $dates; FreeScout and modules (AiAssistant's
     * Document::$last_indexed_at, ...) call Carbon methods on them.
     */
    public function testDatesPropertyStillMakesDates()
    {
        $conversation = new \App\Conversation();
        $conversation->setRawAttributes(['last_reply_at' => '2026-03-05 14:07:09', 'closed_at' => null]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $conversation->last_reply_at);
        $this->assertSame('2026-03-05 14:07:09', $conversation->last_reply_at->toDateTimeString());
        $this->assertNull($conversation->closed_at);
        $this->assertSame('2026-03-05 14:07:09', $conversation->toArray()['last_reply_at']);

        $module_model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $dates = ['last_indexed_at'];
        };
        $module_model->last_indexed_at = \Carbon\Carbon::create(2026, 3, 5, 14, 7, 9);
        $this->assertSame('2026-03-05 14:07:09', $module_model->getAttributes()['last_indexed_at'], 'Stored in the database format.');
        $this->assertSame('2026-03-05 14:07:09', $module_model->last_indexed_at->toDateTimeString());
    }

    public function testModelsWithoutReturnTypesLoad()
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../Support/module-compat/old-style-model.php').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame('{"serialized":true} value', end($output));
    }

    public function testOldHelpers()
    {
        $this->assertSame('b', array_get(['a' => 'b'], 'a'));
        $this->assertSame('hello_world', snake_case('helloWorld'));
        $this->assertSame(16, strlen(str_random(16)));
    }
}
