{{-- Extra-security settings: shown after a recent password confirmation, otherwise the password is asked for here (App\Livewire\PasswordGate). --}}
@props(['description' => null])
@if (App\Auth\PasswordConfirmation::isRecent())
    {{ $slot }}
@else
    <livewire:password-gate :description="$description" />
@endif
