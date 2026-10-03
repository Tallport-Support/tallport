<form class="settings-form" method="POST" action="" enctype="multipart/form-data">
    {{ csrf_field() }}

    @foreach ([
        'branding.logo'    => [__('Header Logo'), asset('img/logo-brand.svg'), '22 × 22, JPG PNG GIF SVG'],
        'branding.banner'  => [__('Login Page Banner'), asset('img/banner.png'), '184 × 36, JPG PNG GIF SVG'],
        'branding.favicon' => [__('Favicon'), asset('favicon.ico'), '16 × 16 / 32 × 32, ICO PNG'],
    ] as $name => [$label, $default, $help])
        @php $field = str_replace('.', '_', $name); $url = App\Misc\Branding::imageUrl($name); @endphp
        <div class="f-stack">
            <div class="branding-image"><img src="{{ $url ?: $default }}" alt="" @if (!$url) class="branding-default" @endif></div>
            <x-fruit::field :label="$label" :description="$help">
                <x-fruit::file :name="$field" :accept="'.'.implode(',.', App\Misc\Branding::IMAGES[$name])" />
            </x-fruit::field>
            @if ($url)
                <x-fruit::checkbox :name="$field.'_remove'" value="1">{{ __('Remove') }}</x-fruit::checkbox>
            @endif
        </div>
    @endforeach

    @php $color = ltrim((string) $settings['branding.header_color'], '#') ?: App\Misc\Branding::DEFAULT_HEADER_COLOR; @endphp
    <x-fruit::field :label="__('Header Color')">
        <div class="f-row">
            <x-fruit::color id="branding_header_color" name="settings[branding.header_color]" :value="'#'.$color" class="branding-color" />
            <x-fruit::button variant="ghost" size="small" class="branding-color-reset" data-color="#{{ App\Misc\Branding::DEFAULT_HEADER_COLOR }}">{{ __('Reset') }}</x-fruit::button>
        </div>
    </x-fruit::field>

    <x-fruit::field :label="__('Name')" :description="__('In browser tabs.')">
        <x-fruit::input id="branding_title" name="settings[branding.title]" :value="old('settings.branding.title', $settings['branding.title'])" :placeholder="config('app.name')" maxlength="100" />
    </x-fruit::field>

    <x-fruit::field :label="__('Footer')" :description="__('Instead of the copyright line at the bottom of the pages.')">
        <x-fruit::textarea id="branding_footer" class="branding-editor" name="settings[branding.footer]" rows="3">{{ old('settings.branding.footer', $settings['branding.footer']) }}</x-fruit::textarea>
    </x-fruit::field>

    <x-fruit::field :label="__('Custom CSS')">
        <x-fruit::textarea id="branding_css" class="font-monospace" name="settings[branding.css]" rows="6">{{ old('settings.branding.css', $settings['branding.css']) }}</x-fruit::textarea>
    </x-fruit::field>

    <h2 class="settings-form__heading">{{ __('Emails to Customers') }}</h2>

    <x-fruit::field :label="__('Header')" :description="__('Above replies and auto replies.')">
        <x-fruit::textarea id="branding_email_header" class="branding-editor" name="settings[branding.email_header]" rows="3">{{ old('settings.branding.email_header', $settings['branding.email_header']) }}</x-fruit::textarea>
    </x-fruit::field>

    <x-fruit::field :label="__('Footer')">
        <x-fruit::textarea id="branding_email_footer" class="branding-editor" name="settings[branding.email_footer]" rows="3">{{ old('settings.branding.email_footer', $settings['branding.email_footer']) }}</x-fruit::textarea>
    </x-fruit::field>

    <x-fruit::field :label="__('Custom CSS')">
        <x-fruit::textarea id="branding_email_css" class="font-monospace" name="settings[branding.email_css]" rows="5">{{ old('settings.branding.email_css', $settings['branding.email_css']) }}</x-fruit::textarea>
    </x-fruit::field>

    <h2 class="settings-form__heading">{{ __('Widgets') }}</h2>

    <x-fruit::switch name="settings[branding.widget_powered_by]" value="1" :checked="(bool) $settings['branding.widget_powered_by']">
        <strong>{{ __('Powered by') }}</strong>
        <small>{{ __('Show "Powered by" in widgets for customers (such as the knowledge base widget)') }}</small>
    </x-fruit::switch>

    <div class="settings-form__actions">
        <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
    </div>
</form>

@include('partials/editor')

@section('javascript')
    @parent
    brandingSettingsInit();
@endsection
