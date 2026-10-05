{{-- Above the sign-in forms: the uploaded banner (Settings » Appearance), or Tallport's mark and name. --}}
<div class="banner">
    @if (($banner = \Eventy::filter('login.banner', '')) !== '')
        <x-themed-image :src="$banner" :dark="\Eventy::filter('login.banner_dark', '')" alt="" height="36" />
    @else
        <span class="banner__default"><x-logo class="banner__logo" aria-hidden="true" />Tallport</span>
    @endif
</div>
