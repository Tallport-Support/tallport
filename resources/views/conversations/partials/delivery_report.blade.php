{{-- A delivery report (App\Misc\DeliveryReports): which email failed, to whom and why, then the original email and the report itself, collapsed. --}}
@php
    $report_kind = $delivery_report['kind'];
    $report_recipients = [];
    $report_customer = null;
    foreach ($delivery_report['recipients'] as $report_address) {
        $report_email = App\Email::where('email', $report_address)->first();
        if (!$report_customer && $report_email && $report_email->customer) {
            $report_customer = $report_email->customer;
        }
        $report_recipients[] = $report_email && $report_email->customer && !\Helper::isPrint()
            ? '<a href="'.e($report_email->customer->url()).'" class="delivery-report__recipient">'.e($report_address).'</a>'
            : '<strong>'.e($report_address).'</strong>';
    }
    $report_other_conversations = $report_customer ? App\Conversation::where('customer_id', $report_customer->id)
        ->where('id', '!=', $conversation->id)
        ->where('state', App\Conversation::STATE_PUBLISHED)
        ->whereIn('mailbox_id', Auth::user()->mailboxesIdsCanView())
        ->count() : 0;
    $report_channels = $report_customer ? $report_customer->getChannels()->map(fn ($channel) => $channel->getChannelName())->filter()->unique()->values() : collect();
    $report_original = $delivery_report['original'] ?? [];
    $report_original_body = App\Misc\DeliveryReports::originalBody($thread);
@endphp
<div class="f-stack delivery-report-block">
    <x-fruit::alert :tone="$report_kind == App\Incoming\DeliveryReport::DELAYED ? 'warning' : 'danger'" class="delivery-report" :data-kind="$report_kind">
        <x-slot:icon>
            @if ($report_kind == App\Incoming\DeliveryReport::DELAYED)
                <x-icon.clock class="f-icon" />
            @elseif ($report_kind == App\Incoming\DeliveryReport::COMPLAINT)
                <x-icon.flag class="f-icon" />
            @elseif ($report_kind == App\Incoming\DeliveryReport::SUPPRESSED)
                <x-icon.ban class="f-icon" />
            @else
                <x-icon.mail-x class="f-icon" />
            @endif
        </x-slot:icon>
        <p class="delivery-report__headline"><strong>{!! safe_raw_html(App\Misc\DeliveryReports::headline($report_kind, implode(', ', $report_recipients))) !!}</strong></p>
        <p class="delivery-report__reason">{{ App\Misc\DeliveryReports::reasonText($delivery_report['reason'] ?? '') }}</p>
        @if (!empty($delivery_report['reporter']))
            <p class="f-footnote f-muted delivery-report__reporter">{{ $report_kind == App\Incoming\DeliveryReport::SUPPRESSED ? __('Suppressed by :reporter', ['reporter' => $delivery_report['reporter']]) : __('Reported by :reporter', ['reporter' => $delivery_report['reporter']]) }}</p>
        @endif
        @if (!empty($delivery_report['status']) || !empty($delivery_report['diagnostic']))
            <p class="f-footnote f-muted delivery-report__technical">@if (!empty($delivery_report['status']))<code>{{ $delivery_report['status'] }}</code> @endif{{ $delivery_report['diagnostic'] ?? '' }}</p>
        @endif
        @if ($report_other_conversations || count($report_channels))
            <p class="f-footnote delivery-report__customer">
                @if ($report_other_conversations)<a href="{{ $report_customer->urlView() }}">{{ __('Other conversations: :count', ['count' => $report_other_conversations]) }}</a>@endif
                @if ($report_other_conversations && count($report_channels)) · @endif
                @if (count($report_channels)){{ __('Other channels: :channels', ['channels' => $report_channels->implode(', ')]) }}@endif
            </p>
        @endif
        @if (!empty($send_status_data['bounce_for_thread']) && !empty($send_status_data['bounce_for_conversation']) && ($bounce_for_conversation = App\Conversation::find($send_status_data['bounce_for_conversation'])))
            <p class="f-footnote delivery-report__reply">{!! __safe_raw_html('This is a bounce message for :link', [
                'link' => '<a href="'.route('conversations.view', ['id' => $send_status_data['bounce_for_conversation']]).'#thread-'.$send_status_data['bounce_for_thread'].'">#'.$bounce_for_conversation->number.'</a>'
            ]) !!}</p>
        @endif
    </x-fruit::alert>

    @if ($report_original)
        <x-fruit::disclosure :title="__('Original Message')" class="delivery-report__original">
            <x-fruit::description-list>
                @foreach (['from' => __('From'), 'to' => __('To'), 'subject' => __('Subject')] as $report_key => $report_label)
                    @if (!empty($report_original[$report_key]))<div><dt>{{ $report_label }}</dt><dd>{{ $report_original[$report_key] }}</dd></div>@endif
                @endforeach
                @if (!empty($report_original['date']))<div><dt>{{ __('Date') }}</dt><dd>{{ App\User::dateFormat($report_original['date']) }}</dd></div>@endif
            </x-fruit::description-list>
            @if ($report_original_body)
                <hr>
                <div class="thread-content f-prose delivery-report__original-body" dir="auto">{!! $report_original_body['html'] !!}</div>
                @if (count($report_original_body['attachments']))
                    <div class="f-row">
                        @foreach ($report_original_body['attachments'] as $report_attachment)
                            <x-fruit::attachment :href="$report_attachment->url()" target="_blank" class="delivery-report__attachment">
                                <x-slot:leading><x-icon.paperclip class="f-icon" aria-hidden="true" /></x-slot:leading>
                                {{ $report_attachment->file_name }}
                                <x-slot:detail>{{ $report_attachment->getSizeName() }}</x-slot:detail>
                            </x-fruit::attachment>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-fruit::disclosure>
    @endif

    <x-fruit::disclosure :title="__('Delivery Report')" class="delivery-report__text">
        <div class="thread-content f-prose" dir="auto">
            {!! safe_raw_html(\Eventy::filter('thread.body_output', $thread->getBodyWithFormatedLinks(), $thread, $conversation, $mailbox)) !!}
        </div>
        @if (!empty($delivery_report['details']))
            <pre class="delivery-report__details" tabindex="0" role="region" aria-label="{{ __('Delivery Report') }}">{{ $delivery_report['details'] }}</pre>
        @endif
    </x-fruit::disclosure>
</div>
