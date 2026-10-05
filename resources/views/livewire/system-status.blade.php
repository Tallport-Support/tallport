{{-- System Status's checks (App\Livewire\SystemStatus). --}}
@php
    $missing_extensions = [];
    foreach ($php_extensions as $extension_name => $extension_status) {
        if (!$extension_status) {
            $missing_extensions[$extension_name] = config('installer.optional.'.strtolower($extension_name));
        }
    }
    $pcre_jit_off = array_filter([
        __('Web Server') => !\Helper::pcreJitAvailable(),
        'tallport:receive' => (bool) \Option::get('receive_pcre_jit_off'),
    ]);
    $missing_functions = array_keys(array_filter($functions, fn ($status) => !$status));
    $unwritable = array_filter($permissions, fn ($perm) => !$perm['status']);
    $chown_command = 'sudo chown -R www-data:www-data '.base_path();
    $crontab_line = '* * * * * php '.base_path().'/artisan schedule:run >> /dev/null 2>&1';
    $web_cron_url = route('system.cron', ['hash' => \Helper::getWebCronHash()]);

    // The database, as people know it: "MariaDB 11.8.6".
    $db_driver = \DB::connection()->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $db_version = (string) \DB::connection()->getPDO()->getAttribute(\PDO::ATTR_SERVER_VERSION);
    $db_name = str_contains(strtolower($db_version), 'mariadb') ? 'MariaDB' : (['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'][$db_driver] ?? ucfirst($db_driver));
    $db_version = preg_match('#(\d+\.\d+\.\d+)#', $db_version, $db_m) ? $db_m[1] : $db_version;
    $size_label = fn ($size) => preg_replace('#^(\d+)\s*([KMG])$#i', '$1 $2B', trim((string) $size));

    // Problems, called out once at the top: [anchor, sentence, tone].
    $problems = [];
    if ($missing_migrations) {
        $problems[] = ['db', __('Database migrations are pending'), 'danger'];
    }
    if ($redis_uses && $redis_error) {
        $problems[] = ['redis', __('Redis isn\'t reachable'), 'danger'];
    }
    foreach ($missing_extensions as $extension_name => $optional_purpose) {
        $problems[] = $optional_purpose
            ? ['php-extensions', __('The optional :name extension is missing', ['name' => $extension_name]), 'warning']
            : ['php-extensions', __('The :name extension is missing', ['name' => $extension_name]), 'danger'];
    }
    if ($pcre_jit_off) {
        $problems[] = ['php-extensions', __('PCRE JIT is off'), 'warning'];
    }
    foreach ($missing_functions as $function_name) {
        $problems[] = ['functions', __('The :name function is missing', ['name' => $function_name]), 'danger'];
    }
    foreach (array_keys($unwritable) as $perm_path) {
        $problems[] = ['permissions', __(':path isn\'t writable', ['path' => $perm_path]), 'danger'];
    }
    if ($non_writable_cache_file) {
        $problems[] = ['permissions', __('Some cache files aren\'t writable'), 'danger'];
    }
    if (!$public_symlink_exists) {
        $problems[] = ['permissions', __('The public/storage link is missing'), 'danger'];
    }
    if (!$env_is_writable) {
        $problems[] = ['permissions', __(':path isn\'t writable', ['path' => '.env']), 'danger'];
    }
    if ($invalid_symlinks) {
        $problems[] = ['permissions', __('Invalid or missing modules symlinks'), 'danger'];
    }
    // Just updated: background commands restart within a minute or two.
    $updated_at = (int) \Option::get('app_updated_at');
    $just_updated = $updated_at && time() - $updated_at < 5 * 60;
    foreach ($commands as $command) {
        if ($command['status'] != 'success') {
            $problems[] = ['cron', __(':command isn\'t running', ['command' => $command['name']]), $just_updated ? 'warning' : 'danger'];
        }
    }
    if (count($failed_jobs)) {
        $problems[] = ['failed-jobs', trans_choice('1 job failed|:count jobs failed', count($failed_jobs)), 'warning'];
    }
    $problems_tone = collect($problems)->contains(fn ($problem) => $problem[2] == 'danger') ? 'danger' : 'warning';

    // A job in words ("Send reply to customer") and its conversation.
    $job_info = function ($payload, $job) {
        $name = $payload ? \Illuminate\Support\Str::ucfirst(strtolower(\Illuminate\Support\Str::headline(class_basename($payload['displayName'])))) : '';
        if ($payload && $payload['displayName'] == 'App\Jobs\TriggerAction' && !empty($payload['data']['command'])) {
            $name .= ' ('.App\Job::getTriggerActionName($payload).')';
        }
        $command = null;
        $last_thread = null;
        if ($payload && \Str::startsWith($payload['displayName'], 'App\Jobs\Send')) {
            $command = $job->getCommand();
            if ($command && !empty($command->conversation) && !empty($command->threads)) {
                $last_thread = \App\Thread::getLastThread($command->threads);
            }
        }

        return [$name, $last_thread ? $command->conversation : null, $last_thread];
    };
@endphp

<div class="system-status f-stack" x-data="tallportSystemStatus">

    @if (!\Config::get('app.disable_updating') && $new_version_available)
        <x-fruit::alert tone="info" id="update">
            <strong>{{ __('A new version is available') }}: {{ $latest_version }}</strong>
            (<a href="{{ config('app.tallport_url') }}/releases" target="_blank">{{ __('View details') }}</a>)
            <x-slot:actions><x-fruit::button variant="primary" class="update-trigger" x-on:click="update">{{ __('Update Now') }}</x-fruit::button></x-slot:actions>
        </x-fruit::alert>
    @endif

    @if ($problems)
        <x-fruit::alert :tone="$problems_tone" class="system-status__problems">
            <strong>{{ trans_choice('1 thing needs attention|:count things need attention', count($problems)) }}</strong>
            @if ($just_updated)
                <p class="f-help">{{ __('Tallport was updated :time. Background commands restart within a minute or two.', ['time' => \Carbon\Carbon::createFromTimestamp($updated_at)->diffForHumans()]) }}</p>
            @endif
            <ul>
                @foreach ($problems as [$anchor, $text, $tone])
                    <li><a href="#{{ $anchor }}">{{ $text }}</a></li>
                @endforeach
            </ul>
        </x-fruit::alert>
    @else
        <p class="f-help system-status__ok" role="status"><x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('Everything is working') }}</p>
    @endif

    @action('system.status.before_info_table')

    <x-fruit::form-section :title="__('Info')" id="app">
        <div class="f-form-row" id="version">
            <span>{{ __('App Version') }}</span>
            <span class="f-row">
                <span class="f-muted">{{ \Config::get('app.version') }}</span>
                @if (!\Config::get('app.disable_updating') && !$new_version_available)
                    <x-fruit::button variant="ghost" size="small" class="check-updates-trigger" x-on:click="checkUpdates">{{ __('Check for Updates') }}</x-fruit::button>
                @endif
            </span>
        </div>
        @if ($latest_version_error)
            <div class="f-form-row"><p class="f-error">{{ $latest_version_error }}</p></div>
        @endif
        <div class="f-form-row">
            <span>{{ __('Date & Time') }}</span>
            <span class="f-muted">{{ App\User::dateFormat(new Illuminate\Support\Carbon(), 'M j, Y H:i', null, true, false) }}</span>
        </div>
        <div class="f-form-row">
            <div>
                <span>{{ __('Timezone') }}</span>
                <p class="f-help">APP_TIMEZONE (.env)</p>
            </div>
            <span class="f-muted">{{ \Config::get('app.timezone') }}, GMT{{ preg_replace('#^([+-])0?(\d+)00$#', '$1$2', date('O')) }}</span>
        </div>
        <div class="f-form-row" id="protocol">
            <div>
                <span>{{ __('Protocol') }}</span>
                <p class="f-help" id="protocol_push_notifications" x-show="!https" x-cloak>{{ __("HTTPS protocol is required for the browser push notifications to work.") }}</p>
                @if (!\Config::get('session.secure'))
                    <div id="session_secure_cookie" class="f-input-group system-status__command" x-show="https" x-cloak>
                        <x-fruit::input value="SESSION_SECURE_COOKIE=true" readonly :aria-label="__('Run the following command')" />
                        <x-fruit::copy-button value="SESSION_SECURE_COOKIE=true" />
                    </div>
                @endif
            </div>
            <span class="f-row">
                <span class="f-muted" id="system-app-protocol" x-show="https">HTTPS</span>
                <x-fruit::badge tone="warning" x-show="!https" x-cloak>HTTP</x-fruit::badge>
            </span>
        </div>
        @if (\Helper::detectCloudFlare())
            <div class="f-form-row">
                <span>Proxy</span>
                <span class="f-row">
                    <span class="f-muted">CloudFlare</span>
                    @if (!config('app.cloudflare_is_used'))<x-fruit::badge tone="warning">CloudFlare</x-fruit::badge>@endif
                    <a href="{{ config('app.freescout_repo') }}/wiki/Installation-Guide#103-cloudflare" target="_blank">{{ __('read more') }}</a>
                </span>
            </div>
        @endif
        <div class="f-form-row" id="db">
            <div>
                <span>{{ __('Database') }}</span>
                @if ($missing_migrations)
                    <p class="f-help">{{ implode(', ', $missing_migrations) }}</p>
                @endif
            </div>
            <span class="f-row">
                <span class="f-muted">{{ $db_name }} {{ $db_version }}</span>
                @if ($missing_migrations)
                    <a href="{{ route('system.tools') }}" class="f-button f-button--small f-button--danger">{{ 'Migrate DB' }}</a>
                @endif
            </span>
        </div>
        @if ($search_index)
            <div class="f-form-row">
                <span>{{ __('Search Index') }}</span>
                @if ($search_index[0] >= $search_index[1] && \App\Search\Indexer::isReady())
                    <span class="f-muted">{{ trans_choice('1 conversation indexed|:count conversations indexed', $search_index[1]) }}</span>
                @else
                    <x-fruit::badge tone="warning">{{ __('Building: :indexed of :total conversations', ['indexed' => $search_index[0], 'total' => $search_index[1]]) }}</x-fruit::badge>
                @endif
            </div>
        @endif
        {{-- Retention (Settings » Retention): the last night's run and what waits to be deleted. --}}
        @php
            $retention_last = \Option::get(App\Retention\Retention::LAST_RUN_OPTION);
            $retention_pending = App\Retention\Retention::isEnabled() ? App\Retention\Retention::pending() : null;
        @endphp
        <div class="f-form-row">
            <span>{{ __('Retention') }}</span>
            <span class="f-muted">
                @if (!$retention_pending)
                    {{ __('Off') }}
                @else
                    @if (!empty($retention_last['at']))
                        {{ __('Last run :date: :expired expired, :deleted deleted for good.', [
                            'date'    => App\User::dateFormat($retention_last['at'], 'M j, H:i'),
                            'expired' => $retention_last['expired'] ?? 0,
                            'deleted' => ($retention_last['deleted'] ?? 0) + ($retention_last['trash'] ?? 0) + ($retention_last['spam'] ?? 0),
                        ]) }}
                    @endif
                    @if ($retention_pending['count'])
                        {{ __(':count waiting, the first to be deleted on :date.', ['count' => $retention_pending['count'], 'date' => App\User::dateFormat($retention_pending['next'], 'M j, Y')]) }}
                    @endif
                @endif
            </span>
        </div>
        @if ($redis_uses)
            <div class="f-form-row" id="redis">
                <div>
                    <span>Redis</span>
                    <p class="f-help">@if ($redis_error){{ $redis_error }}@else{{ implode(', ', $redis_uses) }}@endif</p>
                </div>
                @if ($redis_error)
                    <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
                @else
                    <span class="f-muted">Redis {{ $redis_version }}</span>
                @endif
            </div>
        @endif
        <div class="f-form-row">
            <span>{{ __('Web Server') }}</span>
            <span class="f-muted">@if (!empty($_SERVER['SERVER_SOFTWARE'])){{ $_SERVER['SERVER_SOFTWARE'] }}@else — @endif</span>
        </div>
        <div class="f-form-row">
            <span>{{ __('PHP Version') }}</span>
            <span class="f-muted">PHP {{ phpversion() }}</span>
        </div>
        <div class="f-form-row">
            <div>
                <span>{{ __('Maximum Upload Size') }}</span>
                <p class="f-help">{{ __('Requests up to :size (post_max_size)', ['size' => $size_label(ini_get('post_max_size'))]) }}</p>
            </div>
            <span class="f-muted">{{ $size_label(ini_get('upload_max_filesize')) }}</span>
        </div>
    </x-fruit::form-section>

    @action('system.status.after_info_table')

    <x-fruit::form-section :title="__('PHP Extensions')" id="php-extensions">
        @foreach ($missing_extensions as $extension_name => $optional_purpose)
            <div class="f-form-row">
                <div>
                    <span>{{ $extension_name }}@if ($optional_purpose) {{ __('(optional)') }}@endif</span>
                    @if ($optional_purpose)<p class="f-help">{{ __('Needed for') }}: {{ __($optional_purpose) }}</p>@endif
                </div>
                <x-fruit::badge :tone="$optional_purpose ? 'warning' : 'danger'">{{ __('Not found') }}</x-fruit::badge>
            </div>
        @endforeach
        @if ($pcre_jit_off)
            <div class="f-form-row">
                <div>
                    <span>PCRE JIT</span>
                    <p class="f-help">{{ __('Needed for') }}: {{ __('Faster text processing') }} ({{ implode(', ', array_keys($pcre_jit_off)) }})</p>
                </div>
                <x-fruit::badge tone="warning">{{ __('Off') }}</x-fruit::badge>
            </div>
        @endif
        @php
            $installed_extensions = array_keys(array_filter($php_extensions));
            if (!$pcre_jit_off) {
                $installed_extensions[] = 'PCRE JIT';
            }
        @endphp
        <x-fruit::disclosure :title="__('All :count extensions are installed', ['count' => count($installed_extensions)])">
            <p class="f-muted">{{ implode(', ', $installed_extensions) }}</p>
        </x-fruit::disclosure>
    </x-fruit::form-section>

    @action('system.status.after_php_extensions')

    <x-fruit::form-section :title="__('Functions')" id="functions">
        @foreach ($missing_functions as $function_name)
            <div class="f-form-row">
                <span>{{ $function_name }}</span>
                <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
            </div>
        @endforeach
        @if (count($functions) > count($missing_functions))
            <x-fruit::disclosure :title="__('All :count functions are available', ['count' => count($functions) - count($missing_functions)])">
                <p class="f-muted">{{ implode(', ', array_keys(array_filter($functions))) }}</p>
            </x-fruit::disclosure>
        @endif
    </x-fruit::form-section>

    @action('system.status.after_functions')

    <x-fruit::form-section :title="__('Permissions')" id="permissions" :footer="__('These folders must be writable by web server user (:user).', ['user' => function_exists('get_current_user') ? get_current_user() : '']).' '.__('Recommended permissions').': 775'">
        @foreach ($unwritable as $perm_path => $perm)
            <div class="f-form-row">
                <div class="system-status__wide">
                    <span>{{ $perm_path }}</span>
                    <p class="f-help">{{ __('Run the following command') }} (<a href="{{ config('app.freescout_repo') }}/wiki/Installation-Guide#6-configuring-web-server" target="_blank">{{ __('read more') }}</a>):</p>
                    <div class="f-input-group system-status__command">
                        <x-fruit::input :value="$chown_command" readonly :aria-label="__('Run the following command')" />
                        <x-fruit::copy-button :value="$chown_command" />
                    </div>
                </div>
                <x-fruit::badge tone="danger">{{ __('Not writable') }}@if ($perm['value']) ({{ $perm['value'] }})@endif</x-fruit::badge>
            </div>
        @endforeach
        @if ($non_writable_cache_file)
            <div class="f-form-row">
                <div class="system-status__wide">
                    <span>storage/framework/cache/data/</span>
                    <p class="f-help">{{ $non_writable_cache_file }}</p>
                    @unless (strstr($non_writable_cache_file, 'shell_exec()'))
                        <div class="f-input-group system-status__command">
                            <x-fruit::input :value="$chown_command" readonly :aria-label="__('Run the following command')" />
                            <x-fruit::copy-button :value="$chown_command" />
                        </div>
                    @endunless
                </div>
                <x-fruit::badge tone="danger">{{ __('Non-writable files found') }}</x-fruit::badge>
            </div>
        @endif
        @unless ($public_symlink_exists)
            <div class="f-form-row">
                <div class="system-status__wide">
                    <span>public/storage (symlink)</span>
                    <p class="f-help">{{ __('Create symlink manually') }}:</p>
                    <div class="f-input-group system-status__command">
                        <x-fruit::input value="ln -s storage/app/public public/storage" readonly :aria-label="__('Create symlink manually')" />
                        <x-fruit::copy-button value="ln -s storage/app/public public/storage" />
                    </div>
                </div>
                <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
            </div>
        @endunless
        @unless ($env_is_writable)
            <div class="f-form-row">
                <span>.env</span>
                <x-fruit::badge tone="danger">{{ __('Not writable') }}</x-fruit::badge>
            </div>
        @endunless
        @if ($invalid_symlinks)
            <div class="f-form-row">
                <div>
                    <span>{{ __('Invalid or missing modules symlinks') }}</span>
                    <p class="f-help">@foreach ($invalid_symlinks as $invalid_symlink_from => $invalid_symlinks_to){{ $invalid_symlink_from }} → {{ $invalid_symlinks_to }}@if (!$loop->last), @endif @endforeach (<a href="{{ config('app.freescout_repo') }}/wiki/FreeScout-Modules#-invalid-or-missing-modules-symlinks-error" target="_blank">{{ __('read more') }}</a>)</p>
                </div>
                <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
            </div>
        @endif
        @php
            $writable_paths = array_merge(array_keys(array_diff_key($permissions, $unwritable)), $public_symlink_exists ? ['public/storage'] : [], $env_is_writable ? ['.env'] : []);
        @endphp
        <x-fruit::disclosure :title="__('All :count folders are writable', ['count' => count($writable_paths)])">
            <p class="f-muted">{{ implode(', ', $writable_paths) }}</p>
        </x-fruit::disclosure>
    </x-fruit::form-section>

    @action('system.status.after_permissions')

    <x-fruit::form-section title="Cron Commands" id="cron">
        @foreach ($commands as $command)
            <div class="f-form-row">
                <div>
                    <span>{{ $command['name'] }}</span>
                    @if ($command['status'] != 'success')
                        <p class="f-help">
                            @if (!array_key_exists('last_run', $command))
                                {!! $command['status_text'] !!}
                            @elseif (empty($command['last_run']))
                                {{ __('Hasn\'t run yet') }}
                            @else
                                {{ __('Last ran :time', ['time' => $command['last_run']->diffForHumans()]) }}@if (!empty($command['last_successful_run'])) · {{ __('Last success :time', ['time' => $command['last_successful_run']->diffForHumans()]) }}@endif
                            @endif
                            @if ($command['name'] == 'tallport:fetch-emails') · <a href="{{ route('logs', ['name' => 'fetch_errors']) }}">{{ __('See logs') }}</a>@endif
                        </p>
                    @endif
                </div>
                @if ($command['status'] == 'success')
                    <span class="f-muted">@if (!empty($command['last_successful_run'])){{ __('Last ran :time', ['time' => $command['last_successful_run']->diffForHumans()]) }}@else{!! $command['status_text'] !!}@endif</span>
                @else
                    <x-fruit::badge tone="danger">{{ __('Not running') }}</x-fruit::badge>
                @endif
            </div>
        @endforeach
        <div class="f-form-row">
            <div class="system-status__wide">
                <p class="f-help">{{ __('Make sure that you have the following line in your crontab:') }}</p>
                <div class="f-input-group system-status__command">
                    <x-fruit::input :value="$crontab_line" readonly aria-label="crontab" />
                    <x-fruit::copy-button :value="$crontab_line" />
                </div>
            </div>
        </div>
        <x-fruit::disclosure :title="__('Can\'t use cron?')">
            <p class="f-help">{{ __('Alternatively cron job can be executed by requesting the following URL every minute (this method is not recommended as some features may not work as expected, use it at your own risk)') }}:</p>
            <div class="f-input-group system-status__command">
                <x-fruit::input :value="$web_cron_url" readonly aria-label="URL" />
                <x-fruit::copy-button :value="$web_cron_url" />
            </div>
        </x-fruit::disclosure>
    </x-fruit::form-section>

    @action('system.status.after_cron_commands')

    <x-fruit::form-section :title="__('Queued Jobs').(count($queued_jobs) ? ' · '.count($queued_jobs) : '')" id="jobs">
        @forelse ($queued_jobs as $job)
            @php
                $payload = $job->getPayloadDecoded();
                [$job_name, $job_conversation, $last_thread] = $job_info($payload, $job);
            @endphp
            <div class="f-form-row system-status__job">
                <div>
                    <span>{{ $job_name }}</span>
                    <p class="f-help">
                        @if ($job_conversation)<a href="{{ route('conversations.view', ['id' => $last_thread->conversation_id]) }}#thread-{{ $last_thread->id }}" target="_blank">{{ __('Conversation') }} #{{ $job_conversation->number }}</a> · @endif{{ __('Queue') }}: {{ $job->queue }}
                        @if ($job->attempts > 0)
                            · <span class="system-status__attempts">{{ trans_choice('1 attempt|:count attempts', $job->attempts) }}</span>
                            · {{ __('Next attempt in :time', ['time' => \Illuminate\Support\Carbon::make(is_numeric($job->available_at) ? '@'.$job->available_at : $job->available_at)->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1])]) }}
                        @endif
                    </p>
                </div>
                <form action="{{ route('system.action') }}" method="POST" class="f-row system-status__job-actions">
                    {{ csrf_field() }}
                    <input type="hidden" name="job_id" value="{{ $job->id }}" />
                    @if ($job->attempts > 0 && $last_thread)
                        <a href="{{ route('logs', ['name' => 'out_emails', 'thread_id' => $last_thread->id]) }}" target="_blank" class="f-button f-button--ghost f-button--small">{{ __('View log') }}</a>
                    @endif
                    <button type="submit" name="action" value="cancel_job" class="f-button f-button--ghost f-button--small">{{ __('Cancel') }}</button>
                    @if ($job->attempts > 0)
                        <button type="submit" name="action" value="retry_job" class="f-button f-button--small">{{ __('Retry') }}</button>
                    @endif
                </form>
            </div>
        @empty
            <div class="f-form-row"><span class="f-muted">{{ __('No queued jobs') }}</span></div>
        @endforelse
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Failed Jobs').(count($failed_jobs) ? ' · '.count($failed_jobs) : '')" id="failed-jobs" :footer="count($queued_jobs) || count($failed_jobs) ? __('Queued and failed jobs are cleaned automatically once in a while. No need to worry or delete them manually.') : null">
        @if (count($failed_jobs))
            <div class="f-form-row">
                <span class="f-muted">{{ trans_choice('1 job failed|:count jobs failed', count($failed_jobs)) }}</span>
                <form action="{{ route('system.action') }}" method="POST" class="f-row">
                    {{ csrf_field() }}
                    <x-fruit::select name="failed_queue" class="system-status__queue" :aria-label="__('Queue')">
                        @foreach ($failed_queues as $queue)
                            <option value="{{ $queue }}">{{ __('Queue') }}: {{ $queue }}</option>
                        @endforeach
                    </x-fruit::select>
                    <button type="submit" name="action" value="delete_failed_jobs" class="f-button f-button--ghost f-button--small">{{ __('Delete') }}</button>
                    <button type="submit" name="action" value="retry_failed_jobs" class="f-button f-button--small">{{ __('Retry') }}</button>
                </form>
            </div>
        @endif
        @forelse ($failed_jobs as $job)
            @php
                $payload = $job->getPayloadDecoded();
                [$job_name, $job_conversation, $last_thread] = $job_info($payload, $job);
            @endphp
            <div class="f-form-row system-status__job">
                <div>
                    <span>{{ $job_name }}</span>
                    <p class="f-help">
                        @if ($job_conversation)<a href="{{ route('conversations.view', ['id' => $last_thread->conversation_id]) }}#thread-{{ $last_thread->id }}" target="_blank">{{ __('Conversation') }} #{{ $job_conversation->number }}</a> · @endif{{ __('Queue') }}: {{ $job->queue }}
                        · {{ __('Failed At') }}: {{ App\User::dateFormat($job->failed_at, 'M j, Y H:i') }}
                    </p>
                </div>
                <span class="f-row system-status__job-actions">
                    @if ($last_thread)
                        <a href="{{ route('logs', ['name' => 'out_emails', 'thread_id' => $last_thread->id]) }}" target="_blank" class="f-button f-button--ghost f-button--small">{{ __('View log') }}</a>
                    @endif
                    <a href="{{ route('system.ajax_html', ['action' => 'job_details', 'param' => $job->id]) }}" class="f-button f-button--ghost f-button--small" data-fruit-dialog-url data-fruit-dialog-title="{{ $job_name }}" data-fruit-dialog-size="large">{{ __('View Details') }}</a>
                </span>
            </div>
        @empty
            <div class="f-form-row"><span class="f-muted">{{ __('No failed jobs') }}</span></div>
        @endforelse
    </x-fruit::form-section>

    @action('system.status.after_background_jobs')
</div>
