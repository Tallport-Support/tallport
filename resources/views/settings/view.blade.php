@extends('layouts.app')

@section('title', $section_name)

@section('title_full', __('Settings').' - '.$section_name)

{{-- A settings section as a level beside the sidebar (layouts/app). Tallport's sections save as they
     change; a section with a Save button of its own (a module's, or Retention's) keeps it, at the end. --}}
@section('drilldown')
    <x-fruit::drilldown-column :title="$section_name" :id="'settings-'.$section">
        <x-fruit::page :width="$section == 'api' ? 'medium' : 'narrow'">
            @include('partials/flash_messages')
            @include(\Eventy::filter('settings.view', 'settings/'.$section, $section))
            @hasSection('page_footer')
                <div class="f-row settings-form__actions">@yield('page_footer')</div>
            @endif
        </x-fruit::page>
    </x-fruit::drilldown-column>
@endsection
