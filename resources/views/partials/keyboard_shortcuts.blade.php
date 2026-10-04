{{-- The keyboard shortcuts (public/js/shortcuts.js), shown with ?. --}}
<x-fruit::dialog name="keyboard-shortcuts" size="large" aria-labelledby="keyboard-shortcuts-title">
    <header class="f-dialog__header">
        <h2 id="keyboard-shortcuts-title">{{ __('Keyboard Shortcuts') }}</h2>
        <x-fruit::button variant="ghost" size="small" class="f-button--icon" x-on:click="$el.closest('dialog').close()" :aria-label="__('Close')"><x-icon.x class="f-icon" aria-hidden="true" /></x-fruit::button>
    </header>
    <div class="f-dialog__body keyboard-shortcuts">
        <div class="keyboard-shortcuts__columns">
                    <div>
                        <h3 class="f-headline">{{ __('Conversation') }}</h3>
                        <table class="f-table keyboard-shortcuts__table">
                            <tr><td><kbd>r</kbd></td><td>{{ __('Reply') }}</td></tr>
                            <tr><td><kbd>n</kbd></td><td>{{ __('Note') }}</td></tr>
                            <tr><td><kbd>f</kbd></td><td>{{ __('Forward') }}</td></tr>
                            <tr><td><kbd>e</kbd></td><td>{{ __('Edit Draft') }}</td></tr>
                            <tr><td><kbd>a</kbd></td><td>{{ __('Assign') }}</td></tr>
                            <tr><td><kbd>s</kbd></td><td>{{ __('Status') }}: <kbd>a</kbd> {{ __('Active') }}, <kbd>p</kbd> {{ __('Pending') }}, <kbd>c</kbd> {{ __('Closed') }}, <kbd>s</kbd> {{ __('Spam') }}, <kbd>n</kbd> {{ __('Not Spam') }}</td></tr>
                            <tr><td><kbd>o</kbd></td><td>{{ __('Follow') }}</td></tr>
                            <tr><td><kbd>m</kbd></td><td>{{ __('Merge') }}</td></tr>
                            <tr><td><kbd>v</kbd></td><td>{{ __('Move') }}</td></tr>
                            <tr><td><kbd>d</kbd></td><td>{{ __('Delete') }}</td></tr>
                            <tr><td><kbd>j</kbd> / <kbd>k</kbd></td><td>{{ __('Newer') }} / {{ __('Older') }}</td></tr>
                            <tr><td><kbd>q</kbd></td><td>{{ __('New Conversation') }}</td></tr>
                            <tr><td><kbd>Ctrl</kbd> <kbd>Enter</kbd></td><td>{{ __('Send') }}</td></tr>
                        </table>
                    </div>
                    <div>
                        <h3 class="f-headline">{{ __('Conversations') }}</h3>
                        <table class="f-table keyboard-shortcuts__table">
                            <tr><td><kbd>j</kbd> / <kbd>k</kbd></td><td>{{ __('Previous Page') }} / {{ __('Next Page') }}</td></tr>
                            <tr><td><kbd>c</kbd></td><td>{{ __('New Conversation') }}</td></tr>
                        </table>
                        <h3 class="f-headline">{{ __('Everywhere') }}</h3>
                        <table class="f-table keyboard-shortcuts__table">
                            <tr><td><kbd>/</kbd></td><td>{{ __('Search') }}</td></tr>
                            <tr><td><kbd>?</kbd></td><td>{{ __('Keyboard Shortcuts') }}</td></tr>
                        </table>
                        <p class="f-help">{{ __('Turn them off in your profile.') }}</p>
                    </div>
                </div>
    </div>
</x-fruit::dialog>
