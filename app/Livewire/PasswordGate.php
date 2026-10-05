<?php

namespace App\Livewire;

use App\Auth\PasswordConfirmation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Asks for the user's password in place, for a page's extra-security settings
 * (<x-password-gate>); confirmed, the page shows them.
 */
class PasswordGate extends Component
{
    #[Locked]
    public $description;

    #[Locked]
    public $url;

    public $password = '';

    public function mount($description = null)
    {
        $this->description = $description;
        $this->url = url()->full();
    }

    public function confirm()
    {
        $this->resetErrorBag();
        $user = auth()->user();
        $key = 'password-gate:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('password', __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }
        if (!Hash::check((string) $this->password, $user->password)) {
            RateLimiter::hit($key, 60);
            $this->password = '';
            $this->addError('password', __('The provided password was incorrect.'));

            return;
        }
        RateLimiter::clear($key);
        PasswordConfirmation::mark();

        return $this->redirect($this->url);
    }

    public function render()
    {
        return view('livewire.password-gate');
    }
}
