{{-- A customer's photo, or their initials (decorative: their name is beside it). --}}
@if ($customer->photo_url)
    <x-fruit::avatar :src="$customer->getPhotoUrl()" :class="$class ?? null" />
@else
    <x-fruit::avatar :class="$class ?? null">{{ mb_strtoupper(mb_substr((string) $customer->first_name, 0, 1).mb_substr((string) $customer->last_name, 0, 1)) ?: mb_strtoupper(mb_substr((string) $customer->getMainEmail(), 0, 1)) }}</x-fruit::avatar>
@endif
