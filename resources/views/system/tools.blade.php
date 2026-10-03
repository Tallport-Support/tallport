@extends('layouts.app')

@section('title', __('Tools'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('system/sidebar_menu')
@endsection

@section('content')
<div class="page-content system-tools">

    @action('system.tools.before_form')

    <form class="settings-form" method="POST" action="">
        {{ csrf_field() }}

        @action('system.tools.form_start')

        <div class="f-row">
            <x-fruit::button type="submit" name="action" value="clear_cache">{{ __('Clear Cache') }}</x-fruit::button>
            <x-fruit::button type="submit" name="action" value="migrate_db">{{ __('Migrate DB') }}</x-fruit::button>
            <x-fruit::button type="submit" name="action" value="logout_users">{{ __('Logout Users') }}</x-fruit::button>
            @action('system.tools.main_buttons')
        </div>

        @action('system.tools.after_main_buttons')

        <hr>
        <div class="f-row">
            <x-fruit::button type="submit" name="action" value="fetch_emails">{{ __('Fetch Emails') }}</x-fruit::button>
            <label class="f-row">{{ __('Days') }} <x-fruit::number name="days" :value="old('days', 3)" class="system-tools__days" /></label>
            <x-fruit::radio name="unseen" value="1" :checked="(bool) (int) old('unseen', 1)">{{ __('Unread') }}</x-fruit::radio>
            <x-fruit::radio name="unseen" value="0" :checked="!(int) old('unseen', 1)">{{ __('All') }}</x-fruit::radio>
            <x-fruit::checkbox name="debug" value="1" :checked="(bool) (int) old('debug')">{{ __('Debug') }}</x-fruit::checkbox>

            @action('system.tools.fetch_emails_append')
        </div>

        @action('system.tools.form_append')

    </form>

    @action('system.tools.after_form')

    @if ($output)
        @action('system.tools.before_output')
        <pre class="system-tools__output">{{ $output }}</pre>
        @action('system.tools.after_output')
    @endif

    @action('system.tools.after_content')

</div>
@endsection
