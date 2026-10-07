<?php

namespace App\Workflows;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use App\Workflow;

/**
 * What workflows can do to a conversation. Modules add theirs with the
 * workflows.actions_config filter and do them with workflow.perform_action.
 */
class Actions
{
    /**
     * Actions with an email (or note) to write; their value is JSON:
     * {to, cc, bcc, subject, body, no_signature, conv_history, sender_name}.
     */
    const EMAIL_TYPES = ['reply', 'email_customer', 'forward', 'note'];

    /**
     * Sender name of a workflow's emails.
     */
    const SENDER_ASSIGNEE_OR_MAILBOX = 1;
    const SENDER_MAILBOX = 2;
    const SENDER_WORKFLOW = 3;

    /**
     * Thread meta: the workflow; and the email's settings.
     */
    const META_WORKFLOW = 'workflow_id';
    const META_SUBJECT = 'wf_subj';
    const META_NO_SIGNATURE = 'wf_ns';
    const META_SENDER = 'wf_sn';

    /**
     * {dummy: {items: {key: {title, values?, values_custom?}}}} (one group,
     * as modules expect).
     */
    public static function config($mailbox_id = null)
    {
        $users = [];
        $user_query = User::nonDeleted()->where('type', '!=', User::TYPE_ROBOT)->orderBy('first_name');
        if ($mailbox_id && ($mailbox = Mailbox::find($mailbox_id))) {
            $user_query->whereIn('id', $mailbox->usersHavingAccess(false, 'users.*', false)->pluck('id'));
        }
        foreach ($user_query->get() as $user) {
            $users[$user->id] = $user->getFullName();
        }
        $mailboxes = Mailbox::orderBy('name')->pluck('name', 'id')->all();

        $config = [
            'dummy' => [
                'title' => '',
                'items' => [
                    'notification' => [
                        'title'  => __('Send Email Notification'),
                        'values' => ['assignee' => __('Assignee'), 'last_user' => __('Last user replied')] + $users,
                        'multiple' => true,
                    ],
                    'reply' => ['title' => __('Reply to Conversation'), 'values_type' => 'email'],
                    'email_customer' => ['title' => __('Email the Customer'), 'values_type' => 'email'],
                    'no_autoreply' => ['title' => __('Disable Auto Reply'), 'values' => []],
                    'forward' => ['title' => __('Forward'), 'values_type' => 'email'],
                    'note' => ['title' => __('Add a Note'), 'values_type' => 'email'],
                    'status' => ['title' => __('Change Status'), 'values' => Conversation::getStatusesWithNames()],
                    'assign' => [
                        'title'  => __('Assign to User'),
                        'values' => [-1 => __('Unassigned'), Workflow::ASSIGNEE_CURRENT => __('User running the workflow')] + $users,
                    ],
                    'move' => ['title' => __('Move to Mailbox'), 'values' => $mailboxes],
                    'delete' => ['title' => __('Move to Deleted Folder'), 'values' => []],
                    'delete_forever' => ['title' => __('Delete Forever'), 'values' => []],
                    'stop' => ['title' => __('Stop Processing Workflows'), 'values' => []],
                ],
            ],
        ];

        return \Eventy::filter('workflows.actions_config', $config, $mailbox_id);
    }

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
     * The settings of an email action.
     */
    public static function email($value)
    {
        $email = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($email) ? $email : [];
    }

    /**
     * Do an action. Returns whether it did something (false), or 'stop' /
     * 'deleted'.
     */
    public static function perform(array $row, Conversation $conversation, Workflow $workflow, User $robot, ?User $runner = null)
    {
        $value = $row['value'] ?? null;
        $actor = $runner ?: $robot;

        switch ($row['type']) {
            case 'notification':
                return self::notify($conversation, (array) $value, $robot);

            case 'reply':
            case 'email_customer':
            case 'note':
                $email = self::email($value);
                $body = trim((string) ($email['body'] ?? ''));
                if (trim(strip_tags($body, '<img>')) === '' || $conversation->hasChannel() && $row['type'] != 'note') {
                    return false;
                }
                $meta = [self::META_WORKFLOW => $workflow->id];
                $data = [];
                if ($row['type'] == 'note') {
                    $data['type'] = Thread::TYPE_NOTE;
                } else {
                    $history = $email['conv_history'] ?? ($row['type'] == 'email_customer' ? 'none' : '');
                    if ($history) {
                        $meta[Thread::META_CONVERSATION_HISTORY] = $history;
                    }
                    if (!empty($email['no_signature'])) {
                        $meta[self::META_NO_SIGNATURE] = 1;
                    }
                    if (!empty($email['sender_name'])) {
                        $meta[self::META_SENDER] = (int) $email['sender_name'];
                    }
                    if ($row['type'] == 'email_customer' && trim((string) ($email['subject'] ?? '')) !== '') {
                        $meta[self::META_SUBJECT] = self::vars(trim($email['subject']), $conversation, $robot);
                    }
                    $cc = Conversation::sanitizeEmails($email['cc'] ?? '');
                    if ($row['type'] == 'reply') {
                        $cc = array_merge($cc, $conversation->getCcArray());
                    }
                    $data['cc'] = array_values(array_unique($cc));
                    $data['bcc'] = Conversation::sanitizeEmails($email['bcc'] ?? '');
                }
                $data['meta'] = $meta;
                $conversation->createUserThread($robot, self::vars($body, $conversation, $robot), $data);

                return true;

            case 'forward':
                $email = self::email($value);
                $to = Conversation::sanitizeEmails($email['to'] ?? '')[0] ?? '';
                // Not what a workflow forwarded (no loops).
                if (!$to || trim(strip_tags((string) ($email['body'] ?? ''))) === '' || $conversation->created_by_user_id == $robot->id) {
                    return false;
                }
                $meta = [self::META_WORKFLOW => $workflow->id];
                if (!empty($email['conv_history'])) {
                    $meta[Thread::META_CONVERSATION_HISTORY] = $email['conv_history'];
                }
                if (!empty($email['sender_name'])) {
                    $meta[self::META_SENDER] = (int) $email['sender_name'];
                }
                $conversation->forward($robot, self::vars($email['body'], $conversation, $robot), $to, [
                    'cc'   => Conversation::sanitizeEmails($email['cc'] ?? ''),
                    'bcc'  => Conversation::sanitizeEmails($email['bcc'] ?? ''),
                    'meta' => $meta,
                ], true);

                return true;

            case 'no_autoreply':
                $meta = (array) $conversation->meta;
                if (!empty($meta['ar_off'])) {
                    return false;
                }
                $meta['ar_off'] = true;
                $conversation->meta = $meta;
                $conversation->save();

                return true;

            case 'status':
                if ((int) $value == $conversation->status || !array_key_exists((int) $value, Conversation::$statuses)) {
                    return false;
                }
                $conversation->changeStatus((int) $value, $actor);

                return true;

            case 'assign':
                $user_id = (int) $value;
                if ($user_id == Workflow::ASSIGNEE_CURRENT) {
                    if (!$runner) {
                        return false;
                    }
                    $user_id = $runner->id;
                }
                if ($user_id == -1) {
                    $user_id = Conversation::USER_UNASSIGNED;
                    if (!$conversation->user_id) {
                        return false;
                    }
                } elseif ($user_id == $conversation->user_id || !User::nonDeleted()->where('id', $user_id)->exists()) {
                    return false;
                }
                if (!empty($row['only_if_available']) && ($user = User::find($user_id))
                    && !\Eventy::filter('user.is_user_available', $user->available ?? true, $user)
                ) {
                    return false;
                }
                $conversation->changeUser($user_id, $actor, false);

                return true;

            case 'move':
                $mailbox = Mailbox::find((int) $value);
                if (!$mailbox || $mailbox->id == $conversation->mailbox_id) {
                    return false;
                }
                $conversation->moveToMailbox($mailbox, $actor);

                return true;

            case 'delete':
                if ($conversation->state == Conversation::STATE_DELETED) {
                    return false;
                }
                $conversation->deleteToFolder($actor);

                return true;

            case 'delete_forever':
                $mailbox = $conversation->mailbox;
                Conversation::deleteConversationsForever([$conversation->id]);
                if ($mailbox) {
                    $mailbox->updateFoldersCounters();
                }

                return 'deleted';

            case 'stop':
                return 'stop';
        }

        return (bool) \Eventy::filter('workflow.perform_action', false, $row['type'], $row['operator'] ?? '', $value, $conversation, $workflow);
    }

    /**
     * Email notifications to users: the assignee, the user who replied last,
     * or chosen ones (those with access to the mailbox).
     */
    protected static function notify(Conversation $conversation, array $values, User $robot)
    {
        $user_ids = [];
        foreach ($values as $value) {
            if ($value == 'assignee') {
                $user_ids[] = $conversation->user_id;
            } elseif ($value == 'last_user') {
                $user_ids[] = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)
                    ->where('created_by_user_id', '!=', $robot->id)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->value('created_by_user_id');
            } elseif (is_numeric($value)) {
                $user_ids[] = (int) $value;
            }
        }
        $users = User::nonDeleted()->whereIn('id', array_filter($user_ids))->where('type', '!=', User::TYPE_ROBOT)
            ->where('invite_state', User::INVITE_STATE_ACTIVATED)->get()
            ->filter(fn ($user) => $user->can('view', $conversation));
        $users = \Eventy::filter('users.unpack', $users->all());
        if (!$users) {
            return false;
        }
        $threads = $conversation->threads()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get();
        \App\Jobs\SendNotificationToUsers::dispatch(collect($users), $conversation, $threads)->onQueue('emails');

        return true;
    }

    protected static function vars($text, Conversation $conversation, User $robot)
    {
        return \MailHelper::replaceMailVars($text, [
            'conversation' => $conversation,
            'mailbox'      => $conversation->mailbox,
            'customer'     => $conversation->customer,
            'user'         => $robot,
        ]);
    }

    /**
     * Emails of workflows: no signature, the sender's name, the subject.
     */
    public static function listen()
    {
        \Eventy::addFilter('reply_email.include_signature', function ($include, $thread) {
            return $thread->getMeta(self::META_NO_SIGNATURE) ? false : $include;
        }, 20, 2);

        \Eventy::addFilter('email.reply_to_customer.subject', function ($subject, $conversation, $thread) {
            return $thread && $thread->getMeta(self::META_SUBJECT) ? $thread->getMeta(self::META_SUBJECT) : $subject;
        }, 50, 3);

        \Eventy::addFilter('mailbox.get_mail_from_name', function ($name, $from_user, $conversation, $thread, $mailbox) {
            $sender = $thread ? (int) $thread->getMeta(self::META_SENDER) : 0;
            if (!$sender || $sender == self::SENDER_WORKFLOW || $mailbox->from_name != Mailbox::FROM_NAME_USER) {
                return $name;
            }
            if ($sender == self::SENDER_ASSIGNEE_OR_MAILBOX && $conversation && $conversation->user) {
                return $conversation->user->getFullName();
            }

            return $mailbox->name;
        }, 20, 5);
    }
}
