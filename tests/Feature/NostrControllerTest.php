<?php

namespace Tests\Feature;

use App\Customer;
use App\Jobs\NostrTask;
use App\Nostr\CustomerKey;
use App\Nostr\Keys;
use App\Nostr\MailboxKey;
use App\Nostr\NostrMailbox;
use Illuminate\Support\Facades\Bus;
use Tests\FeatureTestCase;

/**
 * NostrController: importing and replacing a mailbox's key, the settings
 * form's checks, publishing to relays (offline: a closed port) and a
 * customer's keys.
 */
class NostrControllerTest extends FeatureTestCase
{
    const UNREACHABLE = 'ws://127.0.0.1:1';

    protected $admin;
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin(['password' => \Hash::make('correct horse')]);
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function settings(array $data, $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;

        return $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id.'/nostr', $data);
    }

    protected function config($mailbox = null)
    {
        return NostrMailbox::forMailbox(($mailbox ?: $this->mailbox)->id, false);
    }

    protected function saveSettings(array $data)
    {
        return $this->settings(array_merge([
            'action' => 'save', 'enabled' => '1', 'inbox_relays' => self::UNREACHABLE, 'announce_relays' => '',
            'profile_name' => 'Support', 'auto_reply_enabled' => '0', 'auto_reply_text' => '', 'reopen_days' => '30',
        ], $data));
    }

    // The mailbox's key.

    public function testImportKey()
    {
        $this->settings(['action' => 'import', 'nsec' => 'nsec1notakey'])
            ->assertSessionHas('flash_error_floating', 'This is not a valid private key (nsec or hex).');
        $this->assertNull($this->config());

        $private = Keys::generatePrivateKey();
        $this->settings(['action' => 'import', 'nsec' => Keys::nsec($private)])
            ->assertRedirect(route('mailboxes.nostr', ['id' => $this->mailbox->id]))
            ->assertSessionHas('flash_success_floating');
        $cfg = $this->config();
        $this->assertSame(Keys::pubkeyFromPrivate($private), $cfg->pubkey);
        $this->assertSame($private, $cfg->getPrivateKey());

        // The same key can't serve a second mailbox.
        $other = $this->createMailbox();
        $this->settings(['action' => 'import', 'nsec' => $private], $other)
            ->assertSessionHas('flash_error_floating', 'This key is already used by a mailbox.');
        $this->assertNull($this->config($other));
    }

    public function testImportedKeyCantBeAnotherMailboxsRetiredKey()
    {
        $retired_private = Keys::generatePrivateKey();
        $retired = new MailboxKey();
        $retired->mailbox_id = $this->mailbox->id;
        $retired->pubkey = Keys::pubkeyFromPrivate($retired_private);
        $retired->private_key = encrypt($retired_private);
        $retired->retired_at = now();
        $retired->save();

        $other = $this->createMailbox();
        $this->settings(['action' => 'import', 'nsec' => $retired_private], $other)
            ->assertSessionHas('flash_error_floating', 'This key is already used by a mailbox.');
        $this->assertNull($this->config($other));
    }

    public function testReplaceWithAnImportedKey()
    {
        Bus::fake([NostrTask::class]);

        $this->settings(['action' => 'replace', 'password' => 'correct horse', 'confirm' => 'REPLACE'])
            ->assertSessionHas('flash_error_floating', 'There is no key to replace yet.');

        $this->settings(['action' => 'generate']);
        $this->saveSettings([]);
        $first = $this->config()->pubkey;
        Bus::assertDispatched(NostrTask::class);

        $replace = ['action' => 'replace', 'password' => 'correct horse', 'confirm' => 'REPLACE', 'replace_mode' => 'import'];
        $this->settings(array_merge($replace, ['nsec' => 'garbage']))
            ->assertSessionHas('flash_error_floating', 'This is not a valid private key (nsec or hex).');
        $this->settings(array_merge($replace, ['nsec' => $this->config()->getPrivateKey()]))
            ->assertSessionHas('flash_error_floating', 'This key is already used by a mailbox.');
        $this->assertSame($first, $this->config()->pubkey);

        $new_private = Keys::generatePrivateKey();
        $this->settings(array_merge($replace, ['nsec' => Keys::nsec($new_private)]))->assertSessionHas('flash_success_floating');
        $cfg = $this->config();
        $this->assertSame(Keys::pubkeyFromPrivate($new_private), $cfg->pubkey);
        $this->assertSame([$first], $cfg->getRetiredKeys()->pluck('pubkey')->all());
        // Enabled with relays: the new key is announced.
        Bus::assertDispatchedTimes(NostrTask::class, 2);
    }

    public function testRevealAndDiagnoseNeedAKey()
    {
        $this->settings(['action' => 'reveal', 'password' => 'correct horse'])
            ->assertRedirect(route('mailboxes.nostr', ['id' => $this->mailbox->id]))
            ->assertSessionMissing('nostr_reveal_nsec');

        $this->settings(['action' => 'diagnose'])
            ->assertSessionHas('flash_error_floating', 'Generate a key first.')
            ->assertSessionMissing('nostr_diagnose');
    }

    public function testDeletingAnUnknownRetiredKey()
    {
        $this->settings(['action' => 'generate']);

        $this->settings(['action' => 'delete_key', 'key_id' => 999999, 'password' => 'correct horse', 'confirm' => 'DELETE'])
            ->assertSessionHas('flash_error_floating', 'Retired key not found.');
    }

    public function testAnnounce()
    {
        $this->settings(['action' => 'announce'])
            ->assertSessionHas('flash_error_floating', 'Generate a key and configure relays first.');

        Bus::fake([NostrTask::class]);
        $this->settings(['action' => 'generate']);
        $this->saveSettings([]);

        // No relay can be reached.
        $this->settings(['action' => 'announce'])
            ->assertRedirect(route('mailboxes.nostr', ['id' => $this->mailbox->id]))
            ->assertSessionHas('flash_error_floating', 'No relay accepted the events.');
        $this->assertNotNull($this->config()->last_announced_at);
    }

    // Settings form.

    public function testSettingsChecks()
    {
        $this->saveSettings([])->assertSessionHasErrors('enabled');
        $this->assertNull($this->config());

        $this->settings(['action' => 'generate']);
        $this->saveSettings(['enabled' => '0', 'auto_reply_enabled' => '1', 'auto_reply_text' => ' '])
            ->assertSessionHasErrors('auto_reply_text');
        $this->assertFalse((bool) $this->config()->auto_reply_enabled);

        Bus::fake([NostrTask::class]);
        $this->saveSettings(['enabled' => '0', 'auto_reply_enabled' => '1', 'auto_reply_text' => 'We will answer soon.', 'reopen_days' => '7'])
            ->assertSessionHasNoErrors();
        $cfg = $this->config();
        $this->assertSame('We will answer soon.', $cfg->auto_reply_text);
        $this->assertSame(7, (int) $cfg->reopen_days);
        Bus::assertNotDispatched(NostrTask::class, 'Disabled: nothing is announced.');
    }

    // A customer's keys.

    public function testCustomerKeys()
    {
        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $url = '/customers/'.$customer->id.'/nostr';

        $this->postForm($this->agent, $url, ['action' => 'add', 'pubkey' => 'npub1nonsense'])
            ->assertRedirect(route('customers.nostr', ['id' => $customer->id]))
            ->assertSessionHas('flash_error_floating', 'This is not a valid public key. Use an npub, nprofile or hex key.');
        $this->assertCount(0, CustomerKey::forCustomer($customer->id));

        $pubkey = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
        $this->postForm($this->agent, $url, ['action' => 'add', 'pubkey' => $pubkey, 'label' => 'Phone']);
        $key = CustomerKey::byPubkey($pubkey);
        $this->assertSame($customer->id, $key->customer_id);

        $this->postForm($this->agent, $url, ['action' => 'label', 'key_id' => $key->id, 'label' => '  Old phone  '])
            ->assertSessionHas('flash_success_floating', 'Label updated');
        $this->assertSame('Old phone', $key->fresh()->label);
        $this->postForm($this->agent, $url, ['action' => 'label', 'key_id' => $key->id, 'label' => '']);
        $this->assertNull($key->fresh()->label);

        // Another customer's key: not moved, a link to them instead.
        $sam = $this->createCustomer('sam@customer.example.org', ['first_name' => 'Sam', 'last_name' => 'Other']);
        $this->postForm($this->agent, '/customers/'.$sam->id.'/nostr', ['action' => 'add', 'pubkey' => Keys::npub($pubkey)])
            ->assertSessionHas('flash_error_floating', 'This key already belongs to <a href="'.$customer->url().'">Robin Customer</a>. Merge the two customers if they are the same person.');
        $this->assertSame($customer->id, $key->fresh()->customer_id);

        // Labels of another customer's keys can't be changed through this one.
        $this->postForm($this->agent, '/customers/'.$sam->id.'/nostr', ['action' => 'label', 'key_id' => $key->id, 'label' => 'Hijacked'])
            ->assertSessionMissing('flash_success_floating');
        $this->assertNull($key->fresh()->label);
    }

    public function testCustomerKeysFollowLimitedVisibility()
    {
        $this->knownBug('K4');

        config(['app.limit_user_customer_visibility' => true]);
        $elsewhere = $this->createMailbox([], ['name' => 'Elsewhere']);
        $this->receiveEmail($elsewhere, $this->makeEmail(['from' => 'sam@customer.example.org', 'to' => $elsewhere->email]));
        $sam = Customer::getByEmail('sam@customer.example.org');
        $this->actingAs($this->agent)->get('/customers/'.$sam->id.'/edit')->assertStatus(403);

        $this->actingAs($this->agent)->get('/customers/'.$sam->id.'/nostr')->assertStatus(403);
        $pubkey = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
        $this->postForm($this->agent, '/customers/'.$sam->id.'/nostr', ['action' => 'add', 'pubkey' => $pubkey])->assertStatus(403);
        $this->assertNull(CustomerKey::byPubkey($pubkey));
    }
}
