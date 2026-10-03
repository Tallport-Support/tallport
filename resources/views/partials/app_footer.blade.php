@if (!in_array(Route::currentRouteName(), array('mailboxes.view'))
    && empty(app('request')->x_embed) && empty($__env->yieldContent('no_footer')))
    <div class="footer">
        @if (!\Eventy::filter('footer.text', ''))
            &copy; 2018-{{ date('Y') }} {!! \Helper::productCreditHtml() !!} — {{ __('Free open source help desk & shared mailbox') }}
        @else
            {!! \Eventy::filter('footer.text', '') !!}
        @endif
        @if (!Auth::user())
            <a href="#" class="hidden in-app-switcher"><br/>{{ __('Switch Helpdesk URL') }}</a>
        @endif
        {{-- Show version to admin only --}}
        @if (Auth::user() && Auth::user()->isAdmin())
            <br/>
            <a href="{{ route('system') }}">{{ config('app.version') }}</a>
        @endif
    </div>
@endif
