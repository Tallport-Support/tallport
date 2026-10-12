@extends('layouts.app')

{{-- A page as one level beside the settings sidebar (layouts/app): titled by its 'title', its 'content'
     in a page of its 'page_width' (narrow by default), its 'level_actions' in the title row. --}}
@section('drilldown')
    <x-fruit::drilldown-column :title="trim($__env->yieldContent('level_title')) ?: trim($__env->yieldContent('title'))" :id="'level-'.Str::slug((string) Route::currentRouteName())">
        @hasSection('level_actions')
            <x-slot:actions>@yield('level_actions')</x-slot:actions>
        @endif
        <x-fruit::page :width="trim($__env->yieldContent('page_width')) ?: 'narrow'">
            @yield('content')
            @hasSection('page_footer')
                <div class="f-row settings-form__actions">@yield('page_footer')</div>
            @endif
        </x-fruit::page>
    </x-fruit::drilldown-column>
@endsection
