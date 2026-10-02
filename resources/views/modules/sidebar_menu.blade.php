<div class="sidebar-title">
    {{ __('Modules') }}
</div>
<ul class="sidebar-menu">
    <li><a href="#installed"><i class="glyphicon glyphicon-saved"></i> {{ __('Installed Modules') }}@if (count($installed_modules)) <small>({{ count($installed_modules) }})</small>@endif</a></li>
</ul>
