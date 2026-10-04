/**
 * Keyboard shortcuts (a user can turn them off in the profile); ? lists them.
 * Other code can make an element ignore them with the shortcuts.ignore_target
 * filter.
 */
$(document).ready(function() {
	if ($('body').attr('data-keyboard-shortcuts') != '1') {
		return;
	}

	// Click the first matching element (links and dropdown items too).
	var click = function(selector, visible_only) {
		var el = $(selector).not('.inactive, .hidden');
		if (visible_only) {
			el = el.filter(':visible');
		}
		el = el.first();
		if (!el.length) {
			return false;
		}
		el[0].click();
		return true;
	};
	var go = function(selector) {
		var href = $(selector).first().attr('href');
		if (!href || href == '#') {
			return false;
		}
		window.location.href = href;
		return true;
	};
	var formOpen = function() {
		return $('.form-reply:visible').length > 0;
	};
	var statuses = {a: 1, p: 2, c: 3, s: 4, n: 'not_spam'};

	$(document).on('keydown.shortcuts', function(e) {
		var key = e.key;
		var target = e.target;
		if (e.ctrlKey || e.metaKey || e.altKey) {
			return;
		}
		var typing = $(target).is('input, select, textarea') || target.isContentEditable;

		if (key == '?' && !typing) {
			$('#keyboard-shortcuts-modal').modal('toggle');
			e.preventDefault();
			return;
		}

		var status_open = $('#conv-status').prop('open') || $('#conv-status').hasClass('open');
		if (!status_open && (e.shiftKey || typing || $('.modal:visible').length || fsApplyFilter('shortcuts.ignore_target', false, {target: target}))) {
			return;
		}

		var conversation = $('.conv-next-prev').length > 0;
		var list = $('.table-conversations').length == 1;
		var done = false;

		if (status_open) {
			// s, then a (active), p (pending), c (closed), s (spam) or n (not spam).
			if (typeof(statuses[key]) != "undefined") {
				done = click('#conv-status a[data-status="'+statuses[key]+'"]');
			}
		} else if (conversation) {
			switch (key) {
				case 'r':
					done = click('.conv-reply', true);
					break;
				case 'n':
					done = click('.conv-add-note', true);
					break;
				case 'f':
					done = click('.conv-forward');
					break;
				case 'm':
					done = click('[data-modal-on-show="initMergeConv"]');
					break;
				case 'v':
					done = click('[data-modal-on-show="initMoveConv"]');
					break;
				case 'w':
					done = click('[data-modal-on-show="initRunWorkflow"]');
					break;
				case 't':
					done = click('.conv-add-tags');
					break;
				case 'o':
					// Follow (not unfollow).
					done = !$('#conv-layout').hasClass('conv-following') && click('.conv-follow');
					break;
				case 'a':
					done = click('#conv-assignee > summary');
					break;
				case 's':
					done = click('#conv-status > summary');
					break;
			}
			if (!done && !formOpen()) {
				switch (key) {
					case 'e':
						done = click('.edit-draft-trigger', true);
						break;
					case 'd':
						done = click('.conv-delete', true);
						break;
					case 'j':
						done = go('.conv-next-prev a:eq(0)');
						break;
					case 'k':
						done = go('.conv-next-prev a:eq(1)');
						break;
					case 'q':
						done = go('a.new-conversation-link');
						break;
				}
			}
		} else if (list) {
			switch (key) {
				case 'k':
					done = click('.pager-next:not(.disabled)');
					break;
				case 'j':
					done = click('.pager-prev:not(.disabled)');
					break;
				case 'c':
					done = !formOpen() && go('a.new-conversation-link');
					break;
			}
		}

		if (!done && key == '/') {
			var search = $('#search-dt');
			if (search.length) {
				search.focus();
				done = true;
			}
		}

		if (done) {
			e.preventDefault();
		}
	});
});
