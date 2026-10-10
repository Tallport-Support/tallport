<?php

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Email;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\Telegram\Telegram;
use App\User;
use App\Workflow;
use App\Ai\Settings;
use App\Ai\Summaries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    const MAILBOXES = 3;
    const CUSTOMERS = 12;
    const FOLDER_SAMPLES = 4;
    const PERSONAL_SAMPLES = 2;
    const LONG_SAMPLES = 2;

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
                    $this->enrichSamples($mailbox, $users);
                    $this->workflows($mailbox, $users);
                }
                $this->seedWorkflows(null, [
                    Workflow::TYPE_MANUAL => [
                        [
                            'name' => 'Sample: Resolve conversation',
                            'conditions' => [],
                            'actions' => [[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]],
                        ],
                    ],
                ]);
            });
        });
        if ($this->command) {
            $this->command->info('Sample data topped up: '.$this->sequence.' conversations added. Existing data preserved.');
        }
    }

    protected function users()
    {
        $users = User::where('status', User::STATUS_ACTIVE)->where('type', User::TYPE_USER)->orderBy('id')->get();
        for ($i = 1; $users->count() < 3; $i++) {
            $email = 'agent'.$i.'@demo.example.test';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $password = \Illuminate\Support\Str::random(24);
            $user = new User();
            [$user->first_name, $user->last_name] = [
                ['Alex', 'Morgan'], ['Sam', 'Rivera'], ['Jamie', 'Park'],
            ][$users->count()];
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
                ->whereNull('conversations.channel')
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
        $long_count = $mailbox->conversations()
            ->where('type', Conversation::TYPE_EMAIL)
            ->whereNull('channel')
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ->whereHas('threads', function ($query) {
                $query->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                    ->where('state', Thread::STATE_PUBLISHED);
            }, '>=', 20)
            ->whereHas('threads', function ($query) {
                $query->where('state', Thread::STATE_PUBLISHED)->whereRaw((\DB::getDriverName() != 'sqlite' ? 'CHAR_LENGTH' : 'LENGTH').'(body) >= 1000');
            })->count();
        $folder = $mailbox->folders()->where('type', Folder::TYPE_ASSIGNED)->first();
        for ($i = $long_count; $i < self::LONG_SAMPLES; $i++) {
            $this->conversation($mailbox, $folder, $users->first(), true);
        }
        $this->telegramChats($mailbox, $users);
        $this->remoteImages($mailbox, $users);
        foreach ($mailbox->folders as $folder) {
            $folder->updateCountersNow();
        }
    }

    protected function remoteImages($mailbox, $users)
    {
        $existing = $mailbox->conversations()->where('type', Conversation::TYPE_EMAIL)->whereNull('channel')
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ->whereHas('threads', function ($query) {
                $query->where('type', Thread::TYPE_CUSTOMER)->where('state', Thread::STATE_PUBLISHED)
                    ->where('body', 'like', '%<img%');
            })->with('threads')->get()->filter(function ($conversation) {
                return $conversation->threads->contains(function ($thread) {
                    return $thread->type == Thread::TYPE_CUSTOMER && $thread->state == Thread::STATE_PUBLISHED
                        && \App\Misc\ExternalImages::block($thread->body)[1] > 0;
                });
            })->count();
        $folder = $mailbox->folders()->where('type', Folder::TYPE_ASSIGNED)->first();
        for ($i = $existing; $i < 2; $i++) {
            $this->conversation($mailbox, $folder, $users->first(), false, null, $i == 0 ? 'banner' : 'pixel');
        }
    }

    protected function imageCustomer()
    {
        $address = Email::where('email', 'images@demo.example.test')->first();
        if ($address) {
            return $address->customer;
        }
        $customer = new Customer();
        $customer->first_name = 'Morgan';
        $customer->last_name = 'Ellis';
        $customer->save();
        $address = new Email();
        $address->email = 'images@demo.example.test';
        $customer->emails()->save($address);

        return $customer;
    }

    protected function telegramChats($mailbox, $users)
    {
        foreach ([Folder::TYPE_UNASSIGNED, Folder::TYPE_ASSIGNED, Folder::TYPE_CLOSED] as $index => $type) {
            $folder = $mailbox->folders()->where('type', $type)->first();
            $query = Conversation::getQueryByFolder($folder, $users->first()->id)
                ->where('channel', Telegram::CHANNEL)
                ->whereHas('customer')
                ->whereHas('threads', function ($query) {
                    $query->where('type', Thread::TYPE_CUSTOMER)->where('state', Thread::STATE_PUBLISHED);
                })->whereHas('threads', function ($query) {
                    $query->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED);
                });
            if ($query->exists()) {
                continue;
            }
            // Deliberately invalid Telegram destinations: demo chats cannot identify real users.
            $channel_id = 'sample-telegram-'.($index + 1);
            $customer = Customer::getCustomerByChannel(Telegram::CHANNEL, $channel_id);
            if (!$customer) {
                $customer = new Customer();
                [$customer->first_name, $customer->last_name] = [
                    ['Casey', 'Lee'], ['Riley', 'Brooks'], ['Taylor', 'Reed'],
                ][$index];
                $customer->channel = Telegram::CHANNEL;
                $customer->channel_id = $channel_id;
                $customer->save();
                // Observers are disabled while seeding, so link the channel explicitly.
                CustomerChannel::create($customer->id, Telegram::CHANNEL, $channel_id);
            }
            $this->conversation($mailbox, $folder, $users->last(), $type == Folder::TYPE_ASSIGNED, $customer);
        }
    }

    protected function workflows($mailbox, $users)
    {
        $samples = [
            Workflow::TYPE_AUTOMATIC => [
                [
                    'name' => 'Sample: Route invoice questions',
                    'conditions' => [
                        [['type' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
                            ['type' => 'subject', 'operator' => 'contains', 'value' => 'payment']],
                        [['type' => 'status', 'operator' => 'equal', 'value' => (string) Conversation::STATUS_ACTIVE]],
                    ],
                    'actions' => [
                        [['type' => 'assign', 'value' => (string) $users[1]->id]],
                        [['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]],
                    ],
                ],
                [
                    'name' => 'Sample: Follow up after two days',
                    'conditions' => [
                        [['type' => 'waiting', 'operator' => 'longer', 'value' => ['number' => '2', 'metric' => 'd']]],
                        [['type' => 'state', 'operator' => 'equal', 'value' => (string) Conversation::STATE_PUBLISHED]],
                    ],
                    'actions' => [
                        [['type' => 'assign', 'value' => (string) $users[2]->id]],
                        [['type' => 'note', 'value' => json_encode(['body' => '<p>This customer has been waiting for two days. Please review the conversation and follow up.</p>'])]],
                    ],
                ],
            ],
            Workflow::TYPE_MANUAL => [
                [
                    'name' => 'Sample: Take ownership',
                    'conditions' => [],
                    'actions' => [
                        [['type' => 'assign', 'value' => (string) Workflow::ASSIGNEE_CURRENT]],
                        [['type' => 'status', 'value' => (string) Conversation::STATUS_ACTIVE]],
                    ],
                ],
            ],
        ];
        $this->seedWorkflows($mailbox->id, $samples);
    }

    protected function seedWorkflows($mailbox_id, $samples)
    {
        foreach ($samples as $type => $examples) {
            $existing = Workflow::where('mailbox_id', $mailbox_id)->where('type', $type)->get();
            $missing = count($examples) - $existing->count();
            $sort_order = (int) Workflow::where('mailbox_id', $mailbox_id)->max('sort_order');
            foreach ($examples as $example) {
                if ($missing <= 0) {
                    break;
                }
                if ($existing->contains('name', $example['name'])) {
                    continue;
                }
                $workflow = new Workflow();
                $workflow->mailbox_id = $mailbox_id;
                $workflow->name = $example['name'];
                $workflow->type = $type;
                // Automatic examples are opt-in; manual ones run only when selected.
                $workflow->active = $type == Workflow::TYPE_MANUAL;
                $workflow->complete = true;
                $workflow->apply_to_prev = false;
                $workflow->max_executions = 1;
                $workflow->sort_order = ++$sort_order;
                $workflow->setConditions($example['conditions']);
                $workflow->setActions($example['actions']);
                $workflow->save();
                $missing--;
            }
        }
    }

    protected function conversation($mailbox, $folder, $agent, $long = false, $telegram_customer = null, $remote_image = null)
    {
        $customer = $telegram_customer ?: ($remote_image ? $this->imageCustomer() : $this->customers[$this->sequence % $this->customers->count()]);
        $topics = [
            ['Help connecting my new laptop', 'I can sign in on my phone, but the connection on my new laptop stops during setup. What should I check?', 'Please install the latest client and try the nearest location. If it still fails, send us the connection log.'],
            ['A question about my latest invoice', 'Could you explain the adjustment on my latest invoice and confirm when the next payment is due?', 'The adjustment covers the additional seats for the remaining days of this billing period. Your next invoice will use the regular monthly rate.'],
            ['Adding colleagues to our account', 'We are bringing three more colleagues onto the team next week. Can we keep everyone on the same account?', 'Yes. Invite your colleagues from the team page and choose their access levels. Each person will receive their own invitation.'],
            ['Changing my account email address', 'Our company has a new domain. How can I change my email address without losing my history?', 'Update the address in your account settings, then confirm the link sent to the new address. Your existing history will stay with the account.'],
            ['Planning our renewal', 'Our subscription renews next month. Could you help us review the options for a larger team?', 'Happy to help. Let us know your expected team size and whether you prefer monthly or annual billing, and we will prepare a comparison.'],
            ['Following up on yesterday’s request', 'Thank you for looking into this yesterday. Is there anything else you need from me to move this forward?', 'We have the details we need and are checking the final result. I will follow up here as soon as the review is complete.'],
        ];
        [$subject, $question, $answer] = $topics[$this->sequence % count($topics)];
        if ($remote_image) {
            [$subject, $question, $answer] = $remote_image == 'banner'
                ? ['Checking the banner in our welcome email', 'Our welcome email includes a banner hosted on another server. Can you check how it appears?', 'Thanks for the example. I will check how the email displays when remote images are blocked.']
                : ['Reviewing an email with a tracking pixel', 'This email template contains a small remote tracking image. Does your reader warn about it?', 'Yes, images hosted on other servers are blocked until the reader chooses to show them.'];
        }
        if ($long) {
            [$subject, $question, $answer] = $topics[0];
            $subject .= ' — extended troubleshooting';
        }
        $started = now()->subDays(1 + ($this->sequence % 14))->subMinutes($this->sequence * 7);
        $conversation = new Conversation();
        $conversation->number = ++$this->number;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->type = Conversation::TYPE_EMAIL;
        if ($telegram_customer) {
            $conversation->channel = Telegram::CHANNEL;
        }
        $conversation->state = $folder->type == Folder::TYPE_DELETED ? Conversation::STATE_DELETED : Conversation::STATE_PUBLISHED;
        $conversation->status = match ($folder->type) {
            Folder::TYPE_CLOSED => Conversation::STATUS_CLOSED,
            Folder::TYPE_SPAM => Conversation::STATUS_SPAM,
            default => $this->sequence % 3 == 0 ? Conversation::STATUS_PENDING : Conversation::STATUS_ACTIVE,
        };
        $conversation->user_id = $folder->type == Folder::TYPE_UNASSIGNED ? null : $agent->id;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = $telegram_customer ? null : $customer->emails->first()->email;
        $conversation->created_by_customer_id = $customer->id;
        $conversation->subject = $subject;
        $conversation->source_via = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = $telegram_customer ? Conversation::SOURCE_TYPE_WEB : Conversation::SOURCE_TYPE_EMAIL;
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
        if ($long) {
            foreach ($this->longExchange() as $index => $body) {
                $messages[] = [$index % 2 == 0 ? Thread::TYPE_MESSAGE : Thread::TYPE_CUSTOMER, $body];
            }
            $conversation->threads_count = count($messages) - 1;
            $conversation->updated_at = $started->copy()->addMinutes((count($messages) - 1) * 40);
            $conversation->last_reply_at = $conversation->updated_at;
            $conversation->user_updated_at = $conversation->updated_at->copy()->subMinutes(40);
            $conversation->setPreview(end($messages)[1]);
            $conversation->save();
        }
        $draft_index = null;
        if ($folder->type == Folder::TYPE_DRAFTS) {
            $draft_index = count($messages);
            $messages[] = [Thread::TYPE_MESSAGE, 'I have reviewed the details and am preparing the next steps for you.'];
        }
        foreach ($messages as $index => [$type, $body]) {
            $incoming = $type == Thread::TYPE_CUSTOMER;
            $thread = new Thread();
            $thread->conversation_id = $conversation->id;
            $thread->customer_id = $customer->id;
            $thread->type = $type;
            $thread->status = $conversation->status;
            $thread->state = $index === $draft_index ? Thread::STATE_DRAFT : Thread::STATE_PUBLISHED;
            $thread->body = '<p>'.implode('</p><p>', array_map('e', explode("\n\n", $body))).'</p>';
            if ($remote_image && $index == 0) {
                $thread->body .= $remote_image == 'banner'
                    ? '<p><img src="https://images.example.invalid/demo/banner.png" width="560" height="160" alt="Sample welcome banner"></p>'
                    : '<img src="https://images.example.invalid/demo/open.gif" width="1" height="1" alt="">';
            }
            $thread->first = $index == 0;
            $thread->source_via = $incoming ? Thread::PERSON_CUSTOMER : Thread::PERSON_USER;
            $thread->source_type = $incoming
                ? ($telegram_customer ? Thread::SOURCE_TYPE_API : Thread::SOURCE_TYPE_EMAIL) : Thread::SOURCE_TYPE_WEB;
            $thread->created_by_customer_id = $incoming ? $customer->id : null;
            $thread->created_by_user_id = $incoming ? null : $agent->id;
            $thread->user_id = $conversation->user_id;
            if (!$telegram_customer) {
                $thread->from = $incoming ? $conversation->customer_email : $mailbox->email;
                $thread->setTo([$incoming ? $mailbox->email : $conversation->customer_email]);
            } elseif ($type == Thread::TYPE_MESSAGE) {
                $thread->send_status = \App\SendLog::STATUS_ACCEPTED;
            }
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

    protected function enrichSamples($mailbox, $users)
    {
        $conversations = $mailbox->conversations()->where('imported', true)
            ->whereHas('threads', function ($query) {
                $query->where('message_id', 'like', 'sample-%@demo.example.test');
            })->with('threads')->get();
        foreach ($conversations as $conversation) {
            // The old seeder's message IDs identify its fixtures without touching real mail.
            $samples = $conversation->threads->filter(function ($thread) use ($conversation) {
                return preg_match('/^sample-'.(int) $conversation->id.'-\d+@demo\.example\.test$/D', (string) $thread->message_id);
            })->sortBy('id');
            $staff = $samples->whereIn('type', [Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
                ->where('state', Thread::STATE_PUBLISHED)->values();
            if ($staff->pluck('created_by_user_id')->filter()->unique()->count() < min(3, $staff->count())) {
                foreach ($staff as $index => $thread) {
                    DB::table('threads')->where('id', $thread->id)->update([
                        'created_by_user_id' => $users[$index % $users->count()]->id,
                    ]);
                    $thread->created_by_user_id = $users[$index % $users->count()]->id;
                }
            }
            $replies = $samples->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED);
            if ($replies->isNotEmpty()) {
                $authors = $replies->pluck('created_by_user_id');
                $index = $samples->max(function ($thread) {
                    preg_match('/-(\d+)@/', $thread->message_id, $match);

                    return (int) $match[1];
                });
                $added = false;
                foreach ($users as $user) {
                    if ($authors->contains($user->id)) {
                        continue;
                    }
                    $reply = $replies->first()->replicate();
                    $reply->headers = null;
                    $reply->body_original = null;
                    $reply->created_by_user_id = $user->id;
                    $reply->body = '<p>Hi '.e($conversation->customer->first_name).',</p>'
                        .'<p>I have reviewed your conversation with the team and will help with the follow-up. '
                        .'Please reply here if you have any further questions or results to share.</p>'
                        .'<p>Best,<br>'.e($user->getFullName()).'</p>';
                    $reply->first = false;
                    $reply->message_id = 'sample-'.$conversation->id.'-'.(++$index).'@demo.example.test';
                    $reply->created_at = $conversation->threads->max('created_at')->copy()->addMinute();
                    $reply->updated_at = $reply->created_at;
                    $reply->save();
                    $conversation->threads->push($reply);
                    $conversation->last_reply_at = $reply->created_at;
                    $conversation->last_reply_from = Conversation::PERSON_USER;
                    $conversation->user_updated_at = $reply->created_at;
                    $conversation->updated_at = $reply->created_at;
                    $conversation->setPreview($reply->body);
                    $added = true;
                }
                if ($added) {
                    $conversation->threads_count = $conversation->threads->where('state', Thread::STATE_PUBLISHED)
                        ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])->count();
                    $conversation->save();
                }
            }
            foreach ($samples->where('type', Thread::TYPE_CUSTOMER)->where('state', Thread::STATE_PUBLISHED) as $thread) {
                $data = Summaries::data($thread);
                $original = $data;
                foreach (Settings::LANGUAGES as $language => $name) {
                    if (!isset($data['translations'][$language])) {
                        // Deliberately labelled English placeholders, not machine translations.
                        $data['translations'][$language] = 'Sample translation ('.$name.")\n\n".Summaries::text($thread);
                        unset($data['errors'][$language]);
                    }
                }
                if ($data !== $original) {
                    DB::table('threads')->where('id', $thread->id)->update([
                        'ai_assistant' => json_encode($data, JSON_UNESCAPED_UNICODE),
                        'ai_assistant_updated_at' => now(),
                    ]);
                }
            }
            $published = $conversation->threads->where('state', Thread::STATE_PUBLISHED)
                ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE]);
            $latest = (int) $published->max('id');
            $data = Summaries::data($conversation);
            $original = $data;
            foreach (Settings::LANGUAGES as $language => $name) {
                $summary = $data['summaries'][$language] ?? null;
                if (!$summary || (!empty($summary['seeded']) && (int) $summary['thread_id'] < $latest)) {
                    $first = $samples->where('type', Thread::TYPE_CUSTOMER)->first();
                    $last = $samples->where('type', Thread::TYPE_CUSTOMER)->last();
                    $data['summaries'][$language] = [
                        'one_liner' => 'Sample summary: '.$conversation->subject,
                        'background' => $published->count() >= Summaries::BACKGROUND_MIN_MESSAGES
                            ? "- Tried: ".($first ? mb_substr(Summaries::text($first), 0, 120) : $conversation->subject)
                                ."\n- Still open: ".($last ? mb_substr(Summaries::text($last), 0, 120) : 'awaiting a reply.')
                            : '',
                        'thread_id' => $latest,
                        'at' => now()->toDateTimeString(),
                        'seeded' => true,
                    ];
                }
            }
            if ($data !== $original) {
                DB::table('conversations')->where('id', $conversation->id)->update([
                    'ai_assistant' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'ai_assistant_updated_at' => now(),
                ]);
            }
            $this->emailSources($conversation, $users);
        }
    }

    protected function emailSources($conversation, $users)
    {
        if (!$conversation->isEmail() || $conversation->hasChannel()) {
            return;
        }
        $references = [];
        $previous = null;
        foreach ($conversation->threads->sortBy('created_at') as $thread) {
            if ($thread->state != Thread::STATE_PUBLISHED
                || !in_array($thread->type, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                || !preg_match('/^sample-'.(int) $conversation->id.'-\d+@demo\.example\.test$/D', (string) $thread->message_id)
            ) {
                continue;
            }
            $missing = [];
            if (!$thread->body_original) {
                $body = $thread->body;
                if ($previous) {
                    $body .= '<blockquote><p>On '.e($previous->created_at->toRfc2822String()).', '
                        .e($previous->from).' wrote:</p>'.$previous->body.'</blockquote>';
                }
                $missing['body_original'] = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$body.'</body></html>';
                $thread->body_original = $missing['body_original'];
            }
            if (!$thread->headers) {
                $name = $thread->type == Thread::TYPE_CUSTOMER ? $conversation->customer->getFullName()
                    : ($users->firstWhere('id', $thread->created_by_user_id)?->getFullName() ?: $conversation->mailbox->name);
                $email = (new \Symfony\Component\Mime\Email())
                    ->from(new \Symfony\Component\Mime\Address($thread->from, $name))
                    ->to(...$thread->getToArray())
                    ->replyTo($thread->from)
                    ->subject(($references ? 'Re: ' : '').$conversation->subject)
                    ->date($thread->created_at)
                    ->html($thread->body_original);
                if ($thread->getCcArray()) {
                    $email->cc(...$thread->getCcArray());
                }
                $headers = $email->getHeaders();
                $headers->addIdHeader('Message-ID', $thread->message_id);
                if ($references) {
                    $headers->addIdHeader('In-Reply-To', end($references));
                    $headers->addIdHeader('References', $references);
                }
                $headers->addTextHeader('X-Tallport-Sample', 'email-source-v1');
                $headers->addPathHeader('Return-Path', $thread->from);
                $headers->addTextHeader('Delivered-To', $thread->getToArray()[0]);
                $headers->addTextHeader('Received', 'from smtp.demo.example.test (smtp.demo.example.test [192.0.2.10])'
                    .' by mx.demo.example.test with ESMTPS; '.$thread->created_at->toRfc2822String());
                $headers->addTextHeader('Received', 'from client.demo.example.test ([192.0.2.20])'
                    .' by smtp.demo.example.test with ESMTPSA; '.$thread->created_at->copy()->subSeconds(2)->toRfc2822String());
                $domain = substr(strrchr($thread->from, '@'), 1);
                $headers->addTextHeader('Authentication-Results', 'mx.demo.example.test; spf=pass smtp.mailfrom='.$domain
                    .'; dkim=none; dmarc=pass header.from='.$domain);
                [$missing['headers']] = explode("\r\n\r\n", $email->toString(), 2);
                $thread->headers = $missing['headers'];
            }
            if ($missing) {
                DB::table('threads')->where('id', $thread->id)->update($missing);
            }
            // Only reconstruct sources we generated; preserve other headers and retained originals.
            if (\App\Incoming\RawSources::retentionDays() > 0
                && !is_file(\App\Incoming\RawSources::path($thread))
                && \MailHelper::getHeader($thread->headers, 'X-Tallport-Sample') == 'email-source-v1'
            ) {
                $raw = $thread->headers."\r\n\r\n".quoted_printable_encode($thread->body_original);
                \App\Incoming\RawSources::store($thread, \App\Incoming\Parser::parse($raw));
            }
            $references[] = $thread->message_id;
            $previous = $thread;
        }
    }

    protected function longExchange()
    {
        return [
            "Let's work through the connection step by step. First, disconnect the client, restart the laptop, and reconnect using the nearest location. Please note whether the connection fails immediately or only after the laptop has been asleep. Those two cases help us distinguish a setup problem from a connection that is not recovering correctly.\n\nNext, check whether ordinary websites load before you connect. If possible, repeat the same test on a phone hotspot as well as your usual Wi-Fi. Keep the client settings the same for both tests so we are comparing just the network. Please include the approximate time of each attempt and the location selected in the client.\n\nFinally, send the connection log from the client after one failed attempt. You do not need to share your password or any private account information. A short description of what you clicked, the message on screen, and whether reconnecting helped will give us enough context to compare the log with your observations. We will keep the findings in this conversation so you do not have to repeat the details to another member of the team.",
            "I followed those steps this morning. Websites load normally before I connect, and the first connection after a restart works. The problem returns after I close the laptop lid for about ten minutes.\n\nOn the phone hotspot, reconnecting works straight away. On the office Wi-Fi, the client says it is connected but pages keep loading. Disconnecting and connecting again fixes it until the next time the laptop sleeps.",
            'That is helpful. Can you repeat the sleep test once with the client connected and once with it disconnected, then tell us whether both cases behave the same?',
            'Only the first case fails. If I disconnect before closing the lid, it reconnects normally when I open it again.',
            "We have narrowed this down to recovery after sleep on the office network. I have shared your results with the engineering team.\n\nFor now, disconnecting before sleep is a useful workaround. You do not need to reinstall the application or reset your account. Please keep the current settings while we compare the two connection logs.",
            'Understood. Two colleagues have the same laptop model. Would it help if they checked the same sequence?',
            "Yes, a small comparison would be useful. Ask one colleague to test on the office Wi-Fi and the other to use a hotspot. They should start with a fresh connection, open a normal website, close the lid for ten minutes, and then try that website again.\n\nPlease record the application version, operating system version, network, selected location, and approximate wake time for each laptop. If there is a difference, we can check whether it follows the network or the device. There is no need to change power settings or disable any security software for this test.\n\nOnce you have the results, send a short summary here. A successful test is just as useful as a failed one because it gives us a reference to compare against. We will use these observations to validate a targeted fix, then ask you to repeat the original sequence. Our goal is for the connection to recover automatically after sleep without requiring your team to remember a special workaround every time they move between meetings.",
            "Both colleagues completed the test. The office Wi-Fi laptop had the same problem; the hotspot laptop recovered successfully. All three of us are using the same application version.\n\nThe failure happened around 14:20. I have kept the log from that attempt for the team.",
            'Thank you. That matches our reproduction. We are checking a change to how the client restores the connection after the network becomes available again.',
            'Will that change affect our saved locations or account settings?',
            'Your saved locations and account settings will stay in place. The change is limited to restoring an existing connection after the laptop wakes up.',
            'Good. We can try the update on one laptop before rolling it out to everyone.',
            "The test build is ready for that first laptop. Please repeat your original office Wi-Fi test before trying any additional scenarios.\n\nAfter that, try waking the laptop on a different network from the one it used before sleep. Please check both a short sleep of a few minutes and a longer break. We are interested in whether pages start loading automatically, how long recovery takes, and whether the displayed connection state matches what you observe.",
            "The original test now passes. I also moved from the office Wi-Fi to my hotspot while the laptop was asleep, and pages loaded shortly after it woke up.\n\nI will leave it asleep during lunch and try the longer test afterward.",
            'Excellent. We will wait for the longer test before considering the investigation complete.',
            'The lunch test passed too. I did not need to disconnect manually this time.',
            "Thanks for checking all of those cases. Here is the summary for your team: the failure occurred after sleep on the office network, a manual reconnect restored traffic, and the updated client recovered automatically in both the original test and the network-change test.\n\nYou can now try the same update on the other two laptops. Keep the workaround available until they have completed their normal working day. If either laptop behaves differently, reply here with the time and network so we can continue from the existing findings.",
            'Both colleagues have installed it. We will monitor it through tomorrow morning and send one final update.',
            'That sounds good. I will keep this conversation open while you finish the checks. The details above are saved here for whoever handles the next update.',
            "Final update: all three laptops have been working normally since yesterday. We tested sleep, office Wi-Fi, and hotspots during our usual meetings.\n\nThank you for staying with this and explaining the steps clearly. We are ready to roll the update out to the rest of the team.",
        ];
    }
}
