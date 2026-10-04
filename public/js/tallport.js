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
	function busy(button, on) {
		if (!button) {
			return;
		}
		button.disabled = !!on;
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

/**
 * wire:navigate (the sidebar's folder links): pages whose scripts are ready
 * for it (data-navigable on the body) swap in the next page, others load it
 * in full. The sidebar keeps its scroll position.
 */
(function () {
	var sidebar_scroll = 0;

	function sidebar() {
		return document.getElementById('app-sidebar');
	}

	document.addEventListener('livewire:navigate', function (event) {
		if (!document.body.hasAttribute('data-navigable') && !event.detail.history) {
			event.preventDefault();
			window.location.href = event.detail.url;
			return;
		}
		sidebar_scroll = sidebar() ? sidebar().scrollTop : 0;
	});

	document.addEventListener('livewire:navigated', function () {
		if (sidebar_scroll && sidebar()) {
			sidebar().scrollTop = sidebar_scroll;
		}
		sidebar_scroll = 0;
	});
})();
