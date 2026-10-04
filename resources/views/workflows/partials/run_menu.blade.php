{{-- The conversation menu's manual workflows (App\Workflows\Runner). --}}
<li role="none">
    <x-fruit::menu-group :label="__('Run Workflow')">
        @foreach ($workflows as $workflow)
            <x-fruit::menu-link href="#" class="workflow-run" :data-workflow-id="$workflow->id"><x-icon.shuffle class="f-icon" aria-hidden="true" /> {{ $workflow->name }}</x-fruit::menu-link>
        @endforeach
    </x-fruit::menu-group>
</li>
