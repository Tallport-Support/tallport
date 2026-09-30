<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

// When processing attachments FreeScout may create files in /tmp folder.
// So it's good to clean this folder periodically.
class CleanTmp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:clean-tmp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove from system temp folder old FreeScout tempt files to avoid "No space left on device"';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->cleanDirectory(\Helper::getTempDir());

        $this->comment("Done");
    }

    /**
     * Remove the application's old files from a temp directory: its own temp
     * files after a week, and SwiftMailer's cache directories after a day.
     * Only direct children are looked at, so other programs' files (also in
     * subdirectories) are left alone.
     */
    public function cleanDirectory($dir)
    {
        $prefix = \Helper::getTempFilePrefix();
        $week_ago = time() - 7 * 86400;
        $day_ago = time() - 86400;

        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.DIRECTORY_SEPARATOR.$name;

            if (strpos($name, $prefix) === 0 && is_file($path) && !is_link($path)) {
                if (filemtime($path) < $week_ago) {
                    @unlink($path);
                }
                continue;
            }

            // SwiftMailer's disk cache: /tmp/<32 hex>/body.
            // https://github.com/freescout-help-desk/freescout/issues/5558
            if (preg_match('/^[a-f0-9]{32}$/', $name) && is_dir($path) && !is_link($path)
                && filemtime($path) < $day_ago
            ) {
                $contents = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
                if ($contents === [] || $contents === ['body'] && is_file($path.DIRECTORY_SEPARATOR.'body')) {
                    if ($contents) {
                        @unlink($path.DIRECTORY_SEPARATOR.'body');
                    }
                    @rmdir($path);
                }
            }
        }
    }
}
