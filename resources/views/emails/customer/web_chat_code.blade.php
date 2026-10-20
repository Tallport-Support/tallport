<html lang="{{ app()->getLocale() }}" @if (\Helper::isLocaleRtl()) dir="rtl" @endif>
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
</head>
<body>
    {{-- App\Mail\WebChatCode: only the code and the team's name. --}}
    <p>{{ __('Your code to get replies from :name by email:', ['name' => $team]) }}</p>
    <p style="font-size: 28px; font-weight: bold; letter-spacing: 4px;">{{ $code }}</p>
    <p style="color: #6e6e73;">{{ __('It works for an hour. If you didn’t ask for it, you can ignore this email.') }}</p>
</body>
</html>
