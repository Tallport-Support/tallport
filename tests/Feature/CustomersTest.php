<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Email;
use Tests\FeatureTestCase;

/**
 * Customer profiles: creating, viewing, editing, merging and searching.
 */
class CustomersTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveFrom($from, $subject)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'    => $from,
            'to'      => $this->mailbox->email,
            'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->where('subject', $subject)->first();
    }

    public function testCreateCustomer()
    {
        $response = $this->postAjax($this->agent, '/customers/ajax', [
            'action'     => 'create',
            'first_name' => 'Robin',
            'last_name'  => 'Buyer',
            'email'      => 'robin@customer.example.org',
        ]);

        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));
        $customer = Customer::getByEmail('robin@customer.example.org');
        $this->assertNotNull($customer);
        $this->assertSame('Robin', $customer->first_name);
        $this->assertSame('Buyer', $customer->last_name);
    }

    public function testCreateCustomerWithExistingEmailFails()
    {
        $this->createCustomer('robin@customer.example.org');

        $response = $this->postAjax($this->agent, '/customers/ajax', [
            'action'     => 'create',
            'first_name' => 'Robin',
            'email'      => 'robin@customer.example.org',
        ]);

        $this->assertSame('error', $response->json()['status']);
        $this->assertStringContainsString('already been taken', $response->json()['msg']);
        $this->assertSame(1, Email::where('email', 'robin@customer.example.org')->count());
    }

    public function testProfileListsTheirConversations()
    {
        $this->receiveFrom('Robin Buyer <robin@customer.example.org>', 'Robin\'s question');
        $this->receiveFrom('Sam Other <sam@customer.example.org>', 'Sam\'s question');
        $customer = Customer::getByEmail('robin@customer.example.org');

        $response = $this->actingAs($this->agent)->get('/customers/'.$customer->id);

        $response->assertStatus(200);
        $response->assertSee('Robin Buyer');
        $response->assertSee('Robin&#039;s question');
        $response->assertDontSee('Sam&#039;s question');
    }

    public function testProfileHidesConversationsFromInaccessibleMailboxes()
    {
        $this->receiveFrom('Robin Buyer <robin@customer.example.org>', 'Robin\'s question');
        $customer = Customer::getByEmail('robin@customer.example.org');
        $outsider = $this->createUser();

        $response = $this->actingAs($outsider)->get('/customers/'.$customer->id);

        $response->assertStatus(200);
        $response->assertDontSee('Robin&#039;s question');
    }

    public function testEditCustomer()
    {
        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);

        $this->actingAs($this->agent)->get('/customers/'.$customer->id.'/edit')->assertStatus(200)->assertSee('robin@customer.example.org');

        \Session::start();
        $response = $this->actingAs($this->agent)->post('/customers/'.$customer->id.'/edit', [
            '_token'     => csrf_token(),
            'first_name' => 'Robin',
            'last_name'  => 'Buyer',
            'job_title'  => 'Purchaser',
            'emails'     => ['robin@customer.example.org', 'robin@work.example.org'],
        ]);

        $response->assertRedirect(route('customers.update', ['id' => $customer->id]));
        $customer->refresh();
        $this->assertSame('Buyer', $customer->last_name);
        $this->assertSame('Purchaser', $customer->job_title);
        $this->assertEquals(
            ['robin@customer.example.org', 'robin@work.example.org'],
            $customer->emails()->orderBy('email')->pluck('email')->all()
        );

        // Mail from the added address now belongs to the same customer.
        $conversation = $this->receiveFrom('robin@work.example.org', 'From work');
        $this->assertEquals($customer->id, $conversation->customer_id);
    }

    public function testMergeCustomers()
    {
        $first = $this->receiveFrom('Robin Buyer <robin@customer.example.org>', 'First address');
        $second = $this->receiveFrom('R. Buyer <robin@work.example.org>', 'Second address');
        $robin = Customer::find($first->customer_id);
        $duplicate = Customer::find($second->customer_id);
        $this->assertNotEquals($robin->id, $duplicate->id);

        \Session::start();
        $response = $this->actingAs($this->agent)->post('/customers/'.$robin->id.'/merge', [
            '_token'       => csrf_token(),
            'customer2_id' => $duplicate->id,
        ]);

        $response->assertRedirect(route('customers.update', ['id' => $robin->id]));
        $this->assertEquals($robin->id, $second->fresh()->customer_id);
        $this->assertEquals($robin->id, $second->threads()->first()->customer_id);
        $this->assertEquals(
            ['robin@customer.example.org', 'robin@work.example.org'],
            $robin->emails()->orderBy('email')->pluck('email')->all()
        );
        $this->assertNull(Customer::find($duplicate->id), 'The merged customer should be deleted.');
    }

    public function testSearchByNameAndEmail()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin', 'last_name' => 'Buyer']);
        $this->createCustomer('sam@customer.example.org', ['first_name' => 'Sam', 'last_name' => 'Other']);

        // Results feed recipient fields, so their id is the email address.
        $by_name = $this->actingAs($this->agent)->get('/customers/ajax-search?search_by=all&q=Robin+Buy')->json();
        $this->assertSame([['id' => 'robin@customer.example.org', 'text' => 'Robin Buyer <robin@customer.example.org>']], $by_name['results']);

        $by_email = $this->actingAs($this->agent)->get('/customers/ajax-search?search_by=email&q=robin@customer')->json();
        $this->assertSame(['robin@customer.example.org'], array_column($by_email['results'], 'id'));

        // use_id asks for customer ids instead (e.g. when picking a customer to merge).
        $by_id = $this->actingAs($this->agent)->get('/customers/ajax-search?search_by=all&q=Robin&use_id=1')->json();
        $this->assertSame([$robin->id], array_column($by_id['results'], 'id'));
    }
}
