{{-- A customer at a glance: photo, name and tags, then their details (as in FruitUI's Support example inspector). --}}
@php
	$channels = $customer->getChannels();
	$customer_emails = $customer->emails->sortBy(fn ($email) => !empty($main_email) && $email->email == $main_email ? 0 : 1);
	$location = array_filter([$customer->city, $customer->state, $customer->getCountryName()]);
	$sender_offset = !empty($conversation) ? App\Misc\SenderTime::offset($conversation) : null;
@endphp
<div class="customer-snippet">
	<div class="customer-snippet__identity">
		@if ($customer->photo_url)
			<x-fruit::avatar :src="$customer->getPhotoUrl()" class="customer-snippet__avatar" />
		@else
			<x-fruit::avatar class="customer-snippet__avatar">{{ mb_strtoupper(mb_substr((string) $customer->first_name, 0, 1).mb_substr((string) $customer->last_name, 0, 1)) ?: mb_strtoupper(mb_substr((string) $customer->getMainEmail(), 0, 1)) }}</x-fruit::avatar>
		@endif
		@if ($customer->getFullName(true, true))
			<a href="{{ route('customers.update', ['id' => $customer->id]) }}" class="customer-snippet__name customer-name">{{ $customer->getFullName(true, true) }}</a>
		@endif
		@if ($customer->job_title || $customer->company)
			<p class="f-muted">{{ implode(' · ', array_filter([$customer->job_title, $customer->company])) }}</p>
		@endif
		<div class="customer-tags">@foreach ($channels as $channel)<x-fruit::badge>{{ $channel->getChannelName() }}</x-fruit::badge>@endforeach{{ '' }}@action('customer.tags', $customer, $conversation ?? null)</div>
	</div>

	<x-fruit::description-list class="customer-snippet__details">
		@if (count($customer_emails))
			<div>
				<dt>{{ __('Email') }}</dt>
				@foreach ($customer_emails as $email)
					<dd class="customer-email"><a href="#" class="contact-main" title="{{ __('Copy') }}">{{ $email->email }}</a></dd>
				@endforeach
			</div>
		@endif
		@if (count($customer->getPhones()))
			<div>
				<dt>{{ __('Phone') }}</dt>
				@foreach ($customer->getPhones() as $phone)
					<dd class="customer-phone"><a href="tel:{{ $phone['value'] }}">{{ $phone['value'] }}</a>@if (!\App\Customer::isDefaultPhoneType($phone['type'])) <span class="f-muted">({{ \App\Customer::getPhoneTypeName($phone['type']) }})</span>@endif</dd>
				@endforeach
			</div>
		@endif
		@if ($customer->getWebsites())
			<div>
				<dt>{{ __('Website') }}</dt>
				@foreach ($customer->getWebsites() as $website)
					<dd><a href="{{ $website }}" target="_blank">{{ parse_url($website, PHP_URL_HOST) ?: $website }}</a></dd>
				@endforeach
			</div>
		@endif
		@if ($customer->getSocialProfiles())
			<div>
				<dt>{{ __('Social Profiles') }}</dt>
				@foreach ($customer->getSocialProfiles() as $sp)
					<dd><a href="{{ App\Customer::formatSocialProfile($sp)['value_url'] }}" target="_blank">{{ App\Customer::formatSocialProfile($sp)['type_name'] }}</a></dd>
				@endforeach
			</div>
		@endif
		@if ($location)
			<div><dt>{{ __('Location') }}</dt><dd>{{ implode(', ', $location) }}</dd></div>
		@endif
		@if ($customer->address || $customer->zip)
			<div><dt>{{ __('Address') }}</dt><dd>{{ $customer->address }}@if ($customer->address && $customer->zip), @endif{{ $customer->zip }}</dd></div>
		@endif
		@if ($sender_offset)
			<div title="{{ __('From the time zone of their latest email') }}"><dt>{{ __('Local time') }}</dt><dd>{{ App\Misc\SenderTime::format(now(), $sender_offset) }} (GMT{{ $sender_offset }})</dd></div>
		@endif
		@if ($customer->notes)
			<div><dt>{{ __('Notes') }}</dt><dd>{{ $customer->notes }}</dd></div>
		@endif
	</x-fruit::description-list>

	@include('nostr/partials/customer_keys_snippet', ['keys' => App\Nostr\CustomerKey::forCustomer($customer->id)])
	@action('customer.profile.extra', $customer, $conversation ?? '')
	@action('customer.profile_data', $customer, $conversation ?? '')
</div>
