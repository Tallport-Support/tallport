<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\SavedReply;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * Saved replies (SavedRepliesController): removing a file, and the ajax
 * actions' refusals.
 */
class SavedRepliesControllerTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
    }

    protected function savedReply($name, $text = null, $mailbox = null)
    {
        $saved_reply = new SavedReply();
        $saved_reply->mailbox_id = ($mailbox ?: $this->mailbox)->id;
        $saved_reply->name = $name;
        $saved_reply->text = $text ?? '<p>'.$name.'</p>';
        $saved_reply->save();

        return $saved_reply;
    }

    protected function ajax($user, array $data)
    {
        return $this->postAjax($user, route('saved_replies.ajax'), $data);
    }

    public function testRemovingAFile()
    {
        \Session::start();
        $save = function (array $data) {
            return $this->actingAs($this->admin)->post(route('mailboxes.saved_replies.save', ['id' => $this->mailbox->id]), array_merge(['_token' => csrf_token(), 'name' => 'Label'], $data));
        };
        $save(['files' => [
            UploadedFile::fake()->createWithContent('label.txt', 'Label'),
            UploadedFile::fake()->createWithContent('terms.txt', 'Terms'),
        ]])->assertRedirect();
        $saved_reply = SavedReply::where('name', 'Label')->first();
        [$label_id, $terms_id] = $saved_reply->attachments;

        $save(['saved_reply_id' => $saved_reply->id, 'remove_attachments' => [$label_id, 999999]])->assertRedirect();

        $this->assertSame([$terms_id], $saved_reply->fresh()->attachments);
        $this->assertNull(Attachment::find($label_id));
        $this->assertNotNull(Attachment::find($terms_id));
    }

    public function testGetRefusals()
    {
        $other_mailbox = $this->createMailbox([], ['name' => 'Sales']);
        $saved_reply = $this->savedReply('Sales pitch', null, $other_mailbox);

        $this->ajax($this->agent, ['action' => 'get', 'mailbox_id' => $other_mailbox->id, 'saved_reply_id' => $saved_reply->id])
            ->assertJson(['status' => 'error', 'msg' => 'Not enough permissions']);
        $this->ajax($this->agent, ['action' => 'nonsense'])
            ->assertJson(['status' => 'error', 'msg' => 'Unknown error occurred']);
    }

    /**
     * A conversation the user can't see gives no values: its variables stay.
     */
    public function testConversationTheUserCantSeeIsNotUsed()
    {
        $saved_reply = $this->savedReply('Number', '<p>Ticket #{%conversation.number%}</p>');
        $other_mailbox = $this->createMailbox([], ['name' => 'Sales']);
        $this->receiveEmail($other_mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $other_mailbox->email, 'subject' => 'Hidden']));
        $hidden = Conversation::where('mailbox_id', $other_mailbox->id)->first();
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Visible']));
        $visible = Conversation::where('mailbox_id', $this->mailbox->id)->first();

        $text = $this->ajax($this->agent, ['action' => 'get', 'mailbox_id' => $this->mailbox->id, 'saved_reply_id' => $saved_reply->id, 'conversation_id' => $hidden->id])
            ->assertJsonPath('status', 'success')->json('text');
        $this->assertStringContainsString('{%conversation.number%}', $text);

        $text = $this->ajax($this->agent, ['action' => 'get', 'mailbox_id' => $this->mailbox->id, 'saved_reply_id' => $saved_reply->id, 'conversation_id' => $visible->id])->json('text');
        $this->assertStringContainsString('Ticket #'.$visible->number, $text);
    }

    public function testSaveFromReply()
    {
        $this->savedReply('Thanks');

        $this->ajax($this->agent, ['action' => 'save_from_reply', 'mailbox_id' => $this->mailbox->id, 'name' => 'Mine', 'text' => '<p>x</p>'])
            ->assertJson(['status' => 'error', 'msg' => 'Not enough permissions']);
        $this->ajax($this->admin, ['action' => 'save_from_reply', 'mailbox_id' => $this->mailbox->id, 'name' => '  ', 'text' => '<p>x</p>'])
            ->assertJson(['status' => 'error', 'msg' => 'Enter a name']);
        $this->ajax($this->admin, ['action' => 'save_from_reply', 'mailbox_id' => $this->mailbox->id, 'name' => 'Thanks', 'text' => '<p>x</p>'])
            ->assertJson(['status' => 'error', 'msg' => 'A saved reply with this name already exists in this mailbox.']);
        $this->assertSame(1, SavedReply::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testSortNeedsManaging()
    {
        $first = $this->savedReply('First');
        $second = $this->savedReply('Second');
        $order = [$first->fresh()->sort_order, $second->fresh()->sort_order];

        $this->ajax($this->agent, ['action' => 'sort', 'mailbox_id' => $this->mailbox->id, 'saved_replies' => [$second->id, $first->id]])
            ->assertJson(['status' => 'error', 'msg' => 'Not enough permissions']);
        $this->assertSame($order, [$first->fresh()->sort_order, $second->fresh()->sort_order]);
    }
}
