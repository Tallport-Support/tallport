<?php

namespace App\Api;

use App\Conversation;
use App\Customer;
use App\Email;
use App\Thread;
use App\User;

/**
 * Creating and changing customers and threads from API input (camelCase;
 * snake_case is accepted too). Methods return [result, error]: error is
 * [message, path, status] when the input can't be used.
 */
class Writer
{
    /**
     * A value of $data by camelCase or snake_case name.
     */
    public static function get($data, $name, $default = null)
    {
        if (!is_array($data)) {
            return $default;
        }
        foreach ([$name, \Str::snake($name)] as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return $default;
    }

    /**
     * A code from its name (or the code itself).
     */
    public static function code($map, $value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_numeric($value) && array_key_exists((int) $value, $map)) {
            return (int) $value;
        }
        $code = array_search(strtolower((string) $value), array_map('strtolower', $map));

        return $code === false ? $default : $code;
    }

    /**
     * A date as the database stores it (server time zone).
     */
    public static function date($value)
    {
        try {
            return \Carbon\Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The customer of a conversation or thread: by id, email or phone, or
     * a new one.
     */
    public static function resolveCustomer($data)
    {
        // Zapier sends a list.
        if (is_array($data) && array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        if (!is_array($data) || !$data) {
            return [null, ['`customer` parameter is required', 'customer', 400]];
        }

        $id = self::get($data, 'id');
        if ($id) {
            $customer = \Eventy::filter('api.customer.find', Customer::find($id), request());

            return $customer ? [$customer, null] : [null, ['Customer not found', 'customer.id', 400]];
        }
        $email = Email::sanitizeEmail((string) self::get($data, 'email'));
        if ($email && ($customer = Customer::getByEmail($email))) {
            return [$customer, null];
        }
        $phone = self::get($data, 'phone');
        if ($phone && ($customer = Customer::findByPhone($phone))) {
            return [$customer, null];
        }

        return self::createCustomer($data);
    }

    /**
     * A new customer (never changes an existing one).
     */
    public static function createCustomer($data)
    {
        [$fields, $emails, $error] = self::customerFields($data);
        if ($error) {
            return [null, $error];
        }
        if ($emails && Email::whereIn('email', $emails)->count() == count($emails)) {
            return [null, ['Customers with such email(s) already exist', 'emails', 400]];
        }
        if (empty($fields['first_name']) && !$emails) {
            return [null, ['Customer first name or email is required.', 'customer.first_name', 400]];
        }

        $email_types = $fields['email_types'];
        $customer = new Customer();
        self::fill($customer, $fields);
        $customer->save();
        foreach ($emails as $i => $email) {
            if (!Email::where('email', $email)->exists()) {
                $customer->emails()->save(new Email(['email' => $email, 'type' => $email_types[$i] ?? Email::TYPE_WORK]));
            }
        }
        self::savePhoto($customer, self::get($data, 'photoUrl'));
        \Eventy::action('customer.created', $customer);

        return [$customer->fresh(), null];
    }

    /**
     * Change a customer: the fields given; emails replaces them all,
     * emails_add adds some.
     */
    public static function updateCustomer(Customer $customer, $data)
    {
        [$fields, $emails, $error] = self::customerFields($data, true);
        if ($error) {
            return [null, $error];
        }
        self::fill($customer, $fields);
        if (self::get($data, 'emails') !== null) {
            $customer->syncEmails($emails);
        } elseif ($add = self::get($data, 'emailsAdd')) {
            foreach ((array) $add as $email) {
                $email = Email::sanitizeEmail(is_array($email) ? ($email['value'] ?? '') : $email);
                if ($email) {
                    $customer->addEmail($email, true);
                }
            }
        }
        $customer->save();
        self::savePhoto($customer, self::get($data, 'photoUrl'));

        return [$customer->fresh(), null];
    }

    /**
     * Customer input as setData() takes it, and the email addresses.
     */
    protected static function customerFields($data, $update = false)
    {
        $fields = [];
        foreach (['firstName', 'lastName', 'jobTitle', 'company', 'notes'] as $name) {
            $value = self::get($data, $name);
            if ($value !== null) {
                $fields[\Str::snake($name)] = (string) $value;
            }
        }
        $photo_type = self::get($data, 'photoType');
        if ($photo_type !== null) {
            $fields['photo_type'] = self::code(Customer::$photo_types, $photo_type, Customer::PHOTO_TYPE_UKNOWN);
        }

        $address = self::get($data, 'address');
        $address = is_array($address) ? $address : [];
        if ($update) {
            $address = array_merge($address, array_intersect_key((array) $data, array_flip(['city', 'state', 'zip', 'country'])));
        }
        foreach (['city', 'state', 'zip'] as $name) {
            if (isset($address[$name])) {
                $fields[$name] = (string) $address[$name];
            }
        }
        if (isset($address['country'])) {
            $country = strtoupper((string) $address['country']);
            $fields['country'] = array_key_exists($country, Customer::$countries) ? $country : '';
        }
        if (isset($address['lines']) && is_array($address['lines'])) {
            $fields['address'] = implode(', ', $address['lines']);
        } elseif (isset($address['address'])) {
            $fields['address'] = (string) $address['address'];
        } elseif ($update && is_string(self::get($data, 'address'))) {
            $fields['address'] = self::get($data, 'address');
        }

        $phones = [];
        foreach ((array) self::get($data, 'phones', []) as $phone) {
            if (is_array($phone) && !empty($phone['value'])) {
                $phones[] = ['value' => (string) $phone['value'], 'type' => self::code(Customer::$phone_types, $phone['type'] ?? null, Customer::PHONE_TYPE_WORK)];
            }
        }
        if (self::get($data, 'phone')) {
            $phones[] = ['value' => (string) self::get($data, 'phone'), 'type' => Customer::PHONE_TYPE_WORK];
        }
        if ($phones) {
            $fields['phones'] = $phones;
        }
        $profiles = [];
        foreach ((array) self::get($data, 'socialProfiles', []) as $profile) {
            if (is_array($profile) && !empty($profile['value'])) {
                $profiles[] = ['value' => (string) $profile['value'], 'type' => self::code(Customer::$social_types, $profile['type'] ?? null, Customer::SOCIAL_TYPE_OTHER)];
            }
        }
        if ($profiles) {
            $fields['social_profiles'] = $profiles;
        }
        $websites = [];
        foreach ((array) self::get($data, 'websites', []) as $website) {
            $website = is_array($website) ? ($website['value'] ?? '') : $website;
            if ($website) {
                $websites[] = (string) $website;
            }
        }
        if ($websites) {
            $fields['websites'] = $websites;
        }

        $emails = [];
        $types = [];
        $list = (array) self::get($data, 'emails', []);
        if (self::get($data, 'email')) {
            $list[] = ['value' => self::get($data, 'email'), 'type' => 'work'];
        }
        foreach ($list as $email) {
            $value = is_array($email) ? ($email['value'] ?? '') : $email;
            $sanitized = Email::sanitizeEmail((string) $value);
            if (!$sanitized) {
                return [null, null, ['Invalid email: '.$value, 'emails', 400]];
            }
            if (!in_array($sanitized, $emails)) {
                $emails[] = $sanitized;
                $types[] = self::code(Email::$types, is_array($email) ? ($email['type'] ?? null) : null, Email::TYPE_WORK);
            }
        }
        $fields['email_types'] = $types;

        return [$fields, $emails, null];
    }

    /**
     * Fields that setData() doesn't take are set directly.
     */
    protected static function fill(Customer $customer, $fields)
    {
        unset($fields['email_types']);
        if (isset($fields['photo_type'])) {
            $customer->photo_type = $fields['photo_type'];
            unset($fields['photo_type']);
        }
        $customer->setData($fields);
    }

    protected static function savePhoto(Customer $customer, $url)
    {
        if (!$url) {
            return;
        }
        $path = \Helper::downloadRemoteFileAsTmp($url);
        if ($path) {
            $photo = $customer->savePhoto($path, mime_content_type($path) ?: '');
            if ($photo) {
                $customer->photo_url = $photo;
                $customer->save();
            }
            @unlink($path);
        }
    }

    /**
     * Add a customer reply, agent reply or note to a conversation, as the
     * app does.
     */
    public static function createThread(Conversation $conversation, $data, ApiAccess $access)
    {
        $type = self::code(Thread::$types, self::get($data, 'type'), null);
        if (!$type) {
            return [null, ['`type` parameter is required', 'type', 400]];
        }
        $text = (string) self::get($data, 'text', '');
        if ($text === '') {
            return [null, ['`text` parameter is required', 'text', 400]];
        }

        $customer = null;
        $user = null;
        if ($type == Thread::TYPE_CUSTOMER) {
            if (self::get($data, 'customer')) {
                [$customer, $error] = self::resolveCustomer(self::get($data, 'customer'));
                if ($error) {
                    return [null, $error];
                }
            } else {
                $customer = $conversation->customer;
            }
            if (!$customer) {
                return [null, ['`customer` parameter is required', 'customer', 400]];
            }
        } else {
            $user_id = self::get($data, 'user');
            if (!$user_id) {
                return [null, ['`user` parameter is required', 'user', 400]];
            }
            $user = User::find($user_id);
            if (!$user || $user->isDeleted()) {
                return [null, ['User not found', 'user', 400]];
            }
            if (!$access->canActAs($user->id)) {
                return [null, ['Forbidden: API key can only act as its owner', 'user', 403]];
            }
        }

        $status = self::get($data, 'status');
        $status = $status !== null ? self::code(Conversation::$statuses, $status, null) : null;
        $imported = (bool) self::get($data, 'imported', false);
        $attachments = [];
        foreach ((array) self::get($data, 'attachments', []) as $attachment) {
            if (!is_array($attachment) || !self::get($attachment, 'fileName') || !self::get($attachment, 'mimeType')) {
                continue;
            }
            $attachments[] = [
                'file_name' => self::get($attachment, 'fileName'),
                'mime_type' => self::get($attachment, 'mimeType'),
                'data'      => self::get($attachment, 'data'),
                'file_url'  => self::get($attachment, 'fileUrl'),
            ];
        }

        $previous_status = $conversation->status;
        // Not the first thread of a new conversation.
        $had_threads = $conversation->threads_count > 0;
        $thread = Thread::createExtended([
            'type'               => $type,
            'body'               => $text,
            'customer_id'        => $customer->id ?? null,
            'created_by_user_id' => $user->id ?? null,
            'state'              => self::code(Thread::$states, self::get($data, 'state'), Thread::STATE_PUBLISHED),
            'status'             => $type == Thread::TYPE_NOTE ? null : $status,
            'imported'           => $imported,
            'created_at'         => $imported ? self::get($data, 'createdAt') : null,
            'cc'                 => (array) self::get($data, 'cc', []),
            'bcc'                => (array) self::get($data, 'bcc', []),
            'attachments'        => $attachments,
        ], $conversation, $customer);

        if (!$thread) {
            return [null, ['Error occurred creating a thread', 'type', 400]];
        }
        $to = \MailHelper::sanitizeEmails((array) self::get($data, 'to', []));
        if ($to && $type != Thread::TYPE_CUSTOMER) {
            $thread->setTo($to);
            $thread->save();
        }

        if ($user && $status) {
            if ($type == Thread::TYPE_NOTE && $status != $previous_status) {
                $conversation->changeStatus($status, $user);
            } elseif ($type != Thread::TYPE_NOTE && $status == Conversation::STATUS_CLOSED) {
                $conversation->closed_by_user_id = $user->id;
                $conversation->closed_at = now();
                $conversation->save();
                if ($previous_status != $status && $had_threads) {
                    \Eventy::action('conversation.status_changed', $conversation, $user, true, $previous_status);
                }
            }
        }

        return [$thread, null];
    }
}
