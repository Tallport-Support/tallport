<?php

namespace Tests\Feature;

use App\Customer;
use App\Misc\Gravatar;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Customer photos from Gravatar, when the setting is on.
 */
class GravatarTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    public function testPhotoForNewCustomers()
    {
        Http::fake([
            'gravatar.com/avatar/'.hash('sha256', 'casey@customer.example.org').'*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            'gravatar.com/*' => Http::response('', 404),
        ]);
        $mailbox = $this->createMailbox();

        // Off: nothing is asked.
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Casey@Customer.example.org', 'to' => $mailbox->email]));
        Http::assertNothingSent();

        $this->actingAs($this->createAdmin())->get(route('settings', ['section' => 'general']))
            ->assertSee('name="settings[customer_gravatar]"', false);
        \Option::set(Gravatar::OPTION, true);

        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $casey = Customer::getByEmail('casey@customer.example.org');
        $this->assertNotNull($casey->fresh()->photo_url, 'Their Gravatar.');

        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'sam@customer.example.org', 'to' => $mailbox->email]));
        $sam = Customer::getByEmail('sam@customer.example.org')->fresh();
        $this->assertNull($sam->photo_url, 'No Gravatar.');
        $this->assertNotNull($sam->getMeta(Gravatar::META));

        // Asked once.
        Http::assertSentCount(2);
        Gravatar::request($sam, 'sam@customer.example.org');
        Http::assertSentCount(2);
    }

    public function testGeneratedImagesRefreshesAndOwnPhotos()
    {
        $hash = hash('sha256', 'casey@customer.example.org');
        \Option::set(Gravatar::OPTION, true);

        // Without a Gravatar: the generated image chosen, or initials (404).
        $this->assertStringContainsString('d=404', Gravatar::url('casey@customer.example.org'));
        \Option::set(Gravatar::DEFAULT_OPTION, 'robohash');
        $this->assertStringContainsString('d=robohash', Gravatar::url('casey@customer.example.org'));
        \Option::set(Gravatar::DEFAULT_OPTION, 'something');
        $this->assertStringContainsString('d=404', Gravatar::url('casey@customer.example.org'));

        Http::fake(['gravatar.com/avatar/'.$hash.'*' => Http::sequence()
            ->push($this->png(), 200, ['Content-Type' => 'image/png'])
            ->push('', 404)]);
        $casey = $this->createCustomer('casey@customer.example.org');
        $this->assertTrue(Gravatar::fetch($casey, 'casey@customer.example.org'));
        $this->assertSame(Customer::PHOTO_TYPE_GRAVATAR, (int) $casey->fresh()->photo_type);

        // Not asked again for a while; after REFRESH_DAYS, and a Gravatar that's gone goes.
        $this->assertFalse(Gravatar::isDue($casey->fresh()));
        $casey->setMeta(Gravatar::META, now()->subDays(Gravatar::REFRESH_DAYS + 1)->toDateTimeString());
        $casey->save();
        $this->assertTrue(Gravatar::isDue($casey->fresh()));
        Gravatar::fetch($casey->fresh(), 'casey@customer.example.org');
        $this->assertEmpty($casey->fresh()->photo_url);

        // A photo of their own is never replaced.
        $casey = $casey->fresh();
        $casey->photo_url = 'own.jpg';
        $casey->photo_type = Customer::PHOTO_TYPE_UKNOWN;
        $casey->setMeta(Gravatar::META, null);
        $casey->save();
        $this->assertFalse(Gravatar::isDue($casey->fresh()));
    }

    public function testOldPhotosAreRemovedAndOpeningAConversationGetsAFreshOne()
    {
        \Option::set(Gravatar::OPTION, true);
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
        $casey->setMeta(Gravatar::META, now()->subDays(Gravatar::REFRESH_DAYS + 1)->toDateTimeString());
        $casey->save();
        $this->artisan('tallport:clean-gravatars')->expectsOutput('Removed: 1')->assertExitCode(0);
        $this->assertEmpty($casey->fresh()->photo_url);
        $this->assertNotEmpty($sam->fresh()->photo_url);

        // Their conversation opened: a fresh one.
        $conversation = \App\Conversation::where('customer_id', $casey->id)->first();
        $this->actingAs($agent)->get($conversation->url())->assertOk();
        $this->assertNotEmpty($casey->fresh()->photo_url);
    }
}
