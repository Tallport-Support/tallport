{{--
    A rich-text field: FruitUI's editor plus what Tallport's editors share.
    Images pasted or dropped are uploaded (public/js/editor.js); `vars` adds an
    Insert variable menu ({%customer.fullName%} and the like; modules add theirs
    through the editor.vars filter), `exclude-vars` leaves groups out; `formats`:
    the formatting allowed (FruitUI's formats; null: all); `markdown`: written and
    stored as Markdown (App\Misc\Markdown renders it), else rich text (HTML); in
    Markdown the field's name ends in _markdown (versions[en][message_markdown]),
    which is how App\Misc\Markdown::fromInput() tells them apart.
--}}
@props(['vars' => false, 'excludeVars' => [], 'uploadUrl' => null, 'formats' => null, 'markdown' => false])
@php
    $editor_name = $attributes->get('name');
    if ($markdown && $editor_name !== null) {
        $editor_name = str_ends_with($editor_name, ']') ? substr($editor_name, 0, -1).'_markdown]' : $editor_name.'_markdown';
    }
    $editor_vars = [];
    if ($vars) {
        $editor_vars = \Eventy::filter('editor.vars', [
            __('Mailbox') => [
                'mailbox.email' => __('Email'),
                'mailbox.name' => __('Name'),
                'mailbox.fromName' => __('From name'),
            ],
            __('Conversation') => [
                'conversation.number' => __('Number'),
            ],
            __('Customer') => [
                'customer.fullName' => __('Full Name'),
                'customer.firstName' => __('First Name'),
                'customer.lastName' => __('Last Name'),
                'customer.email' => __('Email Address'),
                'customer.company' => __('Company'),
            ],
            __('User') => [
                'user.fullName' => __('Full Name'),
                'user.firstName' => __('First Name'),
                'user.lastName' => __('Last Name'),
                'user.jobTitle' => __('Job Title'),
                'user.phone' => __('Phone Number'),
                'user.email' => __('Email Address'),
                'user.photoUrl' => __('Profile Photo (URL)'),
            ],
        ]);
        foreach ($editor_vars as $group => $group_vars) {
            foreach (array_keys($group_vars) as $var_name) {
                foreach ($excludeVars as $excluded) {
                    if (str_starts_with($var_name, $excluded)) {
                        unset($editor_vars[$group][$var_name]);
                    }
                }
            }
            if (!$editor_vars[$group]) {
                unset($editor_vars[$group]);
            }
        }
    }
@endphp
<x-fruit::editor {{ $attributes->except('name') }} :name="$editor_name" :mode="$markdown ? 'markdown' : 'rich'" :formats="$formats" :wrapper="['class' => 'tallport-editor', 'data-upload-url' => $uploadUrl ?? route('uploads.upload')]">
    {{ $slot }}
    {{-- Plain PHP, not @if: Livewire marks @if blocks with comments, which would land in the text
         (FruitUI's editor takes its slot as the text; Markdown shows them). --}}
    <?php if ($editor_vars || isset($extras)): ?>
        <x-slot:extras>
            {{ $extras ?? '' }}
            @if ($editor_vars)
                <x-fruit::menu :title="__('Insert Variable')" class="editor-vars">
                    <x-slot:trigger class="f-button--ghost">{{ __('Insert Variable') }}<span class="f-menu__chevron" aria-hidden="true"></span></x-slot:trigger>
                    @foreach ($editor_vars as $group => $group_vars)
                        <x-fruit::menu-group :label="$group">
                            @foreach ($group_vars as $var_name => $var_label)
                                <x-fruit::menu-item x-on:click="$dispatch('fruit-editor-insert', { html: '{%{{ $var_name }}%}', markdown: '{%{{ $var_name }}%}' })">{{ $var_label }}</x-fruit::menu-item>
                            @endforeach
                        </x-fruit::menu-group>
                    @endforeach
                </x-fruit::menu>
            @endif
        </x-slot:extras>
    <?php endif; ?>
</x-fruit::editor>
