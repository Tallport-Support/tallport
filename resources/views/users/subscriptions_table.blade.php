<?php
    if (!isset($subscriptions_formname, $subscriptions)) $subscriptions_formname = "subscriptions";
?>
<table class="f-table subscriptions-table" x-data="tallportSubscriptions">
    <tr class="table-header">
        <th>
            @if ($person)
                {{ __('Notify :person when…', ['person' => $person]) }}
            @else
                {{ __('Notify me when…') }}
            @endif
        </th>
        <td class="subs-cb"><span id="subs-medium-email">{{ __('Email') }}</span><br/><input type="checkbox" class="f-check sel-all" value="email" aria-labelledby="subs-medium-email"></td>
        <td class="subs-cb"><span id="subs-medium-browser">{{ __('Browser') }}</span><br/><input type="checkbox" class="f-check sel-all" value="browser" aria-labelledby="subs-medium-browser"></td>
        @action('notifications_table.th')
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_NEW_CONVERSATION }}">{{ __('There is a new conversation') }}</th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_NEW_CONVERSATION }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_NEW_CONVERSATION]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_NEW_CONVERSATION }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_NEW_CONVERSATION }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_NEW_CONVERSATION]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_NEW_CONVERSATION }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_NEW_CONVERSATION, $subscriptions_formname, $subscriptions)
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME }}">
            @if ($person)
                {{ __('A conversation is assigned to :person', ['person' => $person]) }}
            @else
                {{ __('A conversation is assigned to me') }}
            @endif
        </th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME, $subscriptions_formname, $subscriptions)
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED }}">{{ __('A conversation is assigned to someone else') }}</th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_CONVERSATION_ASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_CONVERSATION_ASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_CONVERSATION_ASSIGNED }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_CONVERSATION_ASSIGNED, $subscriptions_formname, $subscriptions)
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED }}">
            @if ($person)
                {{ __('A conversation :person is following is updated', ['person' => $person]) }}
            @else
                {{ __("A conversation I'm following is updated") }}
            @endif
        </th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED, $subscriptions_formname, $subscriptions)
    </tr>
    @action('notifications_table.general.append', ['person' => $person, 'subscriptions' => $subscriptions, 'subscriptions_formname' => $subscriptions_formname])
    <tr class="table-header">
        <th>
            @if ($person)
                {{ __("Notify :person when a customer replies…", ['person' => $person]) }}
            @else
                {{ __('Notify me when a customer replies…') }}
            @endif
        </th>
        <td class="subs-cb">&nbsp;</td>
        <td class="subs-cb">&nbsp;</td>
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED }}">{{ __('To an unassigned conversation') }}</th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED, $subscriptions_formname, $subscriptions)
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY }}">
            @if ($person)
                {{ __("To one of :person's conversations", ['person' => $person]) }}
            @else
                {{ __('To one of my conversations') }}
            @endif
        </th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY, $subscriptions_formname, $subscriptions)
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED }}">{{ __('To a conversation owned by someone else') }}</th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED, $subscriptions_formname, $subscriptions)
    </tr>
    <tr class="table-header">
        <th>
            @if ($person)
                {{ __("Notify :person when another :app_name user replies or adds a note…", ['person' => $person, 'app_name' => config('app.name')]) }}
            @else
                {{ __('Notify me when another :app_name user replies or adds a note…', ['app_name' => config('app.name')]) }}
            @endif
        </th>
        <td class="subs-cb">&nbsp;</td>
        <td class="subs-cb">&nbsp;</td>
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED }}">{{ __('To an unassigned conversation') }}</th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED, $subscriptions_formname, $subscriptions)
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_MY }}">
            @if ($person)
                {{ __("To one of :person's conversations", ['person' => $person]) }}
            @else
                {{ __('To one of my conversations') }}
            @endif
        </th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_MY }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_USER_REPLIED_TO_MY]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_USER_REPLIED_TO_MY }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_MY }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_USER_REPLIED_TO_MY]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_USER_REPLIED_TO_MY }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_USER_REPLIED_TO_MY, $subscriptions_formname, $subscriptions)
    </tr>
    <tr>
        <th scope="row" id="subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED }}">{{ __('To a conversation owned by someone else') }}</th>
        <td class="subs-cb subscriptions-email" data-label="{{ __('Email') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-email subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_EMAIL, 'event' => App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_EMAIL }}][]" value="{{ App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED }}"></td>
        <td class="subs-cb subscriptions-browser" data-label="{{ __('Browser') }}"><input type="checkbox" class="f-check" aria-labelledby="subs-medium-browser subs-event-{{ App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED }}" @include('users/is_subscribed', ['medium' => App\Subscription::MEDIUM_BROWSER, 'event' => App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED]) name="{{ $subscriptions_formname }}[{{ App\Subscription::MEDIUM_BROWSER }}][]" value="{{ App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED }}"></td>
        @action('notifications_table.td', App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED, $subscriptions_formname, $subscriptions)
    </tr>
</table>
