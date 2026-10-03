@extends('layouts.app')

@section('title', __('System Status'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('system/sidebar_menu')
@endsection

@section('content')

<div class="page-content system-status">

    @action('system.status.before_info_table')

    <h3 id="app">{{ __('Info') }}</h3>

    <table class="f-table system-status__table">
        <tbody>
            <tr id="version">
                <th>{{ __('App Version') }}</th>
                <td>
                    @if (!\Config::get('app.disable_updating'))
                        @if ($new_version_available)
                            <strong>{{ \Config::get('app.version') }}</strong>
                            <div class="f-alert f-alert--danger system-status__alert">
                                {{ __('A new version is available') }}: <strong>{{ $latest_version }}</strong> <a href="{{ config('app.tallport_url') }}/releases" target="_blank">({{ __('View details') }})</a>
                                <button class="f-button f-button--small update-trigger" data-loading-text="{{ __('Updating') }}…{{ __('This may take several minutes') }}">{{ __('Update Now') }}</button>
                            </div>
                        @else
                            <x-fruit::badge tone="success">{{ \Config::get('app.version') }}</x-fruit::badge>
                            
                            <a href="#" class="f-button f-button--small check-updates-trigger" data-loading-text="{{ __('Checking') }}…">{{ __('Check for updates') }}</a>
                            @if ($latest_version_error)
                                <p class="f-error">{{ $latest_version_error }}</p>
                            @endif
                        @endif
                    @else
                        <x-fruit::badge tone="success">{{ \Config::get('app.version') }}</x-fruit::badge>
                    @endif
                </td>
            </tr>
            <tr>
                <th>{{ __('Date & Time') }}</th>
                <td>{{ App\User::dateFormat(new Illuminate\Support\Carbon(), 'M j, Y H:i', null, true, false) }}</td>
            </tr>
            <tr>
                <th>{{ __('Timezone') }} (.env)</th>
                <td>{{ \Config::get('app.timezone') }} (GMT{{ date('O') }})</td>
            </tr>
            <tr>
                <th>{{ __('Protocol') }}</th>
                <td>
                    <div id="system-app-protocol"></div>
                    <div id="session_secure_cookie" data-session-secure="{{ (int)\Config::get('session.secure') }}" class="f-alert f-alert--danger system-status__alert hidden">
                        .env &gt;&gt; SESSION_SECURE_COOKIE=true
                    </div>
                    <div id="protocol_push_notifications" class="f-alert f-alert--danger system-status__alert hidden">
                        {{ __("HTTPS protocol is required for the browser push notifications to work.") }}
                    </div>
                </td>
            </tr>
            @if (\Helper::detectCloudFlare())
                @php
                    $cloudflare_is_used = config('app.cloudflare_is_used');
                @endphp
                <tr>
                    <th>Proxy</th>
                    <td>
                        <div @if (!$cloudflare_is_used) class="f-alert f-alert--warning" @endif>
                            @if (!$cloudflare_is_used)@endif{{ 'CloudFlare' }} (<a href="{{ config('app.freescout_repo') }}/wiki/Installation-Guide#103-cloudflare" target="_blank">{{ __('read more') }}</a>)
                        </div>
                    </td>
                </tr>
            @endif
            {{--<tr>
                <th>{{ __('.env file') }}</th>
                <td>
                    @if (\File::exists(base_path().DIRECTORY_SEPARATOR.'.env'))
                        {{ 'Exists'}}
                    @else
                        <strong class="text-danger">{{ 'Not found'}}</strong>
                    @endif
                </td>
            </tr>--}}
            <tr>
                <th>DB</th>
                <td>
                    {{ ucfirst(\DB::connection()->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME)) }} ({{ \DB::connection()->getPDO()->getAttribute(\PDO::ATTR_SERVER_VERSION) }})
                    @if ($missing_migrations)
                        &nbsp;&nbsp;<a href="{{ route('system.tools') }}" class="f-button f-button--small f-button--danger">{{ 'Migrate DB' }}</a>
                        <div class="f-alert f-alert--danger system-status__alert">
                            @foreach($missing_migrations as $missing_migration)
                                {{ $missing_migration }}<br/>
                            @endforeach
                        </div>
                    @endif
                </td>
            </tr>
            @if ($search_index)
                <tr>
                    <th>{{ __('Search index') }}</th>
                    <td>
                        @if ($search_index[0] >= $search_index[1] && \App\Search\Indexer::isReady())
                            <x-fruit::badge tone="success">OK</x-fruit::badge> ({{ $search_index[1] }})
                        @else
                            <x-fruit::badge tone="warning">{{ __('Building: :indexed of :total conversations', ['indexed' => $search_index[0], 'total' => $search_index[1]]) }}</x-fruit::badge>
                        @endif
                    </td>
                </tr>
            @endif
            @if ($redis_uses)
                <tr>
                    <th>Redis</th>
                    <td>
                        @if ($redis_error)
                            <span class="f-error">{{ $redis_error }}</span>
                        @else
                            Redis {{ $redis_version }}
                        @endif
                        ({{ implode(', ', $redis_uses) }})
                    </td>
                </tr>
            @endif
            <tr>
                <th>{{ __('Web Server') }}</th>
                <td>@if (!empty($_SERVER['SERVER_SOFTWARE'])){{ $_SERVER['SERVER_SOFTWARE'] }}@else ? @endif</td>
            </tr>
            <tr>
                <th>{{ __('PHP Version') }}</th>
                <td>PHP {{ phpversion() }}</td>
            </tr>
            <tr>
                <th>PHP upload_max_filesize / post_max_size</th>
                <td>{{ ini_get('upload_max_filesize') }} / {{ ini_get('post_max_size') }}</td>
            </tr>
        </tbody>
    </table>

    @action('system.status.after_info_table')

    <h3 id="php">{{ __('PHP Extensions') }}</h3>
    <table class="f-table system-status__table system-status__table--narrow">
        <tbody>
            @foreach ($php_extensions as $extension_name => $extension_status)
                <tr>
                    @php
                        $optional_purpose = config('installer.optional.'.strtolower($extension_name));
                    @endphp
                    <th>{{ $extension_name }}@if (!$extension_status && $optional_purpose) {{ __('(optional)') }}@endif</th>
                    <td>
                        @if ($extension_status)
                            <x-fruit::badge tone="success">OK</x-fruit::badge>
                        @elseif ($optional_purpose)
                            <x-fruit::badge tone="warning">{{ __('Not found') }}</x-fruit::badge> <span class="f-muted">{{ __('Needed for') }}: {{ __($optional_purpose) }}</span>
                        @else
                            <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
                        @endif
                    </td>
                </tr>
            @endforeach
            @php
                $pcre_jit_off = array_filter([
                    __('Web Server') => !\Helper::pcreJitAvailable(),
                    'tallport:receive' => (bool) \Option::get('receive_pcre_jit_off'),
                ]);
            @endphp
            <tr>
                <th>PCRE JIT</th>
                <td>
                    @if (!$pcre_jit_off)
                        <x-fruit::badge tone="success">OK</x-fruit::badge>
                    @else
                        <x-fruit::badge tone="warning">{{ __('Off') }}</x-fruit::badge> ({{ implode(', ', array_keys($pcre_jit_off)) }})
                        <span class="f-muted">{{ __('Needed for') }}: {{ __('Faster text processing') }}</span>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    @action('system.status.after_php_extensions')

    <h3 id="php">{{ __('Functions') }}</h3>
    <table class="f-table system-status__table system-status__table--narrow">
        <tbody>
            @foreach ($functions as $functions_name => $functions_status)
                <tr>
                    <th>{{ $functions_name }}</th>
                    <td>
                        @if ($functions_status)
                            <x-fruit::badge tone="success">OK</x-fruit::badge>
                        @else
                            <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @action('system.status.after_functions')

    <h3 id="permissions">{{ __('Permissions') }}</h3>
    {!! __h('These folders must be writable by web server user (:user).', ['user' => '<strong>'.(function_exists('get_current_user') ? htmlspecialchars(get_current_user()) : '').'</strong>']) !!} {{ __('Recommended permissions') }}: <strong>775</strong>
    <table class="f-table system-status__table system-status__table--narrow">
        <tbody>
            @foreach ($permissions as $perm_path => $perm)
                <tr>
                    <th>{{ $perm_path }}</th>
                    <td>
                        @if ($perm_path == 'storage/framework/cache/data/')
                            @if ($non_writable_cache_file)
                                @if (strstr($non_writable_cache_file, 'shell_exec()'))
                                    <span class="f-error">{{ $non_writable_cache_file }}</span>
                                @else
                                    <x-fruit::badge tone="danger">{{ __('Non-writable files found') }}</x-fruit::badge>
                                    <br/>
                                    <span class="f-error">{{ $non_writable_cache_file }}</span>
                                    <br/><br/>
                                    {{ __('Run the following command') }} (<a href="{{ config('app.freescout_repo') }}/wiki/Installation-Guide#6-configuring-web-server" target="_blank">{{ __('read more') }}</a>):<br/>
                                    <code>sudo chown -R www-data:www-data {{ base_path() }}</code>
                                @endif
                            @elseif (!$perm['status'])
                                <x-fruit::badge tone="danger">{{ __('Not writable') }} @if ($perm['value'])({{ $perm['value'] }})@endif</x-fruit::badge>
                            @else
                                <x-fruit::badge tone="success">OK</x-fruit::badge>
                            @endif
                        @else
                            @if ($perm['status'])
                                <x-fruit::badge tone="success">OK</x-fruit::badge>
                            @else
                                <x-fruit::badge tone="danger">{{ __('Not writable') }} @if ($perm['value'])({{ $perm['value'] }})@endif</x-fruit::badge>

                                <br/><br/>
                                {{ __('Run the following command') }} (<a href="{{ config('app.freescout_repo') }}/wiki/Installation-Guide#6-configuring-web-server" target="_blank">{{ __('read more') }}</a>):<br/>
                                <code>sudo chown -R www-data:www-data {{ base_path() }}</code>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="f-table system-status__table system-status__table--narrow">
        <tbody>
            <tr>
                <th>public/storage (symlink)</th>
                <td>
                    @if ($public_symlink_exists)
                        <x-fruit::badge tone="success">OK</x-fruit::badge>
                    @else
                        <x-fruit::badge tone="danger">{{ __('Not found') }}</x-fruit::badge>
                        <div class="f-alert f-alert--danger system-status__alert">{{ __('Create symlink manually') }}: <code>ln -s storage/app/public public/storage</code></div>
                    @endif
                </td>
            </tr>
            <tr>
                <th>.env</th>
                <td>
                    @if ($env_is_writable)
                        <x-fruit::badge tone="success">OK</x-fruit::badge>
                    @else
                        <x-fruit::badge tone="danger">{{ __('Not writable') }}</x-fruit::badge>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    @if ($invalid_symlinks)
        @include('modules/partials/invalid_symlinks')
    @endif

    @action('system.status.after_permissions')

    <h3 id="cron">Cron Commands</h3>
    <p>
        {{ __('Make sure that you have the following line in your crontab:') }}<br/>
        <code>* * * * * php {{ base_path() }}/artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
        <br/>
        {{ __('Alternatively cron job can be executed by requesting the following URL every minute (this method is not recommended as some features may not work as expected, use it at your own risk)') }}:<br/>
        <pre><a href="{{ route('system.cron', ['hash' => \Helper::getWebCronHash()]) }}" target="_blank">{{ route('system.cron', ['hash' => \Helper::getWebCronHash()]) }}</a></pre>
    </p>
    <table class="f-table system-status__table">
        <tbody>
            @foreach ($commands as $command)
                <tr>
                    <th>{{ $command['name'] }}</th>
                    <td>
                        <x-fruit::badge :tone="$command['status'] == 'success' ? 'success' : 'danger'">{!! $command['status_text'] !!}</x-fruit::badge>
                        @if ($command['name'] == 'tallport:fetch-emails' && $command['status'] != "success")
                            (<a href="{{ route('logs', ['name' => 'fetch_errors']) }}">{{ __('See logs') }}</a>)
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @action('system.status.after_cron_commands')

    <h3 id="jobs">{{ __('Background Jobs') }}</h3>
    @if (count($queued_jobs) || count($failed_jobs))
        {{ __('Queued and failed jobs are cleaned automatically once in a while. No need to worry or delete them manually.') }}
    @endif
    <table class="f-table system-status__table">
        <tbody>
            <tr>
                <th>{{ __('Queued Jobs') }}</th>
                <td>
                    <p>
                        {{ __('Total') }}: <strong>{{ count($queued_jobs)}}</strong>
                    </p>
                    <div class="jobs-list">
                        @foreach ($queued_jobs as $job)
                            @php
                                $payload = $job->getPayloadDecoded();
                            @endphp
                            @if ($payload)
                                <table class="f-table">
                                    <tbody>
                                        <tr>
                                            <th>
                                                {{ $loop->index+1 }}. {{ $payload['displayName'] }} 
                                                @if ($payload['displayName'] == 'App\Jobs\TriggerAction' && !empty($payload['data']['command']))
                                                    ({{ App\Job::getTriggerActionName($payload) }})
                                                @endif
                                            </th>
                                            <th>
                                                <form action="{{ route('system.action') }}" method="POST" class="f-row system-status__job-actions">
                                                    {{ csrf_field() }}

                                                    <input type="hidden" name="job_id" value="{{ $job->id }}" />

                                                    <button type="submit" name="action" value="cancel_job" class="f-button f-button--small">{{ __('Cancel') }}</button>
                                                    @if ($job->attempts > 0)
                                                        <button type="submit" name="action" value="retry_job" class="f-button f-button--small f-button--primary">{{ __('Retry') }}</button>
                                                    @endif
                                                </form>
                                            </th>
                                        </tr>
                                        <tr>
                                            <td>{{ __('Queue') }}</td>
                                            <td>{{ $job->queue }}</td>
                                        </tr>
                                        @if (\Str::startsWith($payload['displayName'], 'App\Jobs\Send'))
                                            @php
                                                $command = $job->getCommand();
                                                $last_thread = null;
                                                if ($command
                                                    && !empty($command->conversation)
                                                    && !empty($command->threads)
                                                ) {
                                                    $last_thread = \App\Thread::getLastThread($command->threads);
                                                }
                                            @endphp
                                            @if (!empty($last_thread))
                                                <tr>
                                                    <td>{{ __('Message') }}</td>
                                                    <td><a href="{{ route('conversations.view', ['id' => $last_thread->conversation_id]) }}#thread-{{ $last_thread->id }}" target="_blank">#{{ $command->conversation->number }}</a></td>
                                                </tr>
                                            @endif
                                        @endif
                                        <tr>
                                            <td>{{ __('Attempts') }}</td>
                                            <td>
                                                @if ($job->attempts > 0)<strong class="f-error">@endif
                                                    {{ $job->attempts }}
                                                @if ($job->attempts > 0)
                                                    </strong>
                                                @endif
                                                @if ($job->attempts > 0 && !empty($last_thread))
                                                     &nbsp;<small>(<a href="{{ route('logs', ['name' => 'out_emails', 'thread_id' => $last_thread->id]) }}" target="_blank">{{ __('View log') }}</a>)</small>
                                                @endif
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>{{ __('Created At') }}</td>
                                            <td>{{  App\User::dateFormat($job->created_at) }}</td>
                                        </tr>
                                        @if ($job->attempts > 0)
                                            <tr>
                                                <td>{{ __('Next Attempt') }}</td>
                                                <td>{{  App\User::dateFormat($job->available_at) }}</td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            @endif
                        @endforeach
                    </div>
                </td>
            </tr>
            <tr>
                <th>{{ __('Failed Jobs') }}</th>
                <td>
                    <p>
                        {{ __('Total') }}:  <strong @if (count($failed_jobs) > 0) class="f-error" @endif >{{ count($failed_jobs) }}</strong>

                        @if (count($failed_jobs))
                            &nbsp;&nbsp;
                            <form action="{{ route('system.action') }}" method="POST" class="f-row">
                                {{ csrf_field() }}

                                <select name="failed_queue" class="f-input system-status__queue">
                                    @foreach ($failed_queues as $queue)
                                        <option value="{{ $queue }}">{{ __('Queue') }}: {{ $queue }}</option>
                                    @endforeach
                                </select>

                                <button type="submit" name="action" value="delete_failed_jobs" class="f-button f-button--small">{{ __('Delete') }}</button>
                                <button type="submit" name="action" value="retry_failed_jobs" class="f-button f-button--small">{{ __('Retry') }}</button>
                            </form>
                        @endif
                    </p>
                    <div class="jobs-list">
                        @foreach ($failed_jobs as $job)
                            @php
                                $payload = $job->getPayloadDecoded();
                            @endphp
                            <table class="f-table">
                                <tbody>
                                    <tr>
                                        <th colspan="2">{{ $loop->index+1 }}. {{ json_decode($job->payload, true)['displayName'] }}
                                            @if ($payload['displayName'] == 'App\Jobs\TriggerAction' && !empty($payload['data']['command']))
                                                ({{ App\Job::getTriggerActionName($payload) }})
                                            @endif
                                         – <small><a href="{{ route('system.ajax_html', ['action' => 'job_details', 'param' => $job->id]) }}" data-trigger="modal" data-modal-title="{{ $loop->index+1 }}. {{ json_decode($job->payload, true)['displayName'] }}" data-modal-no-footer="true">{{ __('View Details') }}</a></small></th>
                                    </tr>
                                    <tr>
                                        <td>{{ __('Queue') }}</td>
                                        <td>{{ $job->queue }}</td>
                                    </tr>
                                    @if (\Str::startsWith($payload['displayName'], 'App\Jobs\Send'))
                                        @php
                                            $command = $job->getCommand();
                                            $last_thread = null;
                                            if ($command
                                                && !empty($command->conversation)
                                                && !empty($command->threads)
                                            ) {
                                                $last_thread = \App\Thread::getLastThread($command->threads);
                                            }
                                        @endphp
                                        @if (!empty($last_thread))
                                            <tr>
                                                <td>{{ __('Message') }}</td>
                                                <td><a href="{{ route('conversations.view', ['id' => $last_thread->conversation_id]) }}#thread-{{ $last_thread->id }}" target="_blank">#{{ $command->conversation->number }}</a></</td>
                                            </tr>
                                        <tr>
                                            <td>{{ __('Logs') }}</td>
                                            <td>
                                                <small><a href="{{ route('logs', ['name' => 'out_emails', 'thread_id' => $last_thread->id]) }}" target="_blank">{{ __('View log') }}</a></small>
                                            </td>
                                        </tr>
                                        @endif
                                    @endif
                                    <tr>
                                        <td>{{ __('Failed At') }}</td>
                                        <td>{{  App\User::dateFormat($job->failed_at, 'M j, Y H:i:s') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        @endforeach
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

    @action('system.status.after_background_jobs')

</div>

@action('system.status.after_content')

@endsection

@section('javascript')
    @parent
    initSystemStatus();
@endsection