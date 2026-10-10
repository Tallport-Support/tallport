<?php

namespace App\Misc;

use App\Customer;
use App\Thread;
use Illuminate\Support\Facades\Http;

/**
 * Customer photos looked up online (a setting: a service from SERVICES, or
 * none): for a customer without a photo of their own, when they email or their
 * conversation is opened. Photos older than REFRESH_DAYS are removed
 * (tallport:clean-customer-photos), so a customer still in touch gets a fresh
 * one and the others none. Gravatar gets the address's SHA-256 hash, Unavatar
 * the address itself. Without a photo: initials, or Gravatar's generated image
 * (DEFAULT_OPTION), asked for when the service has none and kept as such
 * (GENERATED_META), so a real photo replaces it.
 */
class CustomerPhotos
{
    /**
     * The service: a key of SERVICES, or 'none'. Unset: DEFAULT_SERVICE.
     */
    const OPTION = 'customer_photos';

    const NONE = 'none';

    const SERVICES = ['gravatar' => 'Gravatar', 'unavatar' => 'Unavatar'];

    /**
     * Gravatar: it only receives a hash of the address.
     */
    const DEFAULT_SERVICE = 'gravatar';

    /**
     * Customer meta: when a service was asked (named when Gravatar was the
     * only one), and which one (none: Gravatar).
     */
    const META = 'gravatar_checked_at';

    const SERVICE_META = 'photo_service';

    /**
     * Customer meta: the style of Gravatar's generated image they have.
     */
    const GENERATED_META = 'photo_generated';

    /**
     * Without a photo: Gravatar's generated image (d=), or initials (none set).
     */
    const DEFAULT_OPTION = 'customer_gravatar_default';

    const DEFAULTS = ['robohash' => 'Robohash', 'identicon' => 'Identicon', 'retro' => 'Retro', 'monsterid' => 'MonsterID', 'wavatar' => 'Wavatar'];

    const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * How long a photo looked up online is kept.
     */
    const REFRESH_DAYS = 90;

    /**
     * The service chosen, or null for none.
     */
    public static function service()
    {
        $service = \Option::get(self::OPTION, self::DEFAULT_SERVICE);

        return is_string($service) && array_key_exists($service, self::SERVICES) ? $service : null;
    }

    public static function isEnabled()
    {
        return (bool) self::service();
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
            \App\Jobs\FetchCustomerPhoto::dispatch($customer->id, $email);
        }
    }

    /**
     * Whether to look up a photo: no photo of their own, and not asked lately
     * (by this service).
     */
    public static function isDue(Customer $customer)
    {
        if ($customer->photo_url && $customer->photo_type != Customer::PHOTO_TYPE_LOOKED_UP) {
            return false;
        }
        if (($customer->getMeta(self::SERVICE_META) ?: self::DEFAULT_SERVICE) != self::service()) {
            return true;
        }
        if ($customer->photo_url && $customer->getMeta(self::GENERATED_META) && $customer->getMeta(self::GENERATED_META) != self::generatedStyle()) {
            return true;
        }
        $checked_at = $customer->getMeta(self::META);

        return !$checked_at || \Carbon\Carbon::parse($checked_at)->lt(now()->subDays(self::REFRESH_DAYS));
    }

    /**
     * Removes the photos looked up online older than REFRESH_DAYS, or all of
     * them when there's no service any more; how many.
     */
    public static function removeOld()
    {
        $removed = 0;
        $enabled = self::isEnabled();
        Customer::where('photo_type', Customer::PHOTO_TYPE_LOOKED_UP)->where('photo_url', '!=', '')->whereNotNull('photo_url')
            ->chunkById(500, function ($customers) use (&$removed, $enabled) {
                foreach ($customers as $customer) {
                    $checked_at = $customer->getMeta(self::META);
                    if ($enabled && $checked_at && \Carbon\Carbon::parse($checked_at)->gte(now()->subDays(self::REFRESH_DAYS))) {
                        continue;
                    }
                    $customer->removePhoto();
                    $customer->setMeta(self::META, null);
                    $customer->setMeta(self::SERVICE_META, null);
                    $customer->setMeta(self::GENERATED_META, null);
                    $customer->save();
                    $removed++;
                }
            });

        return $removed;
    }

    /**
     * The style of Gravatar's generated image chosen, or null (initials).
     */
    public static function generatedStyle()
    {
        $style = (string) \Option::get(self::DEFAULT_OPTION, '');

        return array_key_exists($style, self::DEFAULTS) ? $style : null;
    }

    /**
     * The customer's own photo at the service: 404 when there's none.
     */
    public static function url($service, $email)
    {
        $email = strtolower(trim($email));

        switch ($service) {
            case 'unavatar':
                return 'https://unavatar.io/'.rawurlencode($email).'?fallback=false';

            default:
                return self::gravatarUrl($email, '404');
        }
    }

    /**
     * Gravatar's image generated for the address, in the style chosen.
     */
    public static function generatedUrl($email, $style)
    {
        return self::gravatarUrl(strtolower(trim($email)), $style);
    }

    protected static function gravatarUrl($email, $default)
    {
        return 'https://gravatar.com/avatar/'.hash('sha256', $email).'?d='.$default.'&s='.(int) config('app.customer_photo_size');
    }

    /**
     * Save the customer's photo from the service as their photo. Returns
     * whether there was one.
     */
    public static function fetch(Customer $customer, $email)
    {
        $service = self::service();
        if (!$service || !self::isDue($customer)) {
            return false;
        }
        $found = false;
        $generated = null;
        try {
            $response = self::get(self::url($service, $email));
            if ($response->status() == 429 || $response->serverError()) {
                // Not now (too many lookups): asked again for the next address.
                return false;
            }
            $found = self::save($customer, $response);
            if (!$found && $response->status() == 404 && self::generatedStyle()) {
                // None: Gravatar's generated image.
                $generated = self::generatedStyle();
                $found = self::save($customer, self::get(self::generatedUrl($email, $generated)));
            }
            if (!$found && $response->status() == 404 && $customer->photo_url) {
                // Their photo is gone (or the other service has none).
                $customer->removePhoto();
            }
        } catch (\Throwable $e) {
            // Not now: asked again for the next address.
            \Helper::logException($e, '['.self::SERVICES[$service].']');

            return false;
        }
        $customer->setMeta(self::META, now()->toDateTimeString());
        $customer->setMeta(self::SERVICE_META, $service);
        $customer->setMeta(self::GENERATED_META, $found ? $generated : null);
        $customer->save();

        return $found;
    }

    protected static function get($url)
    {
        return Http::withOptions(\Helper::setGuzzleDefaultOptions(['timeout' => 20]))->get($url);
    }

    /**
     * Saves the image in the response as the customer's photo; whether it did.
     */
    protected static function save(Customer $customer, $response)
    {
        $mime_type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (!$response->successful() || !in_array($mime_type, self::MIME_TYPES)) {
            return false;
        }
        $file = \Helper::getTempFileName();
        file_put_contents($file, $response->body());
        $photo_url = $customer->savePhoto($file, $mime_type);
        @unlink($file);
        if (!$photo_url) {
            return false;
        }
        $customer->photo_url = $photo_url;
        $customer->photo_type = Customer::PHOTO_TYPE_LOOKED_UP;

        return true;
    }
}
