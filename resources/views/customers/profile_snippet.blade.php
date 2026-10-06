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

	{{-- The details, each behind its icon (its name for screen readers), the Nostr devices among them. --}}
	@php
		$detail = fn ($label) => '<span class="f-sr-only">'.e($label).': </span>';
		$nostr_keys = collect(App\Nostr\CustomerKey::forCustomer($customer->id))->filter(fn ($key) => $key->label);
	@endphp
	@if (count($customer_emails) || count($customer->getPhones()) || $customer->getWebsites() || $customer->getSocialProfiles() || $location || $customer->address || $customer->zip || $sender_offset || $customer->notes || count($nostr_keys))
		<ul class="customer-snippet__details customer-contacts">
			@foreach ($customer_emails as $email)
				<li class="customer-email"><x-icon.mail class="f-icon" aria-hidden="true" />{!! $detail(__('Email')) !!}<a href="#" class="contact-main" title="{{ __('Copy') }}">{{ $email->email }}</a></li>
			@endforeach
			@foreach ($customer->getPhones() as $phone)
				<li class="customer-phone"><x-icon.phone class="f-icon" aria-hidden="true" />{!! $detail(__('Phone')) !!}<span><a href="tel:{{ $phone['value'] }}">{{ $phone['value'] }}</a>@if (!\App\Customer::isDefaultPhoneType($phone['type'])) <span class="f-muted">({{ \App\Customer::getPhoneTypeName($phone['type']) }})</span>@endif</span></li>
			@endforeach
			@foreach ($customer->getWebsites() as $website)
				<li><x-icon.globe class="f-icon" aria-hidden="true" />{!! $detail(__('Website')) !!}<a href="{{ $website }}" target="_blank">{{ parse_url($website, PHP_URL_HOST) ?: $website }}</a></li>
			@endforeach
			@foreach ($customer->getSocialProfiles() as $sp)
				<li><x-icon.link class="f-icon" aria-hidden="true" />{!! $detail(__('Social Profiles')) !!}<a href="{{ App\Customer::formatSocialProfile($sp)['value_url'] }}" target="_blank">{{ App\Customer::formatSocialProfile($sp)['type_name'] }}</a></li>
			@endforeach
			@if ($location)
				<li><x-icon.map-pin class="f-icon" aria-hidden="true" />{!! $detail(__('Location')) !!}<span>{{ implode(', ', $location) }}</span></li>
			@endif
			@if ($customer->address || $customer->zip)
				<li><x-icon.house class="f-icon" aria-hidden="true" />{!! $detail(__('Address')) !!}<span>{{ $customer->address }}@if ($customer->address && $customer->zip), @endif{{ $customer->zip }}</span></li>
			@endif
			@if ($sender_offset)
				<li title="{{ __('From the time zone of their latest email') }}"><x-icon.clock class="f-icon" aria-hidden="true" />{!! $detail(__('Local time')) !!}<span>{{ App\Misc\SenderTime::format(now(), $sender_offset) }} (GMT{{ $sender_offset }})</span></li>
			@endif
			@include('nostr/partials/customer_keys_snippet', ['keys' => $nostr_keys])
			@if ($customer->notes)
				<li><x-icon.file-text class="f-icon" aria-hidden="true" />{!! $detail(__('Notes')) !!}<span>{{ $customer->notes }}</span></li>
			@endif
		</ul>
	@endif
	@action('customer.profile.extra', $customer, $conversation ?? '')
	@action('customer.profile_data', $customer, $conversation ?? '')
</div>
