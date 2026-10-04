@if (!$customers_log && !$users_log)
    <x-fruit::alert tone="warning">{{ __("There were no send attempts yet") }}</x-fruit::alert>
@else
    @if (!empty($customers_log))
    	<h3 class="f-headline send-log-heading">{{ __("Emails to Customers") }}</h3>

    	<table class="f-table send-log-table">
    		<tr>
    			<th>{{ __("Customer") }}</th>
    			<th>{{ __("Remarks") }}</th>
    			<th>{{ __("Date") }}</th>
    			<th>{{ __("Status") }}</th>
    		</tr>
			@foreach ($customers_log as $email => $logs)
				@foreach ($logs as $log)
		    		<tr>
		    			@if ($loop->index == 0)
		    				<td rowspan="{{ count($logs) }}">{{ $email }}</td>
		    			@endif
		    			<td>@if ($log->mail_type == App\SendLog::MAIL_TYPE_AUTO_REPLY) [{{ __('auto reply') }}] @else &nbsp; @endif</td>
		    			<td>{{ App\User::dateFormat($log->created_at, 'M j, Y H:i:s') }}</td>
		    			<td>
		    				<span class="@if ($log->isErrorStatus())text-danger @elseif ($log->isSuccessStatus()) text-success @endif">{{ $log->getStatusName() }}@if ($log->smtp_queue_id) (SMTP ID: {{ $log->smtp_queue_id }})@endif</span>
		    				@if ($log->status_message)
		    					<div class="f-help">{{ $log->status_message }}</div>
		    				@endif
		    			</td>
		    		</tr>
            	@endforeach
            @endforeach
    	</table>
	@endif

    @if (!empty($users_log))
    	<h3 class="f-headline send-log-heading">{{ __("Notification Emails to Users") }}</h3>

    	<table class="f-table send-log-table">
    		<tr>
    			<th>{{ __("User") }}</th>
    			<th>{{ __("Date") }}</th>
    			<th>{{ __("Status") }}</th>
    		</tr>
			@foreach ($users_log as $email => $logs)
				@foreach ($logs as $log)
		    		<tr>
		    			@if ($loop->index == 0)
		    				<td rowspan="{{ count($logs) }}">{{ $email }}</td>
		    			@endif
		    			<td>{{ App\User::dateFormat($log->created_at) }}</td>
		    			<td>
		    				<span class="@if ($log->isErrorStatus())text-danger @elseif ($log->isSuccessStatus()) text-success @endif">{{ $log->getStatusName() }}</span>
		    				@if ($log->status_message)
		    					<div class="f-help">{{ $log->status_message }}</div>
		    				@endif
		    			</td>
		    		</tr>
            	@endforeach
            @endforeach
    	</table>
	@endif
@endif