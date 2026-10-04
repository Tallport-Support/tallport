<?php

namespace App\Livewire;

use App\Module;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The Modules page's new versions, checked remotely once the page has opened:
 * a list to update them all, and each module's card is told its own
 * (the module-updates browser event).
 */
#[Lazy]
class ModuleUpdates extends Component
{
    public function placeholder()
    {
        return '<div></div>';
    }

    public function render()
    {
        abort_unless(auth()->user() && auth()->user()->isAdmin(), 403);

        $updates = Module::availableUpdates();
        $this->dispatch('module-updates', versions: collect($updates)->map(fn ($update) => $update['version'])->all());

        return view('livewire.module-updates', ['updates' => $updates]);
    }
}
