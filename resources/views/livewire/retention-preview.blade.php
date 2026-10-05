{{-- What a retention run would do now, with the form's values (App\Livewire\RetentionPreview). --}}
<div class="f-form-row retention-preview" aria-live="polite">
    <span>{{ $enabled ? __('Next Run') : __('If Switched On Now') }}</span>
    <span class="f-help" wire:loading.class="f-muted">{{ __(':expired conversations expire, :deleted are deleted for good, :customers customers are deleted.', [
        'expired'   => $preview['expired'],
        'deleted'   => $preview['deleted'] + $preview['trash'] + $preview['spam'],
        'customers' => $preview['customers'],
    ]) }}</span>
</div>
