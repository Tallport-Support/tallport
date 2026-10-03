<?php

namespace App\Reports;

use App\Customer;
use App\Thread;

/**
 * How many conversations and messages came in, when, from whom, and how
 * each mailbox did.
 */
class ConversationsReport extends Report
{
    public function data($chart = [])
    {
        $new = $this->conversations()->pluck('conversations.created_at')->all();
        $new_prev = $this->conversations(true)->pluck('conversations.created_at')->all();
        $messages = $this->threads(Thread::TYPE_CUSTOMER)->pluck('threads.created_at')->all();
        $messages_prev = $this->threads(Thread::TYPE_CUSTOMER, true)->pluck('threads.created_at')->all();

        $data = [];
        $data['metrics'] = [
            'total'     => self::metric($this->countActive(), $this->countActive(true)),
            'new'       => self::metric(count($new), count($new_prev)),
            'messages'  => self::metric(count($messages), count($messages_prev)),
            'customers' => self::metric($this->countCustomers(), $this->countCustomers(true)),
            'conv_day'  => self::metric($this->perDay(count($new)), $this->perDay(count($new_prev))),
            'busy_day'  => ['value' => $this->busiestDay($new), 'change' => null],
        ];

        $types = ['new_conv' => __('New Conversations'), 'messages' => __('Messages Received')];
        $type = ($chart['type'] ?? '') == 'messages' ? 'messages' : 'new_conv';
        $data['chart'] = $type == 'messages'
            ? $this->chart($chart, $types, $messages, $messages_prev)
            : $this->chart($chart, $types, $new, $new_prev);
        $data['chart']['type'] = $type;

        $data['table_customers'] = $this->tableCustomers();
        $data['table_mailboxes'] = count($this->mailbox_ids) > 1 ? $this->tableMailboxes() : [];

        return $data;
    }

    /**
     * Conversations with a message from the customer or a reply.
     */
    public function countActive($prev = false)
    {
        return $this->conversations($prev, null)
            ->whereIn('conversations.id', Thread::whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                ->where('state', Thread::STATE_PUBLISHED)
                ->whereBetween('created_at', $this->range($prev))
                ->select('conversation_id'))
            ->count();
    }

    /**
     * Customers who wrote.
     */
    public function countCustomers($prev = false)
    {
        return $this->threads(Thread::TYPE_CUSTOMER, $prev)->distinct()->count('conversations.customer_id');
    }

    /**
     * The day of the week with the most new conversations on average.
     */
    public function busiestDay(array $dates)
    {
        if (!$dates) {
            return null;
        }
        $counts = array_fill(0, 7, 0);
        foreach ($dates as $date) {
            $counts[(int) \Carbon\Carbon::parse($date, 'UTC')->setTimezone($this->timezone)->format('w')]++;
        }
        // How often each day of the week is in the period.
        $days = array_fill(0, 7, 0);
        for ($i = 0; $i < $this->days; $i++) {
            $days[(int) $this->from->addDays($i)->format('w')]++;
        }
        $best = null;
        foreach ($counts as $weekday => $count) {
            if ($days[$weekday] && ($best === null || $count / $days[$weekday] > $counts[$best] / $days[$best])) {
                $best = $weekday;
            }
        }

        return \Carbon\Carbon::now()->locale(app()->getLocale())->startOfWeek(\Carbon\Carbon::SUNDAY)->addDays($best)->dayName;
    }

    /**
     * Customers with the most messages.
     */
    public function tableCustomers()
    {
        $rows = $this->threads(Thread::TYPE_CUSTOMER)
            ->whereNotNull('conversations.customer_id')
            ->groupBy('conversations.customer_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::TOP)
            ->get([\DB::raw(\DB::getTablePrefix().'conversations.customer_id'), \DB::raw('COUNT(*) AS messages')]);
        $customers = Customer::whereIn('id', $rows->pluck('customer_id'))->get()->keyBy('id');

        $table = [];
        foreach ($rows as $row) {
            $customer = $customers[$row->customer_id] ?? null;
            if ($customer) {
                $table[] = [
                    'customer_id' => $customer->id,
                    'name'        => $customer->getFullName(true),
                    'email'       => $customer->getMainEmail(),
                    'messages'    => (int) $row->messages,
                ];
            }
        }

        return $table;
    }

    /**
     * Each mailbox: new conversations, messages, replies, closed, and the
     * median first response and resolution times.
     */
    public function tableMailboxes()
    {
        $count = function ($query) {
            return $query->groupBy('conversations.mailbox_id')
                ->get([\DB::raw(\DB::getTablePrefix().'conversations.mailbox_id'), \DB::raw('COUNT(*) AS aggregate')])
                ->pluck('aggregate', 'mailbox_id');
        };
        $new = $count($this->conversations());
        $messages = $count($this->threads(Thread::TYPE_CUSTOMER));
        $replies = $count($this->replies());
        $closed = $count($this->closed());
        $first_responses = $this->recordedReplies()->where(Replies::TABLE.'.first', true)
            ->get(['conversations.mailbox_id', Replies::TABLE.'.response_time'])
            ->groupBy('mailbox_id');
        $resolutions = $this->closed()->get(['conversations.mailbox_id', 'conversations.created_at', 'conversations.closed_at'])
            ->groupBy('mailbox_id');

        $table = [];
        foreach ($this->mailboxNames()->sort() as $mailbox_id => $name) {
            $table[] = [
                'mailbox_id'          => $mailbox_id,
                'name'                => $name,
                'new'                 => (int) ($new[$mailbox_id] ?? 0),
                'messages'            => (int) ($messages[$mailbox_id] ?? 0),
                'replies'             => (int) ($replies[$mailbox_id] ?? 0),
                'closed'              => (int) ($closed[$mailbox_id] ?? 0),
                'first_response_time' => self::median(($first_responses[$mailbox_id] ?? collect())->pluck('response_time')->map('intval')->all()),
                'resolution_time'     => self::median(($resolutions[$mailbox_id] ?? collect())->map(function ($conversation) {
                    return ProductivityReport::resolutionTime($conversation);
                })->all()),
            ];
        }

        return $table;
    }
}
