<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Option;
use App\SendLog;
use Tests\FeatureTestCase;

/**
 * Settings (SettingsController): what a save stores when fields are left
 * out, the AI settings tidied before saving, sections modules add, and
 * the test email failing.
 *
 * Saves write .env: a temporary copy, as in SettingsAndSystemTest.
 */
class SettingsControllerTest extends FeatureTestCase
{
    protected $admin;
    protected $env_dir;
    protected $filters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->env_dir = sys_get_temp_dir().'/tallport-env-'.uniqid();
        mkdir($this->env_dir);
        file_put_contents($this->env_dir.'/.env.testing', "APP_TIMEZONE=UTC\nAPP_LOCALE=en\n");
        $this->app->useEnvironmentPath($this->env_dir);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->filters as [$name, $callback]) {
                \Eventy::removeFilter($name, $callback, 20);
            }
            @unlink($this->env_dir.'/.env.testing');
            @rmdir($this->env_dir);
        } finally {
            parent::tearDown();
        }
    }

    protected function addFilter($name, $callback)
    {
        \Eventy::addFilter($name, $callback, 20, 2);
        $this->filters[] = [$name, $callback];
    }

    protected function postForm($uri, array $data)
    {
        \Session::start();

        return $this->actingAs($this->admin)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function envFile()
    {
        return file_get_contents($this->env_dir.'/.env.testing');
    }

    public function testUnknownSectionIsNotFound()
    {
        $this->postForm('/app-settings/no-such-section', ['settings' => ['x' => 1]])->assertNotFound();
        $this->assertNull((new SettingsController())->getSectionParams('emails', 'no_such_param'));
    }

    /**
     * A checkbox left unticked is stored as off when it is on by default,
     * and a list as empty, rather than falling back to the default.
     */
    public function testLeftOutFieldsAreStoredAsOffOrEmpty()
    {
        Option::set('email_branding', true);
        $this->postForm('/app-settings/general', ['settings' => [
            'company_name'     => 'Tallport Inc.',
            'timezone'         => 'UTC',
            'locale'           => 'en',
            'user_permissions' => [\App\User::PERM_DELETE_CONVERSATIONS],
        ]])->assertRedirect(route('settings', ['section' => 'general']));

        Option::$cache = [];
        $this->assertFalse((bool) Option::get('email_branding', true));
        $this->assertNotNull(Option::where('name', 'email_branding')->first(), 'Stored, not removed.');
        $this->assertEquals([\App\User::PERM_DELETE_CONVERSATIONS], Option::get('user_permissions'));
        $this->assertStringNotContainsString('APP_USER_PERMISSIONS', $this->envFile());

        Option::set('subscription_defaults', ['1' => ['1' => 1]]);
        $this->postForm('/app-settings/alerts', ['settings' => ['alert_recipients' => 'ops@example.org']])
            ->assertRedirect(route('settings', ['section' => 'alerts']));
        Option::$cache = [];
        $this->assertSame([], Option::get('subscription_defaults', 'default'));
        $this->assertSame('ops@example.org', Option::get('alert_recipients'));
    }

    public function testAiSettingsAreTidied()
    {
        $this->postForm('/app-settings/ai', ['settings' => [
            'aiassistant.providers' => [
                'p1'  => ['provider' => 'openai', 'api_key' => 'sk-one'],
                'new' => ['provider' => 'anthropic', 'api_key' => ''],
            ],
            'aiassistant.documentation.embedding_provider' => ' Mistral ',
            'aiassistant.documentation.embedding_base_url' => ' https://embed.example.org/v1/ ',
            'aiassistant.documentation.embedding_model'    => ' mistral-embed ',
        ]])->assertSessionHasNoErrors()->assertRedirect(route('settings', ['section' => 'ai']));

        Option::$cache = [];
        $this->assertSame(['p1'], array_column(Option::get('aiassistant.providers'), 'id'), 'A new provider without the key it needs is not added.');
        $this->assertSame('mistral', Option::get('aiassistant.documentation.embedding_provider'));
        $this->assertSame('https://embed.example.org/v1', Option::get('aiassistant.documentation.embedding_base_url'));
        $this->assertSame('mistral-embed', Option::get('aiassistant.documentation.embedding_model'));

        $this->postForm('/app-settings/ai', ['settings' => ['aiassistant.documentation.embedding_provider' => 'no-such-provider']]);
        Option::$cache = [];
        $this->assertSame('openai', Option::get('aiassistant.documentation.embedding_provider'));
    }

    /**
     * Modules add sections (settings.sections, settings.section_settings,
     * settings.section_params): their settings can go to .env encrypted,
     * and have their own defaults.
     */
    public function testModuleSection()
    {
        $this->addFilter('settings.sections', function ($sections) {
            return $sections + ['demo' => ['title' => 'Demo', 'icon' => 'cog', 'order' => 900]];
        });
        $this->addFilter('settings.section_settings', function ($settings, $section) {
            return $section == 'demo' ? ['demo.token' => '', 'demo.empty_token' => '', 'demo.enabled' => true, 'demo.note' => ''] : $settings;
        });
        $this->addFilter('settings.section_params', function ($params, $section) {
            return $section == 'demo' ? ['settings' => [
                'demo.token'       => ['env' => 'DEMO_TOKEN', 'encrypt' => true],
                'demo.empty_token' => ['env' => 'DEMO_EMPTY_TOKEN', 'encrypt' => true],
                'demo.enabled'     => ['default' => true],
            ]] : $params;
        });
        Option::set('demo.note', 'old note');

        $this->postForm('/app-settings/demo', ['settings' => ['demo.token' => 'tok-123']])
            ->assertRedirect(route('settings', ['section' => 'demo']));

        preg_match('/^DEMO_TOKEN=(.*)$/m', $this->envFile(), $m);
        $this->assertNotSame('tok-123', trim($m[1] ?? '', '"\''));
        $this->assertSame('tok-123', decrypt(trim($m[1], '"\'')));
        $this->assertMatchesRegularExpression('/^DEMO_EMPTY_TOKEN=(""|)$/m', $this->envFile(), 'Nothing to encrypt.');
        Option::$cache = [];
        $this->assertSame('0', (string) (int) Option::get('demo.enabled', 'missing'), 'Off, not the default.');
        $this->assertNull(Option::where('name', 'demo.note')->first(), 'Without a default: removed.');
        $this->assertCommandCalled('tallport:clear-cache');
    }

    public function testTestEmailNeedsARecipient()
    {
        $this->postAjax($this->admin, '/app-settings/ajax', ['action' => 'send_test'])
            ->assertJson(['status' => 'error', 'msg' => 'Please specify recipient of the test email']);
        $this->postAjax($this->admin, '/app-settings/ajax', ['action' => 'nonsense'])
            ->assertJson(['status' => 'error', 'msg' => 'Unknown action']);
    }

    /**
     * Sending fails with this error message.
     */
    protected function failSending($message)
    {
        $failing = new class($message) extends \Symfony\Component\Mailer\Transport\AbstractTransport {
            protected $error;

            public function __construct($error)
            {
                parent::__construct();
                $this->error = $error;
            }

            protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException($this->error);
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        };
        // An extender of the binding (not the built manager), so mail managers built later
        // (MailHelper rebuilds them) use it too.
        $this->app->forgetInstance('mail.manager');
        $this->app->extend('mail.manager', function ($manager) use ($failing) {
            return static::captureAllMailDrivers($manager, $failing);
        });
        \Mail::swap(app('mail.manager'));
    }

    public function testTestEmailFailureIsShown()
    {
        $this->failSending('Connection refused by smtp.example.org');

        $response = $this->postAjax($this->admin, '/app-settings/ajax', ['action' => 'send_test', 'to' => 'me@example.org']);

        $response->assertJson(['status' => 'error', 'msg' => 'Connection refused by smtp.example.org']);
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, SendLog::where('email', 'me@example.org')->value('status'));
        $this->assertSame('me@example.org', Option::get('send_test_to'), 'The address is remembered.');
    }

    /**
     * A failure without a message is still a failure, with a general
     * message.
     */
    public function testTestEmailFailureWithoutAMessage()
    {
        $this->failSending('');

        $this->postAjax($this->admin, '/app-settings/ajax', ['action' => 'send_test', 'to' => 'me@example.org'])
            ->assertJson(['status' => 'error', 'msg' => 'Error occurred sending email. Please check your mail server logs for more details.']);
    }
}
