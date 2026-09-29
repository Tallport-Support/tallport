<?php

namespace Tests\Concerns;

use App\Customer;
use App\Mailbox;
use App\User;

/**
 * Builders for the models tests need, created the way the application
 * creates them (observers run, folders get made), with explicit values
 * instead of random ones so tests don't depend on each other or on order.
 *
 * Deliberately not Laravel model factories: their API changed in Laravel 8,
 * and keeping creation here means an upgrade touches one file.
 */
trait CreatesModels
{
    protected static $model_sequence = 0;

    protected function uniqueEmail($name)
    {
        return $name.'-'.(++static::$model_sequence).'@example.org';
    }

    protected function createUser(array $attributes = [])
    {
        $user = new User();
        $user->fill(array_merge([
            'first_name' => 'Agent',
            'last_name'  => 'Smith',
            'email'      => $this->uniqueEmail('agent'),
            'password'   => \Hash::make('password'),
        ], $attributes));
        $user->role = $attributes['role'] ?? User::ROLE_USER;
        $user->status = $attributes['status'] ?? User::STATUS_ACTIVE;
        $user->invite_state = User::INVITE_STATE_ACTIVATED;
        $user->save();

        return $user;
    }

    protected function createAdmin(array $attributes = [])
    {
        return $this->createUser(array_merge(['first_name' => 'Admin', 'role' => User::ROLE_ADMIN], $attributes));
    }

    /**
     * A mailbox that sends through PHP mail() (captured in tests), with the
     * given users given access, as MailboxesController does it.
     */
    protected function createMailbox(array $users = [], array $attributes = [])
    {
        $mailbox = new Mailbox();
        $mailbox->fill(array_merge([
            'name'  => 'Support',
            'email' => $this->uniqueEmail('support'),
        ], $attributes));
        $mailbox->out_method = $attributes['out_method'] ?? Mailbox::OUT_METHOD_PHP_MAIL;
        $mailbox->save();

        if ($users) {
            $user_ids = array_map(function ($user) {
                return $user->id;
            }, $users);
            $mailbox->users()->sync($user_ids);
            $mailbox->syncPersonalFolders($user_ids);
        }

        return $mailbox;
    }

    protected function createCustomer($email = null, array $attributes = [])
    {
        return Customer::create($email ?: $this->uniqueEmail('customer'), array_merge([
            'first_name' => 'Casey',
            'last_name'  => 'Customer',
        ], $attributes));
    }
}
