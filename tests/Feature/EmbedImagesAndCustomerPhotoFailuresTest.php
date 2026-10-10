<?php

namespace Tests\Feature;

use App\Customer;
use App\Misc\CustomerPhotos;
use App\Misc\EmbedImages;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;
use Tests\FeatureTestCase;

/**
 * When embedding images in an email or looking up a customer photo goes
 * wrong, the email is sent with links and the photo is asked for again later.
 */
class EmbedImagesAndCustomerPhotoFailuresTest extends FeatureTestCase
{
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        \Option::$cache = [];
        parent::tearDown();
    }

    /**
     * Only files in the uploads folder itself, and only ones that exist.
     */
    public function testUploadedImagesThatAreNotThere()
    {
        \Storage::disk('local')->put('uploads/logo.png', base64_decode(self::PNG));
        $logo = \Helper::uploadedFileUrl('logo.png');
        $elsewhere = str_replace('/uploads/', '/other/uploads/', $logo);
        $missing = \Helper::uploadedFileUrl('missing.png');

        $email = (new Email())->html('<img src="'.$elsewhere.'"><img src="'.$missing.'"><img src="'.$logo.'">');

        $this->assertSame(1, EmbedImages::embed($email));
        $html = $email->getHtmlBody();
        $this->assertStringContainsString('src="'.$elsewhere.'"', $html);
        $this->assertStringContainsString('src="'.$missing.'"', $html);
        \Storage::disk('local')->delete('uploads/logo.png');
    }

    /**
     * An error while embedding leaves the email as it was, and it is still sent.
     */
    public function testEmbeddingErrorKeepsTheLinks()
    {
        $email = new class () extends Email {
            public function embed($body, ?string $name = null, ?string $contentType = null): static
            {
                throw new \RuntimeException('Out of memory');
            }
        };
        $email->html('<p><img src="data:image/png;base64,'.self::PNG.'"></p>');
        \Log::shouldReceive('error')->once()->withArgs(function ($message) {
            return str_contains($message, '[Embed images]') && str_contains($message, 'Out of memory');
        });

        $this->assertTrue(\Eventy::filter('mail.process_swift_message', true, $email));
        $this->assertStringContainsString('src="data:image/png;base64,', $email->getHtmlBody());
    }

    /**
     * Customers with their own photo keep it; a failed request is tried again
     * for the next email.
     */
    public function testCustomerPhotoNotDueOrUnreachable()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });
        \Option::set(CustomerPhotos::OPTION, 'gravatar');
        $customer = $this->createCustomer('casey@customer.example.org');

        $customer->photo_url = 'own.jpg';
        $customer->photo_type = Customer::PHOTO_TYPE_UKNOWN;
        $this->assertFalse(CustomerPhotos::fetch($customer, 'casey@customer.example.org'));
        Http::assertNothingSent();

        $customer->photo_url = null;
        \Log::shouldReceive('error')->once()->withArgs(function ($message) {
            return str_contains($message, '[Gravatar]') && str_contains($message, 'Connection timed out');
        });
        $this->assertFalse(CustomerPhotos::fetch($customer, 'casey@customer.example.org'));
        $this->assertNull($customer->fresh()->getMeta(CustomerPhotos::META), 'Asked again next time.');
        $this->assertTrue(CustomerPhotos::isDue($customer->fresh()));
    }
}
