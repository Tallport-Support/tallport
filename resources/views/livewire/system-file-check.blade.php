{{-- System Status's Files (App\Livewire\SystemFileCheck): the installation compared with its release
     on GitHub (App\Misc\FileCheck), and the leftovers of earlier releases deleted. --}}
<div x-data>
    <x-fruit::form-section :title="__('Files')" id="files">
        <div class="f-form-row">
            <div>
                <span>{{ __('Check Files') }}</span>
                <p class="f-help">{{ __('Compares the installed files with release :version on GitHub.', ['version' => config('app.version')]) }}</p>
            </div>
            <x-fruit::button size="small" wire:click="check" wire:loading.attr="aria-busy" wire:target="check">{{ __('Check Files') }}</x-fruit::button>
        </div>
        @if ($error)
            <div class="f-form-row"><x-fruit::alert tone="danger">{{ $error }}</x-fruit::alert></div>
        @endif
        @if ($deleted)
            <div class="f-form-row"><p class="system-status__ok" role="status"><x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('Leftovers deleted.') }}</p></div>
        @endif
        @if ($result)
            @if (!$result['unexpected'] && !$result['changed'] && !$result['missing'])
                <div class="f-form-row"><p class="system-status__ok" role="status"><x-icon.circle-check class="f-icon" aria-hidden="true" /> {{ __('All files match the release.') }}</p></div>
            @endif
            @foreach ([
                'unexpected' => [__('Unexpected Files'), __('Not in the release. Leftovers are unchanged files of earlier releases; they can be deleted.')],
                'changed'    => [__('Changed Files'), __('Different from the release, for example edited on the server.')],
                'missing'    => [__('Missing Files'), __('In the release, but not on the server.')],
            ] as $group => [$group_title, $group_description])
                @if (count($result[$group]))
                    <div class="f-form-row system-files__group">
                        <x-fruit::disclosure :title="$group_title.' · '.count($result[$group])">
                            <p class="f-help">{{ $group_description }}</p>
                            <ul class="system-files__list">
                                @foreach ($result[$group] as $path)
                                    <li><code>{{ $path }}</code>@if ($group == 'unexpected' && in_array($path, $result['leftovers'])) <x-fruit::badge>{{ __('Leftover') }}</x-fruit::badge>@endif</li>
                                @endforeach
                            </ul>
                        </x-fruit::disclosure>
                        @if ($group == 'unexpected' && count($result['leftovers']))
                            <button type="button" class="f-button f-button--danger f-button--small" wire:loading.attr="aria-busy" wire:target="deleteLeftovers" x-on:click="$confirm({ title: @js(__('Delete leftovers?')), message: @js(__('The unchanged files of earlier releases are deleted. Nothing of yours is lost.')), confirm: @js(__('Delete Leftovers')), tone: 'danger' }).then((confirmed) => { if (confirmed) { $wire.deleteLeftovers() } })">{{ __('Delete Leftovers…') }}</button>
                        @endif
                    </div>
                @endif
            @endforeach
        @endif
    </x-fruit::form-section>
</div>
