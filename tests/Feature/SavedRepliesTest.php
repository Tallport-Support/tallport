<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\SavedReply;
use App\User;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * Saved replies: managed per mailbox, put in replies with variables and
 * files, global ones in every mailbox, a default reply template.
 */
class SavedRepliesTest extends FeatureTestCase
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

    protected function savedReply($attributes, $mailbox = null)
    {
        $saved_reply = new SavedReply();
        $saved_reply->mailbox_id = ($mailbox ?: $this->mailbox)->id;
        $saved_reply->name = $attributes['name'];
        $saved_reply->text = $attributes['text'] ?? '<p>'.$attributes['name'].'</p>';
        foreach (['parent_saved_reply_id', 'global', 'auto_load', 'sort_order', 'attachments'] as $field) {
            if (isset($attributes[$field])) {
                $saved_reply->$field = $attributes[$field];
            }
        }
        $saved_reply->save();

        return $saved_reply;
    }

    protected function ajax($user, $data)
    {
        return $this->postAjax($user, route('saved_replies.ajax'), $data);
    }

    public function testManage()
    {
        \Session::start();
        $this->actingAs($this->admin)->get(route('mailboxes.saved_replies', ['id' => $this->mailbox->id]))->assertOk()->assertSee('New Saved Reply');
        $this->get(route('mailboxes.saved_replies.create', ['id' => $this->mailbox->id]))->assertOk();

        $this->post(route('mailboxes.saved_replies.save', ['id' => $this->mailbox->id]), [
            '_token' => csrf_token(), 'name' => 'Shipping', 'text' => '<p>Shipping</p>',
        ])->assertRedirect(route('mailboxes.saved_replies', ['id' => $this->mailbox->id]));
        $category = SavedReply::where('name', 'Shipping')->first();

        $this->post(route('mailboxes.saved_replies.save', ['id' => $this->mailbox->id]), [
            '_token' => csrf_token(), 'name' => 'Where is my order', 'text' => '<p>Hi {%customer.firstName%}<script>x</script></p>',
            'parent_saved_reply_id' => $category->id, 'global' => 1, 'auto_load' => 1,
            'files' => [UploadedFile::fake()->createWithContent('label.txt', 'Label')],
        ])->assertRedirect();
        $reply = SavedReply::where('name', 'Where is my order')->first();
        $this->assertSame($category->id, $reply->parent_saved_reply_id);
        $this->assertTrue($reply->global);
        $this->assertTrue($reply->auto_load);
        $this->assertStringNotContainsString('<script>', $reply->text);
        $this->assertCount(1, $reply->attachments);

        $this->post(route('mailboxes.saved_replies.save', ['id' => $this->mailbox->id]), ['_token' => csrf_token(), 'name' => 'Shipping'])
            ->assertSessionHasErrors('name');
        // Not under itself or what's under it.
        $this->post(route('mailboxes.saved_replies.save', ['id' => $this->mailbox->id]), [
            '_token' => csrf_token(), 'saved_reply_id' => $category->id, 'name' => 'Shipping', 'parent_saved_reply_id' => $reply->id,
        ]);
        $this->assertNull($category->fresh()->parent_saved_reply_id);

        $this->get(route('mailboxes.saved_replies.edit', ['id' => $this->mailbox->id, 'saved_reply_id' => $reply->id]))->assertOk()->assertSee('label.txt');
        $this->get(route('mailboxes.saved_replies', ['id' => $this->mailbox->id]))->assertSee('Where is my order')->assertSee('Global');

        // Order of a level.
        $other = $this->savedReply(['name' => 'Refunds']);
        $this->ajax($this->admin, ['action' => 'sort', 'mailbox_id' => $this->mailbox->id, 'saved_replies' => [$other->id, $category->id]])->assertJsonPath('status', 'success');
        $this->assertSame(['Refunds', 'Shipping'], SavedReply::ofMailbox($this->mailbox->id)->whereNull('parent_saved_reply_id')->pluck('name')->values()->all());

        // Deleting a category: what's under it moves up; files go too.
        $attachment_id = $reply->attachments[0];
        $this->post(route('mailboxes.saved_replies.delete', ['id' => $this->mailbox->id, 'saved_reply_id' => $category->id]), ['_token' => csrf_token()])->assertRedirect();
        $this->assertNull($reply->fresh()->parent_saved_reply_id);
        $this->post(route('mailboxes.saved_replies.delete', ['id' => $this->mailbox->id, 'saved_reply_id' => $reply->id]), ['_token' => csrf_token()]);
        $this->assertNull(Attachment::find($attachment_id));
    }

    public function testPermissions()
    {
        $this->actingAs($this->agent)->get(route('mailboxes.saved_replies', ['id' => $this->mailbox->id]))->assertForbidden();

        $this->agent->permissions = [User::PERM_EDIT_SAVED_REPLIES => true];
        $this->agent->save();
        $this->actingAs($this->agent->fresh())->get(route('mailboxes.saved_replies', ['id' => $this->mailbox->id]))->assertOk();

        $other = $this->createMailbox([], ['name' => 'Other']);
        $this->get(route('mailboxes.saved_replies', ['id' => $other->id]))->assertForbidden();
    }

    public function testInTheEditor()
    {
        $other = $this->createMailbox([], ['name' => 'Other']);
        $this->savedReply(['name' => 'Own reply']);
        $global = $this->savedReply(['name' => 'Global category', 'global' => true], $other);
        $under_global = $this->savedReply(['name' => 'Under global', 'parent_saved_reply_id' => $global->id], $other);
        $private = $this->savedReply(['name' => 'Private elsewhere'], $other);

        $items = collect(SavedReply::forEditor($this->mailbox, $this->agent));
        $this->assertSame(['Own reply', 'Global category', 'Under global'], $items->pluck('name')->all());
        $this->assertSame([false, true, false], $items->pluck('category')->all());
        $this->assertSame([0, 0, 1], $items->pluck('depth')->all());

        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Order']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$conversation->id)
            ->assertSee('id="saved-replies-data"', false)->assertSee('Under global')->assertDontSee('Private elsewhere');

        // Under a global category: usable everywhere. Others' private: not.
        $this->ajax($this->agent, ['action' => 'get', 'saved_reply_id' => $under_global->id, 'mailbox_id' => $this->mailbox->id])->assertJsonPath('status', 'success');
        $this->ajax($this->agent, ['action' => 'get', 'saved_reply_id' => $private->id, 'mailbox_id' => $this->mailbox->id])->assertJsonPath('status', 'error');
    }

    public function testVariablesAndFiles()
    {
        $file = Attachment::create('terms.txt', 'text/plain', null, 'Terms', null, false, null, $this->admin->id);
        $reply = $this->savedReply(['name' => 'Hello', 'text' => '<p>Hi {%customer.firstName%}, about #{%conversation.number%}. {%customer.company%}</p>', 'attachments' => [$file->id]]);
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Casey <Customer> <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Order']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $conversation->customer->first_name = 'Casey & Co';
        $conversation->customer->save();

        $response = $this->ajax($this->agent, ['action' => 'get', 'saved_reply_id' => $reply->id, 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id])
            ->assertJsonPath('status', 'success');
        $this->assertStringContainsString('Hi Casey &amp; Co, about #'.$conversation->number.'.', $response->json('text'));
        $without_conversation = $this->ajax($this->agent, ['action' => 'get', 'saved_reply_id' => $reply->id, 'mailbox_id' => $this->mailbox->id]);
        $this->assertStringContainsString('Hi {%customer.firstName%}', $without_conversation->json('text'), 'Without a customer the variable stays, to be seen.');

        $copy = Attachment::find(decrypt($response->json('attachments.0.id')));
        $this->assertNotSame($file->id, $copy->id, 'A copy: the saved reply keeps its file.');
        $this->assertSame('Terms', $copy->getFileContents());
        $this->assertNotNull(Attachment::find($file->id));
    }

    public function testDefaultTemplateAndSavingAReply()
    {
        $this->savedReply(['name' => 'Template', 'text' => '<p>Kind regards, {%user.firstName%}</p>', 'auto_load' => true]);
        $this->ajax($this->agent, ['action' => 'template', 'mailbox_id' => $this->mailbox->id])
            ->assertJsonPath('status', 'success')->assertJsonPath('text', '<p>Kind regards, '.$this->agent->first_name.'</p>');

        $this->ajax($this->agent, ['action' => 'save_from_reply', 'mailbox_id' => $this->mailbox->id, 'name' => 'Mine', 'text' => '<p>x</p>'])
            ->assertJsonPath('status', 'error');
        $this->ajax($this->admin, ['action' => 'save_from_reply', 'mailbox_id' => $this->mailbox->id, 'name' => 'Mine', 'text' => '<p>From a reply</p>'])
            ->assertJsonPath('status', 'success')->assertJsonPath('name', 'Mine');
        $this->assertSame('<p>From a reply</p>', SavedReply::where('name', 'Mine')->value('text'));

        // A deleted mailbox takes its saved replies along.
        $this->mailbox->delete();
        $this->assertSame(0, SavedReply::count());
    }
}
