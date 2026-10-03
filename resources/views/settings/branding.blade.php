<form class="form-horizontal margin-top" method="POST" action="" enctype="multipart/form-data">
    {{ csrf_field() }}

    @foreach ([
        'branding.logo'    => [__('Header Logo'), asset('img/logo-brand.svg'), '22 × 22, JPG PNG GIF SVG'],
        'branding.banner'  => [__('Login Page Banner'), asset('img/banner.png'), '184 × 36, JPG PNG GIF SVG'],
        'branding.favicon' => [__('Favicon'), asset('favicon.ico'), '16 × 16 / 32 × 32, ICO PNG'],
    ] as $name => [$label, $default, $help])
        @php $field = str_replace('.', '_', $name); $url = App\Misc\Branding::imageUrl($name); @endphp
        <div class="form-group{{ $errors->has($field) ? ' has-error' : '' }}">
            <label class="col-sm-2 control-label">{{ $label }}</label>
            <div class="col-sm-6">
                <div class="branding-image"><img src="{{ $url ?: $default }}" alt="" @if (!$url) class="branding-default" @endif></div>
                <input type="file" name="{{ $field }}" accept=".{{ implode(',.', App\Misc\Branding::IMAGES[$name]) }}">
                @if ($url)
                    <label class="checkbox-inline"><input type="checkbox" name="{{ $field }}_remove" value="1"> {{ __('Remove') }}</label>
                @endif
                <div class="form-help">{{ $help }}</div>
                @include('partials/field_error', ['field' => $field])
            </div>
        </div>
    @endforeach

    <div class="form-group">
        <label for="branding_header_color" class="col-sm-2 control-label">{{ __('Header Color') }}</label>
        <div class="col-sm-6">
            @php $color = ltrim((string) $settings['branding.header_color'], '#') ?: App\Misc\Branding::DEFAULT_HEADER_COLOR; @endphp
            <input type="color" id="branding_header_color" name="settings[branding.header_color]" value="#{{ $color }}" class="branding-color">
            <a href="#" class="branding-color-reset small margin-left-10" data-color="#{{ App\Misc\Branding::DEFAULT_HEADER_COLOR }}">{{ __('Reset') }}</a>
        </div>
    </div>

    <div class="form-group">
        <label for="branding_title" class="col-sm-2 control-label">{{ __('Name') }}</label>
        <div class="col-sm-6">
            <input type="text" id="branding_title" class="form-control input-sized" name="settings[branding.title]" value="{{ old('settings.branding.title', $settings['branding.title']) }}" placeholder="{{ config('app.name') }}" maxlength="100">
            <div class="form-help">{{ __('In browser tabs.') }}</div>
        </div>
    </div>

    <div class="form-group">
        <label for="branding_footer" class="col-sm-2 control-label">{{ __('Footer') }}</label>
        <div class="col-sm-9">
            <textarea id="branding_footer" class="form-control branding-editor" name="settings[branding.footer]" rows="3">{{ old('settings.branding.footer', $settings['branding.footer']) }}</textarea>
            <div class="form-help">{{ __('Instead of the copyright line at the bottom of the pages.') }}</div>
        </div>
    </div>

    <div class="form-group">
        <label for="branding_css" class="col-sm-2 control-label">{{ __('Custom CSS') }}</label>
        <div class="col-sm-9">
            <textarea id="branding_css" class="form-control font-monospace" name="settings[branding.css]" rows="6">{{ old('settings.branding.css', $settings['branding.css']) }}</textarea>
        </div>
    </div>

    <h3 class="subheader">{{ __('Emails to Customers') }}</h3>

    <div class="form-group">
        <label for="branding_email_header" class="col-sm-2 control-label">{{ __('Header') }}</label>
        <div class="col-sm-9">
            <textarea id="branding_email_header" class="form-control branding-editor" name="settings[branding.email_header]" rows="3">{{ old('settings.branding.email_header', $settings['branding.email_header']) }}</textarea>
            <div class="form-help">{{ __('Above replies and auto replies.') }}</div>
        </div>
    </div>

    <div class="form-group">
        <label for="branding_email_footer" class="col-sm-2 control-label">{{ __('Footer') }}</label>
        <div class="col-sm-9">
            <textarea id="branding_email_footer" class="form-control branding-editor" name="settings[branding.email_footer]" rows="3">{{ old('settings.branding.email_footer', $settings['branding.email_footer']) }}</textarea>
        </div>
    </div>

    <div class="form-group">
        <label for="branding_email_css" class="col-sm-2 control-label">{{ __('Custom CSS') }}</label>
        <div class="col-sm-9">
            <textarea id="branding_email_css" class="form-control font-monospace" name="settings[branding.email_css]" rows="5">{{ old('settings.branding.email_css', $settings['branding.email_css']) }}</textarea>
        </div>
    </div>

    <h3 class="subheader">{{ __('Widgets') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Powered by') }}</label>
        <div class="col-sm-6">
            <div class="checkbox"><label><input type="checkbox" name="settings[branding.widget_powered_by]" value="1" @if ($settings['branding.widget_powered_by']) checked @endif> {{ __('Show "Powered by" in widgets for customers (such as the knowledge base widget)') }}</label></div>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>

@include('partials/editor')

@section('javascript')
    @parent
    brandingSettingsInit();
@endsection
