<?php

namespace App\Workflows;

use App\Conversation;
use App\Thread;
use App\User;
use App\Workflow;
use Carbon\Carbon;

/**
 * What workflows can check about a conversation: the conditions, by group,
 * with their operators, values and the events (triggers) that check them.
 * Modules add theirs with the workflows.conditions_config filter and check
 * them with workflow.check_condition.
 */
class Conditions
{
    /**
     * Conditions that depend on time passing.
     */
    const DATE_TYPES = ['waiting', 'user_reply', 'customer_reply', 'created'];

    const TEXT_TRIGGERS = ['conversation.created_by_user', 'conversation.user_forwarded', 'conversation.created_by_customer', 'conversation.moved'];

    /**
     * Seconds per unit of a date condition.
     */
    const UNITS = ['i' => 60, 'h' => 3600, 'd' => 86400];

    public static function textOperators()
    {
        return [
            'equal'        => __('Is equal to'),
            'contains'     => __('Contains'),
            'not_contains' => __('Does not contain'),
            'not_equal'    => __('Is not equal to'),
            'starts'       => __('Starts with'),
            'ends'         => __('Ends with'),
            'regex'        => __('Matches regex pattern'),
        ];
    }

    /**
     * The conditions by group: {group: {title, items: {key: {title,
     * operators, values (select; [] = none), triggers}}}}.
     */
    public static function config($mailbox_id = null)
    {
        $users = [-1 => __('Anyone')];
        $user_query = User::nonDeleted()->where('type', '!=', User::TYPE_ROBOT)->orderBy('first_name');
        if ($mailbox_id && ($mailbox = \App\Mailbox::find($mailbox_id))) {
            $user_query->whereIn('id', $mailbox->usersHavingAccess(false, 'users.*', false)->pluck('id'));
        }
        foreach ($user_query->get() as $user) {
            $users[$user->id] = $user->getFullName();
        }
        $is = ['equal' => __('Is equal to'), 'not_equal' => __('Is not equal to')];
        $yes_no = ['yes' => __('Yes'), 'no' => __('No')];
        $longer = ['longer' => __('Longer than'), 'not_longer' => __('Not longer than')];
        $in_last = ['in_last' => __('In the last'), 'not_in_last' => __('Not in the last')];
        $all = array_merge(self::TEXT_TRIGGERS, ['conversation.customer_replied', 'conversation.user_replied']);

        $config = [
            'people' => [
                'title' => __('People'),
                'items' => [
                    'customer_name' => ['title' => __('Customer Name'), 'operators' => self::textOperators(), 'triggers' => self::TEXT_TRIGGERS],
                    'customer_email' => ['title' => __('Customer Email'), 'operators' => self::textOperators(), 'triggers' => self::TEXT_TRIGGERS],
                    'user_action' => [
                        'title'     => __('User Action'),
                        'operators' => ['replied' => __('Replied'), 'noted' => __('Added a note')],
                        'values'    => [-1 => __('Any User')] + array_slice($users, 1, null, true),
                        'triggers'  => ['conversation.created_by_user', 'conversation.user_replied', 'conversation.note_added'],
                    ],
                ],
            ],
            'conversation' => [
                'title' => __('Conversation'),
                'items' => [
                    'type' => ['title' => __('Type'), 'operators' => $is, 'values' => [Conversation::TYPE_EMAIL => __('Email'), Conversation::TYPE_PHONE => __('Phone')], 'triggers' => self::TEXT_TRIGGERS],
                    'status' => [
                        'title'     => __('Status'),
                        'operators' => $is,
                        'values'    => Conversation::getStatusesWithNames(),
                        'triggers'  => array_merge(self::TEXT_TRIGGERS, ['conversation.status_changed']),
                    ],
                    'state' => [
                        'title'     => __('State'),
                        'operators' => $is,
                        'values'    => [Conversation::STATE_DRAFT => __('Draft'), Conversation::STATE_PUBLISHED => __('Published'), Conversation::STATE_DELETED => __('Deleted')],
                        'triggers'  => ['conversation.state_changed'],
                    ],
                    'user' => [
                        'title'     => __('Assigned to User'),
                        'operators' => $is,
                        'values'    => [-1 => __('Unassigned')] + array_slice($users, 1, null, true),
                        'triggers'  => array_merge(self::TEXT_TRIGGERS, ['conversation.user_changed']),
                    ],
                    'to' => ['title' => __('To'), 'operators' => self::textOperators(), 'triggers' => self::TEXT_TRIGGERS],
                    'cc' => ['title' => __('Cc'), 'operators' => self::textOperators(), 'triggers' => self::TEXT_TRIGGERS],
                    'subject' => ['title' => __('Subject'), 'operators' => self::textOperators(), 'triggers' => self::TEXT_TRIGGERS],
                    'body' => [
                        'title'     => __('Body'),
                        'operators' => ['customer' => __('Customer message contains'), 'note' => __('Note contains'), 'regex' => __('Matches regex pattern')],
                        'triggers'  => ['conversation.created_by_customer', 'conversation.customer_replied', 'conversation.note_added', 'conversation.moved'],
                    ],
                    'headers' => [
                        'title'     => __('Headers'),
                        'operators' => ['contains' => __('Contains'), 'not_contains' => __('Does not contain'), 'regex' => __('Matches regex pattern')],
                        'triggers'  => ['conversation.created_by_customer', 'conversation.customer_replied'],
                    ],
                    'attachment' => [
                        'title'     => __('Attachment'),
                        'operators' => ['yes' => __('Has an attachment'), 'no' => __('No attachments')],
                        'values'    => [],
                        'triggers'  => $all,
                    ],
                    'bounce' => ['title' => __('Is Bounce'), 'operators' => $yes_no, 'values' => [], 'triggers' => ['conversation.created_by_customer']],
                    'imported' => ['title' => __('Imported'), 'operators' => $yes_no, 'values' => [], 'triggers' => $all],
                    'customer_viewed' => ['title' => __('Customer Viewed'), 'operators' => $yes_no, 'values' => [], 'triggers' => ['thread.opened']],
                    'new_or_reply' => [
                        'title'     => __('New / Reply / Moved'),
                        'operators' => ['new' => __('New conversation created'), 'reply' => __('User or customer replied'), 'moved' => __('Conversation moved from another mailbox')],
                        'values'    => [],
                        'triggers'  => $all,
                    ],
                    'channel' => [
                        'title'     => __('Communication Channel'),
                        'operators' => $is,
                        'values'    => \Eventy::filter('channels.list', []),
                        'triggers'  => $all,
                    ],
                ],
            ],
            'dates' => [
                'title' => __('Dates'),
                'items' => [
                    'waiting' => ['title' => __('Waiting Since'), 'operators' => $longer, 'values_type' => 'date', 'triggers' => []],
                    'user_reply' => ['title' => __('Last User Reply'), 'operators' => $in_last, 'values_type' => 'date', 'triggers' => ['conversation.user_replied']],
                    'customer_reply' => ['title' => __('Last Customer Reply'), 'operators' => $in_last, 'values_type' => 'date', 'triggers' => []],
                    'created' => ['title' => __('Date Created'), 'operators' => $in_last, 'values_type' => 'date', 'triggers' => []],
                ],
            ],
        ];
        if (!$config['conversation']['items']['channel']['values']) {
            unset($config['conversation']['items']['channel']);
        }

        return \Eventy::filter('workflows.conditions_config', $config, $mailbox_id);
    }

    /**
     * A condition's settings, or null.
     */
    public static function item($type, $mailbox_id = null)
    {
        foreach (self::config($mailbox_id) as $group) {
            if (isset($group['items'][$type])) {
                return $group['items'][$type];
            }
        }

        return null;
    }

    /**
     * Whether the conversation meets the conditions: every AND group has a
     * row that's true. $trigger: the event (null when time passes).
     */
    public static function check(Workflow $workflow, Conversation $conversation, $trigger = null)
    {
        if ($conversation->state == Conversation::STATE_DRAFT) {
            return false;
        }
        $groups = $workflow->getConditions();
        if (!$groups) {
            return false;
        }
        // Events only check workflows with a condition about them.
        if ($trigger) {
            $triggers = [];
            $moved = false;
            foreach ($groups as $group) {
                foreach ($group as $row) {
                    $triggers = array_merge($triggers, (array) (self::triggers($row['type'], $workflow->mailbox_id)));
                    $moved = $moved || ($row['type'] == 'new_or_reply' && ($row['operator'] ?? '') == 'moved');
                }
            }
            if (!in_array($trigger, $triggers) || ($trigger == 'conversation.moved' && !$moved)) {
                return false;
            }
        }
        foreach ($groups as $group) {
            $any = false;
            foreach ($group as $row) {
                if (self::checkRow($row, $conversation, $workflow, $trigger)) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                return false;
            }
        }

        return true;
    }

    protected static $triggers = [];

    protected static function triggers($type, $mailbox_id)
    {
        $key = $type.'|'.$mailbox_id;
        if (!array_key_exists($key, self::$triggers)) {
            self::$triggers[$key] = self::item($type, $mailbox_id)['triggers'] ?? [];
        }

        return self::$triggers[$key];
    }

    public static function checkRow(array $row, Conversation $conversation, Workflow $workflow, $trigger = null)
    {
        $operator = $row['operator'] ?? '';
        $value = $row['value'] ?? null;

        switch ($row['type']) {
            case 'customer_name':
                return $conversation->customer && self::compareText($conversation->customer->getFullName(), $operator, $value);

            case 'customer_email':
                $emails = $conversation->customer ? $conversation->customer->emails->pluck('email')->all() : [];
                if (!$emails && $conversation->customer_email) {
                    $emails = [$conversation->customer_email];
                }

                return self::compareList($emails, $operator, $value);

            case 'user_action':
                $thread = Runner::lastThread($conversation);
                if (!$thread || !$thread->created_by_user_id) {
                    return false;
                }
                $type = $operator == 'noted' ? Thread::TYPE_NOTE : Thread::TYPE_MESSAGE;

                return $thread->type == $type && ((int) $value == -1 || (int) $value == $thread->created_by_user_id);

            case 'type':
            case 'status':
            case 'state':
            case 'channel':
                $equal = (int) $conversation->{$row['type']} == (int) $value;

                return $operator == 'not_equal' ? !$equal : $equal;

            case 'user':
                $equal = (int) $conversation->user_id == max(0, (int) $value);

                return $operator == 'not_equal' ? !$equal : $equal;

            case 'to':
            case 'cc':
                $thread = $conversation->getLastReply();
                if (!$thread) {
                    return false;
                }

                return self::compareList($row['type'] == 'to' ? $thread->getToArray() : $thread->getCcArray(), $operator, $value);

            case 'subject':
                return self::compareText((string) $conversation->subject, $operator, $value);

            case 'body':
                foreach (self::bodyThreads($conversation, $operator, $trigger) as $thread) {
                    $text = \App\Search\Indexer::htmlToText($thread->body);
                    if ($operator == 'regex' ? self::compareText($text, 'regex', $value) : self::compareText($text, 'contains', $value)) {
                        return true;
                    }
                }

                return false;

            case 'headers':
                $threads = $trigger
                    ? array_filter([Runner::lastThread($conversation)], fn ($thread) => $thread && $thread->type == Thread::TYPE_CUSTOMER)
                    : $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->get()->all();
                $found = false;
                foreach ($threads as $thread) {
                    if (self::compareText((string) $thread->headers, $operator == 'regex' ? 'regex' : 'contains', $value)) {
                        $found = true;
                        break;
                    }
                }

                return $operator == 'not_contains' ? ($threads && !$found) : $found;

            case 'attachment':
                return $operator == 'no' ? !$conversation->has_attachments : (bool) $conversation->has_attachments;

            case 'bounce':
                $thread = $conversation->getLastThread([Thread::TYPE_CUSTOMER]);
                if (!$thread) {
                    return false;
                }

                return $operator == 'no' ? !$thread->isBounce() : $thread->isBounce();

            case 'imported':
                $thread = Runner::lastThread($conversation);
                $imported = $thread ? (bool) $thread->imported : (bool) $conversation->imported;

                return $operator == 'no' ? !$imported : $imported;

            case 'customer_viewed':
                $thread = $conversation->getLastThread([Thread::TYPE_MESSAGE]);
                $viewed = $thread && $thread->opened_at;

                return $operator == 'no' ? !$viewed : (bool) $viewed;

            case 'new_or_reply':
                switch ($operator) {
                    case 'new':
                        return in_array($trigger, ['conversation.created_by_customer', 'conversation.created_by_user']);
                    case 'reply':
                        return in_array($trigger, ['conversation.customer_replied', 'conversation.user_replied']);
                    case 'moved':
                        return $trigger == 'conversation.moved';
                }

                return false;

            case 'waiting':
            case 'user_reply':
            case 'customer_reply':
            case 'created':
                return self::checkDate($row['type'], $operator, $value, $conversation);
        }

        return (bool) \Eventy::filter('workflow.check_condition', false, $row['type'], $operator, $value, $conversation, $workflow);
    }

    /**
     * A time span of a date condition in seconds ({number, metric}), or null.
     */
    public static function seconds($value)
    {
        $number = (int) ($value['number'] ?? 0);
        $unit = self::UNITS[$value['metric'] ?? ''] ?? null;

        return $number > 0 && $unit ? $number * $unit : null;
    }

    protected static function checkDate($type, $operator, $value, Conversation $conversation)
    {
        $seconds = self::seconds($value);
        if (!$seconds) {
            return false;
        }
        $since = Carbon::now()->subSeconds($seconds);

        switch ($type) {
            case 'waiting':
                // Waiting on a reply from the team.
                if (!in_array($conversation->status, [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
                    || $conversation->last_reply_from == Conversation::PERSON_USER || !$conversation->last_reply_at
                ) {
                    return false;
                }

                return $operator == 'not_longer' ? $conversation->last_reply_at > $since : $conversation->last_reply_at < $since;

            case 'user_reply':
                $date = $conversation->last_reply_from == Conversation::PERSON_USER
                    ? $conversation->last_reply_at
                    : optional($conversation->getLastThread([Thread::TYPE_MESSAGE]))->created_at;
                break;

            case 'customer_reply':
                $date = $conversation->getLastCustomerReplyAt();
                break;

            default:
                $date = $conversation->created_at;
                break;
        }
        if (!$date) {
            return false;
        }
        $date = Carbon::parse($date);

        return $operator == 'not_in_last' ? $date < $since : $date > $since;
    }

    /**
     * Threads whose text a body condition looks at: the new one when
     * something happened, else every one of the kind.
     */
    protected static function bodyThreads(Conversation $conversation, $operator, $trigger)
    {
        $type = $operator == 'note' ? Thread::TYPE_NOTE : Thread::TYPE_CUSTOMER;
        if ($trigger) {
            $thread = Runner::lastThread($conversation);

            return $thread && $thread->type == $type ? [$thread] : [];
        }

        return $conversation->threads()->where('type', $type)->where('state', Thread::STATE_PUBLISHED)->get()->all();
    }

    public static function compareText($text, $operator, $value)
    {
        if (!is_string($text) || !is_scalar($value)) {
            return false;
        }
        $value = (string) $value;
        if ($operator == 'regex') {
            try {
                return (bool) @preg_match($value, $text);
            } catch (\Throwable $e) {
                return false;
            }
        }
        $text = mb_strtolower($text);
        $value = mb_strtolower($value);

        switch ($operator) {
            case 'equal':
                return $text === $value;
            case 'not_equal':
                return $text !== $value;
            case 'contains':
                return $value !== '' && mb_strpos($text, $value) !== false;
            case 'not_contains':
                return $value === '' || mb_strpos($text, $value) === false;
            case 'starts':
                return $value !== '' && str_starts_with($text, $value);
            case 'ends':
                return $value !== '' && str_ends_with($text, $value);
        }

        return false;
    }

    /**
     * A list (addresses): negative operators hold for every item, the
     * others for one.
     */
    public static function compareList(array $items, $operator, $value)
    {
        if (in_array($operator, ['not_equal', 'not_contains'])) {
            foreach ($items as $item) {
                if (!self::compareText((string) $item, $operator, $value)) {
                    return false;
                }
            }

            return true;
        }
        foreach ($items as $item) {
            if (self::compareText((string) $item, $operator, $value)) {
                return true;
            }
        }

        return false;
    }
}
