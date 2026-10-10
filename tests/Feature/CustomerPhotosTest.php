<?php

namespace Tests\Feature;

use App\Customer;
use App\Misc\CustomerPhotos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Customer photos looked up online: from Gravatar (unset), Unavatar, or none.
 */
class CustomerPhotosTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // As in a new installation: Gravatar.
        \Option::remove(CustomerPhotos::OPTION);
        \Option::$cache = [];
    }

    protected function tearDown(): void
    {
        foreach (Customer::whereNotNull('photo_url')->pluck('photo_url') as $photo) {
            \Storage::delete(Customer::PHOTO_DIRECTORY.DIRECTORY_SEPARATOR.$photo);
        }
        \Option::$cache = [];
        parent::tearDown();
    }

    protected function png()
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    protected function webp()
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagewebp($image);

        return ob_get_clean();
    }

    public function testPhotoForNewCustomers()
    {
        Http::fake([
            'gravatar.com/avatar/'.hash('sha256', 'casey@customer.example.org').'*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            'gravatar.com/*' => Http::response('', 404),
        ]);
        $mailbox = $this->createMailbox();

        // None: nothing is asked.
        \Option::set(CustomerPhotos::OPTION, CustomerPhotos::NONE);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Casey@Customer.example.org', 'to' => $mailbox->email]));
        Http::assertNothingSent();

        // Unset: Gravatar.
        \Option::remove(CustomerPhotos::OPTION);
        $this->assertSame('gravatar', CustomerPhotos::service());

        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $casey = Customer::getByEmail('casey@customer.example.org');
        $this->assertNotNull($casey->fresh()->photo_url, 'Their Gravatar.');

        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'sam@customer.example.org', 'to' => $mailbox->email]));
        $sam = Customer::getByEmail('sam@customer.example.org')->fresh();
        $this->assertNull($sam->photo_url, 'No Gravatar.');
        $this->assertNotNull($sam->getMeta(CustomerPhotos::META));

        // Asked once.
        Http::assertSentCount(2);
        CustomerPhotos::request($sam, 'sam@customer.example.org');
        Http::assertSentCount(2);
    }

    public function testTheServiceIsChosenInTheSettings()
    {
        // Saves write .env: a temporary copy, as in SettingsControllerTest.
        $env_dir = sys_get_temp_dir().'/tallport-env-'.uniqid();
        mkdir($env_dir);
        file_put_contents($env_dir.'/.env.testing', "APP_TIMEZONE=UTC\nAPP_LOCALE=en\n");
        $this->app->useEnvironmentPath($env_dir);

        try {
            $admin = $this->createAdmin();
            $this->actingAs($admin)->get(route('settings', ['section' => 'general']))->assertOk()
                ->assertSee('name="settings[customer_photos]"', false)
                ->assertSee('<option value="gravatar" selected>Gravatar</option>', false)
                ->assertSee('<option value="unavatar" >Unavatar</option>', false)
                ->assertSee('Without a Photo')
                ->assertSee('x-show="customerPhotos != \'none\'"', false);

            \Session::start();
            $this->post(route('settings.save', ['section' => 'general']), ['_token' => csrf_token(), 'settings' => [
                'company_name'    => 'Tallport Inc.',
                'timezone'        => 'UTC',
                'locale'          => 'en',
                'customer_photos' => 'unavatar',
                'customer_gravatar_default' => 'robohash',
            ]])->assertRedirect(route('settings', ['section' => 'general']));
            \Option::$cache = [];
            $this->assertSame('unavatar', CustomerPhotos::service());
            $this->assertSame('robohash', \Option::get(CustomerPhotos::DEFAULT_OPTION));
            $this->get(route('settings', ['section' => 'general']))
                ->assertSee('<option value="unavatar" selected>Unavatar</option>', false);

            \Session::start();
            $this->post(route('settings.save', ['section' => 'general']), ['_token' => csrf_token(), 'settings' => [
                'company_name' => 'Tallport Inc.', 'timezone' => 'UTC', 'locale' => 'en', 'customer_photos' => 'none',
            ]]);
            \Option::$cache = [];
            $this->assertNull(CustomerPhotos::service());
            $this->assertFalse(CustomerPhotos::isEnabled());
            $this->get(route('settings', ['section' => 'general']))
                ->assertSee('<option value="none" selected>', false);
        } finally {
            @unlink($env_dir.'/.env.testing');
            @rmdir($env_dir);
        }
    }

    public function testRefreshesAndOwnPhotos()
    {
        $hash = hash('sha256', 'casey@customer.example.org');
        \Option::set(CustomerPhotos::OPTION, 'gravatar');

        // Their own photo, or none (404), whatever is chosen without a photo.
        \Option::set(CustomerPhotos::DEFAULT_OPTION, 'robohash');
        $this->assertStringContainsString('d=404', CustomerPhotos::url('gravatar', 'casey@customer.example.org'));
        $this->assertStringContainsString('d=robohash', CustomerPhotos::generatedUrl('casey@customer.example.org', 'robohash'));
        \Option::set(CustomerPhotos::DEFAULT_OPTION, 'something');
        $this->assertNull(CustomerPhotos::generatedStyle());
        \Option::set(CustomerPhotos::DEFAULT_OPTION, '');

        Http::fake(['gravatar.com/avatar/'.$hash.'*' => Http::sequence()
            ->push($this->png(), 200, ['Content-Type' => 'image/png'])
            ->push('', 404)]);
        $casey = $this->createCustomer('casey@customer.example.org');
        $this->assertTrue(CustomerPhotos::fetch($casey, 'casey@customer.example.org'));
        $this->assertSame(Customer::PHOTO_TYPE_LOOKED_UP, (int) $casey->fresh()->photo_type);

        // Not asked again for a while; after REFRESH_DAYS, and a Gravatar that's gone goes.
        $this->assertFalse(CustomerPhotos::isDue($casey->fresh()));
        $casey->setMeta(CustomerPhotos::META, now()->subDays(CustomerPhotos::REFRESH_DAYS + 1)->toDateTimeString());
        $casey->save();
        $this->assertTrue(CustomerPhotos::isDue($casey->fresh()));
        CustomerPhotos::fetch($casey->fresh(), 'casey@customer.example.org');
        $this->assertEmpty($casey->fresh()->photo_url);

        // A photo of their own is never replaced.
        $casey = $casey->fresh();
        $casey->photo_url = 'own.jpg';
        $casey->photo_type = Customer::PHOTO_TYPE_UKNOWN;
        $casey->setMeta(CustomerPhotos::META, null);
        $casey->save();
        $this->assertFalse(CustomerPhotos::isDue($casey->fresh()));
    }

    /**
     * Unavatar gets the address itself; a WebP photo is saved, none (404)
     * removes the one there was, and too many lookups (429) are asked again.
     */
    public function testUnavatar()
    {
        \Option::set(CustomerPhotos::OPTION, 'unavatar');
        $this->assertSame('https://unavatar.io/casey%40customer.example.org?fallback=false', CustomerPhotos::url('unavatar', ' Casey@Customer.example.org '));

        Http::fake(['unavatar.io/*' => Http::sequence()
            ->push('', 429)
            ->push($this->webp(), 200, ['Content-Type' => 'image/webp'])
            ->push('Not found', 404)]);
        $casey = $this->createCustomer('casey@customer.example.org');

        $this->assertFalse(CustomerPhotos::fetch($casey, 'casey@customer.example.org'));
        $this->assertNull($casey->fresh()->getMeta(CustomerPhotos::META), 'Asked again next time.');

        $this->assertTrue(CustomerPhotos::fetch($casey->fresh(), 'casey@customer.example.org'));
        $casey = $casey->fresh();
        $this->assertNotEmpty($casey->photo_url);
        $this->assertSame(Customer::PHOTO_TYPE_LOOKED_UP, (int) $casey->photo_type);
        $this->assertSame('unavatar', $casey->getMeta(CustomerPhotos::SERVICE_META));
        Http::assertSent(function ($request) {
            return $request->url() == 'https://unavatar.io/casey%40customer.example.org?fallback=false';
        });

        $casey->setMeta(CustomerPhotos::META, now()->subDays(CustomerPhotos::REFRESH_DAYS + 1)->toDateTimeString());
        $casey->save();
        $this->assertFalse(CustomerPhotos::fetch($casey->fresh(), 'casey@customer.example.org'));
        $this->assertEmpty($casey->fresh()->photo_url);
        Http::assertSentCount(3);
    }

    /**
     * Without a photo at Gravatar: its generated image, as a second request,
     * kept as one, so their own photo replaces it later; with initials, none.
     */
    public function testGravatarsGeneratedImageWithoutAPhoto()
    {
        $hash = hash('sha256', 'casey@customer.example.org');
        \Option::set(CustomerPhotos::DEFAULT_OPTION, 'robohash');
        Http::fake([
            'gravatar.com/avatar/'.$hash.'?d=404*' => Http::sequence()
                ->push('', 404)
                ->push($this->png(), 200, ['Content-Type' => 'image/png']),
            'gravatar.com/avatar/'.$hash.'?d=robohash*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $casey = $this->createCustomer('casey@customer.example.org');

        $this->assertTrue(CustomerPhotos::fetch($casey, 'casey@customer.example.org'));
        $casey = $casey->fresh();
        $this->assertNotEmpty($casey->photo_url);
        $this->assertSame('robohash', $casey->getMeta(CustomerPhotos::GENERATED_META));
        $this->assertFalse(CustomerPhotos::isDue($casey));
        Http::assertSentCount(2);

        // Another style (or initials): looked up again.
        \Option::set(CustomerPhotos::DEFAULT_OPTION, 'identicon');
        $this->assertTrue(CustomerPhotos::isDue($casey));
        \Option::set(CustomerPhotos::DEFAULT_OPTION, 'robohash');

        // Their own photo now.
        $casey->setMeta(CustomerPhotos::META, now()->subDays(CustomerPhotos::REFRESH_DAYS + 1)->toDateTimeString());
        $casey->save();
        $this->assertTrue(CustomerPhotos::fetch($casey->fresh(), 'casey@customer.example.org'));
        $this->assertNull($casey->fresh()->getMeta(CustomerPhotos::GENERATED_META));
        Http::assertSentCount(3);
    }

    /**
     * Without a photo at Unavatar: Gravatar's generated image, straight from
     * Gravatar; no plain Gravatar lookup (Unavatar asks Gravatar itself).
     */
    public function testUnavatarFallsBackToGravatarsGeneratedImage()
    {
        $hash = hash('sha256', 'casey@customer.example.org');
        \Option::set(CustomerPhotos::OPTION, 'unavatar');
        Http::fake([
            'unavatar.io/*' => Http::response('Not found', 404),
            'gravatar.com/avatar/'.$hash.'?d=identicon*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            'gravatar.com/*' => Http::response('', 404),
        ]);

        // Initials: Unavatar only.
        $sam = $this->createCustomer('sam@customer.example.org');
        $this->assertFalse(CustomerPhotos::fetch($sam, 'sam@customer.example.org'));
        Http::assertSentCount(1);

        \Option::set(CustomerPhotos::DEFAULT_OPTION, 'identicon');
        $casey = $this->createCustomer('casey@customer.example.org');
        $this->assertTrue(CustomerPhotos::fetch($casey, 'casey@customer.example.org'));
        $this->assertSame('identicon', $casey->fresh()->getMeta(CustomerPhotos::GENERATED_META));
        Http::assertSentCount(3);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'gravatar.com') && str_contains($request->url(), 'd=404');
        });
    }

    /**
     * Another service: the customer is looked up again with it.
     */
    public function testChangingTheServiceLooksUpAgain()
    {
        Http::fake([
            'gravatar.com/*' => Http::response('', 404),
            'unavatar.io/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $casey = $this->createCustomer('casey@customer.example.org');
        $this->assertFalse(CustomerPhotos::fetch($casey, 'casey@customer.example.org'));
        $this->assertFalse(CustomerPhotos::isDue($casey->fresh()));

        \Option::set(CustomerPhotos::OPTION, 'unavatar');
        $this->assertTrue(CustomerPhotos::isDue($casey->fresh()));
        CustomerPhotos::request($casey->fresh(), 'casey@customer.example.org');
        $this->assertNotEmpty($casey->fresh()->photo_url);
        $this->assertFalse(CustomerPhotos::isDue($casey->fresh()));
        Http::assertSentCount(2);
    }

    public function testOldPhotosAreRemovedAndOpeningAConversationGetsAFreshOne()
    {
        \Option::set(CustomerPhotos::OPTION, 'gravatar');
        Http::fake(['gravatar.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $mailbox = $this->createMailbox();
        $agent = $this->createUser();
        $mailbox->users()->attach($agent->id);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'sam@customer.example.org', 'to' => $mailbox->email]));
        $casey = Customer::getByEmail('casey@customer.example.org');
        $sam = Customer::getByEmail('sam@customer.example.org');
        $this->assertNotEmpty($casey->fresh()->photo_url);

        // Older than REFRESH_DAYS: removed; newer ones stay.
        $casey = $casey->fresh();
        $casey->setMeta(CustomerPhotos::META, now()->subDays(CustomerPhotos::REFRESH_DAYS + 1)->toDateTimeString());
        $casey->save();
        $this->artisan('tallport:clean-customer-photos')->expectsOutput('Removed: 1')->assertExitCode(0);
        $this->assertEmpty($casey->fresh()->photo_url);
        $this->assertNotEmpty($sam->fresh()->photo_url);

        // Their conversation opened: a fresh one.
        $conversation = \App\Conversation::where('customer_id', $casey->id)->first();
        $this->actingAs($agent)->get($conversation->url())->assertOk();
        $this->assertNotEmpty($casey->fresh()->photo_url);

        // None: all of them go.
        \Option::set(CustomerPhotos::OPTION, CustomerPhotos::NONE);
        $this->artisan('tallport:clean-customer-photos')->expectsOutput('Removed: 2')->assertExitCode(0);
        $this->assertEmpty($casey->fresh()->photo_url);
        $this->assertEmpty($sam->fresh()->photo_url);
    }

    /**
     * The Gravatar switch: an existing installation keeps what it had, a new
     * one gets Gravatar.
     */
    public function testTheGravatarSwitchIsConverted()
    {
        require_once base_path('database/migrations/2026_11_11_010101_customer_photo_services.php');
        $migrate = function () {
            \Option::$cache = [];
            (new \CustomerPhotoServices())->up();
            \Option::$cache = [];
        };
        $this->createAdmin();

        \Option::set('customer_gravatar', true);
        $migrate();
        $this->assertSame('gravatar', \Option::get(CustomerPhotos::OPTION, null));
        $this->assertNull(\Option::where('name', 'customer_gravatar')->first());

        // Once only.
        \Option::set('customer_gravatar', false);
        $migrate();
        $this->assertSame('gravatar', \Option::get(CustomerPhotos::OPTION, null));

        \Option::remove(CustomerPhotos::OPTION);
        \Option::set('customer_gravatar', false);
        $migrate();
        $this->assertSame('none', \Option::get(CustomerPhotos::OPTION, null));
        $this->assertFalse(CustomerPhotos::isEnabled());

        \Option::remove(CustomerPhotos::OPTION);
        $migrate();
        $this->assertSame('none', \Option::get(CustomerPhotos::OPTION, null), 'Was off (unset).');

        // A new installation.
        \Option::remove(CustomerPhotos::OPTION);
        DB::table('users')->delete();
        $migrate();
        $this->assertNull(\Option::get(CustomerPhotos::OPTION, null));
        $this->assertSame('gravatar', CustomerPhotos::service());
    }
}
