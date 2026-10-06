<?php

namespace App\Http\Controllers;

use App\Option;
use App\User;
use App\FailedJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\Console\Output\BufferedOutput;

class SystemController extends Controller
{
    public static $latest_version_error = '';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth', ['except' => [
            'cron'
        ]]);
    }

    /**
     * System status.
     */
    public function status(Request $request)
    {
        // The checks load after the page (App\Livewire\SystemStatus).
        return view('system/status');
    }

    /**
     * What System Status shows: the checks of PHP, files, the database, Redis,
     * commands, jobs and updates.
     */
    public static function statusData()
    {
        // PHP extensions.
        $php_extensions = \Helper::checkRequiredExtensions();

        // Functions.
        $functions = \Helper::checkRequiredFunctions();

        // Permissions.
        $permissions = [];
        foreach (config('installer.permissions') as $perm_path => $perm_value) {
            $path = base_path($perm_path);
            $value = '';
            if (file_exists($path)) {
                $value = substr(sprintf('%o', fileperms($path)), -4);
            }
            $permissions[$perm_path] = [
                'status' => \Helper::isFolderWritable($path),
                'value'  => $value,
            ];
        }

        // Check if cache files are writable.
        $non_writable_cache_file = '';
        if (function_exists('shell_exec')) {
            $non_writable_cache_file = \Helper::shellExec('find '.base_path('storage/framework/cache/data/').' -type f | xargs -I {} sh -c \'[ ! -w "{}" ] && echo {}\' 2>&1 | head -n 1');
            if ($non_writable_cache_file === false) {
                $non_writable_cache_file = 'Could not execute command via shell_exec()';
            } else {
                $non_writable_cache_file = trim($non_writable_cache_file ?? '');
                // Leave only one line (in case head -n 1 does not work)
                $non_writable_cache_file = preg_replace("#[\r\n].+#m", '', $non_writable_cache_file);
                if (!strstr($non_writable_cache_file, base_path('storage/framework/cache/data/'))) {
                    $non_writable_cache_file = '';
                }
            }
        }
        

        // Check if public symlink exists, if not, try to create.
        $public_symlink_exists = true;
        $public_path = public_path('storage');
        $public_test = $public_path.DIRECTORY_SEPARATOR.'.gitignore';

        if (!file_exists($public_test) || !file_get_contents($public_test)) {
            \File::delete($public_path);
            \Artisan::call('storage:link');
            if (!file_exists($public_test) || !file_get_contents($public_test)) {
                $public_symlink_exists = false;
            }
        }

        // Check if .env is writable.
        $env_is_writable = is_writable(base_path('.env'));

        // Jobs
        // Redis, when the cache, sessions or queue use it.
        $redis_uses = array_keys(array_filter([
            'cache'   => config('cache.stores.'.config('cache.default').'.driver') == 'redis',
            'session' => config('session.driver') == 'redis',
            'queue'   => config('queue.connections.'.config('queue.default').'.driver') == 'redis',
        ]));
        $redis_version = '';
        $redis_error = '';
        if ($redis_uses) {
            try {
                $redis_version = app('redis')->connection()->info('server')['redis_version'] ?? '?';
            } catch (\Throwable $e) {
                $redis_error = $e->getMessage();
            }
        }

        // Search index (MariaDB / MySQL).
        $search_index = null;
        if (in_array(\DB::getDriverName(), ['mysql', 'mariadb'])) {
            try {
                $search_index = \App\Search\Indexer::progress();
            } catch (\Throwable $e) {
                // Before the migration.
            }
        }

        try {
            $queued_jobs = \App\Job::pending(null, null, null, 100);
        } catch (\Throwable $e) {
            $queued_jobs = collect();
        }
        $failed_jobs = \App\FailedJob::orderBy('failed_at', 'desc')->limit(100)->get();
        $failed_queues = $failed_jobs->pluck('queue')->unique();

        // Commands
        $commands_list = [
            'tallport:fetch-emails' => 'tallport:fetch-emails',
            \Helper::getWorkerIdentifier() => 'queue:work',
        ];
        // Mailboxes delivered by the mail server only: nothing is fetched.
        if (!\App\Mailbox::anyFetched()) {
            unset($commands_list['tallport:fetch-emails']);
        }
        if (\App\Ai\Settings::isConfigured()) {
            $commands_list[\Helper::getWorkerIdentifier(\App\Console\Kernel::AI_WORKER)] = 'queue:work (AI)';
        }
        try {
            if (\App\Nostr\NostrMailbox::anyActive()) {
                $commands_list['tallport:nostr-listen'] = 'tallport:nostr-listen';
            }
        } catch (\Throwable $e) {
            // No Nostr tables yet.
        }
        foreach ($commands_list as $command_identifier => $command_name) {
            $status_texts = [];

            // Check if command is running now
            if (function_exists('shell_exec')) {
                $running_commands = 0;

                try {
                    $ps_output = \Helper::shellExec("ps auxww | grep '{$command_identifier}'");

                    if ($ps_output === false) {
                        $commands[] = [
                            'name'        => $command_name,
                            'status'      => 'error',
                            'status_text' => __h('Could not execute command via shell_exec()'),
                        ];
                    } else {
                        $processes = preg_split("/[\r\n]/", $ps_output);
                        $pids = [];
                        foreach ($processes as $process) {
                            $process = trim($process);
                            preg_match("/^[\S]+\s+([\d]+)\s+/", $process, $m);
                            if (empty($m)) {
                                // Another format (used in Docker image).
                                // 1713 nginx     0:00 /usr/bin/php82...
                                preg_match("/^([\d]+)\s+[\S]+\s+/", $process, $m);
                            }
                            if (!preg_match("/(sh \-c|grep )/", $process) && !empty($m[1])) {
                                $running_commands++;
                                $pids[] = $m[1];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Do nothing
                }
                if ($running_commands == 1) {
                    $commands[] = [
                        'name'        => $command_name,
                        'status'      => 'success',
                        'status_text' => __h('Running'),
                    ];
                    continue;
                } elseif ($running_commands > 1) {
                    // queue:work command is stopped by settings a cache key
                    if (str_starts_with($command_name, 'queue:work')) {
                        \Helper::queueWorkerRestart();
                        $commands[] = [
                            'name'        => $command_name,
                            'status'      => 'error',
                            'status_text' => __h(':number commands were running at the same time. Commands have been restarted', ['number' => htmlspecialchars($running_commands)]),
                        ];
                    } else {
                        unset($pids[0]);
                        $commands[] = [
                            'name'        => $command_name,
                            'status'      => 'error',
                            'status_text' => __h(':number commands are running at the same time. Please stop extra commands by executing the following console command:', ['number' => htmlspecialchars($running_commands)]).' kill '.implode(' | kill ', $pids),
                        ];
                    }
                    continue;
                }
            }
            // Check last run
            $option_name = str_replace('freescout_', '', preg_replace('/[^a-zA-Z0-9]/', '_', $command_name));

            $date_text = '?';
            $last_run = Option::get($option_name.'_last_run');
            if ($last_run) {
                $date = Carbon::createFromTimestamp($last_run);
                $date_text = User::dateFormat($date);
            }
            $status_texts[] = __h('Last run:').' '.htmlspecialchars($date_text);

            $date_text = '?';
            $last_successful_run = Option::get($option_name.'_last_successful_run');
            if ($last_successful_run) {
                $date_ = Carbon::createFromTimestamp($last_successful_run);
                $date_text = User::dateFormat($date);
            }
            $status_texts[] = __h('Last successful run:').' '.htmlspecialchars($date_text);

            $status = 'error';
            if ($last_successful_run && $last_run && (int) $last_successful_run >= (int) $last_run) {
                unset($status_texts[0]);
                $status = 'success';
            }

            // If queue:work is not running, clear cache to let it start if something is wrong with the mutex
            if (str_starts_with($command_name, 'queue:work') && !$last_successful_run) {
                $status_texts[] = __h('Try to :%a_start%clear cache:%a_end% to force command to start.', ['%a_start%' => '<a href="'.route('system.tools').'" target="_blank">', '%a_end%' => '</a>']);
                // This sometimes makes Status page open as non logged in user.
                //\Artisan::call('tallport:clear-cache', ['--doNotGenerateVars' => true]);
            }

            $commands[] = [
                'name'        => $command_name,
                'status'      => $status,
                'status_text' => implode(' ', $status_texts),
                'last_run'            => $last_run ? Carbon::createFromTimestamp($last_run) : null,
                'last_successful_run' => $last_successful_run ? Carbon::createFromTimestamp($last_successful_run) : null,
            ];
        }

        // Check new version if enabled
        $new_version_available = false;
        if (!\Config::get('app.disable_updating')) {
            $latest_version = \Cache::remember('latest_version', 15 * 60, function () {
                try {
                    return \Updater::getVersionAvailable();
                } catch (\Exception $e) {
                    SystemController::$latest_version_error = $e->getMessage();
                    return '';
                }
            });

            if ($latest_version && version_compare($latest_version, \Config::get('app.version'), '>')) {
                $new_version_available = true;
            }
        } else {
            $latest_version = \Config::get('app.version');
        }

        // Detect missing migrations.
        $migrations_output = \Helper::runCommand('migrate:status');
        preg_match_all("#\| N    \| ([^\|]+)\|#", $migrations_output, $migrations_m);
        $missing_migrations = $migrations_m[1] ?? [];

        return [
            'commands'              => $commands,
            'queued_jobs'           => $queued_jobs,
            'failed_jobs'           => $failed_jobs,
            'redis_uses'            => $redis_uses,
            'search_index'          => $search_index,
            'redis_version'         => $redis_version,
            'redis_error'           => $redis_error,
            'failed_queues'         => $failed_queues,
            'php_extensions'        => $php_extensions,
            'functions'             => $functions,
            'permissions'           => $permissions,
            'new_version_available' => $new_version_available,
            'latest_version'        => $latest_version,
            'latest_version_error'  => SystemController::$latest_version_error,
            'public_symlink_exists' => $public_symlink_exists,
            'env_is_writable'       => $env_is_writable,
            'non_writable_cache_file' => $non_writable_cache_file,
            'missing_migrations'    => $missing_migrations,
            'invalid_symlinks'      => \App\Module::checkSymlinks(),
        ];
    }

    /**
     * The sidebar's Status badge (partials/app_sidebar_settings): how many problems
     * System Status found last; kept by the page and every few minutes (App\Console\Kernel).
     */
    const PROBLEM_COUNT_CACHE = 'system_problem_count';

    /**
     * What needs attention (System Status, Needs Attention): [key, problem, tone, detail],
     * the key naming the fix the page offers.
     */
    public static function problems(array $data)
    {
        $problems = [];
        if ($data['missing_migrations']) {
            $problems[] = ['migrations', __('Database updates are waiting'), 'danger', implode(', ', $data['missing_migrations'])];
        }
        if ($data['redis_uses'] && $data['redis_error']) {
            $problems[] = ['redis', __('Redis isn\'t reachable'), 'danger', $data['redis_error']];
        }
        // Optional extensions and PCRE JIT aren't problems: Requirements lists them.
        foreach ($data['php_extensions'] as $name => $installed) {
            if (!$installed && !config('installer.optional.'.strtolower($name))) {
                $problems[] = ['extension', __('The :name extension is missing', ['name' => $name]), 'danger', null];
            }
        }
        foreach ($data['functions'] as $name => $available) {
            if (!$available) {
                $problems[] = ['function', __('The :name function is missing', ['name' => $name]), 'danger', null];
            }
        }
        foreach ($data['permissions'] as $path => $permission) {
            if (!$permission['status']) {
                $problems[] = ['permissions', __(':path isn\'t writable', ['path' => $path]), 'danger', null];
            }
        }
        if ($data['non_writable_cache_file']) {
            $problems[] = ['permissions', __('Some cache files aren\'t writable'), 'danger', $data['non_writable_cache_file']];
        }
        if (!$data['env_is_writable']) {
            $problems[] = ['permissions', __(':path isn\'t writable', ['path' => '.env']), 'danger', null];
        }
        if (!$data['public_symlink_exists']) {
            $problems[] = ['symlink', __('The public/storage link is missing'), 'danger', null];
        }
        if ($data['invalid_symlinks']) {
            $problems[] = ['module_symlinks', __('Invalid or missing modules symlinks'), 'danger', collect($data['invalid_symlinks'])->map(fn ($to, $from) => $from.' → '.$to)->implode(', ')];
        }
        // Just updated: background commands restart within a minute or two.
        $updated_at = (int) \Option::get('app_updated_at');
        $just_updated = $updated_at && time() - $updated_at < 5 * 60;
        foreach ($data['commands'] as $command) {
            if ($command['status'] != 'success') {
                $problems[] = ['command', __(':command isn\'t running', ['command' => $command['name']]), $just_updated ? 'warning' : 'danger', $command];
            }
        }
        if (count($data['failed_jobs'])) {
            $problems[] = ['failed_jobs', trans_choice('1 job failed|:count jobs failed', count($data['failed_jobs'])), 'warning', null];
        }

        return $problems;
    }

    /**
     * Counts the problems for the sidebar's badge.
     */
    public static function cacheProblemCount($problems = null)
    {
        $count = count($problems ?? self::problems(self::statusData()));
        \Cache::put(self::PROBLEM_COUNT_CACHE, $count, now()->addDay());

        return $count;
    }

    public static function updateLogPath()
    {
        return storage_path('logs/web-update.log');
    }

    /**
     * Start tallport:update in the background (with the command-line PHP), logging to
     * storage/logs/web-update.log, which ends with TALLPORT_UPDATE_EXIT:<exit code>.
     * False where processes can't be started; the update then runs in this request.
     */
    protected function startBackgroundUpdate()
    {
        if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))))) {
            return false;
        }
        $php = is_executable(PHP_BINDIR.'/php') ? PHP_BINDIR.'/php' : 'php';
        $log = self::updateLogPath();
        @file_put_contents($log, '');
        $command = '('.escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' tallport:update --force; echo "TALLPORT_UPDATE_EXIT:$?") > '.escapeshellarg($log).' 2>&1 &';

        return \Helper::shellExec('nohup sh -c '.escapeshellarg($command).' > /dev/null 2>&1 &') !== false;
    }

    public function action(Request $request)
    {
        switch ($request->action) {
            case 'cancel_job':
                \App\Job::findPending($request->job_id)?->cancel();
                \Session::flash('flash_success_floating', __('Done'));
                break;

            case 'retry_job':
                \App\Job::findPending($request->job_id)?->runNow();
                sleep(1);
                \Session::flash('flash_success_floating', __('Done'));
                break;

            case 'delete_failed_jobs':
                \App\FailedJob::where('queue', $request->failed_queue)->delete();
                \Session::flash('flash_success_floating', __('Failed jobs deleted'));
                break;

            case 'retry_all_failed_jobs':
                foreach (\App\FailedJob::pluck('id') as $failed_job_id) {
                    \Artisan::call('queue:retry', ['id' => $failed_job_id]);
                }
                \Session::flash('flash_success_floating', __('Failed jobs restarted'));
                break;

            case 'retry_failed_jobs':
                $jobs = \App\FailedJob::where('queue', $request->failed_queue)->get();
                foreach ($jobs as $job) {
                    \Artisan::call('queue:retry', ['id' => $job->id]);
                }
                \Session::flash('flash_success_floating', __('Failed jobs restarted'));
                break;
        }

        return redirect()->route('system');
    }

    /**
     * System tools: on System Status now (Maintenance, and Fetch Now in Background Tasks).
     */
    public function tools(Request $request)
    {
        return redirect()->route('system');
    }

    /**
     * Execute tools action.
     *
     * @param Request $request [description]
     *
     * @return [type] [description]
     */
    public function toolsExecute(Request $request)
    {
        $outputLog = new BufferedOutput();

        switch ($request->action) {
            case 'clear_cache':
                \Artisan::call('tallport:clear-cache', [], $outputLog);
                break;

            case 'fetch_emails':
                $params = [];
                $params['--days'] = (int)$request->days;
                $params['--unseen'] = (int)$request->unseen;
                $params['--debug'] = (int)$request->debug;
                \Artisan::call('tallport:fetch-emails', $params, $outputLog);
                break;

            case 'migrate_db':
                \Artisan::call('migrate', ['--force' => true], $outputLog);
                break;

            case 'logout_users':
                \Artisan::call('tallport:logout-users', [], $outputLog);
                break;
        }

        $output = $outputLog->fetch();
        unset($outputLog);

        if ($output) {
            // \Session::flash does not work after BufferedOutput; System Status shows it (Maintenance).
            \Cache::forever('tools_execute_output', $output);
        }

        return redirect()->route('system')->withInput($request->input());
    }

    /**
     * Ajax.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        switch ($request->action) {

            case 'update':
                if (\Config::get('app.disable_updating')) {
                    $response['msg'] = __('Updating is disabled (APP_DISABLE_UPDATING).');
                    break;
                }
                // As on the command line (tallport:update, then tallport:after-app-update in a
                // new process), in the background: the update replaces the code this request runs.
                if ($this->startBackgroundUpdate()) {
                    $response['status'] = 'success';
                    $response['started'] = true;
                    $response['msg_success'] = __('Updating… This may take several minutes.');
                    break;
                }
                try {
                    $status = \Updater::update();

                    // Artisan::output()
                } catch (\Exception $e) {
                    $response['msg'] = __('Error occurred. Please try again or try another :%a_start%update method:%a_end%', ['%a_start%' => '<a href="'.config('app.freescout_url').'/docs/update/" target="_blank">', '%a_end%' => '</a>']);
                    $response['msg'] .= '<br/><br/>'.$e->getMessage();

                    \Helper::logException($e);
                }
                if (!$response['msg'] && $status) {
                    // Adding session flash is useless as cache is cleared
                    $response['msg_success'] = __('Application successfully updated');
                    $response['status'] = 'success';
                }
                break;

            // The background update: still running, or finished (and how).
            case 'update_status':
                $log = is_file(self::updateLogPath()) ? (string) file_get_contents(self::updateLogPath()) : '';
                $response['status'] = 'success';
                $response['finished'] = (bool) preg_match('/TALLPORT_UPDATE_EXIT:(\d+)/', $log, $exit_m);
                $response['succeeded'] = $response['finished'] && $exit_m[1] === '0';
                $response['version'] = config('app.version');
                $response['log'] = trim(mb_substr(preg_replace('/TALLPORT_UPDATE_EXIT:\d+/', '', $log), -2000));
                break;

            case 'check_updates':
                if (!\Config::get('app.disable_updating')) {
                    try {
                        // The page shows the cached latest version: ask again and keep the answer.
                        \Cache::put('latest_version', \Updater::getVersionAvailable(), 15 * 60);
                        $response['new_version_available'] = \Updater::isNewVersionAvailable(config('app.version'));
                        $response['status'] = 'success';
                    } catch (\Exception $e) {
                        $response['msg'] = __('Error occurred').': '.$e->getMessage();
                    }
                    if (!$response['msg'] && !$response['new_version_available']) {
                        // Adding session flash is useless as cache is cleated
                        $response['msg_success'] = __('You have the latest version installed');
                    }
                } else {
                    $response['new_version_available'] = false;
                    $response['status'] = 'success';
                    $response['msg_success'] = __('Updating is disabled (APP_DISABLE_UPDATING).');
                }
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occurred';
        }

        return \Response::json($response);
    }

    /**
     * Web Cron.
     */
    public function cron(Request $request)
    {
        if (empty($request->hash) || !\Helper::hashEquals($request->hash, \Helper::getWebCronHash())) {
            abort(404);
        }
        $outputLog = new BufferedOutput();
        \Artisan::call('schedule:run', [], $outputLog);
        $output = $outputLog->fetch();

        preg_match_all("#'artisan'\s+([^\s>]+)#", $output ?? '', $m);

        $commands = $m[1] ?? [];
        $result = count($commands)." commands executed:\r\n".(count($commands) ? '- ' : '').implode("\r\n- ", $commands);

        return response($result, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Ajax HTML.
     * Content Security Policy header is sent via ContentSecurityPolicy middleware.
     */
    public function ajaxHtml(Request $request)
    {
        switch ($request->action) {
            case 'job_details':
                $job = \App\FailedJob::find($request->param);
                if (!$job) {
                    abort(404);
                }

                $html = '';
                $payload = json_decode($job->payload, true);

                if (!empty($payload['data']['command'])) {
                    $html .= '<pre>'.\Helper::stripDangerousTags(print_r(unserialize($payload['data']['command'], ['allowed_classes' => false]), 1)).'</pre>';
                }
                
                $html .= '<pre>'.\Helper::stripDangerousTags($job->exception).'</pre>';

                return response($html);
        }

        abort(404);
    }
}
