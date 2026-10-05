<?php

namespace App\Livewire;

use App\Http\Controllers\SystemController;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * System Status's checks, loaded after the page: some ask the system (running
 * commands, cache files, migrations, the latest version) and take a while.
 */
#[Lazy]
class SystemStatus extends Component
{
    public function render()
    {
        abort_unless(auth()->user() && auth()->user()->isAdmin(), 403);

        return view('livewire/system-status', SystemController::statusData());
    }
}
