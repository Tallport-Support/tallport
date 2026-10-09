/**
 * Tallport's shared browser helpers, without jQuery: requests to the app's
 * ajax endpoints, and feedback through FruitUI (toasts, busy buttons).
 * Screens moving off jQuery use these instead of fsAjax(), showAjaxResult()
 * and showFloatingAlert().
 */
window.Tallport = (function () {
	function csrf() {
		var meta = document.querySelector('meta[name="csrf-token"]');
		return meta ? meta.getAttribute('content') : '';
	}

	function query(name) {
		return new URLSearchParams(window.location.search).get(name);
	}

	// Form data from an object, a FormData or a <form>.
	function body(data) {
		if (data instanceof FormData) {
			return data;
		}
		if (data instanceof HTMLFormElement) {
			return new FormData(data);
		}
		var form = new FormData();
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) {
					form.append(key + '[]', item);
				});
			} else if (value !== undefined && value !== null) {
				form.append(key, value);
			}
		});
		return form;
	}

	/**
	 * POST to an ajax endpoint; resolves with its JSON. Conversation requests
	 * carry the folder (as fsAjax did), embedded pages keep x_embed.
	 */
	function post(url, data) {
		var target = new URL(url, window.location.href);
		if (target.pathname.indexOf('/conversation/') != -1 && query('folder_id')) {
			target.searchParams.set('folder_id', query('folder_id'));
		}
		if (query('x_embed') == '1') {
			target.searchParams.set('x_embed', '1');
		}
		return fetch(target, {
			method: 'POST',
			body: body(data),
			credentials: 'same-origin',
			headers: {
				'X-CSRF-TOKEN': csrf(),
				'X-Requested-With': 'XMLHttpRequest',
				'Accept': 'application/json'
			}
		}).then(function (response) {
			return response.json().catch(function () {
				return {status: 'error'};
			});
		}).catch(function () {
			return {status: 'error', msg: Lang.get('messages.ajax_error')};
		});
	}

	function isSuccess(response) {
		return !!response && response.status == 'success';
	}

	// A FruitUI toast; tone is success or danger.
	function toast(message, tone) {
		if (!message) {
			return;
		}
		window.FruitUI.toast(message, {tone: tone || 'success'});
	}

	// An endpoint's answer as a toast: its success message, or its error.
	function result(response) {
		if (isSuccess(response)) {
			toast(response.msg_success);
		} else {
			toast((response && (response.msg || response.message)) || Lang.get('messages.error_occurred'), 'danger');
		}
		return isSuccess(response);
	}

	// A button that is working: busy for assistive tech, not clickable twice.
	// FruitUI shows a spinner on a busy button and ignores clicks on it; not
	// disabled, which would take the focus away.
	function busy(button, on) {
		if (!button) {
			return;
		}
		if (on) {
			button.setAttribute('aria-busy', 'true');
		} else {
			button.removeAttribute('aria-busy');
		}
	}

	/**
	 * Ask before an action, in FruitUI's confirm dialog: resolves true to go ahead.
	 * options: {message, confirm (button label), tone ('danger' for destructive)}.
	 */
	function confirm(options) {
		return window.FruitUI.confirm({title: options.message, confirm: options.confirm, tone: options.tone});
	}

	return {csrf: csrf, post: post, isSuccess: isSuccess, toast: toast, result: result, busy: busy, confirm: confirm};
})();

document.addEventListener('fruit-dialog-error', function (event) {
	var response = event.detail.response;
	var login = document.querySelector('meta[name="login-url"]');
	if (event.detail.reason != 'redirect' || !response || !login) {
		return;
	}
	var destination = new URL(response.url, window.location.href);
	var loginUrl = new URL(login.content, window.location.href);
	if (destination.origin == loginUrl.origin && destination.pathname == loginUrl.pathname) {
		// A full-page request replaces Laravel's intended fragment URL with this page.
		window.location.reload();
	}
});

/**
 * wire:navigate (the app's own links): the next page is swapped in, with
 * Livewire's progress bar; folders and conversations are fetched on hover
 * already (wire:navigate.hover), and brought up to date when shown later.
 * The sidebar keeps its scroll position.
 */
(function () {
	var sidebar_scroll = 0;

	function sidebar() {
		return document.getElementById('app-sidebar');
	}

	document.addEventListener('livewire:navigate', function () {
		sidebar_scroll = sidebar() ? sidebar().scrollTop : 0;
	});

	var first_page = true;
	document.addEventListener('livewire:navigated', function () {
		// A page prefetched on hover (wire:navigate.hover) a while before it was shown.
		var rendered_at = parseInt(document.body.getAttribute('data-rendered-at')) || 0;
		if (!first_page && rendered_at && Date.now() / 1000 - rendered_at > 5 && window.tallportCatchUp) {
			window.tallportCatchUp();
		}
		first_page = false;
		// A page shown at another URL (a folder showing its conversation) says its own.
		var page_url = document.body.getAttribute('data-page-url');
		if (page_url && page_url != window.location.href) {
			history.replaceState(history.state, '', page_url);
		}
		if (sidebar_scroll && sidebar()) {
			sidebar().scrollTop = sidebar_scroll;
		}
		sidebar_scroll = 0;
	});
})();

// Whether the window is narrow (the list and a conversation take turns), for the
// server: a folder opens at a conversation only beside its list
// (ConversationsController::openFolder()).
(function () {
	var narrow = window.matchMedia('(max-width: 900px)');
	function remember() {
		document.cookie = 'tallport_narrow=' + (narrow.matches ? 1 : 0) + '; path=/; SameSite=Lax';
	}
	remember();
	narrow.addEventListener('change', remember);
})();

// The columns' widths, as the user resizes them (FruitUI's splitters), for the
// server to open every page with them (Helper::columnWidthsStyle()).
document.addEventListener('fruit-resize', function (event) {
	var widths = {};
	try {
		widths = JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )tallport_columns=([^;]*)/) || [])[1] || '{}')) || {};
	} catch (e) {
		widths = {};
	}
	widths[event.detail.variable] = Math.round(event.detail.value);
	document.cookie = 'tallport_columns=' + encodeURIComponent(JSON.stringify(widths)) + '; path=/; max-age=31536000; SameSite=Lax';
});

// A form sent the regular way: its button is busy until the next page shows (FruitUI's
// spinner). Forms that scripts send themselves (submit prevented) are left alone.
document.addEventListener('submit', function (event) {
	var button = event.submitter;
	if (!event.defaultPrevented && button && button.classList.contains('f-button')) {
		button.setAttribute('aria-busy', 'true');
	}
});
// A folder's count changed (a star: App\Livewire\ConversationSubject::star()): its badge in the sidebar.
window.addEventListener('folder-count', function (event) {
	document.querySelectorAll('.app-folder-link[data-folder_id="'+event.detail.folder_id+'"]').forEach(function (link) {
		var badge = link.querySelector('.active-count');
		link.setAttribute('data-active-count', event.detail.count);
		if (!event.detail.count) {
			badge && badge.remove();
		} else if (badge) {
			badge.textContent = event.detail.count;
		} else {
			badge = document.createElement('span');
			badge.className = 'f-badge active-count';
			badge.textContent = event.detail.count;
			link.appendChild(badge);
		}
	});
});

// Back to a page from the browser's cache: nothing is busy any more.
window.addEventListener('pageshow', function (event) {
	if (event.persisted) {
		document.querySelectorAll('.f-button[aria-busy="true"]').forEach(function (button) {
			button.removeAttribute('aria-busy');
		});
	}
});

// The progress bar along the top for every navigation, not only wire:navigate's: Livewire's
// own look (its #nprogress styles), shown after 150ms as Livewire does. It runs while a
// conversation or folder opens in place (#app-content busy, public/js/conversations.js) and
// while the browser loads another page (a link, a form, a reload).
var tallportProgress = (function () {
	var element = null;
	var status = null;
	var timers = [];
	var clear = function () {
		timers.forEach(clearTimeout);
		timers = [];
	};
	var render = function () {
		if (!element) {
			element = document.createElement('div');
			element.id = 'nprogress';
			element.innerHTML = '<div class="bar" role="bar"><div class="peg"></div></div>';
		}
		if (!element.isConnected) {
			document.body.appendChild(element);
		}
		return element.querySelector('.bar');
	};
	var set = function (value) {
		status = value;
		var bar = render();
		bar.style.transition = 'transform 200ms ease, opacity 200ms ease';
		bar.style.opacity = '1';
		bar.style.transform = 'translate3d(' + ((value - 1) * 100) + '%, 0, 0)';
	};
	var trickle = function () {
		if (status === null || status >= 0.95) {
			return;
		}
		set(status + (1 - status) * 0.08 * Math.random() + 0.01);
		timers.push(setTimeout(trickle, 200));
	};
	return {
		start: function () {
			if (status !== null || document.getElementById('nprogress') && element !== document.getElementById('nprogress')) {
				// Already running (or Livewire's own bar is up).
				return;
			}
			clear();
			status = 0;
			timers.push(setTimeout(function () {
				if (status === null) {
					return;
				}
				set(0.1);
				timers.push(setTimeout(trickle, 200));
			}, 150));
			// Never stuck (a download starts a page load that never comes): it finishes anyway.
			timers.push(setTimeout(function () {
				tallportProgress.done();
			}, 10000));
		},
		done: function () {
			if (status === null) {
				return;
			}
			clear();
			var shown = element && element.isConnected && status > 0;
			status = null;
			if (!shown) {
				return;
			}
			var bar = element.querySelector('.bar');
			bar.style.transform = 'translate3d(0, 0, 0)';
			timers.push(setTimeout(function () {
				bar.style.opacity = '0';
				timers.push(setTimeout(function () {
					element.remove();
				}, 250));
			}, 200));
		}
	};
})();
document.addEventListener('DOMContentLoaded', function () {
	var content = document.getElementById('app-content');
	if (!content) {
		return;
	}
	// The pane is replaced on wire:navigate: watched through the body, its own attribute only.
	new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			if (mutation.target.id !== 'app-content') {
				return;
			}
			if (mutation.target.getAttribute('aria-busy') === 'true') {
				tallportProgress.start();
			} else {
				tallportProgress.done();
			}
		});
	}).observe(document.body, {attributes: true, attributeFilter: ['aria-busy'], subtree: true});
});
document.addEventListener('livewire:navigated', function () {
	tallportProgress.done();
});
// Another page loading the regular way.
window.addEventListener('beforeunload', function () {
	tallportProgress.start();
});
window.addEventListener('pageshow', function (event) {
	if (event.persisted) {
		tallportProgress.done();
	}
});

// Settings' search (partials/app_sidebar): the settings pages whose names, or what's on
// them (data-search), have every word typed; Return opens the first.
(function () {
	var filter = function (query) {
		var sidebar = document.getElementById('app-sidebar');
		var terms = query.toLowerCase().trim().split(/\s+/).filter(Boolean);
		var found = 0;
		sidebar.querySelectorAll('a.f-sidebar__item').forEach(function (item) {
			var text = ((item.getAttribute('data-search') || '') + ' ' + item.textContent).toLowerCase();
			item.hidden = !terms.every(function (term) {
				return text.indexOf(term) != -1;
			});
			found += item.hidden ? 0 : 1;
		});
		// Module items' wrappers, a mailbox's pages and the headings: only with something in them.
		sidebar.querySelectorAll('.app-sidebar__module-items > li, details.f-sidebar__group').forEach(function (group) {
			group.hidden = !group.querySelector('a.f-sidebar__item:not([hidden])');
		});
		sidebar.querySelectorAll('.f-sidebar__heading').forEach(function (heading) {
			var visible = false;
			for (var next = heading.nextElementSibling; next && !next.classList.contains('f-sidebar__heading'); next = next.nextElementSibling) {
				if (next.matches('a.f-sidebar__item:not([hidden])') || next.querySelector('a.f-sidebar__item:not([hidden])')) {
					visible = true;
					break;
				}
			}
			heading.hidden = !visible;
		});
		var none = sidebar.querySelector('.app-sidebar__no-results');
		if (none) {
			none.hidden = found > 0 || !terms.length;
		}
	};
	document.addEventListener('input', function (e) {
		if (e.target.matches && e.target.matches('.app-settings-search')) {
			filter(e.target.value);
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key != 'Enter' || !e.target.matches || !e.target.matches('.app-settings-search')) {
			return;
		}
		var first = document.querySelector('#app-sidebar a.f-sidebar__item:not([hidden])');
		if (first) {
			e.preventDefault();
			Livewire.navigate(first.href);
		}
	});
})();
