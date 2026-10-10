{{-- System Status (App\Livewire\SystemStatus): what needs attention first, each problem with its fix
     (SystemController::problems()), then the facts, the requirements, the background tasks and jobs,
     and the maintenance actions (SystemController::toolsExecute(), with the old Tools page's hooks). --}}
@php
    $missing_extensions = [];
    foreach ($php_extensions as $extension_name => $extension_status) {
        if (!$extension_status) {
            $missing_extensions[$extension_name] = config('installer.optional.'.strtolower($extension_name));
        }
    }
    $pcre_jit_off = array_keys(array_filter([
        __('Web Server') => !\Helper::pcreJitAvailable(),
        'tallport:receive' => (bool) \Option::get('receive_pcre_jit_off'),
    ]));
    $missing_functions = array_keys(array_filter($functions, fn ($status) => !$status));
    $unwritable = array_filter($permissions, fn ($perm) => !$perm['status']);
    $chown_command = 'sudo chown -R www-data:www-data '.base_path();
    $crontab_line = '* * * * * php '.base_path().'/artisan schedule:run >> /dev/null 2>&1';
    $web_cron_url = route('system.cron', ['hash' => \Helper::getWebCronHash()]);

    // The database, as people know it: "MariaDB 11.8.6".
    $db_driver = \DB::connection()->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $db_version = (string) \DB::connection()->getPDO()->getAttribute(\PDO::ATTR_SERVER_VERSION);
    $db_name = str_contains(strtolower($db_version), 'mariadb') ? 'MariaDB' : (['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'][$db_driver] ?? ucfirst($db_driver));
    // Older MariaDB reports "5.5.5-10.11.6-MariaDB"; PostgreSQL "17.2 (Debian 17.2-1)".
    $db_version = preg_match('#(\d+\.\d+(?:\.\d+)?)#', preg_replace('#^5\.5\.5-#', '', $db_version), $db_m) ? $db_m[1] : $db_version;
    $size_label = fn ($size) => preg_replace('#^(\d+)\s*([KMG])$#i', '$1 $2B', trim((string) $size));

    // Just updated: background commands restart within a minute or two.
    $updated_at = (int) \Option::get('app_updated_at');
    $just_updated = $updated_at && time() - $updated_at < 5 * 60;

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
        <x-fruit::form-section :title="__('Needs Attention')" id="attention" :footer="$just_updated ? __('Tallport was updated :time. Background commands restart within a minute or two.', ['time' => \Carbon\Carbon::createFromTimestamp($updated_at)->diffForHumans()]) : null">
            @foreach ($problems as [$problem_key, $problem_text, $problem_tone, $problem_detail, $problem_explanation])
                <div class="f-form-row system-problem">
                    <div class="system-problem__main">
                    <x-icon.triangle-alert class="f-icon system-problem__icon system-problem__icon--{{ $problem_tone }}" aria-hidden="true" />
                    <div class="system-problem__body">
                        <strong>{{ $problem_text }}</strong>
                        @if ($problem_explanation)<p class="f-help">{{ $problem_explanation }}</p>@endif
                        @switch ($problem_key)
                            @case ('command')
                                @if (!array_key_exists('last_run', $problem_detail))
                                    {{-- Several running at once: how to stop the extra ones. --}}
                                    <p class="f-help">{!! $problem_detail['status_text'] !!}</p>
                                @else
                                    <p class="f-help">{{ __('The server\'s scheduled task (cron) isn\'t calling Tallport. Add this line to its crontab:') }}</p>
                                    <div class="system-problem__command"><code>{{ $crontab_line }}</code><x-fruit::copy-button :value="$crontab_line" /></div>
                                    @if ($problem_detail['name'] == 'tallport:fetch-emails')<p class="f-help"><a href="{{ route('logs', ['name' => 'fetch_errors']) }}">{{ __('See logs') }}</a></p>@endif
                                @endif
                                @break
                            @case ('permissions')
                                @if ($problem_detail)<p class="f-help">{{ $problem_detail }}</p>@endif
                                <p class="f-help">{{ __('Run the following command') }} (<a href="{{ config('app.freescout_repo') }}/wiki/Installation-Guide#6-configuring-web-server" target="_blank">{{ __('read more') }}</a>):</p>
                                <div class="system-problem__command"><code>{{ $chown_command }}</code><x-fruit::copy-button :value="$chown_command" /></div>
                                @break
                            @case ('symlink')
                                <p class="f-help">{{ __('Create symlink manually') }}:</p>
                                <div class="system-problem__command"><code>ln -s storage/app/public public/storage</code><x-fruit::copy-button value="ln -s storage/app/public public/storage" /></div>
                                @break
                            @case ('module_symlinks')
                                <p class="f-help">{{ $problem_detail }} (<a href="{{ config('app.freescout_repo') }}/wiki/FreeScout-Modules#-invalid-or-missing-modules-symlinks-error" target="_blank">{{ __('read more') }}</a>)</p>
                                @break
                            @default
                                @if ($problem_detail)<p class="f-help">{{ $problem_detail }}</p>@endif
                        @endswitch
                    </div>
                    </div>
                    @switch ($problem_key)
                        @case ('migrations')
                            <form action="{{ route('system.tools.action') }}" method="POST">
                                {{ csrf_field() }}
                                <x-fruit::button type="submit" name="action" value="migrate_db" size="small">{{ __('Update Database') }}</x-fruit::button>
                            </form>
                            @break
                        @case ('command')
                            @if (array_key_exists('last_run', $problem_detail))
                                <x-fruit::button size="small" wire:click="$refresh">{{ __('Check Again') }}</x-fruit::button>
                            @endif
                            @break
                        @case ('failed_jobs')
                            <form action="{{ route('system.action') }}" method="POST">
                                {{ csrf_field() }}
                                <x-fruit::button type="submit" name="action" value="retry_all_failed_jobs" size="small">{{ __('Retry All') }}</x-fruit::button>
                            </form>
                            @break
                    @endswitch
                </div>
            @endforeach
        </x-fruit::form-section>
    @else
        <p class="system-status__ok" role="status"><x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('Everything is working.') }}</p>
    @endif

    @action('system.status.before_info_table')

    <x-fruit::form-section :title="__('Info')" id="app">
        <div class="f-form-row" id="version">
            <div>
                <span>{{ __('App Version') }}</span>
                @if (!\Config::get('app.disable_updating'))<p class="f-help">{{ __('Tallport updates itself from its releases on GitHub.') }}</p>@endif
            </div>
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
                @if (App\Misc\DatabaseSettings::lockedByEnv('timezone'))
                    <p class="f-help">{{ App\Misc\DatabaseSettings::lockedNote('timezone') }}</p>
                @endif
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
            <span>{{ __('Database') }}</span>
            <span class="f-muted">{{ $db_name }} {{ $db_version }}</span>
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

    @php
        $installed_extensions = array_keys(array_filter($php_extensions));
        $writable_paths = array_merge(array_keys(array_diff_key($permissions, $unwritable)), $public_symlink_exists ? ['public/storage'] : [], $env_is_writable ? ['.env'] : []);
        $all_paths = count($permissions) + 2;
    @endphp
    <x-fruit::form-section :title="__('Requirements')" id="requirements">
        <div class="f-form-row">
            <div>
                <span>{{ __('PHP Extensions') }}</span>
                <p class="f-help">{{ __('Parts of PHP that Tallport uses') }}</p>
            </div>
            <span class="system-status__check" title="{{ implode(', ', array_merge($installed_extensions, $pcre_jit_off ? [] : ['PCRE JIT'])) }}">@if (!$missing_extensions)<x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('All :count installed', ['count' => count($installed_extensions)]) }}@else{{ __(':count of :total installed', ['count' => count($installed_extensions), 'total' => count($php_extensions)]) }}@endif</span>
        </div>
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
                    <p class="f-help">{{ __('Speeds up text matching; Tallport works without it.') }} {{ __('Off for: :where', ['where' => implode(', ', $pcre_jit_off)]) }}</p>
                </div>
                <x-fruit::badge tone="warning">{{ __('Off') }}</x-fruit::badge>
            </div>
        @endif
        <div class="f-form-row">
            <div>
                <span>{{ __('HEIC Images') }} {{ __('(optional)') }}</span>
                <p class="f-help">{{ __('iPhone photos are converted on the server by ImageMagick with HEIC support. Without it, browsers decode them, which is slower.') }}</p>
            </div>
            @if ($heic_converter)
                <span class="system-status__check" title="{{ $heic_converter }}"><x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('Converted on the server') }}</span>
            @else
                <x-fruit::badge tone="warning">{{ __('Not found') }}</x-fruit::badge>
            @endif
        </div>
        @action('system.status.after_php_extensions')
        <div class="f-form-row">
            <div>
                <span>{{ __('PHP Functions') }}</span>
                <p class="f-help">{{ __('Some hosts turn these off; Tallport needs them') }}</p>
            </div>
            <span class="system-status__check" title="{{ implode(', ', array_keys(array_filter($functions))) }}">@if (!$missing_functions)<x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('All :count available', ['count' => count($functions)]) }}@else{{ __(':count of :total available', ['count' => count($functions) - count($missing_functions), 'total' => count($functions)]) }}@endif</span>
        </div>
        @foreach ($missing_functions as $function_name)
            <div class="f-form-row">
                <span>{{ $function_name }}</span>
                <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
            </div>
        @endforeach
        @action('system.status.after_functions')
        <div class="f-form-row">
            <div>
                <span>{{ __('Folders') }}</span>
                <p class="f-help">{{ __('These folders must be writable by web server user (:user).', ['user' => function_exists('get_current_user') ? get_current_user() : '']) }}</p>
            </div>
            <span class="system-status__check" title="{{ implode(', ', $writable_paths) }}">@if (count($writable_paths) == $all_paths)<x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('All :count writable', ['count' => count($writable_paths)]) }}@else{{ __(':count of :total writable', ['count' => count($writable_paths), 'total' => $all_paths]) }}@endif</span>
        </div>
        @foreach (array_merge(array_keys($unwritable), $public_symlink_exists ? [] : ['public/storage'], $env_is_writable ? [] : ['.env']) as $unwritable_path)
            <div class="f-form-row">
                <span>{{ $unwritable_path }}</span>
                <x-fruit::badge tone="danger">{{ $unwritable_path == 'public/storage' ? __('Not found') : __('Not writable') }}</x-fruit::badge>
            </div>
        @endforeach
        @action('system.status.after_permissions')
    </x-fruit::form-section>

    @if ($matrix_mailboxes->isNotEmpty())
        <x-fruit::form-section title="Matrix">
            @foreach ($matrix_mailboxes as $identity)
                <div class="f-form-row">
                    <div>
                        <a href="{{ route('mailboxes.matrix', ['id' => $identity->mailbox_id]) }}">{{ $identity->mailbox->name }}</a>
                        <p class="f-help">{{ $identity->user_id }}</p>
                        <p class="f-help">{{ __('Last successful sync') }}: {{ $identity->last_synced_at ? $identity->last_synced_at->diffForHumans() : __('Never') }}</p>
                        @if ($identity->status === 'verification')<p class="f-help">{{ __('This device is unverified. You can still send and receive messages.') }}</p>@endif
                        @if ($identity->error)<p class="f-help">{{ __('Check the Matrix connection and device verification.') }}</p>@endif
                    </div>
                    <x-fruit::badge :tone="$identity->isVerified() && !$identity->error ? 'success' : 'warning'">{{ $identity->status === 'disabled' ? __('Disabled') : ($identity->isReady() ? __('Connected') : __('Verify this device')) }}</x-fruit::badge>
                </div>
            @endforeach
        </x-fruit::form-section>
    @endif

    <x-fruit::form-section :title="__('Background Tasks')" id="cron">
        @foreach ($commands as $command)
            @php [$task_name, $task_purpose] = App\Http\Controllers\SystemController::taskName($command['name']); @endphp
            <div class="f-form-row">
                <div>
                    <span>{{ $task_name }}</span>
                    <p class="f-help">@if ($task_purpose){{ $task_purpose }} · @endif{{ $command['name'] }}</p>
                    @if ($command['status'] != 'success' && !array_key_exists('last_run', $command))
                        <p class="f-help">{!! $command['status_text'] !!}</p>
                    @endif
                </div>
                <span class="f-row system-status__task-state">
                    @if ($command['status'] == 'success')
                        <span class="f-muted">@if (!empty($command['last_successful_run'])){{ __('Last ran :time', ['time' => $command['last_successful_run']->diffForHumans()]) }}@else{!! $command['status_text'] !!}@endif</span>
                    @elseif (array_key_exists('last_run', $command))
                        <span class="f-muted">@if (empty($command['last_run'])){{ __('Hasn\'t run yet') }}@else{{ __('Last ran :time', ['time' => $command['last_run']->diffForHumans()]) }}@endif</span>
                        <x-fruit::badge tone="danger">{{ __('Not running') }}</x-fruit::badge>
                    @else
                        <x-fruit::badge tone="danger">{{ __('Not running') }}</x-fruit::badge>
                    @endif
                    @if ($command['name'] == 'tallport:fetch-emails')
                        <x-fruit::button size="small" x-on:click="$dispatch('fruit-dialog-open', { name: 'fetch-now' })">{{ __('Fetch Now…') }}</x-fruit::button>
                    @endif
                </span>
            </div>
        @endforeach
        <x-fruit::disclosure :title="__('Can\'t use cron?')">
            <p class="f-help">{{ __('Make sure that you have the following line in your crontab:') }}</p>
            <div class="system-problem__command"><code>{{ $crontab_line }}</code><x-fruit::copy-button :value="$crontab_line" /></div>
            <p class="f-help">{{ __('Alternatively cron job can be executed by requesting the following URL every minute (this method is not recommended as some features may not work as expected, use it at your own risk)') }}:</p>
            <div class="system-problem__command"><code>{{ $web_cron_url }}</code><x-fruit::copy-button :value="$web_cron_url" /></div>
        </x-fruit::disclosure>
    </x-fruit::form-section>

    @action('system.status.after_cron_commands')

    <x-fruit::form-section :title="__('Queued Jobs').(count($queued_jobs) ? ' · '.count($queued_jobs) : '')" id="jobs" :footer="count($queued_jobs) ? __('Work waiting to run, such as emails to send. Retry runs a job now; Cancel removes it, so it never runs.') : null">
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
                            · <span class="system-status__attempts">{{ trans_choice('Tried once|Tried :count times', $job->attempts) }}</span>
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
                <div>
                    <span>{{ trans_choice('1 job failed|:count jobs failed', count($failed_jobs)) }}</span>
                    <p class="f-help">{{ __('Retry runs the queue\'s failed jobs again. Delete removes them for good: what they would have sent is never sent.') }}</p>
                </div>
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

    {{-- Maintenance (the old Tools page): its actions post to SystemController::toolsExecute(). --}}
    @action('system.tools.before_form')
    <form action="{{ route('system.tools.action') }}" method="POST" id="maintenance" x-data>
        {{ csrf_field() }}
        <input type="hidden" name="action" value="" x-ref="action">
        @action('system.tools.form_start')
        <x-fruit::form-section :title="__('Maintenance')">
            <div class="f-form-row">
                <div>
                    <span>{{ __('Clear Cache') }}</span>
                    <p class="f-help">{{ __('Rebuilds cached settings and pages. Safe at any time.') }}</p>
                </div>
                <x-fruit::button type="submit" size="small" x-on:click="$refs.action.value = 'clear_cache'">{{ __('Clear Cache') }}</x-fruit::button>
            </div>
            <div class="f-form-row">
                <div>
                    <span>{{ __('Update Database') }}</span>
                    <p class="f-help">{{ $missing_migrations ? __('Updates are waiting to be applied.') : __('The database is up to date.') }}</p>
                </div>
                <x-fruit::button type="submit" size="small" x-on:click="$refs.action.value = 'migrate_db'" :disabled="!$missing_migrations">{{ __('Update Database') }}</x-fruit::button>
            </div>
            <div class="f-form-row">
                <div>
                    <span>{{ __('Sign Out Everyone') }}</span>
                    <p class="f-help">{{ __('Ends every session, including yours.') }}</p>
                </div>
                <button type="button" class="f-button f-button--danger f-button--small" x-on:click="$confirm({ title: @js(__('Sign out everyone?')), message: @js(__('Everyone, including you, will need to sign in again.')), confirm: @js(__('Sign Out Everyone')), tone: 'danger' }).then((confirmed) => { if (confirmed) { $refs.action.value = 'logout_users'; $root.submit(); } })">{{ __('Sign Out Everyone…') }}</button>
            </div>
            @action('system.tools.main_buttons')
            @action('system.tools.after_main_buttons')
            @action('system.tools.form_append')
        </x-fruit::form-section>
    </form>
    @action('system.tools.after_form')
    @if ($tools_output)
        @action('system.tools.before_output')
        <pre class="system-tools__output" role="status">{{ $tools_output }}</pre>
        @action('system.tools.after_output')
    @endif
    @action('system.tools.after_content')

    {{-- Fetch Emails Now (Background Tasks): the command's output in the dialog. --}}
    <x-fruit::dialog name="fetch-now" aria-labelledby="fetch-now-title">
        <form x-data="{ days: 3, unseen: '1', debug: false, running: false, output: '' }" x-on:submit.prevent="running = true; output = ''; $wire.fetchNow(days, unseen == '1', debug).then((result) => { output = result || @js(__('Done')); running = false })">
            <header class="f-dialog__header"><h2 id="fetch-now-title">{{ __('Fetch Emails Now') }}</h2></header>
            <div class="f-dialog__body f-stack">
                <x-fruit::field :label="__('Days')" control-id="fetch-now-days" :description="__('How far back to look for email')">
                    <x-fruit::number id="fetch-now-days" x-model.number="days" min="1" />
                </x-fruit::field>
                <div>
                    <x-fruit::segmented :legend="__('Messages')">
                        <x-fruit::segment name="fetch_now_unseen" value="1" x-model="unseen" checked>{{ __('Unread Only') }}</x-fruit::segment>
                        <x-fruit::segment name="fetch_now_unseen" value="0" x-model="unseen">{{ __('All') }}</x-fruit::segment>
                    </x-fruit::segmented>
                    <p class="f-help">{{ __('All also looks at email already read in the mailbox. Email already in Tallport is skipped.') }}</p>
                </div>
                <x-fruit::switch x-model="debug" :description="__('Adds the conversation with the mail server, for finding connection problems.')">{{ __('Show Debug Output') }}</x-fruit::switch>
                @action('system.tools.fetch_emails_append')
                <pre class="system-tools__output" role="status" x-show="output" x-text="output" x-cloak></pre>
            </div>
            <footer class="f-dialog__footer">
                <x-fruit::button x-on:click="$dispatch('fruit-dialog-close', { name: 'fetch-now' })"><span x-text="output ? @js(__('Done')) : @js(__('Cancel'))">{{ __('Cancel') }}</span></x-fruit::button>
                <x-fruit::button type="submit" variant="primary" x-bind:aria-busy="running ? 'true' : null">{{ __('Fetch') }}</x-fruit::button>
            </footer>
        </form>
    </x-fruit::dialog>
</div>
