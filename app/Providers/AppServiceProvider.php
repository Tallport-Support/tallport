<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // To avoid MySQL error in packages:
        // "SQLSTATE[42000]: Syntax error or access violation: 1071 Specified key was too long; max key length is 767 bytes"
        Schema::defaultStringLength(191);

        // The UI uses Bootstrap 3 (the default pagination markup before Laravel 6).
        \Illuminate\Pagination\Paginator::useBootstrapThree();

        // Guests are sent to the login page (Laravel 13 no longer falls back to it).
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(function () {
            return route('login');
        });

        // Models observers
        \App\Mailbox::observe(\App\Observers\MailboxObserver::class);
        // Eloquent events for this table are not called automatically, so need to be called manually.
        //\App\MailboxUser::observe(\App\Observers\MailboxUserObserver::class);
        \App\Email::observe(\App\Observers\EmailObserver::class);
        \App\User::observe(\App\Observers\UserObserver::class);
        \App\Conversation::observe(\App\Observers\ConversationObserver::class);
        \App\Customer::observe(\App\Observers\CustomerObserver::class);
        \App\Thread::observe(\App\Observers\ThreadObserver::class);
        \App\Attachment::observe(\App\Observers\AttachmentObserver::class);
        \App\Follower::observe(\App\Observers\FollowerObserver::class);
        \Illuminate\Notifications\DatabaseNotification::observe(\App\Observers\DatabaseNotificationObserver::class);
        \App\Search\Indexer::listen();

        // Channels Tallport has (modules add theirs the same way).
        \Eventy::addFilter('channel.name', function ($name, $channel) {
            if ($channel == \App\Nostr\Nostr::channel()) {
                return 'Nostr';
            }

            return $channel == \App\Telegram\Telegram::CHANNEL ? \App\Telegram\Telegram::CHANNEL_NAME : $name;
        }, 10, 2);
        \Eventy::addFilter('channels.list', function ($channels) {
            $channels[\App\Telegram\Telegram::CHANNEL] = \App\Telegram\Telegram::CHANNEL_NAME;
            $channels[\App\Nostr\Nostr::channel()] = 'Nostr';

            return $channels;
        });

        \Validator::extend('safehost', function ($attribute, $value, $parameters, $validator) {
            if (!$value) {
                return true;
            }
            $msg = '';
            try {
                $url = $value;
                if (!preg_match("#^https?://#", $value)) {
                    $url = 'https://'.$url;
                }
                \Helper::sanitizeRemoteUrl($url, true);
            } catch (\Exception $e) {
                $msg = $e->getMessage();
            }
            if ($msg) {
                $validator->errors()->add($attribute, $msg);
                return false;
            }

            return true;
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Hooks (\Eventy) with listeners kept per hook. First, before anything
        // adds a listener.
        $this->app->singleton('eventy', function () {
            return new \App\Misc\Eventy\Events();
        });

        // JS/CSS build files (\Minify, config/minify.config.php).
        $this->app->singleton('minify', function ($app) {
            return new \App\Misc\Minify((array) config('minify.config'), $app->environment());
        });

        // FreeScout's module system on top of nwidart's (App\Modules).
        $this->app->singleton('modules', function ($app) {
            return new \App\Modules\Repository($app, $app['config']->get('modules.paths.modules'));
        });
        $this->app->bind(\Nwidart\Modules\Contracts\RepositoryInterface::class, \App\Modules\Repository::class);

        $this->registerDevBoost();
        $this->registerLegacyMethods();
        $this->registerAuthHooks();

        // FreeScout's SMTP settings and PHP mail() (App\Misc\MailManager).
        // extend() rather than a binding: the mail manager is recreated when
        // mailbox settings change, and extenders apply every time.
        $this->app->extend('mail.manager', function ($manager, $app) {
            return new \App\Misc\MailManager($app);
        });
        \MailHelper::clearImapErrorsOnShutdown();

        // dump() in the browser, with the CSP nonce (Laravel registers its
        // HtmlDumper the same way, except in the console).
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg']) && empty($_SERVER['VAR_DUMPER_FORMAT'])) {
            \App\Misc\CspHtmlDumper::register($this->app->basePath(), $this->app['config']->get('view.compiled'));
        }

        // Forse HTTPS if using CloudFlare "Flexible SSL"
        // https://support.cloudflare.com/hc/en-us/articles/200170416-What-do-the-SSL-options-mean-
        if (\Helper::isHttps()) {
            // $_SERVER['HTTPS'] = 'on';
            // $_SERVER['SERVER_PORT'] = '443';
            $this->app['url']->forceScheme('https');
        }

        // If APP_KEY is not set, redirect to /install.php
        if (!\Config::get('app.key') && !app()->runningInConsole() && !file_exists(storage_path('.installed'))) {
            // Not defined here yet
            //\Artisan::call("tallport:clear-cache");
            redirect(\Helper::getSubdirectory().'/install.php')->send();
        }

        // Process module registration error - disable module and show error to admin
        \Eventy::addFilter('modules.register_error', function ($exception, $module) {

            $msg = __('The :module_name module has been deactivated due to an error: :error_message', ['module_name' => $module->getName(), 'error_message' => $exception->getMessage()]);

            \Log::error($msg);

            // request() does is empty at this stage
            if (!empty($_POST['action']) && $_POST['action'] == 'activate') {

                // During module activation in case of any error we have to deactivate module.
                \App\Module::deactiveModule($module->getAlias());

                \Session::flash('flashes_floating', [[
                    'text' => $msg,
                    'type' => 'danger',
                    'role' => \App\User::ROLE_ADMIN,
                ]]);

                return;
            } elseif (empty($_POST)) {

                // failed to open stream: No such file or directory
                if (strstr($exception->getMessage(), 'No such file or directory')) {
                    \App\Module::deactiveModule($module->getAlias());

                    \Session::flash('flashes_floating', [[
                        'text' => $msg,
                        'type' => 'danger',
                        'role' => \App\User::ROLE_ADMIN,
                    ]]);
                }

                return;
            }

            return $exception;
        }, 10, 2);
    }

    /**
     * Users are loaded through App\Auth\EloquentUserProvider, which lets
     * modules validate passwords (session_guard.validate_credentials).
     */
    protected function registerAuthHooks()
    {
        $this->app['auth']->provider('eloquent', function ($app, array $config) {
            return new \App\Auth\EloquentUserProvider($app['hash'], $config['model']);
        });
    }

    /**
     * Methods that later Laravel versions removed but FreeScout modules call.
     */
    protected function registerLegacyMethods()
    {
        // Event::fire(), removed in Laravel 5.8.
        \Illuminate\Events\Dispatcher::macro('fire', function ($event, $payload = [], $halt = false) {
            return $this->dispatch($event, $payload, $halt);
        });

        // Mail::failures(), removed in Laravel 9. A failed send throws an
        // exception instead, so there are never failed recipients.
        \Illuminate\Mail\Mailer::macro('failures', function () {
            return [];
        });
    }

    /**
     * Laravel Boost (AI agent guidelines and MCP server) is installed into
     * dev/boost/vendor, outside the shipped vendor/, so it is loaded here when
     * present. Boost itself only runs in the local environment or with debug on.
     */
    protected function registerDevBoost()
    {
        $vendor = base_path('dev/boost/vendor');
        if (!file_exists($vendor.'/autoload.php') || $this->app->runningUnitTests()) {
            return;
        }

        require_once $vendor.'/autoload.php';

        $installed = json_decode(file_get_contents($vendor.'/composer/installed.json'), true);
        foreach ($installed['packages'] ?? [] as $package) {
            foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                $this->app->register($provider);
            }
        }
    }
}
