<?php

namespace App\Http\Controllers\Install;

use Illuminate\Routing\Controller;
use App\Install\EnvironmentManager;
use App\Install\FinalInstallManager;
use App\Install\InstalledFileManager;

class FinalController extends Controller
{
    /**
     * Update installed file and display finished view.
     *
     * @param InstalledFileManager $fileManager
     *
     * @return \Illuminate\View\View
     */
    public function finish(InstalledFileManager $fileManager, FinalInstallManager $finalInstall, EnvironmentManager $environment)
    {
        $dbMessage = [];
        if (!empty(session('message'))) {
            $dbMessage = session('message');
        }

        $finalMessages = $finalInstall->runFinal();
        // Create /storage/.installed file.
        $finalStatusMessage = $fileManager->update();
        $finalEnvFile = $environment->getEnvContent();

        // Now clear cache and cache config
        \Artisan::call('tallport:clear-cache');


        return view('vendor.installer.finished', compact('finalMessages', 'finalStatusMessage', 'finalEnvFile', 'dbMessage'));
    }
}
