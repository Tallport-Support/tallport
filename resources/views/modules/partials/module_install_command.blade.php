{{-- Modules' links in public/modules that Tallport couldn't create itself (App\Module::checkSymlinks()). --}}
@php $module_install_command = 'sudo -u www-data php '.base_path().'/artisan tallport:module-install'; @endphp
<p class="f-help">{{ __('Tallport couldn\'t create them itself. Run this as the web server\'s user:') }}</p>
<div class="system-problem__command"><code>{{ $module_install_command }}</code><x-fruit::copy-button :value="$module_install_command" /></div>
