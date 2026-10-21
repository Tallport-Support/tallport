<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * The inbox's addresses (App\Misc\Addresses): /mailbox/support/drafts/1042/58231.
 */
class AddressesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
    }

    protected function conversation($subject)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function page($url)
    {
        $this->withUnencryptedCookie('tallport_narrow', '0');

        return $this->actingAs($this->agent)->get($url)->assertOk();
    }

    public function testShortNames()
    {
        $this->assertSame('support', $this->mailbox->slug);
        $this->assertSame('support-2', $this->createMailbox([], ['name' => 'Support'])->slug, 'Another mailbox\'s.');
        $this->assertSame('settings-mailbox', $this->createMailbox([], ['name' => 'Settings'])->slug, 'A word of the app\'s own addresses.');
        $this->assertSame('2024-mailbox', $this->createMailbox([], ['name' => '2024'])->slug, 'Not a number: those are IDs.');

        // Renamed: the new name, and the old one still leads to it.
        $this->mailbox->name = 'Customer Care';
        $this->mailbox->save();
        $this->assertSame('customer-care', $this->mailbox->fresh()->slug);
        $this->assertSame($this->mailbox->id, \App\Misc\Addresses::findMailbox('support')->id);
        $this->page('/mailbox/support/unassigned')->assertSee('data-page-url="'.url('/mailbox/customer-care/unassigned'), false);
    }

    /**
     * A folder, and a conversation beside its list (not its own folder's), perhaps at a message.
     */
    public function testFolderConversationAndMessage()
    {
        $conversation = $this->conversation('Where is my order?');
        $conversation->star($this->agent);
        $starred = $this->mailbox->folders()->where('type', Folder::TYPE_STARRED)->where('user_id', $this->agent->id)->first();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();

        $this->page('/mailbox/support/starred')->assertSee('data-folder_id="'.$starred->id.'"', false);
        $page = $this->page('/mailbox/support/starred/'.$conversation->number.'/'.$thread->id);
        $page->assertSee('Where is my order?')->assertSee('data-folder_id="'.$starred->id.'"', false)
            ->assertSee('data-focus-thread="'.$thread->id.'"', false)
            ->assertSee('data-page-url="'.url('/mailbox/support/starred/'.$conversation->number.'/'.$thread->id).'"', false);
        // Its rows link to addresses beside the folder too.
        $page->assertSee('href="'.url('/mailbox/support/starred/'.$conversation->number).'"', false);

        $this->actingAs($this->agent)->get('/mailbox/support/starred/999999')->assertNotFound();

        // The number shown: the ID, unless numbers are custom (then the stored one).
        Conversation::where('id', $conversation->id)->update(['number' => 5000]);
        $this->page('/mailbox/support/starred/'.$conversation->id)->assertSee('Where is my order?');
        config(['app.custom_number' => true]);
        Conversation::$custom_number_cache = null;
        $this->page('/mailbox/support/starred/5000')->assertSee('Where is my order?');
        Conversation::$custom_number_cache = null;
        $this->actingAs($this->agent)->get('/mailbox/nonexistent/unassigned')->assertNotFound();
    }

    /**
     * The link to share: its own folder for whoever opens it, at the message; the page then
     * shows its full address. Each message's menu copies it.
     */
    public function testTheLinkToShare()
    {
        $conversation = $this->conversation('Broken zipper');
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $page = $this->page('/conversation/'.$conversation->id.'/'.$thread->id);
        $page->assertSee('data-focus-thread="'.$thread->id.'"', false)
            ->assertSee('data-page-url="'.url('/mailbox/support/unassigned/'.$conversation->number.'/'.$thread->id).'"', false);
        $this->assertStringContainsString('copyToClipboard('.\Illuminate\Support\Js::from(url('/conversation/'.$conversation->id.'/'.$thread->id)).')', $page->getContent(), 'Copy Link.');
    }

    /**
     * Older addresses (IDs) still work; the page shows its address now. All Mailboxes by word.
     */
    public function testOlderAddressesAndAllMailboxes()
    {
        $conversation = $this->conversation('Question');
        $unassigned = $this->mailbox->folders()->where('type', Folder::TYPE_UNASSIGNED)->first();

        // Wide, a folder opens at its first conversation: at that address.
        $this->page('/mailbox/'.$this->mailbox->id.'/'.$unassigned->id)->assertSee('data-page-url="'.url('/mailbox/support/unassigned/'.$conversation->number).'"', false);
        $this->page('/mailbox/'.$this->mailbox->id)->assertSee('data-folder_id="'.$unassigned->id.'"', false);
        $this->assertSame(url('/mailbox/support/mine'), $this->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $this->agent->id)->first()->url($this->mailbox->id));

        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $this->page('/mailbox/all/unassigned/'.$conversation->number)->assertSee('Question')
            ->assertSee('data-folder_id="'.-Folder::TYPE_UNASSIGNED.'"', false)
            ->assertSee('data-page-url="'.url('/mailbox/all/unassigned/'.$conversation->number).'"', false);
        $this->page('/mailbox/all/'.-Folder::TYPE_UNASSIGNED)->assertSee('data-page-url="'.url('/mailbox/all/unassigned/'.$conversation->number).'"', false);
    }
}
