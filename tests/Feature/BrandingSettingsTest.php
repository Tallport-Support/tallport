<?php

namespace Tests\Feature;

use App\Conversation;
use App\Misc\Branding;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * Settings » Branding: logo, banner, favicon, colour, name, footer, CSS,
 * emails to customers, "Powered by" in widgets.
 */
class BrandingSettingsTest extends FeatureTestCase
{
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        \Option::$cache = [];
        $this->admin = $this->createAdmin();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(Branding::IMAGES) as $name) {
            if ($file = \Option::get($name)) {
                \Helper::uploadedFileRemove($file);
            }
        }
        \Option::$cache = [];
        parent::tearDown();
    }

    public function testSettings()
    {
        \Session::start();
        $this->actingAs($this->admin)->get(route('settings', ['section' => 'branding']))->assertOk()->assertSee('Header Logo');

        $this->post(route('settings.save', ['section' => 'branding']), [
            '_token'           => csrf_token(),
            'branding_logo'    => UploadedFile::fake()->image('logo.png', 22, 22),
            'branding_favicon' => UploadedFile::fake()->createWithContent('icon.php', '<?php'),
            'settings'         => ['branding.accent' => 'purple'],
        ])->assertSessionHasErrors('branding_favicon');

        $this->post(route('settings.save', ['section' => 'branding']), [
            '_token'        => csrf_token(),
            'branding_logo' => UploadedFile::fake()->image('logo.png', 22, 22),
            'settings'      => [
                'branding.accent'       => 'orange',
                'branding.title'        => 'Acme Support',
                'branding.footer'       => '<p>Acme Inc.<script>alert(1)</script></p>',
                'branding.css'          => '.a { color: red; } .b { background: url(javascript:alert(1)); } @import url(x.css); }',
                'branding.email_header' => '<p>Acme header</p>',
                'branding.email_footer' => '<p>Acme footer</p>',
                'branding.email_css'    => 'p { color: #123; }',
            ],
        ])->assertRedirect();
        \Option::$cache = [];

        $logo = \Option::get('branding.logo');
        $this->assertStringEndsWith('.png', $logo);
        $this->assertTrue(\Storage::exists('uploads/'.$logo));
        $this->assertSame('orange', \Option::get('branding.accent'));
        $this->assertStringNotContainsString('<script>', \Option::get('branding.footer'));
        $this->assertStringNotContainsString('javascript:', \Option::get('branding.css'));
        $this->assertStringNotContainsString('@import', \Option::get('branding.css'));
        $this->assertFalse((bool) \Option::get('branding.widget_powered_by', true), 'Unticked: off.');

        $page = $this->get(route('settings', ['section' => 'branding']))->assertOk();
        $this->get('/?dashboard=1')->assertSee('Dashboard - Acme Support', false);
        $page->assertSee(\Helper::uploadedFileUrl($logo), false)
            ->assertSee('data-fruit-accent="orange"', false)
            ->assertSee('Acme Inc.');
        // Custom CSS after the stylesheets, so it overrides them.
        $this->assertMatchesRegularExpression('#<link[^>]+\.css[^>]*>.*<style>\.a \{#s', $page->getContent());

        // Removed: the standard logo again, the file gone.
        $this->post(route('settings.save', ['section' => 'branding']), ['_token' => csrf_token(), 'branding_logo_remove' => 1, 'settings' => ['branding.widget_powered_by' => 1]]);
        \Option::$cache = [];
        $this->assertSame('', (string) \Option::get('branding.logo'));
        $this->assertFalse(\Storage::exists('uploads/'.$logo));
    }

    /**
     * Logo and banner have a dark-mode version (light one in both without it);
     * the sign-in pages show Tallport's mark and name by default.
     */
    public function testDarkModeImagesAndDefaultBanner()
    {
        $this->get(route('login'))->assertOk()->assertSee('banner__default', false)->assertSee('Tallport')->assertDontSee('img/banner.png', false);

        \Session::start();
        $this->actingAs($this->admin)->get(route('settings', ['section' => 'branding']))->assertOk()->assertSee('Appearance');
        $this->post(route('settings.save', ['section' => 'branding']), [
            '_token'               => csrf_token(),
            'branding_logo'        => UploadedFile::fake()->image('logo.png', 22, 22),
            'branding_banner'      => UploadedFile::fake()->image('banner.png', 184, 36),
            'branding_banner_dark' => UploadedFile::fake()->image('banner-dark.png', 184, 36),
        ])->assertRedirect();
        \Option::$cache = [];
        $logo = \Helper::uploadedFileUrl(\Option::get('branding.logo'));
        $dark_banner = \Helper::uploadedFileUrl(\Option::get('branding.banner_dark'));

        // No dark logo: the light one shows in both.
        $this->get('/?dashboard=1')->assertSee('<img src="'.$logo.'"', false)->assertDontSee('prefers-color-scheme', false);
        \Auth::logout();
        $this->get(route('login'))->assertSee('<source srcset="'.$dark_banner.'" media="(prefers-color-scheme: dark)">', false)
            ->assertDontSee('banner__default', false);
    }

    public function testEmailsToCustomers()
    {
        \Option::set('branding.email_header', '<p>Acme header</p>');
        \Option::set('branding.email_footer', '<p>Acme footer</p>');
        \Option::set('branding.email_css', 'p { color: #123456; }');
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $mailbox->auto_reply_enabled = true;
        $mailbox->auto_reply_subject = 'Thanks';
        $mailbox->auto_reply_message = 'We got it.';
        $mailbox->save();

        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Casey <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => 'Question']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $this->postAjax($agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Answer</p>',
        ]);

        $emails = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertCount(2, $emails, 'The auto reply and the reply.');
        foreach ($emails as $email) {
            $this->assertStringContainsString('Acme header', $email->getBody());
            $this->assertStringContainsString('Acme footer', $email->getBody());
            $this->assertStringContainsString('color: #123456', $email->getBody());
        }
    }

    public function testWidgetPoweredBy()
    {
        $this->assertSame('Powered by Tallport', \Eventy::filter('knowledgebase.powered_by', 'Powered by Tallport'));
        \Option::set('branding.widget_powered_by', false);
        $this->assertSame('', \Eventy::filter('knowledgebase.powered_by', 'Powered by Tallport'));
        $this->assertSame('', \Eventy::filter('chat.powered_by', 'Powered by Tallport'));
    }

    public function testImportFromTheEnvironmentFile()
    {
        require_once base_path('database/migrations/2026_10_13_010101_branding.php');
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "CUSTOMIZATION_LOGO=abc.png\nCUSTOMIZATION_HEADER=112233\nCUSTOMIZATION_TITLE=FreeScout\n"
            .'CUSTOMIZATION_CSS='.base64_encode('.x { color: red; }')."\n"
            .'CUSTOMIZATION_CUSTOMER_EMAIL_FOOTER='.base64_encode('<p>Footer</p><script>x</script>')."\n");

        \Branding::importSettings($file);
        @unlink($file);
        \Option::$cache = [];

        $this->assertSame('abc.png', \Option::get('branding.logo'));
        // The old header colour: the nearest accent (2026_10_20 migration).
        if (!class_exists('AddAccentColors')) {
            require base_path('database/migrations/2026_10_20_010101_add_accent_colors.php');
        }
        (new \AddAccentColors())->up();
        \Option::$cache = [];
        $this->assertSame('blue', \Option::get('branding.accent'), 'Dark navy: the nearest hue.');
        $this->assertNull(\Option::get('branding.header_color', null));
        $this->assertNull(\Option::get('branding.title', null), 'The old default name is not kept.');
        $this->assertSame('.x { color: red; }', \Option::get('branding.css'));
        $this->assertStringNotContainsString('<script>', \Option::get('branding.email_footer'));
    }
}
