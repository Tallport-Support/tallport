/**
 * Tallport's shared browser helpers that aren't Tallport.*: the page's data
 * attributes, the reply editor, browser storage, the mobile app, and
 * FreeScout's API for modules (fsAjax(), showFloatingAlert(), triggerModal(),
 * fsAddAction() and alike), all without jQuery: requests go through fetch,
 * messages through FruitUI's toasts, dialogs through FruitUI's dialog.
 */

// For how long to remember unsent notes (the composer).
var fs_keep_conversation_notes = 30; // days
var fs_draft_autosave_period = 12; // seconds
var fs_actions = {};
var fs_filters = {};

var FS_STATUS_CLOSED = 3;

// The page's data attributes (on the body).
function getGlobalAttr(attr)
{
	return document.body.getAttribute('data-'+attr);
}

function setGlobalAttr(attr, value)
{
	document.body.setAttribute('data-'+attr, value);
}

function getCsrfToken()
{
	return Tallport.csrf();
}

function getLocale()
{
	return document.documentElement.getAttribute('lang');
}

function reloadPage()
{
	window.location.href = '';
}

function getQueryParam(name, qs) {
	var arrays_without_indexes = {};

	if (typeof(qs) == "undefined") {
		qs = document.location.search;
	}
	qs = qs.split('+').join(' ');

	var params = {},
		tokens,
		key,
		parsed_key,
		re = /[?&]?([^=]+)=([^&]*)/g;

	while (tokens = re.exec(qs)) {
		key = decodeURIComponent(tokens[1]);

		// Arrays without indexes - []
		if (/\[\]$/.test(key)) {
			if (typeof(arrays_without_indexes[key]) == "undefined") {
				arrays_without_indexes[key] = 0;
			} else {
				arrays_without_indexes[key]++;
			}
			parsed_key = /(.*)\[\]$/.exec(key);
			if (typeof(parsed_key[1]) != "undefined") {
				key = parsed_key[1]+'['+arrays_without_indexes[key]+']';
			}
		}
		params[key] = decodeURIComponent(tokens[2]);
	}

	// Process arrays
	for (var param in params) {

		// Skip __proto__
		// https://github.com/freescout-helpdesk/freescout/security/advisories/GHSA-rx6j-4c33-9h3r
		if (param.match(/__proto__/i)) {
			continue;
		}

		// Two dimentional
		var m = param.match(/^([^\[]+)\[([^\[]+)\]$/i);

		if (m && m.length) {
			if (typeof(params[m[1]]) == "undefined") {
				params[m[1]] = {};
			}
			if (typeof(params[m[1]]) == "object") {
				params[m[1]][m[2]] = params[param];
			}
		}

		// Three dimentional
		m = param.match(/^([^\[]+)\[([^\[]+)\]\[([^\[]+)\]$/i);

		if (m && m.length) {
			if (typeof(params[m[1]]) == "undefined") {
				params[m[1]] = {};
			}
			if (typeof(params[m[1]]) == "object") {
				if (typeof(params[m[1]][m[2]]) == "undefined") {
					params[m[1]][m[2]] = {};
				}
				params[m[1]][m[2]][m[3]] = params[param];
			}
		}
	}

	if (typeof(params[name]) != "undefined") {
		return params[name];
	} else {
		return '';
	}
}

// The reply editor (FruitUI's editor, by its textarea's ID).
function editorSetContent(id, html)
{
	window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: String(id).replace('#', ''), html: html}}));
}

function editorInsert(id, html)
{
	window.dispatchEvent(new CustomEvent('fruit-editor-insert', {detail: {target: String(id).replace('#', ''), html: html}}));
}

// at_end: the cursor after the text.
function editorFocus(id, at_end)
{
	var textarea = document.getElementById(String(id).replace('#', ''));
	var editor = textarea ? textarea.closest('.f-editor') : null;
	var surface = editor ? editor.querySelector('.f-editor__surface [contenteditable="true"]') : null;
	if (surface) {
		surface.focus();
		if (at_end) {
			var range = document.createRange();
			range.selectNodeContents(surface);
			range.collapse(false);
			window.getSelection().removeAllRanges();
			window.getSelection().addRange(range);
		}
	} else if (textarea) {
		textarea.focus();
		if (at_end) {
			textarea.setSelectionRange(textarea.value.length, textarea.value.length);
		}
	}
}

/**
 * Reply, Note or Forward (the toolbar, R, N): the composer in view and its editor focused,
 * so typing can start at once. Waits for it to open (App\Livewire\ConversationComposer)
 * and for FruitUI's editor to start; an open one is focused right away.
 */
function composerFocus()
{
	var started = Date.now();
	var attempt = function () {
		var textarea = document.getElementById('body');
		var editor = textarea ? textarea.closest('.f-editor') : null;
		var surface = editor ? editor.querySelector('.f-editor__surface [contenteditable="true"]') : null;
		var target = surface || (textarea && !editor ? textarea : null);
		if (!target) {
			if (Date.now() - started < 3000) {
				requestAnimationFrame(attempt);
			}
			return;
		}
		(textarea.closest('.conv-action-wrapper') || target).scrollIntoView({block: 'nearest', behavior: 'smooth'});
		target.focus({preventScroll: true});
	};
	requestAnimationFrame(attempt);
}

function getReplyBody()
{
	var body = document.getElementById('body');
	return body ? body.value : '';
}

function setReplyBody(text)
{
	editorSetContent('body', text);
}

// The composer's (App\Livewire\ConversationComposer): reply, note, forward or nothing.
function getReplyFormMode()
{
	var composer = window.Livewire ? Livewire.getByName('conversation-composer')[0] : null;
	return composer ? composer.mode : '';
}

// Text
function stripTags(html)
{
	var div = document.createElement("div");
	div.innerHTML = html;
	return div.textContent || div.innerText || "";
}

function htmlEscape(text)
{
	return String(text)
		.replace(/&(?!amp;|lt;|gt;|quot;|#039;)/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#039;");
}

function htmlDecode(input)
{
	var e = document.createElement('div');
	e.innerHTML = input;
	// handle case of empty input
	return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
}

function copyToClipboard(text)
{
	if (navigator.clipboard) {
		navigator.clipboard.writeText(text);
	}
}

// Browser storage
function localStorageGet(key)
{
	try {
		return localStorage.getItem(key);
	} catch (e) {
		return false;
	}
}

function localStorageSet(key, value)
{
	try {
		localStorage.setItem(key, value);
	} catch (e) {
		return false;
	}
}

function localStorageRemove(key)
{
	try {
		localStorage.removeItem(key);
	} catch (e) {
		return false;
	}
}

// Per tab, and gone with it.
/**
 * Whether a key sends what's being written: in a chat ('chat') or another message
 * ('message'), as App\Misc\KeyboardShortcuts says (the body's data-send-keys).
 * Not while an input method composes, nor what something else handled (a picked mention).
 */
function tallportSendKey(event, kind)
{
	if (event.key != 'Enter' || event.isComposing || event.defaultPrevented || event.altKey) {
		return false;
	}
	var keys = [];
	try {
		keys = JSON.parse(document.body.getAttribute('data-send-keys') || '{}')[kind] || [];
	} catch (e) {}
	var mod = event.metaKey || event.ctrlKey;

	return keys.some(function (combo) {
		var parts = combo.split('+');
		return parts.indexOf('Shift') != -1 == event.shiftKey && parts.indexOf('Mod') != -1 == mod;
	});
}

function sessionStorageGet(key)
{
	try {
		return window.sessionStorage.getItem(key);
	} catch (e) {
		return null;
	}
}

function sessionStorageSet(key, value)
{
	try {
		window.sessionStorage.setItem(key, value);
	} catch (e) {
		// Not available (private mode).
	}
}

function localStorageGetObject(key)
{
	var obj = {};
	try {
		obj = JSON.parse(localStorageGet(key) || '{}');
	} catch (e) {}

	return (obj && typeof(obj) == 'object') ? obj : {};
}

function localStorageSetObject(key, obj)
{
	localStorageSet(key, JSON.stringify(obj));
}

// Unsent notes (the composer).
function loadNotesFromStorage(conversation_id)
{
	return localStorageGetObject('conversation_notes');
}

function saveNoteToStorage(conversation_notes)
{
	localStorageSetObject('conversation_notes', conversation_notes);
}

// Cookies
function setCookie(name, value, props)
{
	props = props || {};
	if (!("path" in props)) {
		props.path = "/";
	}
	if (!("samesite" in props)) {
		props.samesite = "None";
	}
	// SameSite=None needs Secure.
	if (props.samesite && props.samesite.toLowerCase() === "none" && !("secure" in props)) {
		props.secure = true;
	}
	if (!props.expires) {
		var exp_date = new Date();
		exp_date.setTime(exp_date.getTime() + 2147483647 * 1000);
		props.expires = exp_date;
	}
	if (props.expires && props.expires.toUTCString) {
		props.expires = props.expires.toUTCString();
	}
	var cookie = name + "=" + encodeURIComponent(value);
	for (var prop in props) {
		cookie += "; " + prop;
		if (props[prop] !== true) {
			cookie += "=" + props[prop];
		}
	}
	document.cookie = cookie;
}

function getCookie(name)
{
	var matches = document.cookie.match(new RegExp(
		"(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
	));
	return matches ? decodeURIComponent(matches[1]) : undefined;
}

function deleteCookie(name)
{
	document.cookie = name+'=;path=/;expires=Thu, 01 Jan 1970 00:00:01 GMT;';
}

// Hooks for modules' scripts.
function fsAddAction(action, callback, priority)
{
	if (typeof(fs_actions[action]) == "undefined") {
		fs_actions[action] = [];
	}
	fs_actions[action].push({callback: callback, priority: priority || 20});
}

function fsDoAction(action, params)
{
	if (typeof(fs_actions[action]) == "undefined") {
		return false;
	}
	for (var i in fs_actions[action]) {
		fs_actions[action][i].callback(params || {});
	}
	return true;
}

function fsAddFilter(filter, callback, priority)
{
	if (typeof(fs_filters[filter]) == "undefined") {
		fs_filters[filter] = [];
	}
	fs_filters[filter].push({callback: callback, priority: priority || 20});
}

function fsApplyFilter(filter, value, params)
{
	if (typeof(fs_filters[filter]) != "undefined") {
		for (var i in fs_filters[filter]) {
			value = fs_filters[filter][i].callback(value, params || {});
		}
	}
	return value;
}

/*
 * FreeScout's request and message helpers, for modules' scripts.
 */

// Form data from an object (nested objects and arrays as PHP reads them), or a query string.
function fsFormData(data, form, prefix)
{
	form = form || new FormData();
	if (typeof(data) == 'string') {
		new URLSearchParams(data).forEach(function(value, key) {
			form.append(key, value);
		});
		return form;
	}
	Object.keys(data || {}).forEach(function(key) {
		var value = data[key];
		var name = prefix ? prefix+'['+key+']' : key;
		if (value === null || typeof(value) == 'undefined') {
			return;
		}
		if (Array.isArray(value)) {
			value.forEach(function(item) {
				form.append(name+'[]', item);
			});
		} else if (typeof(value) == 'object' && !(value instanceof Blob)) {
			fsFormData(value, form, name);
		} else {
			form.append(name, value);
		}
	});
	return form;
}

/**
 * POST to an ajax endpoint: success_callback(response) with its JSON;
 * error_callback() when the request fails. no_loader is kept for modules.
 */
function fsAjax(data, url, success_callback, no_loader, error_callback, custom_options)
{
	if (!url) {
		return false;
	}
	if (!error_callback) {
		error_callback = function() {
			showFloatingAlert('error', Lang.get("messages.ajax_error"));
		};
	}
	var body = data instanceof FormData ? data : fsFormData(data);
	var target = new URL(url, window.location.href);
	if (target.pathname.indexOf('/conversation/') != -1 && getQueryParam('folder_id')) {
		target.searchParams.set('folder_id', getQueryParam('folder_id'));
	}
	var xembed = getQueryParam('x_embed') == '1';
	if (xembed) {
		target.searchParams.set('x_embed', '1');
	}
	fetch(target, {
		method: 'POST',
		body: body,
		credentials: 'same-origin',
		headers: {'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
	}).then(function(response) {
		return response.json();
	}).then(function(response) {
		if (xembed && response && response.redirect_url && response.redirect_url.indexOf('x_embed=1') == -1) {
			var redirect = new URL(response.redirect_url, window.location.href);
			redirect.searchParams.set('x_embed', '1');
			response.redirect_url = redirect.toString();
		}
		success_callback(response);
	}).catch(function(e) {
		error_callback(e);
	});
}

function isAjaxSuccess(response)
{
	return Tallport.isSuccess(response);
}

function showFloatingAlert(type, msg, no_autohide)
{
	Tallport.toast(msg, type == 'error' ? 'danger' : 'success');
}

function showAjaxResult(response)
{
	if (isAjaxSuccess(response)) {
		if (response.msg_success) {
			showFloatingAlert('success', response.msg_success);
		}
	} else {
		showAjaxError(response);
	}
}

function showAjaxError(response, no_autohide)
{
	var msg = response ? (response.msg || response.message) : '';
	showFloatingAlert('error', msg || Lang.get("messages.error_occurred"), no_autohide);
}

// Kept for modules: there is no page loader any more.
function loaderShow(delay)
{
}

function loaderHide()
{
}

function ajaxFinish()
{
}

/**
 * A dialog the FreeScout way (FruitUI's dialog): from a link (its href or
 * data-remote, data-modal-title, data-modal-size, data-modal-on-show) or
 * params (title, remote, body, size, on_show). on_show gets the dialog's
 * content element.
 */
function triggerModal(link, params)
{
	params = params || {};
	var attr = function(name) {
		return link && link.getAttribute ? link.getAttribute(name) : null;
	};
	var title = params.title || attr('data-modal-title') || (link && link.textContent ? link.textContent.trim() : '');
	if (title && title.charAt(0) == '#' && document.querySelector(title)) {
		title = document.querySelector(title).textContent;
	}
	var body = params.body || attr('data-modal-body');
	if (body && body.charAt && body.charAt(0) == '#' && document.querySelector(body)) {
		body = document.querySelector(body).innerHTML;
	}
	var size = params.size || attr('data-modal-size');
	var options = {title: title, size: size == 'lg' ? 'large' : 'medium'};
	if (body) {
		options.html = body;
	} else {
		options.url = params.remote || attr('data-remote') || attr('href');
	}
	var dialog = FruitUI.dialog(options);
	var on_show = params.on_show || attr('data-modal-on-show');
	if (on_show) {
		dialog.loaded.then(function(content) {
			if (typeof(on_show) == 'function') {
				on_show(content, link);
			} else if (typeof(window[on_show]) == 'function') {
				window[on_show](content, link);
			}
		});
	}
	return dialog;
}

function showModalDialog(body, options)
{
	return triggerModal(null, Object.assign({body: body}, options || {}));
}

// A confirmation (FruitUI's): the button with ok_class in on_show's content confirms.
function showModalConfirm(text, ok_class, options, ok_text)
{
	options = options || {};
	FruitUI.confirm({title: stripTags(text), confirm: ok_text || 'OK'}).then(function(ok) {
		if (ok && typeof(options.on_show) == 'function') {
			// The old dialog's OK button: its click handlers run on a stand-in.
			var content = document.createElement('div');
			var button = document.createElement('button');
			button.className = ok_class;
			content.appendChild(button);
			options.on_show(content);
			button.click();
		}
	});
}

// Links that open their content in a dialog the FreeScout way (modules).
document.addEventListener('click', function(e) {
	var link = e.target.closest && e.target.closest('[data-trigger="modal"], .fs-trigger-modal');
	if (!link || e.ctrlKey || e.metaKey || e.shiftKey) {
		return;
	}
	e.preventDefault();
	triggerModal(link);
});

function closeAllModals()
{
	document.querySelectorAll('dialog[open]').forEach(function(dialog) {
		dialog.close();
	});
}

function closeVisibleModal()
{
	closeAllModals();
}

function isModalOpen()
{
	return !!document.querySelector('dialog[open]');
}
