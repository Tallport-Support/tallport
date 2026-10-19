<?php

namespace App\Console\Commands;

use App\Auth\TrustedDevices;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The host's administrator (HOSTING.md): outranks the other administrators, who can't change or
 * remove their account. Set from the command line only, so it can't be taken away in the browser.
 */
class Operator extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:operator
                            {email : The operator\'s email address; the account is created when there is none}
                            {--first-name=Operator : First name of a new account}
                            {--reset : A new password, and two-factor authentication and passkeys turned off}
                            {--remove : Make them a regular administrator again}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a user the operator, who outranks the other administrators';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $user = User::nonDeleted()->where('email', $this->argument('email'))->first();

        if ($this->option('remove')) {
            if (!$user || !$user->operator) {
                $this->error('No operator with this email address.');

                return 1;
            }
            $user->operator = false;
            $user->save();
            $this->info($user->email.' is a regular administrator now.');

            return 0;
        }

        $password = null;
        if (!$user) {
            $password = Str::password(20, symbols: false);
            $user = User::create([
                'first_name' => $this->option('first-name'),
                'last_name'  => '',
                'email'      => $this->argument('email'),
                'password'   => $password,
            ]);
            if (!$user) {
                $this->error('The account could not be created. Check the email address.');

                return 1;
            }
            $user->invite_state = User::INVITE_STATE_ACTIVATED;
            $user->timezone = config('app.timezone') ?: User::DEFAULT_TIMEZONE;
        } elseif ($this->option('reset')) {
            $password = Str::password(20, symbols: false);
            $user->password = \Hash::make($password);
            $user->forceFill([
                'two_factor_secret'         => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at'   => null,
            ]);
            $user->passkeys()->delete();
            TrustedDevices::forget($user);
        }

        $user->role = User::ROLE_ADMIN;
        $user->operator = true;
        $user->status = User::STATUS_ACTIVE;
        $user->save();

        $this->info($user->email.' is the operator.');
        if ($password) {
            $this->line('Password: '.$password);
        }

        return 0;
    }
}
