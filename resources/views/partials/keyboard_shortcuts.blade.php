{{-- The keyboard shortcuts (public/js/shortcuts.js), shown with ?. --}}
<div class="modal fade" id="keyboard-shortcuts-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ __('Keyboard Shortcuts') }}</h4>
            </div>
            <div class="modal-body keyboard-shortcuts">
                <div class="row">
                    <div class="col-sm-6">
                        <h5>{{ __('Conversation') }}</h5>
                        <table class="table table-condensed">
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
                    <div class="col-sm-6">
                        <h5>{{ __('Conversations') }}</h5>
                        <table class="table table-condensed">
                            <tr><td><kbd>j</kbd> / <kbd>k</kbd></td><td>{{ __('Previous Page') }} / {{ __('Next Page') }}</td></tr>
                            <tr><td><kbd>c</kbd></td><td>{{ __('New Conversation') }}</td></tr>
                        </table>
                        <h5>{{ __('Everywhere') }}</h5>
                        <table class="table table-condensed">
                            <tr><td><kbd>/</kbd></td><td>{{ __('Search') }}</td></tr>
                            <tr><td><kbd>?</kbd></td><td>{{ __('Keyboard Shortcuts') }}</td></tr>
                        </table>
                        <p class="text-help">{{ __('Turn them off in your profile.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
