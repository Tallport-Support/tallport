<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $message->subject() }}</title>
    <style>
        body { font-family: -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; font-size: 14px; color: #333; margin: 16px; }
        .headers { border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 14px; line-height: 1.6; }
        .headers b { color: #777; font-weight: normal; display: inline-block; min-width: 90px; }
        img { max-width: 100%; }
    </style>
</head>
<body>
    @php
        $addresses = function ($list) {
            return implode(', ', array_map(function ($address) {
                return $address->personal ? $address->personal.' <'.$address->mail.'>' : $address->mail;
            }, $list));
        };
    @endphp
    <div class="headers">
        <div><b>{{ __('From') }}:</b> {{ $addresses($message->from()) }}</div>
        @if ($message->to())<div><b>{{ __('To') }}:</b> {{ $addresses($message->to()) }}</div>@endif
        @if ($message->cc())<div><b>{{ __('Cc') }}:</b> {{ $addresses($message->cc()) }}</div>@endif
        @if ($message->date())<div><b>{{ __('Date') }}:</b> {{ App\User::dateFormat($message->date()) }}</div>@endif
        <div><b>{{ __('Subject') }}:</b> {{ $message->subject() }}</div>
        @if ($parts)
            <div><b>{{ __('Attachments') }}:</b>
                @foreach ($parts as $i => $part)
                    <a href="{{ route('attachments.email', ['id' => $attachment->id, 'part' => $i]) }}" target="_blank">{{ $part->getName() }}</a>@if (!$loop->last), @endif
                @endforeach
            </div>
        @endif
    </div>
    {!! $body !!}
</body>
</html>
