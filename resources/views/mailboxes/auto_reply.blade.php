@extends('layouts.app')

@section('title_full', __('Auto Reply').' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')

    <div class="section-heading">
        {{ __('Auto Reply') }}
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-xs-12">
                <form class="form-horizontal margin-top" method="POST" action="">
                    {{ csrf_field() }}

                    <div class="form-group{{ $errors->has('auto_reply_enabled') ? ' has-error' : '' }}">
                        <label for="auto_reply_enabled" class="col-sm-2 control-label">{{ __('Enable Auto Reply') }}</label>

                        <div class="col-sm-6">
                            <div class="controls">
                                <div class="onoffswitch-wrap">
                                    <div class="onoffswitch">
                                        <input type="checkbox" name="auto_reply_enabled" value="1" id="auto_reply_enabled" class="onoffswitch-checkbox" @if (old('auto_reply_enabled', $mailbox->auto_reply_enabled))checked="checked"@endif >
                                        <label class="onoffswitch-label" for="auto_reply_enabled"></label>
                                    </div>

                                    <i class="glyphicon glyphicon-info-sign icon-info icon-info-inline" data-toggle="popover" data-trigger="hover" data-html="true" data-placement="left" data-title="{{ __('Auto Reply') }}" data-content="{{ __('When a customer emails this mailbox, application can send an auto reply to the customer immediately.<br/><br/>Only one auto reply is sent per new conversation.') }}"></i>
                                </div>
                            </div>
                            @include('partials/field_error', ['field'=>'auto_reply_enabled'])
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-9 col-sm-offset-2">
                            <p class="text-help margin-bottom-0">
                                {{ __('Customers get the auto reply in their language if you add it here; otherwise the default one. Chinese, Japanese and Korean are recognised from the characters used, other languages by the AI Assistant (when it is set up).') }}
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-9 col-sm-offset-2">
                            <ul class="nav nav-tabs auto-reply-tabs">
                                <li class="@if (!$active_language) active @endif"><a href="#auto_reply_default" data-toggle="tab">{{ __('Default') }}</a></li>
                                @foreach ($versions as $language => $version)
                                    <li class="@if ($active_language == $language) active @endif"><a href="#auto_reply_{{ $language }}" data-toggle="tab">{{ $languages[$language] ?? $language }}@if (!$version->enabled) <small class="text-help">({{ __('off') }})</small>@endif</a></li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <div class="tab-content">
                        <div class="tab-pane @if (!$active_language) active @endif" id="auto_reply_default">
                            <div class="form-group{{ $errors->has('auto_reply_subject') ? ' has-error' : '' }}">
                                <label for="auto_reply_subject" class="col-sm-2 control-label">{{ __('Subject') }}</label>

                                <div class="col-sm-6">
                                    <input id="auto_reply_subject" type="text" class="form-control input-sized" name="auto_reply_subject" value="{{ old('auto_reply_subject', $mailbox->auto_reply_subject) }}" maxlength="128">

                                    @include('partials/field_error', ['field'=>'auto_reply_subject'])
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="auto_reply_message" class="col-sm-2 control-label">{{ __('Message') }}</label>

                                <div class="col-sm-9 auto_reply_message-editor">
                                    <textarea id="auto_reply_message" class="form-control auto-reply-editor" name="auto_reply_message" rows="8">{{ old('auto_reply_message', $mailbox->auto_reply_message) }}</textarea>
                                    <div class="{{ $errors->has('auto_reply_message') ? ' has-error' : '' }}">
                                        @include('partials/field_error', ['field'=>'auto_reply_message'])
                                    </div>
                                </div>
                            </div>
                        </div>

                        @foreach ($versions as $language => $version)
                            <div class="tab-pane @if ($active_language == $language) active @endif" id="auto_reply_{{ $language }}">
                                <div class="form-group">
                                    <label for="auto_reply_{{ $language }}_enabled" class="col-sm-2 control-label">{{ __('Enabled') }}</label>
                                    <div class="col-sm-6">
                                        <div class="controls">
                                            <div class="onoffswitch-wrap">
                                                <div class="onoffswitch">
                                                    <input type="checkbox" name="versions[{{ $language }}][enabled]" value="1" id="auto_reply_{{ $language }}_enabled" class="onoffswitch-checkbox" @if (old('versions.'.$language.'.enabled', $version->enabled))checked="checked"@endif>
                                                    <label class="onoffswitch-label" for="auto_reply_{{ $language }}_enabled"></label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group{{ $errors->has('versions.'.$language.'.subject') ? ' has-error' : '' }}">
                                    <label for="auto_reply_{{ $language }}_subject" class="col-sm-2 control-label">{{ __('Subject') }}</label>
                                    <div class="col-sm-6">
                                        <input id="auto_reply_{{ $language }}_subject" type="text" class="form-control input-sized" name="versions[{{ $language }}][subject]" value="{{ old('versions.'.$language.'.subject', $version->subject) }}" maxlength="255">
                                        @include('partials/field_error', ['field' => 'versions.'.$language.'.subject'])
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="auto_reply_{{ $language }}_message" class="col-sm-2 control-label">{{ __('Message') }}</label>
                                    <div class="col-sm-9 auto_reply_message-editor">
                                        <textarea id="auto_reply_{{ $language }}_message" class="form-control auto-reply-editor" name="versions[{{ $language }}][message]" rows="8">{{ old('versions.'.$language.'.message', $version->message) }}</textarea>
                                        <div class="{{ $errors->has('versions.'.$language.'.message') ? ' has-error' : '' }}">
                                            @include('partials/field_error', ['field' => 'versions.'.$language.'.message'])
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="col-sm-6 col-sm-offset-2">
                                        <button type="submit" class="btn btn-link text-danger" name="remove_language" value="{{ $language }}" data-confirm="{{ __('Remove the :language auto reply?', ['language' => $languages[$language] ?? $language]) }}">{{ __('Remove :language', ['language' => $languages[$language] ?? $language]) }}</button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="form-group">
                        <div class="col-sm-9 col-sm-offset-2">
                            <p class="block-help">
                                {{ __("Auto replies don't include your mailbox signature, so be sure to add your contact information if necessary.") }}
                            </p>
                            @if (count($languages) > count($versions))
                                <div class="form-inline">
                                    <select name="add_language_code" class="form-control input-sm" aria-label="{{ __('Language') }}">
                                        @foreach ($languages as $code => $name)
                                            @if (!$versions->has($code))
                                                <option value="{{ $code }}">{{ $name }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-default btn-sm" name="add_language" value="1">{{ __('Add Language') }}</button>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-6 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary">
                                {{ __('Save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection

@include('partials/editor')

@section('javascript')
    @parent
    $('.auto-reply-editor').each(function() {
        summernoteInit('#'+$(this).attr('id'), {insertVar: true, excludeVars: ['%user.']});
    });
    confirmButtonsInit();
@endsection