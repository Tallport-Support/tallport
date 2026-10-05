<?php

namespace App\Livewire;

use App\Retention\Retention;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Settings » Retention: what a run would do now, with the form's values as they
 * are (saved or not), counted again whenever one changes.
 */
class RetentionPreview extends Component
{
    /**
     * The form's periods, not saved yet.
     */
    public $settings = [];

    #[On('retention-settings-changed')]
    public function settingsChanged($settings)
    {
        $this->settings = [];
        foreach ((array) $settings as $name => $value) {
            // The form's names: settings[retention_keep_months].
            $name = preg_replace('/^settings\[(.+)\]$/', '$1', $name);
            if (array_key_exists($name, Retention::CHOICES)) {
                $this->settings[$name] = (int) $value;
            }
        }
    }

    public function render()
    {
        abort_unless(auth()->user() && auth()->user()->isAdmin(), 403);
        Retention::$overrides = $this->settings;
        try {
            $preview = Retention::run(true);
        } finally {
            Retention::$overrides = [];
        }

        return view('livewire.retention-preview', ['preview' => $preview, 'enabled' => Retention::isEnabled()]);
    }
}
