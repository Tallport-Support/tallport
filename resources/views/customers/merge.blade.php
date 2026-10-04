@extends('layouts.app')

@section('title_full', $customer->getFullName(true).' - '.__('Customer Profile'))
@section('body_class', 'sidebar-no-height')

@section('body_attrs')@parent data-customer_id="{{ $customer->id }}"@endsection

@section('aside')
    <div class="profile-preview">
        @include('customers/profile_menu')
        @include('customers/profile_snippet')
    </div>
@endsection

@section('content')
    @include('customers/profile_tabs', ['extra_tab' => __('Merge')])

    @include('partials/flash_messages')

    <div class="page-content">
        <form action="" method="POST" class="settings-form">
            {{ csrf_field() }}
            <x-fruit::field :label="__('Merge With')" control-id="merge_customer2_id">
                <x-fruit::combobox name="customer2_id" id="merge_customer2_id" search="server" :placeholder="__('Search for a customer by name or email').'…'" required x-data x-on:fruit-suggest.debounce.250ms="customerSuggest($event, {exclude_id: {{ $customer->id }}})">
                    <option value="" hidden></option>
                </x-fruit::combobox>
            </x-fruit::field>
            <div class="settings-form__actions f-row">
                <x-fruit::button type="submit" variant="primary">{{ __('Merge') }}</x-fruit::button>
            </div>
        </form>
    </div>
@endsection
