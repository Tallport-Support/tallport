<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Rap2hpoutre\LaravelLogViewer\LaravelLogViewer;

/**
 * Manage » Logs » App Logs: the application log files in storage/logs,
 * parsed by rap2hpoutre/laravel-log-viewer. Only files listed in that folder
 * can be viewed, downloaded, emptied or deleted (file names arrive encrypted,
 * and are checked against the listing).
 */
class AppLogsController extends Controller
{
    /**
     * Show a log file, or download, empty or delete one or all of them.
     */
    public function index(Request $request)
    {
        $viewer = new LaravelLogViewer();
        $files = $viewer->getFiles(true);

        if ($request->input('l')) {
            $viewer->setFile($this->logPath($viewer, $files, $request->input('l')));
        }

        if ($request->input('dl')) {
            return response()->download($this->logPath($viewer, $files, $request->input('dl')));
        } elseif ($request->has('clean')) {
            app('files')->put($this->logPath($viewer, $files, $request->input('clean')), '');

            return redirect(url()->previous());
        } elseif ($request->has('del')) {
            app('files')->delete($this->logPath($viewer, $files, $request->input('del')));

            return redirect($request->url());
        } elseif ($request->has('delall') && hash_equals((string) \Session::token(), (string) $request->input('_token'))) {
            foreach ($files as $file) {
                app('files')->delete($viewer->getStoragePath().'/'.$file);
            }

            return redirect($request->url());
        }

        $data = [
            'logs'           => $viewer->all(),
            'folders'        => [],
            'current_folder' => '',
            'folder_files'   => [],
            'files'          => $files,
            'current_file'   => $viewer->getFileName(),
            'standardFormat' => true,
            'structure'      => [],
            'storage_path'   => $viewer->getStoragePath(),
        ];

        if (is_array($data['logs']) && count($data['logs']) > 0) {
            $first_log = reset($data['logs']);
            if ($first_log && !$first_log['context'] && !$first_log['level']) {
                $data['standardFormat'] = false;
            }
        }

        return view('laravel-log-viewer::log', $data);
    }

    /**
     * Full path of a log file from its encrypted name; only files in the log
     * folder's listing.
     */
    protected function logPath(LaravelLogViewer $viewer, array $files, $encrypted_name)
    {
        try {
            $name = Crypt::decrypt($encrypted_name);
        } catch (\Exception $e) {
            abort(404);
        }

        if (!is_string($name) || !in_array($name, $files, true) || basename($name) !== $name) {
            abort(404);
        }

        return $viewer->getStoragePath().'/'.$name;
    }
}
