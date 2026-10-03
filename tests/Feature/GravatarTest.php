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
}
