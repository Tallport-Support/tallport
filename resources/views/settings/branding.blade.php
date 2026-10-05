<form id="page-form" class="settings-form" method="POST" action="" enctype="multipart/form-data">
    {{ csrf_field() }}

    <x-fruit::form-section :title="__('Branding')">
        {{-- Logo and banner each have a dark-mode version; without it, the light one shows in both. --}}
        @foreach ([
            [__('Header Logo'), '22 × 22, JPG PNG GIF SVG', ['branding.logo' => __('Light'), 'branding.logo_dark' => __('Dark')]],
            [__('Login Page Banner'), '184 × 36, JPG PNG GIF SVG', ['branding.banner' => __('Light'), 'branding.banner_dark' => __('Dark')]],
            [__('Favicon'), '16 × 16 / 32 × 32, ICO PNG', ['branding.favicon' => __('File')]],
        ] as [$label, $help, $images])
            <x-fruit::fieldset class="branding-images">
                <legend>{{ $label }}</legend>
                <p class="f-help">{{ $help }}</p>
                <div class="branding-images__row">
                    @foreach ($images as $name => $appearance)
                        @php $field = str_replace('.', '_', $name); $url = App\Misc\Branding::imageUrl($name); @endphp
                        <div class="f-stack branding-image__item @if (str_ends_with($name, '_dark')) branding-image__item--dark @endif">
                            <div class="branding-image">
                                @if ($url)
                                    <img src="{{ $url }}" alt="">
                                @elseif ($name == 'branding.logo' || $name == 'branding.logo_dark')
                                    <x-logo class="branding-default branding-default--logo" aria-hidden="true" />
                                @elseif ($name == 'branding.banner' || $name == 'branding.banner_dark')
                                    <span class="banner__default branding-default"><x-logo class="banner__logo" aria-hidden="true" />Tallport</span>
                                @else
                                    <img src="{{ asset('favicon.ico') }}" alt="" class="branding-default">
                                @endif
                            </div>
                            <x-fruit::field :label="$appearance">
                                <x-fruit::file :name="$field" :accept="'.'.implode(',.', App\Misc\Branding::IMAGES[$name])" />
                            </x-fruit::field>
                            @if ($url)
                                <x-fruit::checkbox :name="$field.'_remove'" value="1">{{ __('Remove') }}</x-fruit::checkbox>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-fruit::fieldset>
        @endforeach

        @php $color = ltrim((string) $settings['branding.header_color'], '#') ?: App\Misc\Branding::DEFAULT_HEADER_COLOR; @endphp
        <x-fruit::field :label="__('Header Color')" layout="row">
            <div class="f-row">
                <x-fruit::color id="branding_header_color" name="settings[branding.header_color]" :value="'#'.$color" class="branding-color" />
                <x-fruit::button variant="ghost" size="small" class="branding-color-reset" data-color="#{{ App\Misc\Branding::DEFAULT_HEADER_COLOR }}" x-data x-on:click="const color = document.getElementById('branding_header_color'); color.value = $el.dataset.color; color.dispatchEvent(new Event('input', {bubbles: true})); color.dispatchEvent(new Event('change', {bubbles: true}))">{{ __('Reset') }}</x-fruit::button>
            </div>
        </x-fruit::field>

        <x-fruit::field :label="__('Name')" :description="__('In browser tabs.')" layout="row">
            <x-fruit::input id="branding_title" name="settings[branding.title]" :value="old('settings.branding.title', $settings['branding.title'])" :placeholder="config('app.name')" maxlength="100" />
        </x-fruit::field>

        <x-fruit::field :label="__('Footer')" :description="__('Instead of the copyright line at the bottom of the pages.')">
            <x-editor id="branding_footer" name="settings[branding.footer]" rows="3">{{ old('settings.branding.footer', $settings['branding.footer']) }}</x-editor>
        </x-fruit::field>

        <x-fruit::field :label="__('Custom CSS')">
            <x-fruit::textarea id="branding_css" class="font-monospace" name="settings[branding.css]" rows="6">{{ old('settings.branding.css', $settings['branding.css']) }}</x-fruit::textarea>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Emails to Customers')">
        <x-fruit::field :label="__('Header')" :description="__('Above replies and auto replies.')">
            <x-editor id="branding_email_header" name="settings[branding.email_header]" rows="3">{{ old('settings.branding.email_header', $settings['branding.email_header']) }}</x-editor>
        </x-fruit::field>

        <x-fruit::field :label="__('Footer')">
            <x-editor id="branding_email_footer" name="settings[branding.email_footer]" rows="3">{{ old('settings.branding.email_footer', $settings['branding.email_footer']) }}</x-editor>
        </x-fruit::field>

        <x-fruit::field :label="__('Custom CSS')">
            <x-fruit::textarea id="branding_email_css" class="font-monospace" name="settings[branding.email_css]" rows="5">{{ old('settings.branding.email_css', $settings['branding.email_css']) }}</x-fruit::textarea>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Widgets')">
        <x-fruit::field :label="__('Powered By')" layout="row">
            <x-fruit::switch name="settings[branding.widget_powered_by]" value="1" :checked="(bool) $settings['branding.widget_powered_by']" />
            <x-slot:description>{{ __('Show "Powered by" in widgets for customers (such as the knowledge base widget)') }}</x-slot:description>
        </x-fruit::field>
    </x-fruit::form-section>

</form>

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection
