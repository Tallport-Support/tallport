<?php

namespace App\Api;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Email;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;

/**
 * The API's JSON for conversations, threads, customers, users, mailboxes,
 * folders and webhooks: camelCase fields, dates in UTC (2026-10-03T09:15:00Z),
 * codes by name ("active", "email"). Webhooks send the same JSON.
 */
class Format
{
    public static function date($value)
    {
        if (!$value) {
            return null;
        }
        $date = $value instanceof \DateTimeInterface ? \Carbon\Carbon::instance($value) : \Carbon\Carbon::parse($value);

        return $date->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * A conversation. $embed: threads (default), as in ?embed=threads.
     */
    public static function conversation(Conversation $conversation, $embed = ['threads'])
    {
        $created_by = $conversation->source_via == Conversation::PERSON_USER
            ? self::userShort($conversation->created_by_user)
            : self::customerShort($conversation->created_by_customer);
        $closed = $conversation->status == Conversation::STATUS_CLOSED;

        $waiting_since = '';
        try {
            $waiting_since = $conversation->folder ? $conversation->getWaitingSince() : '';
        } catch (\Throwable $e) {
            // Without a folder.
        }

        $data = [
            'id'           => $conversation->id,
            'number'       => $conversation->number,
            'threadsCount' => (int) $conversation->threads_count,
            'type'         => Conversation::$types[$conversation->type] ?? 'email',
            'folderId'     => $conversation->folder_id,
            'status'       => Conversation::$statuses[$conversation->status] ?? 'active',
            'state'        => Conversation::$states[$conversation->state] ?? 'published',
            'subject'      => (string) $conversation->subject,
            'preview'      => (string) $conversation->preview,
            'mailboxId'    => $conversation->mailbox_id,
            'assignee'     => self::userShort($conversation->user),
            'createdBy'    => $created_by,
            'createdAt'    => self::date($conversation->created_at),
            'updatedAt'    => self::date($conversation->updated_at),
            'closedBy'     => $closed ? $conversation->closed_by_user_id : null,
            'closedByUser' => $closed ? self::userShort($conversation->closed_by_user) : null,
            'closedAt'     => self::date($conversation->closed_at),
            'userUpdatedAt' => self::date($conversation->user_updated_at),
            'customerWaitingSince' => [
                'time'            => self::date($conversation->last_reply_at),
                'friendly'        => $waiting_since,
                'latestReplyFrom' => Conversation::$persons[$conversation->last_reply_from] ?? '',
            ],
            'source' => [
                'type' => Conversation::$source_types[$conversation->source_type] ?? 'email',
                'via'  => Conversation::$persons[$conversation->source_via] ?? 'customer',
            ],
            'cc'       => $conversation->getCcArray(),
            'bcc'      => $conversation->getBccArray(),
            'customer' => self::customerShort($conversation->customer),
            '_embedded' => [
                'threads' => [],
            ],
        ];

        if (in_array('threads', $embed)) {
            $threads = $conversation->threads()
                ->whereIn('state', [Thread::STATE_PUBLISHED, Thread::STATE_DRAFT])
                ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                ->with('attachments')
                ->get();
            foreach ($threads as $thread) {
                $data['_embedded']['threads'][] = self::thread($thread, $conversation);
            }
        }

        return $data;
    }

    public static function thread(Thread $thread, ?Conversation $conversation = null)
    {
        $conversation = $conversation ?: $thread->conversation;

        $action_text = '';
        if ($thread->action_type) {
            try {
                $action_text = trim(html_entity_decode(strip_tags($thread->getActionDescription($conversation->number ?? '', false))));
            } catch (\Throwable $e) {
                // Unknown action.
            }
        }

        return [
            'id'     => $thread->id,
            'type'   => Thread::$types[$thread->type] ?? 'customer',
            'status' => Thread::$statuses[$thread->status] ?? 'nochange',
            'state'  => Thread::$states[$thread->state] ?? 'published',
            'action' => [
                'type'               => $thread->getActionTypeName(),
                'text'               => $action_text,
                'associatedEntities' => [],
            ],
            'body'   => (string) $thread->body,
            'source' => [
                'type' => Thread::$source_types[$thread->source_type] ?? 'email',
                'via'  => Thread::$persons[$thread->source_via] ?? 'customer',
            ],
            'customer'   => self::customerShort($thread->customer),
            'createdBy'  => $thread->source_via == Thread::PERSON_USER
                ? self::userShort($thread->created_by_user)
                : self::customerShort($thread->created_by_customer),
            'assignedTo' => self::userShort($thread->user),
            'to'         => $thread->getToArray(),
            'cc'         => $thread->getCcArray(),
            'bcc'        => $thread->getBccArray(),
            'createdAt'  => self::date($thread->created_at),
            'openedAt'   => self::date($thread->opened_at),
            '_embedded'  => [
                'attachments' => $thread->attachments->map(function ($attachment) {
                    return self::attachment($attachment);
                })->values()->all(),
            ],
        ];
    }

    public static function attachment(Attachment $attachment)
    {
        return [
            'id'       => $attachment->id,
            'fileName' => $attachment->file_name,
            'fileUrl'  => $attachment->url(),
            'mimeType' => $attachment->mime_type,
            'size'     => (int) $attachment->size,
        ];
    }

    public static function customer(Customer $customer)
    {
        return [
            'id'        => $customer->id,
            'firstName' => (string) $customer->first_name,
            'lastName'  => (string) $customer->last_name,
            'jobTitle'  => (string) $customer->job_title,
            'company'   => (string) $customer->company,
            'photoType' => Customer::$photo_types[$customer->photo_type] ?? 'unknown',
            'photoUrl'  => $customer->getPhotoUrl(false),
            'createdAt' => self::date($customer->created_at),
            'updatedAt' => self::date($customer->updated_at),
            'notes'     => (string) $customer->notes,
            '_embedded' => [
                'emails' => $customer->emails->map(function ($email) {
                    return ['id' => $email->id, 'value' => $email->email, 'type' => Email::$types[$email->type] ?? 'work'];
                })->values()->all(),
                'phones' => array_map(function ($phone) {
                    return ['id' => 0, 'value' => (string) ($phone['value'] ?? ''), 'type' => Customer::$phone_types[$phone['type'] ?? 0] ?? 'work'];
                }, $customer->getPhones()),
                'social_profiles' => array_map(function ($profile) {
                    return ['id' => 0, 'value' => (string) ($profile['value'] ?? ''), 'type' => Customer::$social_types[$profile['type'] ?? 0] ?? 'other'];
                }, $customer->getSocialProfiles()),
                'websites' => array_map(function ($website) {
                    return ['id' => 0, 'value' => is_array($website) ? (string) ($website['value'] ?? '') : (string) $website];
                }, $customer->getWebsites()),
                'address' => [
                    'city'    => (string) $customer->city,
                    'state'   => (string) $customer->state,
                    'zip'     => (string) $customer->zip,
                    'country' => (string) $customer->country,
                    'address' => (string) $customer->address,
                ],
            ],
        ];
    }

    public static function customerShort(?Customer $customer)
    {
        if (!$customer) {
            return null;
        }

        return [
            'id'        => $customer->id,
            'type'      => 'customer',
            'firstName' => (string) $customer->first_name,
            'lastName'  => (string) $customer->last_name,
            'photoUrl'  => $customer->getPhotoUrl(false),
            'email'     => (string) $customer->getMainEmail(),
        ];
    }

    public static function user(User $user)
    {
        return [
            'id'              => $user->id,
            'firstName'       => (string) $user->first_name,
            'lastName'        => (string) $user->last_name,
            'email'           => $user->email,
            'role'            => User::$roles[$user->role] ?? 'user',
            'alternateEmails' => (string) $user->emails,
            'jobTitle'        => (string) $user->job_title,
            'phone'           => (string) $user->phone,
            'timezone'        => (string) $user->timezone,
            'photoUrl'        => $user->getPhotoUrl(false),
            'language'        => (string) $user->locale,
            'available'       => self::available($user),
            'createdAt'       => self::date($user->created_at),
            'updatedAt'       => self::date($user->updated_at),
        ];
    }

    public static function userShort(?User $user)
    {
        if (!$user) {
            return null;
        }

        return [
            'id'        => $user->id,
            'type'      => 'user',
            'firstName' => (string) $user->first_name,
            'lastName'  => (string) $user->last_name,
            'photoUrl'  => $user->getPhotoUrl(false),
            'email'     => $user->email,
            'available' => self::available($user),
        ];
    }

    /**
     * Whether the user is available (modules such as Out of Office decide).
     */
    protected static function available(User $user)
    {
        return (bool) \Eventy::filter('user.is_user_available', $user->available ?? true, $user);
    }

    public static function mailbox(Mailbox $mailbox)
    {
        return [
            'id'        => $mailbox->id,
            'name'      => $mailbox->name,
            'email'     => $mailbox->email,
            'createdAt' => self::date($mailbox->created_at),
            'updatedAt' => self::date($mailbox->updated_at),
        ];
    }

    public static function folder(Folder $folder)
    {
        return [
            'id'          => $folder->id,
            'name'        => $folder->getTypeName(),
            'type'        => (int) $folder->type,
            'userId'      => $folder->user_id,
            'totalCount'  => (int) $folder->total_count,
            'activeCount' => (int) $folder->active_count,
            'meta'        => $folder->meta,
        ];
    }

    public static function webhook(Webhook $webhook)
    {
        return [
            'id'           => $webhook->id,
            'url'          => $webhook->url,
            'events'       => array_values((array) $webhook->events),
            'mailboxes'    => array_values(array_map('intval', (array) $webhook->mailboxes)),
            'lastRunTime'  => self::date($webhook->last_run_time),
            'lastRunError' => (string) $webhook->last_run_error,
        ];
    }
}
