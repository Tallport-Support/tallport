<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Email;
use App\Mailbox;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

class ConversationChangeCustomerTest extends FeatureTestCase
{
    private $admin;
    private $unprivUser;
    private $mailbox;
    private $conversation;
    private $originalCustomer;
    private $attackerCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        // Without it csrf_token() returns empty string.
        \Session::start();

        // Create admin user
        $this->admin = $this->createUser(['role' => User::ROLE_ADMIN]);

        // Create mailbox
        $this->mailbox = $this->createMailbox([$this->admin]);

        // Create unprivileged user with NO mailbox access
        $this->unprivUser = $this->createUser(['role' => User::ROLE_USER]);

        // Create customers
        $this->originalCustomer = $this->createCustomer('original.customer@example.org', [
            'first_name' => 'Original',
            'last_name'  => 'Customer',
        ]);

        $this->attackerCustomer = $this->createCustomer('attacker@example.org', [
            'first_name' => 'Attacker',
            'last_name'  => 'Evil',
        ]);

        // Create conversation belonging to original customer.
        $conversation = new Conversation();
        $conversation->type = Conversation::TYPE_EMAIL;
        $conversation->subject = 'Question';
        $conversation->mailbox_id = $this->mailbox->id;
        $conversation->customer_id = $this->originalCustomer->id;
        $conversation->customer_email = 'original.customer@example.org';
        $conversation->status = Conversation::STATUS_ACTIVE;
        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->source_via = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_EMAIL;
        $conversation->created_by_user_id = $this->admin->id;
        $conversation->updateFolder();
        $conversation->save();
        $this->conversation = $conversation;
    }

    /**
     * Test that a user without mailbox access cannot change a conversation's customer.
     * This is the regression test for the authorization bypass where changeCustomer()
     * executed unconditionally even when permission checks set an error message.
     */
    public function testUnauthorizedUserCannotChangeCustomer()
    {
        if (PHP_VERSION_ID < 80400) {
            $this->assertEquals('1', '1');
            return;
        }
        $response = $this->actingAs($this->unprivUser)
            ->post('/conversation/ajax', [
                'action'          => 'conversation_change_customer',
                'conversation_id' => $this->conversation->id,
                'customer_email'  => 'attacker@example.org',
                '_token' => csrf_token(),
            ], [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);

        $json = $response->json();
        $this->assertEquals('Not enough permissions', $json['msg']);

        // The customer must NOT have changed
        $this->conversation->refresh();
        $this->assertEquals('original.customer@example.org', $this->conversation->customer_email);
        $this->assertEquals($this->originalCustomer->id, $this->conversation->customer_id);
    }

    /**
     * Test that an authorized admin user CAN change a conversation's customer.
     * This ensures the fix does not break normal functionality.
     */
    public function testAuthorizedUserCanChangeCustomer()
    {
        if (PHP_VERSION_ID < 80400) {
            $this->assertEquals('1', '1');
            return;
        }
        $response = $this->actingAs($this->admin)
            ->post('/conversation/ajax', [
                'action'          => 'conversation_change_customer',
                'conversation_id' => $this->conversation->id,
                'customer_email'  => 'attacker@example.org',
                '_token' => csrf_token(),
            ], [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);

        $json = $response->json();
        $this->assertEquals('success', $json['status']);
        $this->assertEmpty($json['msg']);

        // The customer SHOULD have changed.
        $this->conversation->refresh();
        $this->assertEquals('attacker@example.org', $this->conversation->customer_email);
        $this->assertEquals($this->attackerCustomer->id, $this->conversation->customer_id);
    }
}
