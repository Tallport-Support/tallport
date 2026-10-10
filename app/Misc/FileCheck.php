<?php

namespace App\Misc;

use App\ActivityLog;
use Illuminate\Support\Facades\Http;

/**
 * System > Status > Files: the installation's files compared with the release
 * it runs (GitHub's zipball of tag config('app.version')). The release's files
 * come from GitHub's tree of the tag, each with its git blob SHA-1, so a file
 * on disk is compared by hashing it the way git does.
 *
 * Unexpected files that an earlier release shipped, unchanged, are leftovers
 * of updates (the updater copies the new files over the old ones and replaces
 * only REPLACED_FOLDERS wholesale): they may be deleted. Which files those
 * are is listed in resources/data/removed-files.php, generated from git by
 * `php dev/removed-files.php`.
 */
class FileCheck
{
    /**
     * Folders the updater replaces as a whole, so nothing in them lingers.
     */
    const REPLACED_FOLDERS = ['vendor', 'app', 'config', 'database', 'overrides', 'resources', 'routes', 'tests'];

    /**
     * What each installation makes itself, not checked: settings, data,
     * modules, caches and built files, the dev tools' packages and test.sh's output.
     */
    const IGNORED_PATHS = [
        '.env', '.env.backup-*', '.git', 'storage', 'Modules', 'bootstrap/cache', 'node_modules',
        'dev/vendor', 'dev/boost/vendor', 'public/storage', 'public/modules', 'public/css/builds',
        'public/js/builds', 'public/.well-known', 'coverage', '.phpunit.result.cache', '.phpunit.cache',
    ];

    const CACHE_KEY = 'file_check_release_';

    const CACHE_SECONDS = 86400;

    public $base_path;

    public $version;

    /**
     * Files earlier releases shipped: path => their blob SHA-1s.
     */
    public $removed_files;

    public function __construct($base_path = null, $version = null, $removed_files = null)
    {
        $this->base_path = rtrim($base_path ?: base_path(), '/');
        $this->version = $version ?: config('app.version');
        $this->removed_files = $removed_files ?? require resource_path('data/removed-files.php');
    }

    /**
     * The release's files (path => blob SHA-1), from GitHub, kept a day.
     *
     * @throws \RuntimeException With a message for people.
     */
    public function releaseFiles()
    {
        $cache_key = self::CACHE_KEY.$this->version;
        $files = \Cache::get($cache_key);
        if (is_array($files)) {
            return $files;
        }

        $repository = config('self-update.repository_types.github');
        $url = 'https://api.github.com/repos/'.$repository['repository_vendor'].'/'.$repository['repository_name']
            .'/git/trees/'.rawurlencode($this->version).'?recursive=1';
        try {
            $response = Http::withOptions(\Helper::setGuzzleDefaultOptions())
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get($url);
        } catch (\Exception $e) {
            throw new \RuntimeException(__('GitHub couldn\'t be reached: :error', ['error' => $e->getMessage()]));
        }

        if ($response->status() == 404) {
            throw new \RuntimeException(__('Release :version wasn\'t found on GitHub.', ['version' => $this->version]));
        }
        if ($response->status() == 429 || ($response->status() == 403 && $response->header('X-RateLimit-Remaining') === '0')) {
            throw new \RuntimeException(__('GitHub\'s limit on requests from this server was reached. Try again later.'));
        }
        if (!$response->successful() || !is_array($response->json('tree'))) {
            throw new \RuntimeException(__('GitHub sent an error (:status).', ['status' => $response->status()]));
        }
        if ($response->json('truncated')) {
            throw new \RuntimeException(__('GitHub sent an incomplete file list.'));
        }

        $export_ignore = $this->exportIgnorePatterns();
        $files = [];
        foreach ($response->json('tree') as $entry) {
            if (($entry['type'] ?? '') != 'blob' || !isset($entry['path'], $entry['sha'])) {
                continue;
            }
            // Not in the zipball (.gitattributes export-ignore).
            if (self::matches($entry['path'], $export_ignore)) {
                continue;
            }
            $files[$entry['path']] = $entry['sha'];
        }
        \Cache::put($cache_key, $files, self::CACHE_SECONDS);

        return $files;
    }

    /**
     * Compare the files on disk with the release.
     *
     * @return array unexpected, changed and missing paths; leftovers: the unexpected ones that may be deleted.
     */
    public function scan()
    {
        $release = $this->releaseFiles();
        // Also what the repository has but the zipball doesn't (CHANGELOG.md in a git checkout).
        $ignored = array_merge($this->ignoredPaths(), $this->exportIgnorePatterns());
        $result = ['version' => $this->version, 'unexpected' => [], 'changed' => [], 'missing' => [], 'leftovers' => []];

        $on_disk = [];
        foreach ($this->filesOnDisk($ignored) as $path) {
            $on_disk[$path] = true;
            $file = $this->base_path.'/'.$path;
            if (isset($release[$path])) {
                if (!is_link($file) && (!is_readable($file) || self::blobSha($file) !== $release[$path])) {
                    $result['changed'][] = $path;
                }
            } else {
                $result['unexpected'][] = $path;
                if ($this->isLeftover($path)) {
                    $result['leftovers'][] = $path;
                }
            }
        }
        foreach ($release as $path => $sha) {
            if (!isset($on_disk[$path]) && !self::matches($path, $ignored)) {
                $result['missing'][] = $path;
            }
        }

        return $result;
    }

    /**
     * Delete the leftovers, worked out anew, and the folders they leave empty
     * (not the release's own). Each file is hashed again right before.
     *
     * @return array The deleted paths.
     */
    public function deleteLeftovers($user = null)
    {
        $scan = $this->scan();
        $release_folders = [];
        foreach (array_keys($this->releaseFiles()) as $path) {
            for ($folder = dirname($path); $folder != '.'; $folder = dirname($folder)) {
                $release_folders[$folder] = true;
            }
        }

        $deleted = [];
        foreach ($scan['leftovers'] as $path) {
            if ($this->isLeftover($path) && @unlink($this->base_path.'/'.$path)) {
                $deleted[] = $path;
                for ($folder = dirname($path); $folder != '.' && !isset($release_folders[$folder]); $folder = dirname($folder)) {
                    $full_folder = $this->base_path.'/'.$folder;
                    if (is_link($full_folder) || !is_dir($full_folder) || count(scandir($full_folder)) > 2 || !@rmdir($full_folder)) {
                        break;
                    }
                }
            }
        }

        if ($deleted) {
            activity()
                ->causedBy($user)
                ->withProperties(['files' => implode(', ', $deleted)])
                ->useLog(ActivityLog::NAME_SYSTEM)
                ->log(ActivityLog::DESCRIPTION_SYSTEM_FILES_DELETED);
        }

        return $deleted;
    }

    /**
     * A file an earlier release shipped, byte for byte, inside the
     * installation's folder (no symlink on the way).
     */
    public function isLeftover($path)
    {
        if (empty($this->removed_files[$path]) || str_contains('/'.$path.'/', '/../')) {
            return false;
        }
        $file = $this->base_path.'/'.$path;
        if (is_link($file) || !is_file($file) || !is_readable($file)
            || realpath(dirname($file)) !== realpath($this->base_path).(dirname($path) == '.' ? '' : '/'.dirname($path))
        ) {
            return false;
        }

        return in_array(self::blobSha($file), $this->removed_files[$path], true);
    }

    /**
     * Paths not checked; modules add theirs (relative to the installation's folder, * as a wildcard).
     */
    public function ignoredPaths()
    {
        return \Eventy::filter('system.file_check.ignored_paths', self::IGNORED_PATHS);
    }

    /**
     * The files (and symlinks) below the installation's folder, relative to it.
     * Symlinked folders aren't followed.
     */
    protected function filesOnDisk($ignored)
    {
        $base_length = strlen($this->base_path) + 1;
        $directory = new \RecursiveDirectoryIterator($this->base_path, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function ($file) use ($base_length, $ignored) {
            return !self::matches(substr($file->getPathname(), $base_length), $ignored);
        });

        $paths = [];
        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            $paths[] = substr($file->getPathname(), $base_length);
        }
        sort($paths);

        return $paths;
    }

    /**
     * The release's .gitattributes export-ignore patterns.
     */
    protected function exportIgnorePatterns()
    {
        $patterns = [];
        $attributes = @file($this->base_path.'/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($attributes as $line) {
            $parts = preg_split('#\s+#', trim($line));
            if (count($parts) > 1 && !str_starts_with($parts[0], '#') && in_array('export-ignore', $parts)) {
                // "/name" is at the top only; "name" at any depth.
                $patterns[] = str_starts_with($parts[0], '/') ? ltrim($parts[0], '/') : (str_contains(rtrim($parts[0], '/'), '/') ? $parts[0] : '**/'.$parts[0]);
            }
        }

        return $patterns;
    }

    /**
     * The path is one of the patterns or below one: "*" doesn't cross folders,
     * a pattern starting with "**" followed by "/" is a name at any depth.
     */
    public static function matches($path, array $patterns)
    {
        foreach ($patterns as $pattern) {
            $pattern = rtrim($pattern, '/');
            $any_depth = str_starts_with($pattern, '**/');
            if ($any_depth) {
                $pattern = substr($pattern, 3);
            }
            for ($candidate = $path; $candidate != '.' && $candidate != ''; $candidate = dirname($candidate)) {
                $subject = $any_depth ? basename($candidate) : $candidate;
                if ($subject === $pattern || (str_contains($pattern, '*') && fnmatch($pattern, $subject, FNM_PATHNAME))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The git blob SHA-1 of a file: sha1("blob {size}\0{content}").
     */
    public static function blobSha($file)
    {
        $context = hash_init('sha1');
        hash_update($context, 'blob '.filesize($file)."\0");
        hash_update_file($context, $file);

        return hash_final($context);
    }
}
