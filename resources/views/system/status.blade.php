@extends('layouts.app')

@section('page_width', 'medium')

@section('title', __('System Status'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('system/sidebar_menu')
@endsection

@section('content')
@php
    // Problems, called out once at the top: [anchor, text, tone].
    $optional_extensions = [];
    $missing_extensions = [];
    foreach ($php_extensions as $extension_name => $extension_status) {
        if (!$extension_status) {
            $optional_purpose = config('installer.optional.'.strtolower($extension_name));
            $missing_extensions[$extension_name] = $optional_purpose;
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

    $problems = [];
    if ($missing_migrations) {
        $problems[] = ['db', 'DB', 'danger'];
    }
    if ($redis_uses && $redis_error) {
        $problems[] = ['redis', 'Redis', 'danger'];
    }
    foreach ($missing_extensions as $extension_name => $optional_purpose) {
        $problems[] = ['php-extensions', __('PHP Extensions').': '.$extension_name, $optional_purpose ? 'warning' : 'danger'];
    }
    if ($pcre_jit_off) {
        $problems[] = ['php-extensions', 'PCRE JIT', 'warning'];
    }
    foreach ($missing_functions as $function_name) {
        $problems[] = ['functions', __('Functions').': '.$function_name, 'danger'];
    }
    foreach (array_keys($unwritable) as $perm_path) {
        $problems[] = ['permissions', $perm_path, 'danger'];
    }
    if ($non_writable_cache_file) {
        $problems[] = ['permissions', 'storage/framework/cache/data/', 'danger'];
    }
    if (!$public_symlink_exists) {
        $problems[] = ['permissions', 'public/storage', 'danger'];
    }
    if (!$env_is_writable) {
        $problems[] = ['permissions', '.env', 'danger'];
    }
    if ($invalid_symlinks) {
        $problems[] = ['permissions', __('Invalid or missing modules symlinks'), 'danger'];
    }
    foreach ($commands as $command) {
        if ($command['status'] != 'success') {
            $problems[] = ['cron', $command['name'], 'danger'];
        }
    }
    if (count($failed_jobs)) {
        $problems[] = ['jobs', __('Failed Jobs').': '.count($failed_jobs), 'warning'];
    }
    $problems_tone = collect($problems)->contains(fn ($problem) => $problem[2] == 'danger') ? 'danger' : 'warning';
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
            <strong>{{ __('Needs attention') }}</strong>
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
            <span class="f-headline">{{ __('App Version') }}</span>
            <span class="f-row">
                <span class="f-muted">{{ \Config::get('app.version') }}</span>
                @if (!\Config::get('app.disable_updating') && !$new_version_available)
                    <x-fruit::button variant="ghost" size="small" class="check-updates-trigger" x-on:click="checkUpdates">{{ __('Check for updates') }}</x-fruit::button>
                @endif
            </span>
        </div>
        @if ($latest_version_error)
            <div class="f-form-row"><p class="f-error">{{ $latest_version_error }}</p></div>
        @endif
        <div class="f-form-row">
            <span class="f-headline">{{ __('Date & Time') }}</span>
            <span class="f-muted">{{ App\User::dateFormat(new Illuminate\Support\Carbon(), 'M j, Y H:i', null, true, false) }}</span>
        </div>
        <div class="f-form-row">
            <span class="f-headline">{{ __('Timezone') }} (.env)</span>
            <span class="f-muted">{{ \Config::get('app.timezone') }} (GMT{{ date('O') }})</span>
        </div>
        <div class="f-form-row" id="protocol">
            <div>
                <span class="f-headline">{{ __('Protocol') }}</span>
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
                <span class="f-headline">Proxy</span>
                <span class="f-row">
                    <span class="f-muted">CloudFlare</span>
                    @if (!config('app.cloudflare_is_used'))<x-fruit::badge tone="warning">CloudFlare</x-fruit::badge>@endif
                    <a href="{{ config('app.freescout_repo') }}/wiki/Installation-Guide#103-cloudflare" target="_blank">{{ __('read more') }}</a>
                </span>
            </div>
        @endif
        <div class="f-form-row" id="db">
            <div>
                <span class="f-headline">DB</span>
                @if ($missing_migrations)
                    <p class="f-help">{{ implode(', ', $missing_migrations) }}</p>
                @endif
            </div>
            <span class="f-row">
                <span class="f-muted">{{ ucfirst(\DB::connection()->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME)) }} ({{ \DB::connection()->getPDO()->getAttribute(\PDO::ATTR_SERVER_VERSION) }})</span>
                @if ($missing_migrations)
                    <a href="{{ route('system.tools') }}" class="f-button f-button--small f-button--danger">{{ 'Migrate DB' }}</a>
                @endif
            </span>
        </div>
        @if ($search_index)
            <div class="f-form-row">
                <span class="f-headline">{{ __('Search index') }}</span>
                @if ($search_index[0] >= $search_index[1] && \App\Search\Indexer::isReady())
                    <span class="f-muted">{{ $search_index[1] }}</span>
                @else
                    <x-fruit::badge tone="warning">{{ __('Building: :indexed of :total conversations', ['indexed' => $search_index[0], 'total' => $search_index[1]]) }}</x-fruit::badge>
                @endif
            </div>
        @endif
        @if ($redis_uses)
            <div class="f-form-row" id="redis">
                <div>
                    <span class="f-headline">Redis</span>
                    @if ($redis_error)<p class="f-help">{{ $redis_error }}</p>@endif
                </div>
                <span class="f-row">
                    @if ($redis_error)
                        <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
                    @else
                        <span class="f-muted">Redis {{ $redis_version }} ({{ implode(', ', $redis_uses) }})</span>
                    @endif
                </span>
            </div>
        @endif
        <div class="f-form-row">
            <span class="f-headline">{{ __('Web Server') }}</span>
            <span class="f-muted">@if (!empty($_SERVER['SERVER_SOFTWARE'])){{ $_SERVER['SERVER_SOFTWARE'] }}@else ? @endif</span>
        </div>
        <div class="f-form-row">
            <span class="f-headline">{{ __('PHP Version') }}</span>
            <span class="f-muted">PHP {{ phpversion() }}</span>
        </div>
        <div class="f-form-row">
            <span class="f-headline">upload_max_filesize / post_max_size</span>
            <span class="f-muted">{{ ini_get('upload_max_filesize') }} / {{ ini_get('post_max_size') }}</span>
        </div>
    </x-fruit::form-section>

    @action('system.status.after_info_table')

    <x-fruit::form-section :title="__('PHP Extensions')" id="php-extensions">
        @foreach ($missing_extensions as $extension_name => $optional_purpose)
            <div class="f-form-row">
                <div>
                    <span class="f-headline">{{ $extension_name }}@if ($optional_purpose) {{ __('(optional)') }}@endif</span>
                    @if ($optional_purpose)<p class="f-help">{{ __('Needed for') }}: {{ __($optional_purpose) }}</p>@endif
                </div>
                <x-fruit::badge :tone="$optional_purpose ? 'warning' : 'danger'">{{ __('Not found') }}</x-fruit::badge>
            </div>
        @endforeach
        @if ($pcre_jit_off)
            <div class="f-form-row">
                <div>
                    <span class="f-headline">PCRE JIT</span>
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
                <span class="f-headline">{{ $function_name }}</span>
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
                <div>
                    <span class="f-headline">{{ $perm_path }}</span>
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
                <div>
                    <span class="f-headline">storage/framework/cache/data/</span>
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
                <div>
                    <span class="f-headline">public/storage (symlink)</span>
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
                <span class="f-headline">.env</span>
                <x-fruit::badge tone="danger">{{ __('Not writable') }}</x-fruit::badge>
            </div>
        @endunless
        @if ($invalid_symlinks)
            <div class="f-form-row">
                <div>
                    <span class="f-headline">{{ __('Invalid or missing modules symlinks') }}</span>
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
                    <span class="f-headline">{{ $command['name'] }}</span>
                    @if ($command['status'] != 'success')
                        <p class="f-help">{!! $command['status_text'] !!}@if ($command['name'] == 'tallport:fetch-emails') (<a href="{{ route('logs', ['name' => 'fetch_errors']) }}">{{ __('See logs') }}</a>)@endif</p>
                    @endif
                </div>
                @if ($command['status'] == 'success')
                    <span class="f-muted">{!! $command['status_text'] !!}</span>
                @else
                    <x-fruit::badge tone="danger">{{ __('Error') }}</x-fruit::badge>
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

    <x-fruit::form-section :title="__('Background Jobs')" id="jobs" :footer="count($queued_jobs) || count($failed_jobs) ? __('Queued and failed jobs are cleaned automatically once in a while. No need to worry or delete them manually.') : null">
        <div class="f-form-row">
            <span class="f-headline">{{ __('Queued Jobs') }}</span>
            <span class="f-muted">{{ count($queued_jobs) }}</span>
        </div>
        @foreach ($queued_jobs as $job)
            @php
                $payload = $job->getPayloadDecoded();
                $last_thread = null;
                if ($payload && \Str::startsWith($payload['displayName'], 'App\Jobs\Send')) {
                    $command = $job->getCommand();
                    if ($command && !empty($command->conversation) && !empty($command->threads)) {
                        $last_thread = \App\Thread::getLastThread($command->threads);
                    }
                }
            @endphp
            @if ($payload)
                <div class="f-form-row system-status__job">
                    <div>
                        <span class="f-headline">{{ class_basename($payload['displayName']) }}@if ($payload['displayName'] == 'App\Jobs\TriggerAction' && !empty($payload['data']['command'])) ({{ App\Job::getTriggerActionName($payload) }})@endif</span>
                        <p class="f-help">
                            {{ __('Queue') }}: {{ $job->queue }}
                            · {{ __('Created At') }}: {{ App\User::dateFormat($job->created_at) }}
                            @if ($job->attempts > 0)
                                · {{ __('Attempts') }}: {{ $job->attempts }}
                                · {{ __('Next Attempt') }}: {{ App\User::dateFormat($job->available_at) }}
                            @endif
                            @if (!empty($last_thread))
                                · <a href="{{ route('conversations.view', ['id' => $last_thread->conversation_id]) }}#thread-{{ $last_thread->id }}" target="_blank">#{{ $command->conversation->number }}</a>
                                @if ($job->attempts > 0) · <a href="{{ route('logs', ['name' => 'out_emails', 'thread_id' => $last_thread->id]) }}" target="_blank">{{ __('View log') }}</a>@endif
                            @endif
                        </p>
                    </div>
                    <form action="{{ route('system.action') }}" method="POST" class="f-row system-status__job-actions">
                        {{ csrf_field() }}
                        <input type="hidden" name="job_id" value="{{ $job->id }}" />
                        <button type="submit" name="action" value="cancel_job" class="f-button f-button--ghost f-button--small">{{ __('Cancel') }}</button>
                        @if ($job->attempts > 0)
                            <button type="submit" name="action" value="retry_job" class="f-button f-button--small">{{ __('Retry') }}</button>
                        @endif
                    </form>
                </div>
            @endif
        @endforeach
        <div class="f-form-row">
            <span class="f-headline">{{ __('Failed Jobs') }}</span>
            <span class="f-row">
                <span class="f-muted">{{ count($failed_jobs) }}</span>
                @if (count($failed_jobs))
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
                @endif
            </span>
        </div>
        @foreach ($failed_jobs as $job)
            @php
                $payload = $job->getPayloadDecoded();
                $last_thread = null;
                if ($payload && \Str::startsWith($payload['displayName'], 'App\Jobs\Send')) {
                    $command = $job->getCommand();
                    if ($command && !empty($command->conversation) && !empty($command->threads)) {
                        $last_thread = \App\Thread::getLastThread($command->threads);
                    }
                }
                $job_name = $payload ? class_basename($payload['displayName']) : '';
            @endphp
            <div class="f-form-row system-status__job">
                <div>
                    <span class="f-headline">{{ $job_name }}@if ($payload && $payload['displayName'] == 'App\Jobs\TriggerAction' && !empty($payload['data']['command'])) ({{ App\Job::getTriggerActionName($payload) }})@endif</span>
                    <p class="f-help">
                        {{ __('Queue') }}: {{ $job->queue }}
                        · {{ __('Failed At') }}: {{ App\User::dateFormat($job->failed_at, 'M j, Y H:i:s') }}
                        @if (!empty($last_thread))
                            · <a href="{{ route('conversations.view', ['id' => $last_thread->conversation_id]) }}#thread-{{ $last_thread->id }}" target="_blank">#{{ $command->conversation->number }}</a>
                            · <a href="{{ route('logs', ['name' => 'out_emails', 'thread_id' => $last_thread->id]) }}" target="_blank">{{ __('View log') }}</a>
                        @endif
                    </p>
                </div>
                <a href="{{ route('system.ajax_html', ['action' => 'job_details', 'param' => $job->id]) }}" class="f-button f-button--ghost f-button--small" data-fruit-dialog-url data-fruit-dialog-title="{{ $job_name }}" data-fruit-dialog-size="large">{{ __('View Details') }}</a>
            </div>
        @endforeach
    </x-fruit::form-section>

    @action('system.status.after_background_jobs')
</div>

@action('system.status.after_content')

@endsection
