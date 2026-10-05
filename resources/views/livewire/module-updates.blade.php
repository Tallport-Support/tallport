{{-- The modules' new versions (App\Livewire\ModuleUpdates). --}}
<div>
    @if (count($updates))
        <div class="f-alert f-alert--warning modules-updates">
            <div class="f-alert__body">
                {{ __('There are updates available') }}:
                <ul id="new_versions_list">
                    @foreach ($updates as $alias => $update)
                        <li><a href="#module-{{ $alias }}" data-module-alias="{{ $alias }}">{{ $update['name'] }} ({{ $update['version'] }})</a></li>
                    @endforeach
                </ul>
            </div>
            <div class="f-alert__actions"><button type="button" class="f-button f-button--small update-all-trigger" x-on:click="updateAll">{{ __('Update Now') }} ({{ count($updates) }})</button></div>
        </div>
    @endif
    @foreach ($check_errors as [$name, $error])
        <p class="f-help module-update-error">{{ __(':name couldn\'t be checked for updates: :error', ['name' => $name, 'error' => \Illuminate\Support\Str::limit($error, 200)]) }}</p>
    @endforeach
</div>
