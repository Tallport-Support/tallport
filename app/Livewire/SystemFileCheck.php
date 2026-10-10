<?php

namespace App\Livewire;

use App\Misc\FileCheck;
use Livewire\Component;

/**
 * System Status's Files: the installation's files compared with the release on
 * GitHub (App\Misc\FileCheck) on request, and its leftovers deleted. The result
 * is shown once, not kept in the component.
 */
class SystemFileCheck extends Component
{
    public $error = '';

    public $deleted = false;

    protected $result;

    public function check()
    {
        $this->authorizeAdmin();
        $this->deleted = false;
        $this->run(fn () => app(FileCheck::class)->scan());
    }

    public function deleteLeftovers()
    {
        $this->authorizeAdmin();
        $this->run(function () {
            $file_check = app(FileCheck::class);
            $file_check->deleteLeftovers(auth()->user());
            $this->deleted = true;

            return $file_check->scan();
        });
    }

    protected function run($callback)
    {
        $this->error = '';
        try {
            $this->result = $callback();
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function authorizeAdmin()
    {
        abort_unless(auth()->user() && auth()->user()->isAdmin(), 403);
    }

    public function render()
    {
        $this->authorizeAdmin();

        return view('livewire/system-file-check', ['result' => $this->result]);
    }
}
