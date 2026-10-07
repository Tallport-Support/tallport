<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Email;
use App\Thread;
use App\User;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * CustomersController: the profile form (photo, legal hold, moving emails
 * between customers), limited customer visibility, the customer search
 * and the ajax actions.
 */
class CustomersControllerTest extends FeatureTestCase
{
    protected $agent;
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveFrom($from, $subject, $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from'    => $from,
            'to'      => $mailbox->email,
            'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $mailbox->id)->where('subject', $subject)->first();
    }

    protected function saveProfile($user, Customer $customer, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post('/customers/'.$customer->id.'/edit', array_merge(['_token' => csrf_token()], $data));
    }

    // Saving the profile.

    public function testPhotoIsResizedAndSaved()
    {
        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);

        $response = $this->saveProfile($this->agent, $customer, [
            'first_name' => 'Robin',
            'emails'     => ['robin@customer.example.org'],
            'photo_url'  => UploadedFile::fake()->image('me.png', 300, 200),
        ]);

        $response->assertRedirect(route('customers.update', ['id' => $customer->id]))->assertSessionHasNoErrors();
        $customer->refresh();
        $this->assertStringEndsWith('.jpg', $customer->photo_url);
        $this->assertSame(Customer::PHOTO_TYPE_UKNOWN, (int) $customer->photo_type, 'Their own photo: not replaced by Gravatar.');
        \Storage::disk('local')->assertExists(Customer::PHOTO_DIRECTORY.'/'.$customer->photo_url);
    }

    public function testBrokenImageIsRejected()
    {

        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        // A GIF signature and nothing an image library can read.
        $broken = UploadedFile::fake()->createWithContent('me.gif', 'GIF89a');

        $response = $this->saveProfile($this->agent, $customer, [
            'first_name' => 'Robin',
            'last_name'  => 'Changed',
            'emails'     => ['robin@customer.example.org'],
            'photo_url'  => $broken,
        ]);

        $response->assertRedirect(route('customers.update', ['id' => $customer->id]))->assertSessionHasErrors('photo_url');
        $this->assertNull($customer->fresh()->photo_url);
        $this->assertSame('Customer', $customer->fresh()->last_name, 'Nothing is saved.');
    }

    public function testInvalidEmailIsNotSaved()
    {
        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);

        $this->saveProfile($this->agent, $customer, ['first_name' => 'Robin', 'emails' => ['not an email']])
            ->assertRedirect(route('customers.update', ['id' => $customer->id]))
            ->assertSessionHasErrors('emails.0');

        $this->assertSame(['robin@customer.example.org'], $customer->emails()->pluck('email')->all());
    }

    public function testCustomerWithoutEmailCanBeEdited()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Phone', 'last_name' => 'Caller']);

        $this->actingAs($this->agent)->get('/customers/'.$customer->id.'/edit')->assertStatus(200)->assertSee('Phone');

        $this->saveProfile($this->agent, $customer, ['first_name' => 'Phone', 'last_name' => 'Person', 'emails' => ['']])
            ->assertSessionHasNoErrors();
        $this->assertSame('Person', $customer->fresh()->last_name);
        $this->assertSame(0, $customer->emails()->count());
    }

    public function testChannelCantBeChangedThroughTheForm()
    {
        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);

        $this->saveProfile($this->agent, $customer, [
            'first_name' => 'Robin',
            'emails'     => ['robin@customer.example.org'],
            'channel'    => 90,
            'channel_id' => 'abc',
        ])->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertNull($customer->channel);
        $this->assertNull($customer->channel_id);
    }

    public function testAdminLiftsLegalHold()
    {
        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $customer->retention_hold_at = now();
        $customer->retention_hold_by = $this->admin->id;
        $customer->save();

        // An agent's save leaves the hold alone.
        $this->saveProfile($this->agent, $customer, ['first_name' => 'Robin', 'emails' => ['robin@customer.example.org']]);
        $this->assertNotNull($customer->fresh()->retention_hold_at);

        $this->saveProfile($this->admin, $customer, ['first_name' => 'Robin', 'emails' => ['robin@customer.example.org']]);
        $customer->refresh();
        $this->assertNull($customer->retention_hold_at);
        $this->assertNull($customer->retention_hold_by);
    }

    public function testEmailMovedFromAnotherCustomerTakesItsConversations()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $conversation = $this->receiveFrom('sam@customer.example.org', 'Sam\'s question');
        $sam = Customer::find($conversation->customer_id);
        $sam->first_name = 'Sam';
        $sam->last_name = 'Other';
        $sam->save();
        // A phone conversation has no address: it goes along when Sam is left without one.
        $phone = $conversation->replicate();
        $phone->type = \App\Conversation::TYPE_PHONE;
        $phone->customer_email = null;
        $phone->number = $conversation->number + 1;
        $phone->save();

        $response = $this->saveProfile($this->agent, $robin, [
            'first_name' => 'Robin',
            'emails'     => ['robin@customer.example.org', 'sam@customer.example.org'],
        ]);

        $response->assertRedirect(route('customers.update', ['id' => $robin->id]));
        $flash = session('flash_success_unescaped');
        $this->assertStringContainsString('<strong>sam@customer.example.org</strong>', $flash);
        $this->assertStringContainsString('Sam Other', $flash);
        $this->assertStringContainsString($sam->url(), $flash);

        $this->assertSame($robin->id, Email::where('email', 'sam@customer.example.org')->first()->customer_id);
        $conversation->refresh();
        $this->assertSame($robin->id, $conversation->customer_id);
        $this->assertSame('sam@customer.example.org', $conversation->customer_email);
        $this->assertSame($robin->id, $phone->fresh()->customer_id);
        $line_item = $conversation->threads()->where('type', Thread::TYPE_LINEITEM)->where('action_type', Thread::ACTION_TYPE_CUSTOMER_CHANGED)->first();
        $this->assertNotNull($line_item);
        $this->assertSame($this->agent->id, $line_item->created_by_user_id);
    }

    /**
     * The customer who loses their only email keeps a name: the one given on
     * the form, else the profile's, else the email's local part.
     */
    public function testCustomerLosingTheirOnlyEmailIsNamed()
    {
        $cases = [
            ['form' => 'Robin', 'profile' => 'Robin', 'expected' => 'Robin'],
            ['form' => '', 'profile' => 'Robbie', 'expected' => 'Robbie'],
            ['form' => '', 'profile' => null, 'expected' => 'Sam.other'],
        ];
        foreach ($cases as $i => $case) {
            $address = 'sam.OTHER'.$i.'@customer.example.org';
            $profile = $this->createCustomer('robin'.$i.'@customer.example.org', ['first_name' => $case['profile'], 'last_name' => null]);
            $nameless = $this->createCustomer($address, ['first_name' => null, 'last_name' => null]);

            $this->saveProfile($this->agent, $profile, [
                'first_name' => $case['form'],
                'emails'     => ['robin'.$i.'@customer.example.org', strtolower($address)],
            ])->assertSessionHasNoErrors();

            $expected = $case['expected'] === 'Sam.other' ? 'Sam.other'.$i : $case['expected'];
            $this->assertSame($expected, $nameless->fresh()->first_name, 'Case '.$i);
        }
    }

    public function testMovingOneEmailLeavesTheOtherCustomersOtherConversations()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $first = $this->receiveFrom('Sam Other <sam@customer.example.org>', 'From the first address');
        $sam = Customer::find($first->customer_id);
        $sam->addEmail('sam@work.example.org');
        $second = $this->receiveFrom('Sam Other <sam@work.example.org>', 'From the second address');
        $this->assertSame($sam->id, $second->customer_id);

        $this->saveProfile($this->agent, $robin, [
            'first_name' => 'Robin',
            'emails'     => ['robin@customer.example.org', 'sam@customer.example.org'],
        ]);

        $this->assertSame($robin->id, $first->fresh()->customer_id);
        $second->refresh();
        $this->assertSame($sam->id, $second->customer_id, 'Sam still has this address.');
        $this->assertSame('sam@work.example.org', $second->customer_email, 'Replies still go to the address it came from.');
    }

    public function testRemovedEmailTakesItsConversationsToANewCustomer()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $robin->addEmail('robin@work.example.org');
        $conversation = $this->receiveFrom('robin@work.example.org', 'From work');
        $this->assertSame($robin->id, $conversation->customer_id);

        $this->saveProfile($this->agent, $robin, [
            'first_name' => 'Robin',
            'emails'     => ['robin@customer.example.org'],
        ])->assertSessionHasNoErrors();

        $email = Email::where('email', 'robin@work.example.org')->first();
        $this->assertNotSame($robin->id, $email->customer_id);
        $conversation->refresh();
        $this->assertSame($email->customer_id, $conversation->customer_id);
        $this->assertSame(1, $conversation->threads()->where('action_type', Thread::ACTION_TYPE_CUSTOMER_CHANGED)->count());
    }

    // Limited customer visibility (APP_LIMIT_USER_CUSTOMER_VISIBILITY).

    public function testLimitedVisibilityHidesCustomersOfOtherMailboxes()
    {
        config(['app.limit_user_customer_visibility' => true]);
        $robin = $this->receiveFrom('Robin Buyer <robin@customer.example.org>', 'Visible')->customer;
        $elsewhere = $this->createMailbox([], ['name' => 'Elsewhere']);
        $sam = $this->receiveFrom('Sam Other <sam@customer.example.org>', 'Hidden', $elsewhere)->customer;

        $this->actingAs($this->agent)->get('/customers/'.$robin->id.'/edit')->assertStatus(200);
        $this->actingAs($this->agent)->get('/customers/'.$sam->id.'/edit')->assertStatus(403);
        $this->actingAs($this->agent)->get('/customers/'.$sam->id)->assertStatus(403);
        $this->actingAs($this->agent)->get('/customers/'.$sam->id.'/merge')->assertStatus(403);
        $this->actingAs($this->admin)->get('/customers/'.$sam->id.'/edit')->assertStatus(200);

        // An email of a hidden customer can't be pulled into a visible one.
        $this->saveProfile($this->agent, $robin, [
            'first_name' => 'Robin',
            'emails'     => ['robin@customer.example.org', 'sam@customer.example.org'],
        ])->assertRedirect(route('customers.update', ['id' => $robin->id]))->assertSessionHasErrors('email');
        $this->assertSame($sam->id, Email::where('email', 'sam@customer.example.org')->first()->customer_id);

        // Search finds only customers with conversations in the agent's mailboxes.
        $results = $this->actingAs($this->agent)->get('/customers/ajax-search?search_by=all&q=customer.example.org')->json()['results'];
        $this->assertSame(['robin@customer.example.org'], array_column($results, 'id'));
        $results = $this->actingAs($this->admin)->get('/customers/ajax-search?search_by=all&q=customer.example.org')->json()['results'];
        $this->assertCount(2, $results);

        // Creating a customer with a hidden customer's email is refused.
        $response = $this->postAjax($this->agent, '/customers/ajax', ['action' => 'create', 'first_name' => 'Sam', 'email' => 'sam@customer.example.org'])->json();
        $this->assertSame('error', $response['status']);
        $this->assertSame('The specified email belongs to a customer from an inaccessible mailbox.', $response['msg']);

        $response = $this->postAjax($this->agent, '/customers/ajax', ['action' => 'create', 'first_name' => 'Kim', 'email' => 'kim@customer.example.org'])->json();
        $this->assertSame('success', $response['status']);
        $this->assertSame('Kim', Customer::getByEmail('kim@customer.example.org')->first_name);
    }

    public function testAgentSeeingOnlyAssignedConversations()
    {
        $robin_first = $this->receiveFrom('Robin Buyer <robin@customer.example.org>', 'Assigned to me');
        $this->receiveFrom('Robin Buyer <robin@customer.example.org>', 'Not assigned');
        $robin_first->user_id = $this->agent->id;
        $robin_first->save();
        $this->agent->permissions = [User::PERM_ONLY_ASSIGNED_TICKETS => true];
        $this->agent->save();

        $this->actingAs($this->agent)->get('/customers/'.$robin_first->customer_id)
            ->assertStatus(200)
            ->assertSee('Assigned to me')
            ->assertDontSee('Not assigned');
    }

    // Search.

    public function testSearchOptions()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin', 'last_name' => 'Buyer']);
        $robin->addEmail('robin@work.example.org');
        $robin->setPhones(['+1 (555) 123-4567']);
        $robin->save();
        $phone_only = Customer::createWithoutEmail(['first_name' => 'Robin', 'last_name' => 'Phone']);
        $phone_only->setPhones(['555-999']);
        $phone_only->save();

        $search = function ($query) {
            return $this->actingAs($this->agent)->get('/customers/ajax-search?'.http_build_query($query))->json()['results'];
        };

        // Excluding an address or a customer (e.g. the customer being merged).
        $this->assertSame(['robin@work.example.org'], array_column($search(['search_by' => 'email', 'q' => 'robin', 'exclude_email' => 'robin@customer.example.org']), 'id'));
        $this->assertSame([], $search(['search_by' => 'email', 'q' => 'robin', 'exclude_id' => $robin->id]));

        // Customers without an email only with allow_non_emails.
        $this->assertSame(['Robin Buyer'], array_unique(array_column($search(['search_by' => 'all', 'q' => 'Robin', 'show_fields' => 'name']), 'text')));
        $with_non_emails = $search(['search_by' => 'all', 'q' => 'Phone', 'show_fields' => 'name', 'allow_non_emails' => 1, 'use_id' => 1]);
        $this->assertSame([['id' => $phone_only->id, 'text' => 'Robin Phone']], $with_non_emails);

        // By phone: the matching number is the result.
        $this->assertSame([['id' => '+1 (555) 123-4567', 'text' => '+1 (555) 123-4567 — Robin Buyer']], $search(['search_by' => 'phone', 'q' => '555 123', 'show_fields' => 'phone']));
        $this->assertSame([], $search(['search_by' => 'phone', 'q' => 'abc', 'show_fields' => 'phone']));

        // show_fields=all: name and address.
        $this->assertSame(
            ['Robin Buyer <robin@customer.example.org>', 'Robin Buyer <robin@work.example.org>'],
            array_column($search(['search_by' => 'email', 'q' => 'robin@', 'show_fields' => 'all']), 'text')
        );
    }

    // Ajax.

    public function testUnknownAjaxAction()
    {
        $response = $this->postAjax($this->agent, '/customers/ajax', ['action' => 'nonsense'])->json();

        $this->assertSame(['status' => 'error', 'msg' => 'Unknown action'], $response);
    }

    public function testCreateNeedsANameAndValidEmail()
    {
        $response = $this->postAjax($this->agent, '/customers/ajax', ['action' => 'create', 'first_name' => '', 'email' => 'not an email'])->json();

        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('first name', strtolower($response['msg']));
        $this->assertStringContainsString('email', strtolower($response['msg']));
    }

    public function testMergeWithItselfIsRefused()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);

        \Session::start();
        $this->actingAs($this->agent)->from('/customers/'.$robin->id.'/merge')->post('/customers/'.$robin->id.'/merge', [
            '_token'       => csrf_token(),
            'customer2_id' => $robin->id,
        ])->assertSessionHasErrors('customer2_id');

        $this->assertNotNull(Customer::find($robin->id));
    }
}
