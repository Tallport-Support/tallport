<?php

namespace App\Misc;

use App\Customer;
use App\Thread;
use Illuminate\Support\Facades\Http;

/**
 * Customer photos from Gravatar (a setting): looked up once per customer
 * without a photo, when they email. Gravatar gets the address's SHA-256
 * hash.
 */
class Gravatar
{
    const OPTION = 'customer_gravatar';

    /**
     * Customer meta: when Gravatar was asked.
     */
    const META = 'gravatar_checked_at';

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
        if (self::isEnabled() && $customer && !$customer->photo_url && !$customer->getMeta(self::META) && $email) {
            \App\Jobs\FetchGravatar::dispatch($customer->id, $email);
        }
    }

    public static function url($email)
    {
        return 'https://gravatar.com/avatar/'.hash('sha256', strtolower(trim($email)))
            .'?d=404&s='.(int) config('app.customer_photo_size');
    }

    /**
     * Save the customer's Gravatar as their photo. Returns whether there was one.
     */
    public static function fetch(Customer $customer, $email)
    {
        if ($customer->photo_url || $customer->getMeta(self::META)) {
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
                    $found = true;
                }
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
