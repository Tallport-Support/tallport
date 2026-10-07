<?php

namespace Tests\Feature;

use App\Conversation;
use App\Subscription;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * Who gets notified of what happens in a conversation: the events each
 * kind of change raises, and the users left out (muted mailboxes, users who
 * see only their own conversations, a module's filter, imported messages).
 */
class SubscriptionTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receive(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>',
            'to'   => $this->mailbox->email,
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * Subscribe the user to an event, besides the default subscriptions.
     */
    protected function subscribe(User $user, $event, $medium = Subscription::MEDIUM_EMAIL)
    {
        Subscription::create(['user_id' => $user->id, 'medium' => $medium, 'event' => $event]);
    }

    protected function notifiedUserIds($users_to_notify, $medium = Subscription::MEDIUM_EMAIL)
    {
        return collect($users_to_notify[$medium] ?? [])->pluck('id')->sort()->values()->all();
    }

    public function testACustomerReplyToMyConversationNotifiesMe()
    {
        $admin = $this->createAdmin();
        $conversation = $this->receive(['message_id' => 'first@customer.example.org']);
        $conversation->changeUser($this->agent->id, $admin);
        \App\Subscription::processEvents();
        $before = count($this->sentEmailsTo($this->agent->email));

        $this->receive([
            'subject'     => 'Re: Question about my order',
            'in_reply_to' => 'first@customer.example.org',
            'body'        => 'Any news?',
        ]);

        $this->assertCount($before + 1, $this->sentEmailsTo($this->agent->email));
    }

    public function testMutedMailboxesOnlyNotifyOfWhatIsMine()
    {
        $this->subscribe($this->agent, Subscription::EVENT_NEW_CONVERSATION);
        $conversation = $this->receive();
        $threads = $conversation->getThreads();

        $users = Subscription::usersToNotify(Subscription::EVENT_TYPE_NEW, $conversation, $threads);
        $this->assertSame([$this->agent->id], $this->notifiedUserIds($users));

        $this->mailbox->users()->updateExistingPivot($this->agent->id, ['mute' => true]);
        $users = Subscription::usersToNotify(Subscription::EVENT_TYPE_NEW, $conversation->fresh(), $threads);
        $this->assertSame([], $this->notifiedUserIds($users));
    }

    public function testUsersWhoSeeOnlyAssignedConversationsAreNotNotifiedOfOthers()
    {
        $restricted = $this->createUser(['permissions' => [User::PERM_ONLY_ASSIGNED_TICKETS => true]]);
        $this->mailbox->users()->attach($restricted->id);
        $this->subscribe($restricted, Subscription::EVENT_NEW_CONVERSATION);
        $this->subscribe($this->agent, Subscription::EVENT_NEW_CONVERSATION);
        $conversation = $this->receive();

        $users = Subscription::usersToNotify(Subscription::EVENT_TYPE_NEW, $conversation, $conversation->getThreads());
        $this->assertSame([$this->agent->id], $this->notifiedUserIds($users));
    }

    public function testAModuleCanFilterOutASubscriber()
    {
        $this->subscribe($this->agent, Subscription::EVENT_NEW_CONVERSATION);
        $conversation = $this->receive();
        \Eventy::addFilter('subscription.filter_out', function ($filter_out, $subscription) {
            return $subscription->user_id == $this->agent->id;
        }, 20, 2);

        $users = Subscription::usersToNotify(Subscription::EVENT_TYPE_NEW, $conversation, $conversation->getThreads());
        $this->assertSame([], $this->notifiedUserIds($users));
    }

    public function testAReplyThatChangesTheAssigneeNotifiesTheNewAssignee()
    {
        $other = $this->createUser();
        $this->mailbox->users()->attach($other->id);
        $conversation = $this->receive();
        $conversation->user_id = $this->agent->id;
        $conversation->save();
        $customer_thread = $conversation->threads()->first();

        // Replied and assigned to the agent in one go.
        $reply = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Reply', [
            'user_id'            => $this->agent->id,
            'created_by_user_id' => $other->id,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
        ]);

        $users = Subscription::usersToNotify(Subscription::EVENT_TYPE_USER_REPLIED, $conversation, [$reply, $customer_thread]);
        $this->assertSame([$this->agent->id], $this->notifiedUserIds($users));
        $this->assertSame([$this->agent->id], $this->notifiedUserIds($users, Subscription::MEDIUM_BROWSER));
    }

    public function testImportedMessagesNotifyNobody()
    {
        $this->subscribe($this->agent, Subscription::EVENT_NEW_CONVERSATION);
        $conversation = $this->receive();
        $conversation->threads()->update(['imported' => true]);
        $before = count($this->sentEmails());
        $notifications = \DB::table('notifications')->count();

        Subscription::registerEvent(Subscription::EVENT_TYPE_NEW, $conversation->fresh(), null, [], true);

        $this->assertCount($before, $this->sentEmails());
        $this->assertSame($notifications, \DB::table('notifications')->count());
        $this->assertSame([], Subscription::$occurred_events);
    }

    public function testBrowserOnlySubscribersGetAWebsiteNotification()
    {
        $this->subscribe($this->agent, Subscription::EVENT_NEW_CONVERSATION, Subscription::MEDIUM_BROWSER);
        $before = count($this->sentEmails());

        $conversation = $this->receive();

        // No email, a notification in the menu.
        $this->assertCount($before, $this->sentEmails());
        $notification = \DB::table('notifications')->where('notifiable_id', $this->agent->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame(\App\Notifications\WebsiteNotification::class, $notification->type);
        $this->assertEquals($conversation->id, json_decode($notification->data, true)['conversation_id']);
    }

    public function testTheNotificationIsAboutTheNewestMeaningfulThread()
    {
        $conversation = $this->receive();
        $customer_thread = $conversation->threads()->first();
        $status_changed = Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'action_type' => Thread::ACTION_TYPE_STATUS_CHANGED,
            'source_via'  => Thread::PERSON_USER,
            'source_type' => Thread::SOURCE_TYPE_WEB,
        ]);
        $assigned = Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'action_type' => Thread::ACTION_TYPE_USER_CHANGED,
            'source_via'  => Thread::PERSON_USER,
            'source_type' => Thread::SOURCE_TYPE_WEB,
        ]);

        $this->assertSame($customer_thread->id, Subscription::chooseThread([$status_changed, $customer_thread])->id);
        $this->assertSame($assigned->id, Subscription::chooseThread([$status_changed, $assigned])->id);
        // Only line items: the newest.
        $this->assertSame($status_changed->id, Subscription::chooseThread([$status_changed])->id);
    }
}
