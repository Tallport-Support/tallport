@extends('layouts.app')

@section('title', ($q ? $q.' - ' : '').strip_tags(Eventy::filter('search.title', __('Search'))))
@section('body_class', 'body-search')

@section('aside')
    @include('partials/sidebar_menu_toggle')
    <div class="sidebar-title">
		{!! safe_raw_html(Eventy::filter('search.title', __('Search')), ['iframe']) !!}
	</div>
    <ul class="sidebar-menu sidebar-menu-noicons">
    	@if (!$recent || (count($recent) == 1 && $recent[0] == $q))
    	@else
	        <li class="no-link"><span class="text-help">{{ __('Recent') }}</span></li>
			@foreach ($recent as $recent_query)
				@if ($recent_query != $q)
	            	<li class="menu-link menu-padded"><a href="{{ route('conversations.search', ['q' => $recent_query, 'mode' => $mode])}}">{{ $recent_query }}</a></li>
	            @endif
	        @endforeach
	    @endif
        <li class="no-link"><span class="text-help">{{ __('Filters') }}</span></li>
		@foreach ($filters_list as $filter)
            <li class="menu-link menu-padded">
            	<a href="#" data-filter="{{ $filter }}" @if (isset($filters[$filter]))class="active"@endif>{{ mb_strtolower(__(ucwords($filter))) }}:</a>
            </li>
        @endforeach
    </ul>
@endsection

@section('content')

	<div class="section-heading section-search">
		<form action="{{ route('conversations.search') }}">

			@if (request()->x_embed)
				<input type="hidden" name="x_embed" value="{{ request()->x_embed }}" />
			@endif

			@if (!empty($filters['custom']))
				<input type="hidden" name="f[custom]" value="{{ $filters['custom'] }}" />
			@endif

			@if ($mode != App\Conversation::SEARCH_MODE_CONV)
				<input type="hidden" name="mode" value="{{ $mode }}" />
			@endif

			<div id="search-filters">
				<div class="form-group @if (isset($filters['assigned'])) active @endif" data-filter="assigned">
		            <label class="f-label">{{ __('Assigned') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <select name="f[assigned]" class="f-input" @if (empty($filters['assigned'])) disabled @endif>
		            	<option value=""></option>
						<option value="{{ App\Conversation::USER_UNASSIGNED }}" @if (!empty($filters['assigned']) && $filters['assigned'] == App\Conversation::USER_UNASSIGNED)selected="selected"@endif>{{ __('Unassigned') }}</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @if (!empty($filters['assigned']) && $filters['assigned'] == $user->id)selected="selected"@endif>{{ $user->getFullName() }}</option>
                        @endforeach
                    </select>
		        </div>
				<div class="form-group @if (isset($filters['customer'])) active @endif" data-filter="customer">
		            <label class="f-label">{{ __('Customer') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <div class="controls">
		            	<select class="f-input" name="f[customer]" id="search-filter-customer" @if (empty($filters['customer'])) disabled @endif/>
		            	 	@if (!empty($filters['customer']) && !empty($filters_data['customer']))
		            			<option value="{{ $filters_data['customer']->id }}" selected="selected">{{ $filters_data['customer']->getEmailAndName() }}</option>
		            		@endif
		            	</select>
		            </div>
		        </div>
				<div class="form-group @if (isset($filters['mailbox'])) active @endif" data-filter="mailbox">
		            <label class="f-label">{{ __('Mailbox') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <select name="f[mailbox]" class="f-input" @if (empty($filters['mailbox'])) disabled @endif>
		            	<option value=""></option>
                        @foreach ($mailboxes as $mailbox_item)
                            <option value="{{ $mailbox_item->id }}" @if (!empty($filters['mailbox']) && $filters['mailbox'] == $mailbox_item->id)selected="selected"@endif>{{ $mailbox_item->name }}</option>
                        @endforeach
                    </select>
		        </div>
				<div class="form-group @if (isset($filters['status'])) active @endif" data-filter="status">
		            <label class="f-label">{{ __('Status') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <select name="f[status][]" class="f-input filter-multiple" multiple @if (empty($filters['status'])) disabled @endif>
		            	{{--<option value=""></option>--}}
                        @foreach (App\Conversation::$statuses as $status_id => $dummy)
                            <option value="{{ $status_id }}" @if (!empty($filters['status']) && in_array($status_id, $filters['status']))selected="selected"@endif>{{ App\Conversation::statusCodeToName($status_id) }}</option>
                        @endforeach
                    </select>
		        </div>
				<div class="form-group @if (isset($filters['state'])) active @endif" data-filter="state">
		            <label class="f-label">{{ __('State') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <select name="f[state][]" class="f-input filter-multiple" multiple @if (empty($filters['state'])) disabled @endif>
		            	{{--<option value=""></option>--}}
                        @foreach (App\Conversation::$states as $state_id => $dummy)
                            <option value="{{ $state_id }}" @if (!empty($filters['state']) && in_array($state_id, $filters['state']))selected="selected"@endif>{{ App\Conversation::stateCodeToName($state_id) }}</option>
                        @endforeach
                    </select>
		        </div>
				<div class="form-group @if (isset($filters['subject'])) active @endif" data-filter="subject">
		            <label class="f-label">{{ __('Subject') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <input type="text" name="f[subject]" value="{{ $filters['subject'] ?? ''}}" class="f-input" @if (empty($filters['subject'])) disabled @endif>
		        </div>
				<div class="form-group @if (isset($filters['attachments'])) active @endif" data-filter="attachments">
		            <label class="f-label">{{ __('Attachments') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <select name="f[attachments]" class="f-input" @if (empty($filters['attachments'])) disabled @endif>
		            	<option value=""></option>
                        <option value="yes" @if (!empty($filters['attachments']) && $filters['attachments'] == 'yes')selected="selected"@endif>{{ __('Yes') }}</option>
                        <option value="no" @if (!empty($filters['attachments']) && $filters['attachments'] == 'no')selected="selected"@endif>{{ __('No') }}</option>
                    </select>
		        </div>
				<div class="form-group @if (isset($filters['attachment name'])) active @endif" data-filter="attachment name">
		            <label class="f-label">{{ __('Attachment Name') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <input type="text" name="f[attachment name]" value="{{ $filters['attachment name'] ?? '' }}" class="f-input" @if (empty($filters['attachment name'])) disabled @endif>
		        </div>
				<div class="form-group @if (isset($filters['type'])) active @endif" data-filter="type">
		            <label class="f-label">{{ __('Type') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <select name="f[type]" class="f-input" @if (empty($filters['type'])) disabled @endif>
		            	<option value=""></option>
                        @foreach (App\Conversation::$types as $type_id => $dummy)
                            <option value="{{ $type_id }}" @if (!empty($filters['type']) && $filters['type'] == $type_id)selected="selected"@endif>{{ App\Conversation::typeToName($type_id) }}</option>
                        @endforeach
                    </select>
		        </div>
				<div class="form-group @if (isset($filters['body'])) active @endif" data-filter="body">
		            <label class="f-label">{{ __('Body') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <input type="text" name="f[body]" value="{{ $filters['body'] ?? ''}}" class="f-input" @if (empty($filters['body'])) disabled @endif>
		        </div>
				<div class="form-group @if (isset($filters['number'])) active @endif" data-filter="number">
		            <label class="f-label">{{ __('Number') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <input type="text" name="f[number]" value="{{ $filters['number'] ?? ''}}" class="f-input" @if (empty($filters['number'])) disabled @endif>
		        </div>
				<div class="form-group @if (isset($filters['following'])) active @endif" data-filter="following">
		            <label class="f-label">{{ __('Following') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <select name="f[following]" class="f-input" @if (empty($filters['following'])) disabled @endif>
		            	<option value=""></option>
                        <option value="yes" @if (!empty($filters['following']) && $filters['following'] == 'yes')selected="selected"@endif>{{ __('Yes') }}</option>
                    </select>
		        </div>
				<div class="form-group @if (isset($filters['id'])) active @endif" data-filter="id">
		            <label class="f-label">{{ __('ID') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <input type="text" name="f[id]" value="{{ $filters['id'] ?? ''}}" class="f-input" @if (empty($filters['id'])) disabled @endif>
		        </div>
				<div class="form-group @if (isset($filters['after'])) active @endif" data-filter="after">
		            <label class="f-label">{{ __('After') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <input type="text" name="f[after]" value="{{ $filters['after'] ?? ''}}" class="f-input input-date" @if (empty($filters['after'])) disabled @endif>
		        </div>
				<div class="form-group @if (isset($filters['before'])) active @endif" data-filter="before">
		            <label class="f-label">{{ __('Before') }} <button type="button" class="remove f-button f-button--ghost f-button--icon f-button--small" aria-label="{{ __('Remove filter') }}" title="{{ __('Remove filter') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button></label>
		            <input type="text" name="f[before]" value="{{ $filters['before'] ?? ''}}" class="f-input input-date" @if (empty($filters['before'])) disabled @endif>
		        </div>
		        @action('search.display_filters', $filters, $filters_data, $mode)
		    </div>

	        <div class="search-field f-row">
	            <x-fruit::search name="q" :value="$q" :label="__('Search')" :wrapper="['class' => 'search-field__input']" />
	            <x-fruit::button type="submit" variant="primary">{{ __('Search') }}</x-fruit::button>
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
	    	@include('conversations/conversations_table', ['mailbox' => $search_mailbox, 'params' => ['target_blank' => true, 'show_mailbox' => (count(Auth::user()->mailboxesCanView(true)) > 1)]])
	    @else
	    	@include('customers/partials/customers_table')
	    @endif
	</div>
@endsection

@include('partials/include_datepicker')

@section('javascript')
    @parent
    searchInit();
@endsection
