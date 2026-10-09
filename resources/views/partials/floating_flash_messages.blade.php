{{-- FruitUI's toaster: the page's one toast region, opening with a floating
     flash message from the session when there is one. --}}
@php
    $toast_message = null;
    $toast_tone = 'success';
    if (session('flash_success_floating')) {
        $toast_message = session('flash_success_floating');
    } elseif (session('flash_warning_floating')) {
        $toast_message = session('flash_warning_floating');
    } elseif (session('flash_error_floating')) {
        $toast_message = session('flash_error_floating');
        $toast_tone = 'danger';
    } elseif (!empty(session('flashes_floating')) && is_array(session('flashes_floating'))) {
        foreach (session('flashes_floating') as $flash) {
            if (!empty($flash['text']) && (empty($flash['role']) || \App\User::checkRole($flash['role']))) {
                $toast_message = $flash['text'];
                $toast_tone = ($flash['type'] ?? '') == 'danger' ? 'danger' : 'success';
                break;
            }
        }
        // Flashes set in service provider may not be removed
        Session::forget('flashes_floating');
    }
    $undo_flash = session('flash_undo_floating');
    $undo_duration = $toast_message && is_array($undo_flash) && $toast_message === ($undo_flash['text'] ?? null)
        ? max(0, ($undo_flash['expires_at'] ?? 0) - now()->timestamp) * 1000 : 0;
    $undo_thread_id = $undo_duration ? ($undo_flash['thread_id'] ?? null) : null;
    // Some flashes are HTML (a link); a toast is text.
    $toast_message = $toast_message !== null ? html_entity_decode(strip_tags((string) $toast_message)) : null;
@endphp
<x-fruit::toaster :message="$toast_message" :tone="$toast_message ? $toast_tone : null" :duration="$undo_thread_id ? $undo_duration : 4000">
    @if ($undo_thread_id)
        <x-slot:action>
            <form method="POST" action="{{ route('conversations.undo.submit', ['thread_id' => $undo_thread_id]) }}">
                @csrf
                <x-fruit::button type="submit" variant="ghost" size="small">{{ __('Undo') }}</x-fruit::button>
            </form>
        </x-slot:action>
    @endif
</x-fruit::toaster>
<x-fruit::confirmer />
<x-fruit::remote-dialog />
