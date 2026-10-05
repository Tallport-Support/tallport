<?php

namespace App\Misc;

use App\Customer;
use App\Thread;
use Illuminate\Support\Facades\Http;

/**
 * Customer photos from Gravatar (a setting): looked up for a customer without
 * a photo of their own when they email or their conversation is opened. Photos
 * older than REFRESH_DAYS are removed (tallport:clean-gravatars), so a customer
 * still in touch gets a fresh one and the others none. Without a Gravatar:
 * initials, or Gravatar's generated image (DEFAULT_OPTION). Gravatar gets the
 * address's SHA-256 hash.
 */
class Gravatar
{
    const OPTION = 'customer_gravatar';

    /**
     * Customer meta: when Gravatar was asked.
     */
    const META = 'gravatar_checked_at';

    /**
     * Without a Gravatar: Gravatar's generated image (d=), or initials (none set).
     */
    const DEFAULT_OPTION = 'customer_gravatar_default';

    const DEFAULTS = ['robohash' => 'Robohash', 'identicon' => 'Identicon', 'retro' => 'Retro', 'monsterid' => 'MonsterID', 'wavatar' => 'Wavatar'];

    /**
     * How long a photo from Gravatar is kept.
     */
    const REFRESH_DAYS = 90;

    public static function isEnabled()
    {
        return (bool) \Option::get(self::OPTION, false);
    }

    public static function listen()
    {
        // (The customer is set on a new thread after it's first saved.)
        Thread::saved(function ($thread) {
            if ($thread->wasRecentlyCreated && $thread->type == Thread::TYPE_CUSTOMER && $thread->created_by_customer_id && $thread->from) {
                self::request(Customer::find($thread->created_by_customer_id), $thread->from);
            }
        });
    }

    public static function request(?Customer $customer, $email)
    {
        if (self::isEnabled() && $customer && $email && self::isDue($customer)) {
            \App\Jobs\FetchGravatar::dispatch($customer->id, $email);
        }
    }

    /**
     * Whether to ask Gravatar: no photo of their own, and not asked lately.
     */
    public static function isDue(Customer $customer)
    {
        if ($customer->photo_url && $customer->photo_type != Customer::PHOTO_TYPE_GRAVATAR) {
            return false;
        }
        $checked_at = $customer->getMeta(self::META);

        return !$checked_at || \Carbon\Carbon::parse($checked_at)->lt(now()->subDays(self::REFRESH_DAYS));
    }

    /**
     * Removes the Gravatar photos older than REFRESH_DAYS; how many.
     */
    public static function removeOld()
    {
        $removed = 0;
        Customer::where('photo_type', Customer::PHOTO_TYPE_GRAVATAR)->where('photo_url', '!=', '')->whereNotNull('photo_url')
            ->chunkById(500, function ($customers) use (&$removed) {
                foreach ($customers as $customer) {
                    $checked_at = $customer->getMeta(self::META);
                    if ($checked_at && \Carbon\Carbon::parse($checked_at)->gte(now()->subDays(self::REFRESH_DAYS))) {
                        continue;
                    }
                    $customer->removePhoto();
                    $customer->setMeta(self::META, null);
                    $customer->save();
                    $removed++;
                }
            });

        return $removed;
    }

    public static function url($email)
    {
        $default = (string) \Option::get(self::DEFAULT_OPTION, '');

        return 'https://gravatar.com/avatar/'.hash('sha256', strtolower(trim($email)))
            .'?d='.(array_key_exists($default, self::DEFAULTS) ? $default : '404').'&s='.(int) config('app.customer_photo_size');
    }

    /**
     * Save the customer's Gravatar as their photo. Returns whether there was one.
     */
    public static function fetch(Customer $customer, $email)
    {
        if (!self::isDue($customer)) {
            return false;
        }
        $found = false;
        try {
            $response = Http::withOptions(\Helper::setGuzzleDefaultOptions(['timeout' => 20]))->get(self::url($email));
            $mime_type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if ($response->successful() && in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif'])) {
                $file = \Helper::getTempFileName();
                file_put_contents($file, $response->body());
                $photo_url = $customer->savePhoto($file, $mime_type);
                @unlink($file);
                if ($photo_url) {
                    $customer->photo_url = $photo_url;
                    $customer->photo_type = Customer::PHOTO_TYPE_GRAVATAR;
                    $found = true;
                }
            } elseif ($response->status() == 404 && $customer->photo_url) {
                // Their Gravatar is gone.
                $customer->removePhoto();
            }
        } catch (\Throwable $e) {
            // Not now: asked again for the next address.
            \Helper::logException($e, '[Gravatar]');

            return false;
        }
        $customer->setMeta(self::META, now()->toDateTimeString());
        $customer->save();

        return $found;
    }
}
