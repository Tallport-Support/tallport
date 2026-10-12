{{-- A person as a drill-down level (layouts/app): App\Livewire\UserProfile, with the open page's row
     ($user_section) current. --}}
<x-fruit::drilldown-column :title="$user->getFullName()" id="user">
    <x-slot:back><x-fruit::back-link :href="route('users')" wire:navigate>{{ __('Users') }}</x-fruit::back-link></x-slot:back>
    <livewire:user-profile :user="$user" :section="$user_section ?? null" :wire:key="'user-profile-'.$user->id" />
</x-fruit::drilldown-column>
