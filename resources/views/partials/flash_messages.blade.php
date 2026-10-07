@php
    $flash_alerts = [];
    if (session('flash_success') || session('flash_success_unescaped')) {
        $flash_alerts[] = ['type' => 'success', 'html' => e(session('flash_success')).safe_raw_html(session('flash_success_unescaped'))];
    }
    if (session('flash_warning')) {
        $flash_alerts[] = ['type' => 'warning', 'html' => e(session('flash_warning'))];
    }
    if (session('flash_error')) {
        $flash_alerts[] = ['type' => 'danger', 'html' => e(session('flash_error'))];
    }
    if (session('flash_error_unescaped')) {
        $flash_alerts[] = ['type' => 'danger', 'html' => safe_raw_html(session('flash_error_unescaped'))];
    }
    // Floating flash messages are displayed in the layout.
    $flashes = \Eventy::filter('flash_messages.flashes', $flashes ?? []);
    if (!empty($flashes) && is_array($flashes)) {
        foreach ($flashes as $flash) {
            // Optionally a title and an action (a button to where it's fixed).
            $flash_html = !empty($flash['unescaped']) ? safe_raw_html($flash['text']) : e($flash['text']);
            $flash_alerts[] = [
                'type'   => $flash['type'],
                'html'   => !empty($flash['title']) ? '<p><strong>'.e($flash['title']).'</strong></p><p>'.$flash_html.'</p>' : $flash_html,
                'action' => $flash['action'] ?? null,
            ];
        }
    }
@endphp
@foreach ($flash_alerts as $flash_alert)
    <x-fruit::alert :tone="in_array($flash_alert['type'], ['success', 'warning', 'danger']) ? $flash_alert['type'] : 'info'" class="flash-alert" x-data>
        {!! $flash_alert['html'] !!}
        <x-slot:actions>
            @if (!empty($flash_alert['action']))
                <a wire:navigate class="f-button f-button--small" href="{{ $flash_alert['action']['url'] }}">{{ $flash_alert['action']['label'] }}</a>
            @endif
            <button type="button" class="f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Close') }}" x-on:click="$root.remove()"><x-icon.x class="f-icon" aria-hidden="true" /></button>
        </x-slot:actions>
    </x-fruit::alert>
@endforeach
