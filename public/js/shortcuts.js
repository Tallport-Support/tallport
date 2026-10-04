/**
 * Keyboard shortcuts (a user can turn them off in the profile); ? lists them.
 * Other code can make an element ignore them with the shortcuts.ignore_target
 * filter.
 */
document.addEventListener('DOMContentLoaded', function() {
	if (document.body.getAttribute('data-keyboard-shortcuts') != '1') {
		return;
	}

	var visible = function(element) {
		return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
	};

	// Click the first matching element (links and menu items too).
	var click = function(selector, visible_only) {
		var elements = Array.prototype.slice.call(document.querySelectorAll(selector)).filter(function(element) {
			return !element.classList.contains('inactive') && !element.classList.contains('hidden') && (!visible_only || visible(element));
		});
		if (!elements.length) {
			return false;
		}
		elements[0].click();
		return true;
	};
	var go = function(link) {
		var href = link ? link.getAttribute('href') : '';
		if (!href || href == '#') {
			return false;
		}
		window.location.href = href;
		return true;
	};
	var formOpen = function() {
		return Array.prototype.some.call(document.querySelectorAll('.form-reply'), visible);
	};
	var statuses = {a: 1, p: 2, c: 3, s: 4, n: 'not_spam'};

	document.addEventListener('keydown', function(e) {
		var key = e.key;
		var target = e.target;
		if (e.ctrlKey || e.metaKey || e.altKey) {
			return;
		}
		var typing = target.matches('input, select, textarea') || target.isContentEditable;

		if (key == '?' && !typing) {
			var help = document.querySelector('[data-fruit-dialog="keyboard-shortcuts"]');
			if (help) {
				help.open ? help.close() : help.showModal();
			}
			e.preventDefault();
			return;
		}

		var status_menu = document.getElementById('conv-status');
		var status_open = status_menu && status_menu.open;
		if (!status_open && (e.shiftKey || typing || document.querySelector('dialog[open]') || fsApplyFilter('shortcuts.ignore_target', false, {target: target}))) {
			return;
		}

		var conversation = !!document.querySelector('.conv-next-prev');
		var list = document.querySelectorAll('.table-conversations').length == 1;
		var done = false;

		if (status_open) {
			// s, then a (active), p (pending), c (closed), s (spam) or n (not spam).
			if (typeof(statuses[key]) != "undefined") {
				done = click('#conv-status [data-status="'+statuses[key]+'"]');
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
					done = click('[data-fruit-dialog-url][href*="merge_conv"]');
					break;
				case 'v':
					done = click('[data-fruit-dialog-url][href*="move_conv"]');
					break;
				case 'w':
					done = click('[data-modal-on-show="initRunWorkflow"]');
					break;
				case 't':
					done = click('.conv-add-tags');
					break;
				case 'o':
					// Follow (not unfollow).
					done = click('.conv-follow[data-follow-action="follow"]', true);
					break;
				case 'a':
					done = click('#conv-assignee > summary');
					break;
				case 's':
					done = click('#conv-status > summary');
					break;
			}
			if (!done && !formOpen()) {
				var nav = document.querySelectorAll('.conv-next-prev a');
				switch (key) {
					case 'e':
						done = click('.edit-draft-trigger', true);
						break;
					case 'd':
						done = click('.conv-delete', true);
						break;
					case 'j':
						done = go(nav[0]);
						break;
					case 'k':
						done = go(nav[1]);
						break;
					case 'q':
						done = go(document.querySelector('a.new-conversation-link'));
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
					done = !formOpen() && go(document.querySelector('a.new-conversation-link'));
					break;
			}
		}

		if (!done && key == '/') {
			var search = document.getElementById('search-dt');
			if (search) {
				search.focus();
				done = true;
			}
		}

		if (done) {
			e.preventDefault();
		}
	});
});
