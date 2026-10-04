<?php

use App\Conversation;
use App\Customer;
use App\Email;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    const MAILBOXES = 3;
    const CUSTOMERS = 12;
    const FOLDER_SAMPLES = 4;
    const PERSONAL_SAMPLES = 2;

    protected $customers;
    protected $sequence = 0;
    protected $number;

    public function run()
    {
        $this->sequence = 0;
        // Seed history directly: observers must not notify customers or enqueue delivery.
        Model::withoutEvents(function () {
            DB::transaction(function () {
                $users = $this->users();
                $this->customers = $this->customers();
                $this->number = (int) Conversation::max('number');
                $mailboxes = Mailbox::orderBy('id')->take(self::MAILBOXES)->get();
                foreach (['Support', 'Billing', 'Sales'] as $name) {
                    if ($mailboxes->count() >= self::MAILBOXES) {
                        break;
                    }
                    $email = strtolower($name).'@demo.example.test';
                    if (Mailbox::where('email', $email)->exists()) {
                        continue;
                    }
                    $mailbox = new Mailbox();
                    $mailbox->name = $name;
                    $mailbox->email = $email;
                    $mailbox->save();
                    $mailboxes->push($mailbox);
                }
                foreach ($mailboxes as $mailbox) {
                    $this->mailbox($mailbox, $users);
                }
            });
        });
        if ($this->command) {
            $this->command->info('Sample data topped up: '.$this->sequence.' conversations added. Existing data preserved.');
        }
    }

    protected function users()
    {
        $users = User::where('status', User::STATUS_ACTIVE)->where('type', User::TYPE_USER)->orderBy('id')->get();
        for ($i = 1; $users->count() < 2; $i++) {
            $email = 'agent'.$i.'@demo.example.test';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $password = \Illuminate\Support\Str::random(24);
            $user = new User();
            $user->first_name = $users->isEmpty() ? 'Alex' : 'Sam';
            $user->last_name = 'Morgan';
            $user->email = $email;
            $user->password = Hash::make($password);
            $user->role = $users->isEmpty() ? User::ROLE_ADMIN : User::ROLE_USER;
            $user->status = User::STATUS_ACTIVE;
            $user->type = User::TYPE_USER;
            $user->invite_state = User::INVITE_STATE_ACTIVATED;
            $user->save();
            $users->push($user);
            if ($this->command) {
                $this->command->line('Created demo login: '.$email.' / '.$password);
            }
        }

        return $users;
    }

    protected function customers()
    {
        $customers = Customer::whereHas('emails')->with('emails')->orderBy('id')->take(self::CUSTOMERS)->get();
        $names = ['Avery Chen', 'Jordan Patel', 'Maya Williams', 'Noah Kim', 'Sofia Garcia', 'Leo Martin',
            'Amelia Wilson', 'Oliver Nguyen', 'Isla Brown', 'Ethan Davis', 'Zoe Anderson', 'Lucas Taylor'];
        for ($i = 0; $customers->count() < self::CUSTOMERS; $i++) {
            $email = 'customer'.($i + 1).'@demo.example.test';
            if (Email::where('email', $email)->exists()) {
                continue;
            }
            [$first, $last] = explode(' ', $names[$i % count($names)]);
            $customer = new Customer();
            $customer->first_name = $first;
            $customer->last_name = $last;
            $customer->save();
            $address = new Email();
            $address->email = $email;
            $customer->emails()->save($address);
            $customer->load('emails');
            $customers->push($customer);
        }

        return $customers;
    }

    protected function mailbox($mailbox, $users)
    {
        $mailbox->users()->syncWithoutDetaching($users->pluck('id')->all());
        foreach (Folder::$public_types as $type) {
            Folder::firstOrCreate(['mailbox_id' => $mailbox->id, 'type' => $type, 'user_id' => null]);
        }
        foreach ($users as $user) {
            foreach (Folder::$personal_types as $type) {
                Folder::firstOrCreate(['mailbox_id' => $mailbox->id, 'type' => $type, 'user_id' => $user->id]);
            }
        }
        foreach ($mailbox->folders()->orderBy('type')->get() as $folder) {
            if (!isset(Folder::$types[$folder->type])) {
                continue;
            }
            $user = $folder->user_id ? $users->firstWhere('id', $folder->user_id) : $users->first();
            if (!$user) {
                continue;
            }
            $query = Conversation::getQueryByFolder($folder, $user->id)
                ->where('conversations.type', Conversation::TYPE_EMAIL)
                ->whereHas('customer.emails')
                ->whereHas('threads', function ($query) {
                    $query->where('type', Thread::TYPE_CUSTOMER)->where('state', Thread::STATE_PUBLISHED);
                })->whereHas('threads', function ($query) {
                    $query->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED);
                });
            if ($folder->type == Folder::TYPE_DRAFTS) {
                $query->whereHas('threads', function ($query) {
                    $query->where('state', Thread::STATE_DRAFT);
                });
            }
            $target = in_array($folder->type, Folder::$personal_types) ? self::PERSONAL_SAMPLES : self::FOLDER_SAMPLES;
            $missing = max(0, $target - $query->count());
            for ($i = 0; $i < $missing; $i++) {
                $agent = $folder->type == Folder::TYPE_ASSIGNED ? $users->last() : $user;
                $this->conversation($mailbox, $folder, $agent);
            }
        }
        foreach ($mailbox->folders as $folder) {
            $folder->updateCountersNow();
        }
    }

    protected function conversation($mailbox, $folder, $agent)
    {
        $customer = $this->customers[$this->sequence % $this->customers->count()];
        $topics = [
            ['Help connecting my new laptop', 'I can sign in on my phone, but the connection on my new laptop stops during setup. What should I check?', 'Please install the latest client and try the nearest location. If it still fails, send us the connection log.'],
            ['A question about my latest invoice', 'Could you explain the adjustment on my latest invoice and confirm when the next payment is due?', 'The adjustment covers the additional seats for the remaining days of this billing period. Your next invoice will use the regular monthly rate.'],
            ['Adding colleagues to our account', 'We are bringing three more colleagues onto the team next week. Can we keep everyone on the same account?', 'Yes. Invite your colleagues from the team page and choose their access levels. Each person will receive their own invitation.'],
            ['Changing my account email address', 'Our company has a new domain. How can I change my email address without losing my history?', 'Update the address in your account settings, then confirm the link sent to the new address. Your existing history will stay with the account.'],
            ['Planning our renewal', 'Our subscription renews next month. Could you help us review the options for a larger team?', 'Happy to help. Let us know your expected team size and whether you prefer monthly or annual billing, and we will prepare a comparison.'],
            ['Following up on yesterday’s request', 'Thank you for looking into this yesterday. Is there anything else you need from me to move this forward?', 'We have the details we need and are checking the final result. I will follow up here as soon as the review is complete.'],
        ];
        [$subject, $question, $answer] = $topics[$this->sequence % count($topics)];
        $started = now()->subDays(1 + ($this->sequence % 14))->subMinutes($this->sequence * 7);
        $conversation = new Conversation();
        $conversation->number = ++$this->number;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->type = Conversation::TYPE_EMAIL;
        $conversation->state = $folder->type == Folder::TYPE_DELETED ? Conversation::STATE_DELETED : Conversation::STATE_PUBLISHED;
        $conversation->status = match ($folder->type) {
            Folder::TYPE_CLOSED => Conversation::STATUS_CLOSED,
            Folder::TYPE_SPAM => Conversation::STATUS_SPAM,
            default => $this->sequence % 3 == 0 ? Conversation::STATUS_PENDING : Conversation::STATUS_ACTIVE,
        };
        $conversation->user_id = $folder->type == Folder::TYPE_UNASSIGNED ? null : $agent->id;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = $customer->emails->first()->email;
        $conversation->created_by_customer_id = $customer->id;
        $conversation->subject = $subject;
        $conversation->source_via = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_EMAIL;
        $conversation->imported = true;
        $conversation->threads_count = 3;
        $conversation->read_by_user = $this->sequence % 2 == 0;
        $conversation->created_at = $started;
        $conversation->updated_at = $started->copy()->addHours(2);
        $conversation->last_reply_at = $started->copy()->addMinutes(80);
        $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
        $conversation->user_updated_at = $conversation->updated_at;
        if ($conversation->status == Conversation::STATUS_CLOSED) {
            $conversation->closed_at = $conversation->updated_at;
            $conversation->closed_by_user_id = $agent->id;
        }
        $conversation->updateFolder($mailbox);
        $followup = 'Thanks for the clear explanation. I have shared this with our team and will let you know how it goes.';
        $conversation->setPreview($followup);
        $conversation->save();
        $messages = [
            [Thread::TYPE_CUSTOMER, $question],
            [Thread::TYPE_MESSAGE, $answer],
            [Thread::TYPE_CUSTOMER, $followup],
            [Thread::TYPE_NOTE, 'Context for the team: review the earlier exchange before following up.'],
        ];
        if ($folder->type == Folder::TYPE_DRAFTS) {
            $messages[] = [Thread::TYPE_MESSAGE, 'I have reviewed the details and am preparing the next steps for you.'];
        }
        foreach ($messages as $index => [$type, $body]) {
            $incoming = $type == Thread::TYPE_CUSTOMER;
            $thread = new Thread();
            $thread->conversation_id = $conversation->id;
            $thread->customer_id = $customer->id;
            $thread->type = $type;
            $thread->status = $conversation->status;
            $thread->state = $index == 4 ? Thread::STATE_DRAFT : Thread::STATE_PUBLISHED;
            $thread->body = '<p>'.e($body).'</p>';
            $thread->first = $index == 0;
            $thread->source_via = $incoming ? Thread::PERSON_CUSTOMER : Thread::PERSON_USER;
            $thread->source_type = $incoming ? Thread::SOURCE_TYPE_EMAIL : Thread::SOURCE_TYPE_WEB;
            $thread->created_by_customer_id = $incoming ? $customer->id : null;
            $thread->created_by_user_id = $incoming ? null : $agent->id;
            $thread->user_id = $conversation->user_id;
            $thread->from = $incoming ? $conversation->customer_email : $mailbox->email;
            $thread->setTo([$incoming ? $mailbox->email : $conversation->customer_email]);
            $thread->message_id = 'sample-'.$conversation->id.'-'.$index.'@demo.example.test';
            $thread->created_at = $started->copy()->addMinutes($index * 40);
            $thread->updated_at = $thread->created_at;
            $thread->save();
        }
        if (in_array($folder->type, Folder::$indirect_types)) {
            $conversation->folders()->syncWithoutDetaching([$folder->id]);
            if ($folder->type == Folder::TYPE_STARRED) {
                Conversation::clearStarredByUserCache($agent->id, $mailbox->id);
            }
        }
        $this->sequence++;
    }
}
