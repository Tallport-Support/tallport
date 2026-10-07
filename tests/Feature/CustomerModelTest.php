<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Email;
use Tests\FeatureTestCase;

/**
 * App\Customer on its own: names, emails, phones, websites, social
 * profiles, creating and filling customers, merging, photos and channels.
 */
class CustomerModelTest extends FeatureTestCase
{
    public function testEmailsAsArray()
    {
        $customer = $this->createCustomer('casey@customer.example.org');
        $customer->addEmail('casey.home@customer.example.org');

        $this->assertEqualsCanonicalizing(['casey@customer.example.org', 'casey.home@customer.example.org'], $customer->getEmailsAsArray());
        $this->assertSame([], Customer::getCustomerEmailsAsArray(999999));
    }

    public function testNames()
    {
        $customer = new Customer();
        $customer->last_name = 'Customer';
        $this->assertSame('Customer', $customer->getFullName());

        $customer->last_name = '';
        $this->assertSame('', $customer->getFullName());
        $this->assertSame('', $customer->getNameFromEmail());
        $this->assertSame('', $customer->getFirstName(true));
        $this->assertSame('', $customer->getFirstName());

        $dummy = Customer::getDummyCustomer();
        $this->assertSame('Customer', $dummy->getFullName());
        $this->assertFalse($dummy->exists);
    }

    public function testEmailAndNameForSearchResults()
    {
        $customer = $this->createCustomer('casey@customer.example.org');
        $this->assertSame('casey@customer.example.org (Casey Customer)', $customer->getEmailAndName());

        $customer->email = 'selected@customer.example.org';
        $this->assertSame('selected@customer.example.org (Casey Customer)', $customer->getEmailAndName());

        $phone_only = Customer::createWithoutEmail(['first_name' => 'Pat', 'last_name' => 'Phone']);
        $this->assertSame('Pat Phone', $phone_only->getEmailAndName());

        $this->assertSame('', (new Customer())->getEmailAndName());
    }

    public function testEmailOrPhone()
    {
        $customer = $this->createCustomer('casey@customer.example.org');
        $this->assertSame('casey@customer.example.org', $customer->getEmailOrPhone());

        $customer->email = 'selected@customer.example.org';
        $this->assertSame('selected@customer.example.org', $customer->getEmailOrPhone());

        $this->assertSame('', Customer::createWithoutEmail(['first_name' => 'Nobody'])->getEmailOrPhone());
    }

    public function testEmailOrPhoneShowsThePhoneOfACustomerWithoutEmail()
    {
        $this->knownBug('K1');

        $customer = Customer::createWithoutEmail(['first_name' => 'Pat', 'phones' => [['value' => '+1 555 0100', 'type' => Customer::PHONE_TYPE_MOBILE]]]);

        $this->assertSame('+1 555 0100', $customer->getEmailOrPhone());
    }

    public function testSyncEmailsMovesAddressesBetweenCustomers()
    {
        $casey = $this->createCustomer('casey@customer.example.org');
        $casey->addEmail('casey.old@customer.example.org');
        $casey->addEmail('casey.conversation@customer.example.org');
        $pat = $this->createCustomer('pat@customer.example.org', ['first_name' => 'Pat']);

        $conversation = new Conversation();
        $conversation->type = Conversation::TYPE_EMAIL;
        $conversation->subject = 'Question';
        $conversation->mailbox_id = $this->createMailbox()->id;
        $conversation->customer_id = $casey->id;
        $conversation->customer_email = 'casey.conversation@customer.example.org';
        $conversation->source_via = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_EMAIL;
        $conversation->status = Conversation::STATUS_ACTIVE;
        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->updateFolder();
        $conversation->save();

        $casey->syncEmails(['casey@customer.example.org', 'pat@customer.example.org', 'not an email', 'casey.new@customer.example.org']);

        $this->assertEqualsCanonicalizing(['casey@customer.example.org', 'pat@customer.example.org', 'casey.new@customer.example.org'],
        Customer::getCustomerEmailsAsArray($casey->id));
        $this->assertSame([], Customer::getCustomerEmailsAsArray($pat->id));
        $this->assertNull(Email::where('email', 'casey.old@customer.example.org')->first());

        // An address a conversation was sent from gets a customer of its own.
        $kept = Email::where('email', 'casey.conversation@customer.example.org')->first();
        $this->assertNotNull($kept->customer_id);
        $this->assertNotEquals($casey->id, $kept->customer_id);
        $this->assertNotNull(Customer::find($kept->customer_id));
    }

    public function testAddEmailRefusesAnAddressOfAnotherCustomer()
    {
        $this->createCustomer('pat@customer.example.org');
        $casey = $this->createCustomer('casey@customer.example.org');

        $this->assertFalse($casey->addEmail('pat@customer.example.org', true));
        $this->assertSame(['casey@customer.example.org'], $casey->getEmailsAsArray());
    }

    public function testPhones()
    {
        $customer = new Customer();
        $this->assertSame('', $customer->getMainPhoneValue());

        $customer->addPhone('+1 555 0100');
        $customer->addPhone(['value' => '+1 555 0199', 'type' => Customer::PHONE_TYPE_HOME]);
        $customer->addPhone('+1 555 0100', Customer::PHONE_TYPE_MOBILE);
        $customer->addPhone(['value' => '+1 555 0123', 'type' => 99]);

        $this->assertSame([
            ['value' => '+1 555 0100', 'type' => Customer::PHONE_TYPE_WORK, 'n' => '15550100'],
            ['value' => '+1 555 0199', 'type' => Customer::PHONE_TYPE_HOME, 'n' => '15550199'],
            ['value' => '+1 555 0123', 'type' => Customer::PHONE_TYPE_WORK, 'n' => '15550123'],
        ], $customer->getPhones());
        $this->assertSame('+1 555 0100', $customer->getMainPhoneValue());
        $this->assertSame('+1 555 0100', $customer->getMainPhoneNumber());

        $this->assertTrue(Customer::isDefaultPhoneType(Customer::PHONE_TYPE_WORK));
        $this->assertFalse(Customer::isDefaultPhoneType(Customer::PHONE_TYPE_MOBILE));
    }

    public function testWebsites()
    {
        $customer = new Customer();
        $this->assertSame('', $customer->getMainWebsite());

        $customer->setWebsites(['example.org', ['value' => 'https://shop.example.org'], 'http://', '', 'example.org']);
        $customer->addWebsite(['value' => 'blog.example.org']);
        $customer->addWebsite('https://shop.example.org');

        $this->assertSame(['http://example.org', 'https://shop.example.org', 'http://blog.example.org'], array_values($customer->getWebsites()));
        $this->assertSame('http://example.org', $customer->getMainWebsite());
    }

    public function testSocialProfiles()
    {
        $this->assertSame([
            ['value' => 'casey_tg', 'type' => Customer::SOCIAL_TYPE_TELEGRAM],
            ['value' => 'https://example.org/casey', 'type' => Customer::SOCIAL_TYPE_OTHER],
        ], Customer::formatSocialProfiles([
            ['value' => 'casey_tg', 'type' => 'Telegram'],
            ['value' => 'casey', 'type' => 'myspace'],
            ['value' => '', 'type' => 'twitter'],
            'https://example.org/casey',
        ]));

        $customer = new Customer();
        $customer->setSocialProfiles([
            ['value' => 'casey', 'type' => Customer::SOCIAL_TYPE_TWITTER],
            ['value' => 'casey', 'type' => Customer::SOCIAL_TYPE_FACEBOOK],
        ]);
        $this->assertSame([['value' => 'casey', 'type' => Customer::SOCIAL_TYPE_TWITTER]], $customer->getSocialProfiles());

        $other = Customer::formatSocialProfile(['value' => 'example.org/casey', 'type' => 999]);
        $this->assertSame(Customer::SOCIAL_TYPE_OTHER, $other['type']);
        $this->assertSame('Other', $other['type_name']);
        $this->assertSame('http://example.org/casey', $other['value_url']);
    }

    public function testCreateGivesAnOrphanEmailACustomer()
    {
        // The email's customer was deleted without it.
        $email = new Email();
        $email->email = 'orphan@customer.example.org';
        $email->customer_id = 999999;
        $email->save();

        $customer = Customer::create('orphan@customer.example.org', ['first_name' => 'Olive']);

        $this->assertTrue($customer->exists);
        $this->assertSame('Olive', $customer->first_name);
        $this->assertSame($customer->id, $email->fresh()->customer_id);
    }

    public function testCreateWithTheEmailAlsoInTheEmailsList()
    {
        foreach (['string' => 'casey@customer.example.org', 'array' => ['value' => 'casey@customer.example.org', 'type' => Email::TYPE_HOME]] as $form => $listed) {
            $customer = Customer::create('casey@customer.example.org', ['first_name' => 'Casey', 'emails' => [$listed, 'casey.'.$form.'@customer.example.org']]);

            $this->assertEqualsCanonicalizing(['casey@customer.example.org', 'casey.'.$form.'@customer.example.org'], $customer->getEmailsAsArray());
            $this->assertSame(1, Email::where('email', 'casey@customer.example.org')->count());

            $customer->deleteCustomer();
        }
    }

    public function testSetDataFillsFields()
    {
        $customer = Customer::createWithoutEmail([
            'first_name'      => 'Casey',
            'background'      => 'Long-time customer',
            'photo_url'       => 'https://example.org/photo.png',
            'phone'           => '+1 555 0100',
            'websites'        => 'example.org',
            'social_profiles' => [['value' => 'casey', 'type' => 'twitter']],
            'emails'          => [['value' => 'casey@customer.example.org', 'type' => Email::TYPE_WORK], ['type' => Email::TYPE_HOME]],
        ]);
        $customer = $customer->fresh();

        $this->assertSame('Long-time customer', $customer->notes);
        $this->assertEmpty($customer->photo_url);
        $this->assertSame('+1 555 0100', $customer->getMainPhoneNumber());
        $this->assertSame(['http://example.org'], $customer->getWebsites());
        $this->assertSame([['value' => 'casey', 'type' => Customer::SOCIAL_TYPE_TWITTER]], $customer->getSocialProfiles());
        $this->assertSame(['casey@customer.example.org'], $customer->getEmailsAsArray());

        $customer->setData(['phones' => ['value' => '+1 555 0199', 'type' => Customer::PHONE_TYPE_HOME], 'notes' => 'Kept'], false);
        $this->assertSame(['+1 555 0100', '+1 555 0199'], array_column($customer->getPhones(), 'value'));
        $this->assertSame('Long-time customer', $customer->notes);

        $this->assertSame(['casey@customer.example.org'], $customer->fresh()->getEmailsAsArray());
        $customer->setData(['notes' => 'Saved'], true, true);
        $this->assertSame('Saved', $customer->fresh()->notes);
    }

    public function testSetDataTurnsCountryNamesIntoCodes()
    {
        $this->knownBug('K2');

        $customer = new Customer();
        $customer->setData(['country' => 'Germany']);

        $this->assertSame('DE', $customer->country);
        $this->assertSame('Germany', $customer->getCountryName());
    }

    public function testCountryName()
    {
        $customer = new Customer();
        $customer->country = 'DE';
        $this->assertSame('Germany', $customer->getCountryName());

        $customer->country = 'ZZ';
        $this->assertSame('', $customer->getCountryName());
    }

    public function testUrls()
    {
        $customer = $this->createCustomer();

        $this->assertSame(route('customers.update', ['id' => $customer->id]), $customer->url());
        $this->assertSame(route('customers.conversations', ['id' => $customer->id]), $customer->urlView());
    }

    public function testParseName()
    {
        $this->assertSame([], Customer::parseName(''));
        $this->assertSame(['first_name' => 'Smith'], Customer::parseName('Smith, '));
        $this->assertSame(['first_name' => 'John', 'last_name' => 'Smith'], Customer::parseName('Smith, John'));
        $this->assertSame(['first_name' => 'John', 'last_name' => 'van Smith'], Customer::parseName('John van Smith'));
    }

    public function testMergeWith()
    {
        $casey = $this->createCustomer('casey@customer.example.org', [
            'phones'          => [['value' => '+1 555 0100', 'type' => Customer::PHONE_TYPE_WORK]],
            'social_profiles' => [['value' => 'casey', 'type' => Customer::SOCIAL_TYPE_TWITTER]],
        ]);
        $pat = $this->createCustomer('pat@customer.example.org', [
            'first_name'      => 'Pat',
            'company'         => 'Acme',
            'phones'          => [['value' => '+1 555 0100', 'type' => Customer::PHONE_TYPE_WORK], ['value' => '+1 555 0199', 'type' => Customer::PHONE_TYPE_HOME]],
            'websites'        => ['acme.example.org'],
            'social_profiles' => [['value' => 'pat', 'type' => Customer::SOCIAL_TYPE_TWITTER]],
        ]);

        $this->assertFalse($casey->mergeWith($casey));

        $this->assertTrue($casey->mergeWith($pat));
        $casey = $casey->fresh();

        $this->assertNull(Customer::find($pat->id));
        $this->assertSame('Acme', $casey->company);
        $this->assertSame('Casey', $casey->first_name);
        $this->assertSame(['+1 555 0100', '+1 555 0199'], array_column($casey->getPhones(), 'value'));
        $this->assertSame(['http://acme.example.org'], array_values($casey->getWebsites()));
        $this->assertSame([
            ['value' => 'casey', 'type' => Customer::SOCIAL_TYPE_TWITTER],
            ['value' => 'pat', 'type' => Customer::SOCIAL_TYPE_TWITTER],
        ], $casey->getSocialProfiles());
        $this->assertEqualsCanonicalizing(['casey@customer.example.org', 'pat@customer.example.org'], $casey->getEmailsAsArray());
    }

    public function testMergeTypeValueListsSkipsIncompleteAndExistingEntries()
    {
        $this->assertSame([
            ['type' => 1, 'value' => 'a'],
            ['type' => 2, 'value' => 'a'],
        ], Customer::mergeTypeValueLists([['type' => 1, 'value' => 'a']], [
            ['type' => 1, 'value' => 'a'],
            ['type' => 2, 'value' => 'a'],
            ['type' => '', 'value' => 'b'],
            ['type' => 3, 'value' => ''],
        ]));
    }

    public function testPhotos()
    {
        $customer = $this->createCustomer();
        $this->assertSame('', $customer->getPhotoUrl(false));
        $this->assertStringEndsWith('/img/default-avatar.png', $customer->getPhotoUrl());

        $disk = \Storage::disk('local');
        $disk->makeDirectory('upload');
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $disk->path('upload/photo.png'));
        $file_name = $customer->savePhoto($disk->path('upload/photo.png'), 'image/png');
        $this->assertNotFalse($file_name);
        $customer->photo_url = $file_name;
        $this->assertTrue($disk->exists(Customer::PHOTO_DIRECTORY.'/'.$file_name));
        $this->assertSame(Customer::getPhotoUrlByFileName($file_name), $customer->getPhotoUrl());
        $this->assertStringContainsString(Customer::PHOTO_DIRECTORY.'/'.$file_name, $customer->getPhotoUrl(false));

        // A new photo replaces the old one.
        $new_file_name = $customer->savePhoto($disk->path('upload/photo.png'), 'image/png');
        $this->assertNotSame($file_name, $new_file_name);
        $this->assertFalse($disk->exists(Customer::PHOTO_DIRECTORY.'/'.$file_name));
        $this->assertTrue($disk->exists(Customer::PHOTO_DIRECTORY.'/'.$new_file_name));

        $customer->photo_url = $new_file_name;
        $customer->removePhoto();
        $this->assertSame('', $customer->photo_url);
        $this->assertFalse($disk->exists(Customer::PHOTO_DIRECTORY.'/'.$new_file_name));
    }

    public function testRemotePhotoFromALocalAddressIsRefused()
    {
        $customer = $this->createCustomer();

        $this->assertFalse($customer->setPhotoFromRemoteFile('http://127.0.0.1/photo.png'));
        $this->assertEmpty($customer->photo_url);
    }

    public function testChannels()
    {
        \Eventy::addFilter('channel.name', function ($name, $channel) {
            return $channel == 77 ? 'Pigeon Post' : $name;
        }, 20, 2);

        $customer = $this->createCustomer();
        $this->assertCount(0, $customer->getChannels());

        $customer->addChannel(77, 'pigeon-1');
        $this->assertSame('Pigeon Post', $customer->getChannelName());
        $this->assertSame('pigeon-1', $customer->getChannelId(77));

        $updated = $customer->addChannel(77, 'pigeon-2');
        $this->assertSame('pigeon-2', $updated->channel_id);
        $this->assertSame('pigeon-2', $customer->getChannelId(77));
        $this->assertNull($customer->addChannel(77, 'pigeon-2'));

        $customer->updateChannelId(77, 'pigeon-3');
        $customer->updateChannelId(77, '');
        $customer->updateChannelId(null, 'pigeon-4');
        $this->assertSame('pigeon-3', $customer->getChannelId(77));
        $this->assertSame($customer->id, Customer::getCustomerByChannel(77, 'pigeon-3')->id);
        $this->assertCount(1, $customer->getChannels());
    }

    public function testAChannelIdOfAnotherCustomerIsNotTaken()
    {
        $casey = $this->createCustomer();
        $casey->addChannel(77, 'pigeon-1');
        $pat = $this->createCustomer();
        $pat->addChannel(77, 'pigeon-2');

        $pat->addChannel(77, 'pigeon-1');
        $pat->updateChannelId(77, 'pigeon-1');

        $this->assertSame('pigeon-2', $pat->getChannelId(77));
        $this->assertSame($casey->id, Customer::getCustomerByChannel(77, 'pigeon-1')->id);
    }

    public function testFindCustomersBySocialProfileMatchesTheWholeName()
    {
        $casey = $this->createCustomer(null, ['social_profiles' => [['value' => '@casey', 'type' => Customer::SOCIAL_TYPE_TELEGRAM]]]);
        $this->createCustomer(null, ['social_profiles' => [['value' => 'mrcasey', 'type' => Customer::SOCIAL_TYPE_TELEGRAM]]]);

        $found = Customer::findCustomersBySocialProfile(Customer::SOCIAL_TYPE_TELEGRAM, 'casey');

        $this->assertSame([$casey->id], $found->pluck('id')->values()->all());
    }

    public function testDeleteCustomerDeletesItsEmails()
    {
        $customer = $this->createCustomer('casey@customer.example.org');

        $customer->deleteCustomer();

        $this->assertNull(Customer::find($customer->id));
        $this->assertSame(0, Email::where('email', 'casey@customer.example.org')->count());
    }
}
