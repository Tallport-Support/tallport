<div class="sidebar-title">
    {{ __('Knowledge Base') }}
</div>
<ul class="sidebar-menu">
    <li @if (Route::is('kb') && ($category ?? '') === '')class="active"@endif><a href="{{ route('kb') }}"><i class="glyphicon glyphicon-book"></i> {{ __('All Articles') }}</a></li>
    @foreach ($categories as $sidebar_category)
        <li @if (($category ?? '') === $sidebar_category)class="active"@endif><a href="{{ route('kb', ['category' => $sidebar_category]) }}"><i class="glyphicon glyphicon-folder-open"></i> {{ $sidebar_category }}</a></li>
    @endforeach
</ul>
