<?php

namespace App\Livewire;

use App\Http\Controllers\SystemController;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * System Status's checks, loaded after the page: some ask the system (running
 * commands, cache files, migrations, the latest version) and take a while.
 * What needs attention comes first, each problem with its fix.
 */
#[Lazy]
class SystemStatus extends Component
{
    /**
     * Fetch Emails Now (Background Tasks): the command's output.
     */
    #[Renderless]
    public function fetchNow($days = 3, $unseen = true, $debug = false)
    {
        $this->authorizeAdmin();
        $output = new BufferedOutput();
        \Artisan::call('tallport:fetch-emails', ['--days' => max(1, (int) $days), '--unseen' => (int) (bool) $unseen, '--debug' => (int) (bool) $debug], $output);

        return $output->fetch();
    }

    protected function authorizeAdmin()
    {
        abort_unless(auth()->user() && auth()->user()->isAdmin(), 403);
    }

    public function render()
    {
        $this->authorizeAdmin();
        $data = SystemController::statusData();
        $data['problems'] = SystemController::problems($data);
        SystemController::cacheProblemCount($data['problems']);
        // The last Maintenance action's output (SystemController::toolsExecute()).
        $data['tools_output'] = \Cache::pull('tools_execute_output');

        return view('livewire/system-status', $data);
    }
}
