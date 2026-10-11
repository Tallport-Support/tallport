/**
 * Tallport's shared browser helpers that aren't Tallport.*: the page's data
 * attributes, the reply editor, text and browser storage.
 */

// For how long to remember unsent notes (the composer).
var fs_keep_conversation_notes = 30; // days
var fs_draft_autosave_period = 12; // seconds

// The page's data attributes (on the body).
function getGlobalAttr(attr)
{
	return document.body.getAttribute('data-'+attr);
}

function getCsrfToken()
{
	return Tallport.csrf();
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

// Per tab, and gone with it.
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
