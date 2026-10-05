{{-- The keyboard shortcuts (public/js/shortcuts.js), shown with ? (or Ctrl+/, also while typing): a reference
     sheet, actions with their keys (as menus list them; a chord, or another way to press them), closed
     with Done, Esc, ? or a click outside it. --}}
@php
    $shortcuts_mac = str_contains((string) request()->userAgent(), 'Mac');
    $shortcuts_then = '<span class="keyboard-shortcuts__then">'.e(__('then')).'</span>';
    $shortcuts_groups = [
        __('In a Conversation') => [
            [__('Reply'), ['R']],
            [__('Note'), ['N']],
            [__('Forward'), ['F']],
            [__('Edit Draft'), ['E']],
            [__('Assign'), ['A']],
            [__('Status').': '.__('Active'), ['S', 'A']],
            [__('Status').': '.__('Pending'), ['S', 'P']],
            [__('Status').': '.__('Closed'), ['S', 'C']],
            [__('Status').': '.__('Spam'), ['S', 'S']],
            [__('Status').': '.__('Not Spam'), ['S', 'N']],
            [__('Follow'), ['O']],
            [__('Merge'), ['M']],
            [__('Move'), ['V']],
            [__('Delete'), ['D']],
            [__('Newer'), ['J']],
            [__('Older'), ['K']],
            [__('New Conversation'), ['Q']],
            [__('Send'), [$shortcuts_mac ? '⌘ Return' : 'Ctrl + Enter'], true],
        ],
        __('In the List') => [
            [__('Previous Page'), ['J']],
            [__('Next Page'), ['K']],
            [__('New Conversation'), ['C']],
        ],
        __('Everywhere') => [
            [__('Search'), ['/']],
            [__('Keyboard Shortcuts'), ['?'], false, 'Ctrl + /'],
        ],
    ];
@endphp
<x-fruit::dialog name="keyboard-shortcuts" size="large" class="f-dialog--scroll" aria-labelledby="keyboard-shortcuts-title" closedby="any">
    <header class="f-dialog__header">
        <h2 id="keyboard-shortcuts-title">{{ __('Keyboard Shortcuts') }}</h2>
    </header>
    <div class="f-dialog__body keyboard-shortcuts">
        @foreach ($shortcuts_groups as $group => $shortcuts)
            <section class="keyboard-shortcuts__group">
                <h3 class="f-headline">{{ $group }}</h3>
                <dl class="keyboard-shortcuts__list">
                    @foreach ($shortcuts as $shortcut)
                        <div>
                            <dt>{{ $shortcut[0] }}</dt>
                            {{-- A sequence of keys: one, "then" the next; a chord (Send) as one. --}}
                            <dd>@if (!empty($shortcut[2]))@foreach (explode(' ', str_replace(' + ', ' ', $shortcut[1][0])) as $key)<kbd>{{ $key }}</kbd>@endforeach @else{!! implode(' '.$shortcuts_then.' ', array_map(fn ($key) => '<kbd>'.e($key).'</kbd>', $shortcut[1])) !!}@endif@if (!empty($shortcut[3])) <span class="keyboard-shortcuts__then">{{ __('or') }}</span> @foreach (explode(' ', str_replace(' + ', ' ', $shortcut[3])) as $key)<kbd>{{ $key }}</kbd>@endforeach @endif</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
    <form class="f-dialog__footer keyboard-shortcuts__footer" method="dialog">
        <a href="{{ route('users.profile', ['id' => Auth::user()->id]) }}#keyboard_shortcuts" class="f-help">{{ __('Turn them off in your profile.') }}</a>
        <x-fruit::button type="submit" variant="primary" autofocus>{{ __('Done') }}</x-fruit::button>
    </form>
</x-fruit::dialog>
