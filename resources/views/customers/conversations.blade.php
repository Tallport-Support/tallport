@extends('layouts.app')

@section('title_full', $customer->getFullName().' - '.__('Customer Profile'))
@section('body_class', 'sidebar-no-height')

@section('body_attrs')@parent data-customer_id="{{ $customer->id }}"@endsection

@section('aside')
    <div class="profile-preview">
    	@include('customers/profile_menu')
        @include('customers/profile_snippet')
    </div>
@endsection

@section('content')
    @include('customers/profile_tabs')
    <livewire:conversation-list :conversations="$conversations" :params="['no_checkboxes' => 1, 'no_customer' => 1]" :filter="['customer_id' => $customer->id]" />
@endsection
