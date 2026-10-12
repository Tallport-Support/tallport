{{-- Settings › Users as a drill-down level (layouts/app): a row per person, the open one ($user)
     current: the page, or on the way to a page of theirs ($user_section). --}}
<x-fruit::drilldown-column :title="__('Users')" id="users">
    <x-slot:back><x-fruit::back-link :href="route('settings')" wire:navigate>{{ __('Settings') }}</x-fruit::back-link></x-slot:back>
    <x-slot:actions><a class="f-button f-button--small" href="{{ route('users.create') }}" wire:navigate>{{ __('New User') }}</a></x-slot:actions>
    <x-fruit::page width="narrow" x-data="{ q: '', people: {{ \Illuminate\Support\Js::from(collect($users)->map(fn ($person) => mb_strtolower($person->first_name.' '.$person->last_name.' '.$person->email))->values()) }}, matches(text) { return !this.q || text.includes(this.q.trim().toLowerCase()) } }">
        @if (count($users) > 1)
            <x-fruit::search id="search-users" x-model="q" :label="__('Search Users')" :placeholder="__('Search Users')" />
        @endif
        <x-fruit::form-section>
            @foreach ($users as $users_item)
                <a class="f-form-row f-form-row--link users-row @if ($users_item->invite_state != App\User::INVITE_STATE_ACTIVATED || $users_item->isDisabled()) entity-list__inactive @endif" href="{{ route('users.profile', ['id' => $users_item->id]) }}" wire:navigate @if ($users_item->id == ($user->id ?? null)) aria-current="{{ empty($user_section) ? 'page' : 'true' }}" @endif x-bind:hidden="!matches($el.dataset.search)" data-search="{{ mb_strtolower($users_item->first_name.' '.$users_item->last_name.' '.$users_item->email) }}">
                    <span class="users-row__person">
                        @if ($users_item->photo_url)
                            <x-fruit::avatar :src="$users_item->getPhotoUrl()" />
                        @else
                            <x-fruit::avatar>{{ mb_strtoupper(mb_substr((string) $users_item->first_name, 0, 1).mb_substr((string) $users_item->last_name, 0, 1)) }}</x-fruit::avatar>
                        @endif
                        <span class="users-row__name"><span>{{ $users_item->getFullName() }}@if ($users_item->id == Auth::id()) <span class="f-muted">{{ __('(You)') }}</span>@endif</span><small class="f-help">@filter('users.email', $users_item->email)</small></span>
                    </span>
                </a>
            @endforeach
            <div class="f-form-row" hidden x-bind:hidden="people.some(text => matches(text))"><p class="f-help">{{ __('No matching users') }}</p></div>
        </x-fruit::form-section>
    </x-fruit::page>
</x-fruit::drilldown-column>
