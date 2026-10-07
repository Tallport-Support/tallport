<?php

namespace App\Retention;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\TeamMessage;
use App\Thread;
use Carbon\Carbon;

/**
 * How long Tallport keeps things (Settings » Retention), and the daily job that
 * removes them (tallport:retention).
 *
 * A closed conversation's clock is the latest of its last message (customer or
 * team; Conversation::last_reply_at), its customer's last contact and a restore
 * by hand (retention_reset_at). When it
 * runs out the conversation expires: soft-deleted into the Deleted folder
 * (expired_at), restorable, and deleted for good after the grace period. A
 * customer's message restores their expired conversations in every mailbox. An
 * optional maximum age expires conversations whatever the customer's activity.
 * The Deleted folder, spam, customers without conversations, team chat
 * messages and logs have their own periods. A legal hold (retention_hold_at) on a conversation or its
 * customer keeps it.
 */
class Retention
{
    /**
     * Settings: option => default.
     */
    const OPTIONS = [
        'retention_enabled'          => false,
        'retention_keep_months'      => 24,
        'retention_grace_days'       => 90,
        // 0: off.
        'retention_max_age_years'    => 0,
        'retention_trash_days'       => 30,
        'retention_spam_days'        => 30,
        'retention_customer_months'  => 12,
        'retention_team_chat_months' => 12,
        'retention_send_log_months'  => 6,
        'retention_notification_months' => 6,
        'retention_activity_log_days' => 90,
    ];

    /**
     * The choices for each period (Settings » Retention); others aren't kept.
     */
    const CHOICES = [
        'retention_keep_months'         => [6, 12, 18, 24, 36, 48, 60, 84, 120],
        'retention_grace_days'          => [30, 60, 90, 180, 365],
        'retention_max_age_years'       => [0, 3, 5, 7, 10],
        'retention_trash_days'          => [7, 14, 30, 60, 90],
        'retention_spam_days'           => [7, 14, 30, 60, 90],
        'retention_customer_months'     => [3, 6, 12, 24, 36],
        'retention_team_chat_months'    => [1, 3, 6, 12, 24, 36],
        'retention_send_log_months'     => [1, 3, 6, 12],
        'retention_notification_months' => [1, 3, 6, 12],
        'retention_activity_log_days'   => [30, 90, 180, 365],
    ];

    /**
     * Failed jobs and Telegram updates (seen update IDs): not a setting.
     */
    const FAILED_JOBS_DAYS = 30;

    const TELEGRAM_UPDATES_DAYS = 30;

    /**
     * The last run's counts (System Status).
     */
    const LAST_RUN_OPTION = 'retention_last_run';

    const BATCH = 500;

    /**
     * Values to use instead of the saved ones: the settings page's preview of
     * unsaved changes (App\Livewire\RetentionPreview).
     */
    public static $overrides = [];

    public static function get($option)
    {
        $value = array_key_exists($option, self::$overrides) ? self::$overrides[$option] : \Option::get($option, self::OPTIONS[$option]);
        if ($option == 'retention_enabled') {
            return (bool) $value;
        }

        return in_array((int) $value, self::CHOICES[$option]) ? (int) $value : self::OPTIONS[$option];
    }

    /**
     * A period's choice as people say it, in the user's language ("24 months").
     */
    public static function period($option, $value)
    {
        $unit = substr($option, strrpos($option, '_') + 1);
        if ($unit == 'months' && $value % 12 == 0) {
            [$unit, $value] = ['years', $value / 12];
        }
        $interval = $unit == 'days' ? \Carbon\CarbonInterval::days($value) : ($unit == 'years' ? \Carbon\CarbonInterval::years($value) : \Carbon\CarbonInterval::months($value));
        // Carbon's codes: kz is kk, pt-BR pt_BR.
        $locale = str_replace(['-', 'kz'], ['_', 'kk'], app()->getLocale());

        return $interval->locale($locale)->forHumans(['skip' => ['week']]);
    }

    public static function isEnabled()
    {
        return self::get('retention_enabled');
    }

    /**
     * A customer wrote: their last contact, and their expired conversations back.
     */
    public static function listen()
    {
        Thread::saved(function ($thread) {
            if ($thread->wasRecentlyCreated && $thread->type == Thread::TYPE_CUSTOMER && $thread->created_by_customer_id) {
                self::customerContacted(Customer::find($thread->created_by_customer_id));
            }
        });
    }

    public static function customerContacted(?Customer $customer)
    {
        if (!$customer) {
            return;
        }
        Customer::where('id', $customer->id)->update(['last_contact_at' => now()]);
        foreach (self::restorable()->where('customer_id', $customer->id)->get() as $conversation) {
            self::restore($conversation);
        }
    }

    /**
     * Expired conversations a customer's contact brings back (not past the maximum age).
     */
    protected static function restorable()
    {
        $query = Conversation::whereNotNull('expired_at')->where('state', Conversation::STATE_DELETED);
        if ($years = self::get('retention_max_age_years')) {
            $query->where('last_reply_at', '>=', now()->subYears($years));
        }

        return $query;
    }

    /**
     * An expired conversation back where it was (Closed in its folder).
     */
    public static function restore(Conversation $conversation)
    {
        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->expired_at = null;
        $conversation->updateFolder();
        $conversation->save();
        \Eventy::action('retention.conversation_restored', $conversation);
    }

    /**
     * Runs every step; their counts. $dry_run: only counts what would happen.
     */
    public static function run($dry_run = false)
    {
        $counts = ['logs' => self::cleanLogs($dry_run)];
        if (self::isEnabled() || $dry_run) {
            $counts['trash'] = self::deleteTrash($dry_run);
            $counts['spam'] = self::deleteSpam($dry_run);
            $counts['deleted'] = self::deleteExpired($dry_run);
            $counts['expired'] = self::expire($dry_run);
            $counts['customers'] = self::deleteCustomers($dry_run);
            $counts['team_chat'] = self::deleteTeamMessages($dry_run);
        }
        if (!$dry_run) {
            \Option::set(self::LAST_RUN_OPTION, ['at' => now()->toDateTimeString()] + $counts);
            if (array_sum(array_map(fn ($count) => is_array($count) ? array_sum($count) : $count, $counts))) {
                \Helper::log(\App\ActivityLog::NAME_SYSTEM, 'Retention', $counts);
            }
        }

        return $counts;
    }

    /**
     * Conversations nothing keeps (no legal hold on them or their customer).
     */
    protected static function unheld($query)
    {
        return $query->whereNull('conversations.retention_hold_at')
            ->whereNotIn('conversations.customer_id', Customer::whereNotNull('retention_hold_at')->select('id'));
    }

    /**
     * The Deleted folder: conversations agents deleted, after the trash period.
     */
    public static function deleteTrash($dry_run = false)
    {
        $query = self::unheld(Conversation::where('conversations.state', Conversation::STATE_DELETED)->whereNull('conversations.expired_at')
            ->where('conversations.user_updated_at', '<', now()->subDays(self::get('retention_trash_days'))));

        return self::deleteForever($query, $dry_run);
    }

    public static function deleteSpam($dry_run = false)
    {
        $query = self::unheld(Conversation::where('conversations.status', Conversation::STATUS_SPAM)->where('conversations.state', Conversation::STATE_PUBLISHED)
            ->where('conversations.updated_at', '<', now()->subDays(self::get('retention_spam_days'))));

        return self::deleteForever($query, $dry_run);
    }

    /**
     * Expired conversations after the grace period.
     */
    public static function deleteExpired($dry_run = false)
    {
        $query = self::unheld(Conversation::where('conversations.state', Conversation::STATE_DELETED)->whereNotNull('conversations.expired_at')
            ->where('conversations.expired_at', '<', now()->subDays(self::get('retention_grace_days'))));

        return self::deleteForever($query, $dry_run);
    }

    /**
     * Closed conversations whose clock ran out, and any past the maximum age: soft-deleted.
     */
    public static function expire($dry_run = false)
    {
        $keep_until = now()->subMonths(self::get('retention_keep_months'));
        $query = self::unheld(Conversation::where('conversations.state', Conversation::STATE_PUBLISHED)->where('conversations.status', '!=', Conversation::STATUS_SPAM))
            ->leftJoin('customers', 'customers.id', '=', 'conversations.customer_id')
            ->where(function ($query) use ($keep_until) {
                $query->where(function ($query) use ($keep_until) {
                    $query->where('conversations.status', Conversation::STATUS_CLOSED)
                        ->where('conversations.last_reply_at', '<', $keep_until)
                        ->where(function ($query) use ($keep_until) {
                            $query->whereNull('conversations.retention_reset_at')->orWhere('conversations.retention_reset_at', '<', $keep_until);
                        })
                        ->where(function ($query) use ($keep_until) {
                            $query->whereNull('customers.last_contact_at')->orWhere('customers.last_contact_at', '<', $keep_until);
                        });
                });
                if ($years = self::get('retention_max_age_years')) {
                    $query->orWhere('conversations.last_reply_at', '<', now()->subYears($years));
                }
            })
            ->select('conversations.*');
        if ($dry_run) {
            return $query->count();
        }
        $count = 0;
        $query->chunkById(self::BATCH, function ($conversations) use (&$count) {
            foreach ($conversations as $conversation) {
                $conversation->state = Conversation::STATE_DELETED;
                $conversation->expired_at = now();
                $conversation->updateFolder();
                $conversation->save();
                $count++;
            }
            \Eventy::action('retention.conversations_expired', $conversations->pluck('id')->all());
        }, 'conversations.id', 'id');

        return $count;
    }

    protected static function deleteForever($query, $dry_run)
    {
        if ($dry_run) {
            return $query->count();
        }
        $count = 0;
        while ($ids = (clone $query)->limit(self::BATCH)->pluck('conversations.id')->all()) {
            Conversation::deleteConversationsForever($ids);
            $count += count($ids);
        }

        return $count;
    }

    /**
     * Customers without conversations, no contact for the period, and no hold.
     */
    public static function deleteCustomers($dry_run = false)
    {
        $before = now()->subMonths(self::get('retention_customer_months'));
        $query = Customer::whereNull('retention_hold_at')
            ->whereNotExists(function ($query) {
                $query->select(\DB::raw(1))->from('conversations')->whereColumn('conversations.customer_id', 'customers.id');
            })
            ->where(function ($query) use ($before) {
                $query->where('last_contact_at', '<', $before)
                    ->orWhere(function ($query) use ($before) {
                        $query->whereNull('last_contact_at')->where('created_at', '<', $before);
                    });
            });
        if ($dry_run) {
            return $query->count();
        }
        $count = 0;
        $query->chunkById(self::BATCH, function ($customers) use (&$count) {
            $ids = $customers->pluck('id')->all();
            \Eventy::action('retention.customers_deleting', $ids);
            foreach ($customers as $customer) {
                $customer->removePhoto();
            }
            \DB::table('emails')->whereIn('customer_id', $ids)->delete();
            \DB::table('customer_channel')->whereIn('customer_id', $ids)->delete();
            \DB::table('nostr_customer_keys')->whereIn('customer_id', $ids)->delete();
            Customer::whereIn('id', $ids)->delete();
            $count += count($ids);
        });

        return $count;
    }

    /**
     * Team chat messages older than their period, with their files.
     */
    public static function deleteTeamMessages($dry_run = false)
    {
        $query = TeamMessage::where('created_at', '<', now()->subMonths(self::get('retention_team_chat_months')));
        if ($dry_run) {
            return $query->count();
        }
        $count = 0;
        while ($ids = (clone $query)->limit(self::BATCH)->pluck('id')->all()) {
            Attachment::deleteForever(Attachment::whereIn('team_message_id', $ids)->get());
            TeamMessage::whereIn('id', $ids)->delete();
            $count += count($ids);
        }

        return $count;
    }

    /**
     * Logs and records that only matter for a while.
     */
    public static function cleanLogs($dry_run = false)
    {
        $queries = [
            'send_log'      => \DB::table('send_logs')->where('created_at', '<', now()->subMonths(self::get('retention_send_log_months'))),
            'notifications' => \DB::table('notifications')->where('created_at', '<', now()->subMonths(self::get('retention_notification_months'))),
            'activity_log'  => \DB::table('activity_logs')->where('created_at', '<', now()->subDays(self::get('retention_activity_log_days'))),
            'failed_jobs'   => \DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(self::FAILED_JOBS_DAYS)),
            'telegram'      => \DB::table('telegram_updates')->where('created_at', '<', now()->subDays(self::TELEGRAM_UPDATES_DAYS)),
            // The AI log: what no budget or conversation's token count needs (Usage::expiredLog()).
            'ai_log'        => \App\Ai\Usage::expiredLog(),
        ];
        $counts = [];
        foreach ($queries as $name => $query) {
            $counts[$name] = $dry_run ? $query->count() : $query->delete();
        }

        return $counts;
    }

    /**
     * Stored files no record points to any more: attachments and customer photos
     * (older than a day, so files being saved are left alone). Weekly.
     */
    public static function sweepFiles($dry_run = false)
    {
        $removed = 0;
        $day_ago = now()->subDay()->getTimestamp();

        $disk = \Storage::disk(Attachment::getDiskName());
        foreach (array_chunk($disk->allFiles(Attachment::DIRECTORY), self::BATCH) as $files) {
            $known = [];
            $names = array_map('basename', $files);
            foreach (Attachment::whereIn('file_name', $names)->get(['file_dir', 'file_name']) as $attachment) {
                $known[Attachment::DIRECTORY.DIRECTORY_SEPARATOR.$attachment->file_dir.$attachment->file_name] = true;
            }
            foreach ($files as $file) {
                if (!isset($known[$file]) && $disk->lastModified($file) < $day_ago) {
                    $removed++;
                    if (!$dry_run) {
                        $disk->delete($file);
                    }
                }
            }
        }

        $photos = \Storage::disk('local');
        foreach (array_chunk($photos->files(Customer::PHOTO_DIRECTORY), self::BATCH) as $files) {
            $known = array_flip(Customer::whereIn('photo_url', array_map('basename', $files))->pluck('photo_url')->all());
            foreach ($files as $file) {
                if (!isset($known[basename($file)]) && $photos->lastModified($file) < $day_ago) {
                    $removed++;
                    if (!$dry_run) {
                        $photos->delete($file);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Conversations waiting for their final deletion, and when the next one goes.
     */
    public static function pending()
    {
        $query = Conversation::where('state', Conversation::STATE_DELETED)->whereNotNull('expired_at');
        $first = (clone $query)->min('expired_at');

        return [
            'count' => $query->count(),
            'next'  => $first ? Carbon::parse($first)->addDays(self::get('retention_grace_days')) : null,
        ];
    }
}
