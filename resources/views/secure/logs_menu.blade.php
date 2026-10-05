{{-- Under System's tabs: the logs to look at. --}}
<nav class="f-section-nav logs-nav" aria-label="{{ __('Logs') }}">
    @foreach ($names as $name)
        <a href="{{ route('logs', ['name' => $name]) }}" @if ($current_name == $name) aria-current="page" @endif>{{ App\ActivityLog::getLogTitle($name) }}</a>
    @endforeach
</nav>
