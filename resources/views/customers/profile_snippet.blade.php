{{-- A customer at a glance: photo, name and tags, then their details (as in FruitUI's Support example inspector). --}}
@php
	$channels = $customer->getChannels();
	$customer_emails = $customer->emails->sortBy(fn ($email) => !empty($main_email) && $email->email == $main_email ? 0 : 1);
	$location = array_filter([$customer->city, $customer->state, $customer->getCountryName()]);
	$sender_offset = !empty($conversation) ? App\Misc\SenderTime::offset($conversation) : null;
@endphp
<div class="customer-snippet">
	<div class="customer-snippet__identity">
		@include('customers/partials/avatar', ['class' => 'customer-snippet__avatar'])
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
	@php
		// Their language and those they read besides (replies in them aren't translated).
		$reads = implode(', ', array_map(fn ($code) => App\Ai\Settings::displayName($code), array_diff((array) $customer->languages, [$customer->language])));
		$reads = $reads !== '' ? ($customer->language ? __('also reads :languages', ['languages' => $reads]) : __('Reads :languages', ['languages' => $reads])) : '';
		$languages = implode(' · ', array_filter([$customer->language ? App\Ai\Settings::displayName($customer->language) : '', $reads]));
		// In a conversation, agents who may edit the customer set it here (replies are translated
		// into it, App\Ai\ChatTranslation); "Detect Automatically": from their next message.
		// Beside a conversation only where it's translated (App\Ai\ChatTranslation::isOn()): elsewhere it isn't used.
		if (!empty($conversation) && !App\Ai\ChatTranslation::isOn($conversation)) {
			$languages = '';
		}
		$set_language = !empty($conversation) && App\Ai\ChatTranslation::isOn($conversation) && !\Helper::isPrint() && Auth::check() && Auth::user()->can('view', $customer);
	@endphp
	@if (count($customer_emails) || count($customer->getPhones()) || $customer->getWebsites() || $customer->getSocialProfiles() || $location || $customer->address || $customer->zip || $sender_offset || $languages !== '' || $set_language || $customer->notes || count($nostr_keys))
		<ul class="customer-snippet__details customer-contacts">
			@foreach ($customer_emails as $email)
				<li class="customer-email"><x-icon.mail class="f-icon" aria-hidden="true" />{!! $detail(__('Email')) !!}{{-- Copied on a click (said in a toast). --}}<button type="button" class="contact-main" title="{{ __('Copy') }}" aria-label="{{ __('Copy') }}: {{ $email->email }}" x-data x-on:click="copyToClipboard(@js($email->email)); Tallport.toast(@js(__('Copied')))">{{ $email->email }}</button></li>
				@if (!empty($email->delivery_problem['kind']))
					{{-- Emails to it failed (App\Misc\DeliveryReports), until cleared. --}}
					<li class="customer-delivery-problem" x-data>
						<x-icon.triangle-alert class="f-icon" aria-hidden="true" />
						<span><span class="customer-delivery-problem__text" title="{{ App\Misc\DeliveryReports::reasonText($email->delivery_problem['reason'] ?? '') }}">{{ App\Misc\DeliveryReports::flagText($email->delivery_problem) }}</span>
						@unless (\Helper::isPrint())
							<x-fruit::button variant="ghost" size="small" class="customer-delivery-problem__clear" :aria-label="__('Clear').': '.App\Misc\DeliveryReports::flagText($email->delivery_problem)" x-on:click="Tallport.busy($el, true); Tallport.post(laroute.route('customers.ajax'), {action: 'clear_delivery_problem', email_id: {{ $email->id }}}).then(response => Tallport.isSuccess(response) ? $root.remove() : (Tallport.busy($el, false), Tallport.result(response)))">{{ __('Clear') }}</x-fruit::button>
						@endunless
						</span>
					</li>
				@endif
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
			@if ($set_language)
				<li class="customer-language" x-data>
					<x-icon.languages class="f-icon" aria-hidden="true" />
					<span>
						<x-fruit::select class="customer-language__select" control-size="small" :aria-label="__('Language')" x-on:change="Tallport.post(laroute.route('customers.ajax'), {action: 'set_language', customer_id: {{ $customer->id }}, language: $el.value}).then(response => Tallport.isSuccess(response) ? Livewire.dispatch('customer-language-changed') : Tallport.result(response))">
							<option value="">{{ __('Detect Automatically') }}</option>
							@foreach (App\Ai\Settings::displayNames() as $code => $name)
								<option value="{{ $code }}" @selected($customer->language === $code)>{{ App\Ai\Settings::optionName($code) }}</option>
							@endforeach
						</x-fruit::select>
						@if ($reads !== '')
							· {{ $reads }}
						@endif
					</span>
				</li>
			@elseif ($languages !== '')
				<li class="customer-language"><x-icon.languages class="f-icon" aria-hidden="true" />{!! $detail(__('Language')) !!}<span>{{ $languages }}</span></li>
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
