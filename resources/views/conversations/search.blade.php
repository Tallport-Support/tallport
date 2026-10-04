@extends('layouts.app')

@section('title', ($q ? $q.' - ' : '').strip_tags(Eventy::filter('search.title', __('Search'))))
@section('body_class', 'body-search')

@php
    $recent_queries = array_values(array_filter((array) $recent, fn ($recent_query) => $recent_query != $q));
@endphp
@if (count($recent_queries))
    @section('aside')
        <nav class="search-recent" aria-label="{{ __('Recent') }}">
            <h2 class="f-headline">{{ __('Recent') }}</h2>
            <x-fruit::item-list>
                @foreach ($recent_queries as $recent_query)
                    <li><x-fruit::item-link :href="route('conversations.search', ['q' => $recent_query, 'mode' => $mode])"><x-slot:title>{{ $recent_query }}</x-slot:title></x-fruit::item-link></li>
                @endforeach
            </x-fruit::item-list>
        </nav>
    @endsection
@endif

@section('content')

	<div class="section-heading section-search">
		<h1 class="search-title">{!! safe_raw_html(Eventy::filter('search.title', __('Search')), ['iframe']) !!}</h1>
		{{-- Filters show and hide in the browser (tallportSearchFilters in public/js/conversations.js); hidden ones are not sent. --}}
		<form action="{{ route('conversations.search') }}" x-data="tallportSearchFilters">

			@if (request()->x_embed)
				<input type="hidden" name="x_embed" value="{{ request()->x_embed }}" />
			@endif

			@if (!empty($filters['custom']))
				<input type="hidden" name="f[custom]" value="{{ $filters['custom'] }}" />
			@endif

			@if ($mode != App\Conversation::SEARCH_MODE_CONV)
				<input type="hidden" name="mode" value="{{ $mode }}" />
			@endif

	        <div class="search-field f-row">
	            <x-fruit::search name="q" :value="$q" :label="__('Search')" :wrapper="['class' => 'search-field__input']" />
	            <x-fruit::menu :title="__('Filters')" class="search-filters-menu">
	                <x-slot:trigger class="f-button--ghost"><x-heroicon-o-funnel class="f-icon" aria-hidden="true" /> {{ __('Filters') }}</x-slot:trigger>
	                @foreach ($filters_list as $filter)
	                    <x-fruit::menu-checkbox x-bind:aria-checked="active[{{ \Illuminate\Support\Js::from($filter) }}] ? 'true' : 'false'" x-on:click="toggle({{ \Illuminate\Support\Js::from($filter) }})">{{ __(ucwords($filter)) }}</x-fruit::menu-checkbox>
	                @endforeach
	            </x-fruit::menu>
	            <x-fruit::button type="submit" variant="primary">{{ __('Search') }}</x-fruit::button>
	        </div>

			<div id="search-filters">
                <div class="search-filter" data-filter="assigned" @unless (isset($filters['assigned'])) hidden @endunless>
                    <x-fruit::field :label="__('Assigned')" control-id="search-filter-assigned">
                        <x-fruit::select id="search-filter-assigned" name="f[assigned]">
                            <option value=""></option>
                            <option value="{{ App\Conversation::USER_UNASSIGNED }}" @if (($filters['assigned'] ?? '') == App\Conversation::USER_UNASSIGNED) selected @endif>{{ __('Unassigned') }}</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @if (($filters['assigned'] ?? '') == $user->id) selected @endif>{{ $user->getFullName() }}</option>
                            @endforeach
                        </x-fruit::select>
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('assigned')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="customer" @unless (isset($filters['customer'])) hidden @endunless>
                    <x-fruit::field :label="__('Customer')" control-id="search-filter-customer">
                        <x-fruit::combobox id="search-filter-customer" name="f[customer]" search="server" x-on:fruit-suggest.debounce.250ms="customers($event)">
                            <option value="" hidden></option>
                            @if (!empty($filters_data['customer']))
                                <option value="{{ $filters_data['customer']->id }}" selected>{{ $filters_data['customer']->getEmailAndName() }}</option>
                            @endif
                        </x-fruit::combobox>
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('customer')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="mailbox" @unless (isset($filters['mailbox'])) hidden @endunless>
                    <x-fruit::field :label="__('Mailbox')" control-id="search-filter-mailbox">
                        <x-fruit::select id="search-filter-mailbox" name="f[mailbox]">
                            <option value=""></option>
                            @foreach ($mailboxes as $mailbox_item)
                                <option value="{{ $mailbox_item->id }}" @if (($filters['mailbox'] ?? '') == $mailbox_item->id) selected @endif>{{ $mailbox_item->name }}</option>
                            @endforeach
                        </x-fruit::select>
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('mailbox')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="status" @unless (isset($filters['status'])) hidden @endunless>
                    <x-fruit::fieldset>
                        <legend>{{ __('Status') }}</legend>
                        @foreach (App\Conversation::$statuses as $option_id => $dummy)
                            <x-fruit::checkbox name="f[status][]" :value="$option_id" :checked="in_array($option_id, (array) ($filters['status'] ?? []))">{{ App\Conversation::statusCodeToName($option_id) }}</x-fruit::checkbox>
                        @endforeach
                    </x-fruit::fieldset>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('status')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="state" @unless (isset($filters['state'])) hidden @endunless>
                    <x-fruit::fieldset>
                        <legend>{{ __('State') }}</legend>
                        @foreach (App\Conversation::$states as $option_id => $dummy)
                            <x-fruit::checkbox name="f[state][]" :value="$option_id" :checked="in_array($option_id, (array) ($filters['state'] ?? []))">{{ App\Conversation::stateCodeToName($option_id) }}</x-fruit::checkbox>
                        @endforeach
                    </x-fruit::fieldset>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('state')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="subject" @unless (isset($filters['subject'])) hidden @endunless>
                    <x-fruit::field :label="__('Subject')" control-id="search-filter-subject">
                        <x-fruit::input id="search-filter-subject" name="f[subject]" :value="$filters['subject'] ?? ''" />
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('subject')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="attachments" @unless (isset($filters['attachments'])) hidden @endunless>
                    <x-fruit::field :label="__('Attachments')" control-id="search-filter-attachments">
                        <x-fruit::select id="search-filter-attachments" name="f[attachments]">
                            <option value=""></option>
                            <option value="yes" @if (($filters['attachments'] ?? '') == 'yes') selected @endif>{{ __('Yes') }}</option>
                            <option value="no" @if (($filters['attachments'] ?? '') == 'no') selected @endif>{{ __('No') }}</option>
                        </x-fruit::select>
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('attachments')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="attachment name" @unless (isset($filters['attachment name'])) hidden @endunless>
                    <x-fruit::field :label="__('Attachment Name')" control-id="search-filter-attachment-name">
                        <x-fruit::input id="search-filter-attachment-name" name="f[attachment name]" :value="$filters['attachment name'] ?? ''" />
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('attachment name')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="type" @unless (isset($filters['type'])) hidden @endunless>
                    <x-fruit::field :label="__('Type')" control-id="search-filter-type">
                        <x-fruit::select id="search-filter-type" name="f[type]">
                            <option value=""></option>
                            @foreach (App\Conversation::$types as $type_id => $dummy)
                                <option value="{{ $type_id }}" @if (($filters['type'] ?? '') == $type_id) selected @endif>{{ App\Conversation::typeToName($type_id) }}</option>
                            @endforeach
                        </x-fruit::select>
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('type')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="body" @unless (isset($filters['body'])) hidden @endunless>
                    <x-fruit::field :label="__('Body')" control-id="search-filter-body">
                        <x-fruit::input id="search-filter-body" name="f[body]" :value="$filters['body'] ?? ''" />
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('body')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="number" @unless (isset($filters['number'])) hidden @endunless>
                    <x-fruit::field :label="__('Number')" control-id="search-filter-number">
                        <x-fruit::input id="search-filter-number" name="f[number]" :value="$filters['number'] ?? ''" />
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('number')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="following" @unless (isset($filters['following'])) hidden @endunless>
                    <x-fruit::field :label="__('Following')" control-id="search-filter-following">
                        <x-fruit::select id="search-filter-following" name="f[following]">
                            <option value=""></option>
                            <option value="yes" @if (($filters['following'] ?? '') == 'yes') selected @endif>{{ __('Yes') }}</option>
                        </x-fruit::select>
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('following')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="id" @unless (isset($filters['id'])) hidden @endunless>
                    <x-fruit::field :label="__('ID')" control-id="search-filter-id">
                        <x-fruit::input id="search-filter-id" name="f[id]" :value="$filters['id'] ?? ''" />
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('id')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="after" @unless (isset($filters['after'])) hidden @endunless>
                    <x-fruit::field :label="__('After')" control-id="search-filter-after">
                        <x-fruit::date id="search-filter-after" name="f[after]" :value="!empty($filters['after']) ? date('Y-m-d', strtotime($filters['after'])) : ''" />
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('after')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
                <div class="search-filter" data-filter="before" @unless (isset($filters['before'])) hidden @endunless>
                    <x-fruit::field :label="__('Before')" control-id="search-filter-before">
                        <x-fruit::date id="search-filter-before" name="f[before]" :value="!empty($filters['before']) ? date('Y-m-d', strtotime($filters['before'])) : ''" />
                    </x-fruit::field>
                    <x-fruit::button variant="ghost" size="small" class="f-button--icon search-filter__remove" x-on:click="toggle('before')" :aria-label="__('Remove filter')" :title="__('Remove filter')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
                </div>
		        @action('search.display_filters', $filters, $filters_data, $mode)
		    </div>

	        @if ($mode == App\Conversation::SEARCH_MODE_CONV && App\Search\ConversationSearch::available())
	            <x-fruit::disclosure :title="__('Search tips')" class="search-tips">
	                <p class="f-help">
	                    <code>refund jacket</code> {{ __('all words, anywhere in the conversation') }}<br>
	                    <code>"exact phrase"</code> {{ __('words in this order') }} · <code>-word</code> {{ __('without this word') }}<br>
	                    <code>from:</code> {{ __('sender') }} · <code>to:</code> {{ __('recipient') }} · <code>subject:</code> · <code>mailbox:</code><br>
	                    <code>is:open</code> <code>is:pending</code> <code>is:closed</code> <code>is:spam</code> <code>is:mine</code> <code>is:unassigned</code> <code>is:following</code><br>
	                    <code>has:attachment</code> · <code>attachment:invoice</code> · <code>after:2026-01-31</code> · <code>before:2026-03-01</code> · <code>#123</code> {{ __('conversation number') }}
	                </p>
	            </x-fruit::disclosure>
	        @endif
	    </form>
	</div>

	<div class="search-results">
		<nav class="f-section-nav search-tabs" aria-label="{{ __('Search') }}">
			@if (Eventy::filter('search.is_tab_visible', true, App\Conversation::SEARCH_MODE_CONV))
		    	<a href="{{ \Helper::fixProtocol(request()->fullUrlWithQuery(['mode' => App\Conversation::SEARCH_MODE_CONV])) }}" @if ($mode == App\Conversation::SEARCH_MODE_CONV) aria-current="page" class="search-tab-conv" @endif>{{ __('Conversations') }} <span class="f-badge">{{ $conversations->total() }}</span>@action('search.conversations_tab_append', $filters, $conversations->total())</a>
		    @endif
		    <a href="{{ \Helper::fixProtocol(request()->fullUrlWithQuery(['mode' => App\Conversation::SEARCH_MODE_CUSTOMERS])) }}" @if ($mode == App\Conversation::SEARCH_MODE_CUSTOMERS) aria-current="page" @endif>{{ __('Customers') }} <span class="f-badge">{{ $customers->total() }}</span></a>
		</nav>
		@if ($mode == App\Conversation::SEARCH_MODE_CONV)
	    	<livewire:conversation-list :conversations="$conversations" :mailbox="$search_mailbox" :params="['target_blank' => true, 'show_mailbox' => (count(Auth::user()->mailboxesCanView(true)) > 1)]" :filter="['q' => (string) request()->input('q', ''), 'f' => (array) request()->input('f', [])]" />
	    @else
	    	@include('customers/partials/customers_table')
	    @endif
	</div>
@endsection

