<?php

namespace Tests\Feature;

use App\Livewire\SystemStatus;
use App\Option;
use App\User;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * What only the highest-ranking administrator changes (the Gate "operate", HOSTING.md): every
 * admin without an operator, only the operator with one.
 */
class OperatorTest extends FeatureTestCase
{
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
    }

    protected function makeOperator()
    {
        $operator = $this->createAdmin(['email' => 'host@example.org']);
        $operator->operator = true;
        $operator->save();

        return $operator;
    }

    protected function postForm($user, $uri, array $data = [])
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    public function testWithoutAnOperatorEveryAdminOperates()
    {
        $this->assertTrue($this->admin->can('operate'));
        $this->assertFalse($this->createUser()->can('operate'));

        // A deleted operator doesn't count.
        $operator = $this->makeOperator();
        $operator->status = User::STATUS_DELETED;
        $operator->save();
        $this->assertTrue($this->admin->fresh()->can('operate'));
    }

    public function testTheOperatorsPagesAreRefusedToOtherAdmins()
    {
        $operator = $this->makeOperator();
        $this->assertFalse($this->admin->can('operate'));
        $this->assertTrue($operator->can('operate'));

        foreach ([route('modules'), route('modules.module', ['alias' => 'none']), route('system'), route('system.tools'), route('logs.app'), route('system.ajax_html', ['action' => 'job_details', 'param' => 1])] as $url) {
            $this->actingAs($this->admin)->get($url)->assertForbidden();
        }
        foreach ([route('modules.ajax'), route('system.ajax'), route('system.action'), route('system.tools.action')] as $url) {
            $this->postForm($this->admin, $url, ['action' => 'clear_cache'])->assertForbidden();
        }
        Livewire::actingAs($this->admin)->test(SystemStatus::class)->call('fetchNow')->assertForbidden();

        $this->actingAs($operator)->get(route('system'))->assertOk();
        $this->actingAs($operator)->get(route('modules'))->assertOk();

        // Not in their sidebar or the Logs list.
        $this->actingAs($this->admin)->get(route('logs'))->assertOk()
            ->assertDontSee(route('modules'))->assertDontSee(route('system'))->assertDontSee(route('logs.app'));
        $this->actingAs($operator)->get(route('logs'))->assertOk()->assertSee(route('modules'))->assertSee(route('logs.app'));
    }

    /**
     * The system's mail and fetching, and "Powered by", are the operator's; the no-reply addresses aren't.
     */
    public function testOperatorsSettings()
    {
        $operator = $this->makeOperator();
        Option::set('mail_host', 'smtp.host.example.org');

        $this->actingAs($this->admin)->get('/app-settings/emails')->assertOk()
            ->assertDontSee('smtp.host.example.org')->assertDontSee('Send Test')->assertSee('No-Reply Addresses');
        $this->postForm($this->admin, '/app-settings/emails', ['settings' => [
            'mail_driver' => 'smtp', 'mail_host' => 'smtp.customer.example.org', 'noreply_emails' => 'robot',
        ]])->assertRedirect();
        Option::$cache = [];
        $this->assertSame('smtp.host.example.org', Option::get('mail_host'));
        $this->assertSame(['robot'], \App\Misc\Noreply::customPatterns());
        $this->postAjax($this->admin, route('settings.ajax'), ['action' => 'send_test', 'to' => 'someone@example.org'])->assertForbidden();

        $this->actingAs($operator)->get('/app-settings/emails')->assertOk()->assertSee('smtp.host.example.org')->assertSee('Send Test');

        Option::set('branding.widget_powered_by', true);
        $this->actingAs($this->admin)->get('/app-settings/branding')->assertOk()->assertDontSee('Powered By');
        $this->postForm($this->admin, '/app-settings/branding', ['settings' => []]);
        Option::$cache = [];
        $this->assertTrue((bool) Option::get('branding.widget_powered_by'));
        $this->actingAs($operator)->get('/app-settings/branding')->assertOk()->assertSee('Powered By');
    }
}
