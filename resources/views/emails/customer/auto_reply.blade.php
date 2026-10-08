<html lang="{{ app()->getLocale() }}" @if (\Helper::isLocaleRtl()) dir="rtl" @endif>
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <style>{!! safe_raw_html(\Eventy::filter('auto_reply_email.css', '')) !!}</style>
</head>
<body>
    <div id="{{ App\Misc\Mail::REPLY_SEPARATOR_HTML }}" class="{{ App\Misc\Mail::REPLY_SEPARATOR_HTML }}">{!! safe_raw_html(\Eventy::filter('auto_reply_email.header', '')) !!}

        <div @if (\Helper::isLocaleRtl()) style="direction: rtl; unicode-bidi: plaintext; text-align: right;" @endif>
            {!! $auto_reply_message !!}
        </div>

        @if (\App\Option::get('email_branding'))
            @if (\Helper::isLocaleRtl())
                <div style="font-size:smaller; color: #999999; border-top: 1px solid #eeeeee; margin: 10px 0 14px 0; padding-top: 10px; direction: rtl; unicode-bidi: plaintext; text-align: right;">
            @else
                <div style="font-size:smaller; color: #999999; border-top: 1px solid #eeeeee;margin: 10px 0 14px 0; padding-top: 10px;">
            @endif
                {!! __('Support powered by :app_name — Free open source help desk & shared mailbox', ['app_name' => \Helper::productCreditHtml()]) !!}
            </div>
        @endif
    </div>{!! safe_raw_html(\Eventy::filter('auto_reply_email.footer', '')) !!}
    <span height="0" style="font-size: 0px; height:0px; line-height: 0px; color:#ffffff;">{{ \MailHelper::getMessageMarker($headers['Message-ID']) }}</span>
</body>
</html>