<html lang="{{ app()->getLocale() }}" @if (\Helper::isLocaleRtl()) dir="rtl" @endif>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width" />
    {{-- No fonts, sizes or colours for the text: the reader's mail app shows it in its own, like other emails. --}}
    <style>
        p { margin:0 0 1em 0; }
    </style>
</head>
<body>
    <div @if (\Helper::isLocaleRtl()) style="text-align: right; direction: rtl; unicode-bidi: plaintext;" @endif>
        @if (isset($slot))
            {{ Illuminate\Mail\Markdown::parse($slot) }}
        @else
            @yield('content')
        @endif

        @if ($__env->yieldContent('footer') || \App\Option::get('email_branding'))
            <br>
            <div style="color:#999999; font-size:smaller;">
                @if (!$__env->yieldContent('footer'))
                    &copy; {{ date('Y') }} {!! \Helper::productCreditHtml('color:#999999') !!} — {{ __('Free open source help desk & shared mailbox') }}
                @else
                    @yield('footer')
                @endif
            </div>
        @endif
    </div>
</body>
</html>
