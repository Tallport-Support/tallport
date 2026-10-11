<div class="f-alert f-alert--danger">
    <div class="f-alert__body">
        <strong>{{ __('Invalid or missing modules symlinks') }}</strong>
        <ul>
            @foreach ($invalid_symlinks as $invalid_symlink_from => $invalid_symlinks_to)
                <li>{{ $invalid_symlink_from }} -&gt; {{ $invalid_symlinks_to }}</li>
            @endforeach
        </ul>
        @include('modules/partials/module_install_command')
    </div>
</div>
