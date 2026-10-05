/**
 * The conversation page: the composer, the Merge, Move and Change Customer dialogs (FruitUI remote dialogs), links
 * in messages, and printing. And the search page's filters.
 */

// Links in messages open in a new tab.
document.addEventListener('click', function (e) {
	var link = e.target.closest && e.target.closest('.thread-content a[href]');
	if (link) {
		link.target = '_blank';
	}
});

/**
 * Another conversation opens in place (App\Livewire\ConversationPane and the toolbar and
 * customer beside it): a row of the list, Newer and Older. The rest of the page stays;
 * the address, the title and the page's data follow when it's there (conversation-opened).
 */
var conversationOpenLink = function (link) {
	var match = (link.getAttribute('href') || '').match(/\/conversation\/(\d+)/);
	return match ? {id: parseInt(match[1]), folder_id: new URL(link.href, window.location.href).searchParams.get('folder_id') || ''} : null;
};
document.addEventListener('click', function (e) {
	var link = e.target.closest && e.target.closest('a.conv-row__link, .conv-next-prev a');
	if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || link.target == '_blank' || !document.querySelector('.conv-pane')) {
		return;
	}
	var open = conversationOpenLink(link);
	if (!open) {
		return;
	}
	e.preventDefault();
	window.history.pushState({tallport_conversation: open}, '', link.href);
	conversationOpen(open);
});
var conversationOpen = function (open) {
	document.querySelectorAll('a.conv-row__link[aria-current]').forEach(function (row) {
		row.removeAttribute('aria-current');
	});
	document.querySelectorAll('a.conv-row__link').forEach(function (row) {
		var row_open = conversationOpenLink(row);
		if (row_open && row_open.id == open.id) {
			row.setAttribute('aria-current', 'page');
		}
	});
	document.getElementById('app-content').setAttribute('aria-busy', 'true');
	Livewire.dispatch('conversation-open', open);
};
// Back and Forward between conversations opened in place.
window.addEventListener('popstate', function (e) {
	if (e.state && e.state.tallport_conversation && document.querySelector('.conv-pane')) {
		conversationOpen(e.state.tallport_conversation);
	}
});
document.addEventListener('livewire:init', function () {
	Livewire.on('conversation-opened', function (event) {
		var data = Array.isArray(event) ? event[0] : event;
		document.getElementById('app-content').removeAttribute('aria-busy');
		document.title = data.title;
		document.body.setAttribute('data-conversation_id', data.id);
		document.body.setAttribute('data-mailbox_id', data.mailbox_id);
		document.body.removeAttribute('data-page-url');
		window.history.replaceState(Object.assign({}, window.history.state || {}, {tallport_conversation: {id: data.id, folder_id: data.folder_id}}), '', data.url);
		// For realtime updates, the composer and modules (CustomApp, Nostr).
		document.dispatchEvent(new CustomEvent('tallport:conversation-opened', {detail: data}));
	});
});

// Each conversation page, also one opened with wire:navigate.
var conversation_first_page = true;
document.addEventListener('livewire:navigated', function () {
	var first_page = conversation_first_page;
	conversation_first_page = false;
	if (!document.body.getAttribute('data-conversation_id')) {
		return;
	}
	// Back to this page after conversations opened in place opens it again (with
	// Livewire's own entry kept).
	window.history.replaceState(Object.assign({}, window.history.state || {}, {tallport_conversation: {
		id: parseInt(document.body.getAttribute('data-conversation_id')),
		folder_id: new URLSearchParams(window.location.search).get('folder_id') || ''
	}}), '');
	// Shown after wire:navigate (perhaps prefetched on hover): now it's seen
	// (ConversationsController::view() does this for a page loaded in full).
	if (!first_page) {
		var params = new URLSearchParams(window.location.search);
		Tallport.post(laroute.route('conversations.ajax'), {
			action: 'viewed',
			conversation_id: document.body.getAttribute('data-conversation_id'),
			folder_id: params.get('folder_id') || '',
			mark_as_read: params.get('mark_as_read') || ''
		});
	}
	// After a message sent in the chat view, the next one can be typed right away.
	if (sessionStorageGet('tallport_chat_focus') == document.body.getAttribute('data-conversation_id')) {
		sessionStorageSet('tallport_chat_focus', '');
		setTimeout(function () {
			editorFocus('body');
		}, 50);
	}
	if (new URLSearchParams(window.location.search).get('print')) {
		window.print();
	}
});
document.addEventListener('alpine:init', function () {
	/**
	 * The composer (App\Livewire\ConversationComposer): gives the component
	 * the editor's text, saves drafts while the user writes, keeps an unsent
	 * note in the browser, uploads files and asks about a forgotten attachment.
	 */
	window.Alpine.data('tallportComposer', function (conversation_id, mode, chat) {
		var editor = function () {
			return document.getElementById('body');
		};
		var notes = function () {
			return loadNotesFromStorage(conversation_id) || {};
		};

		return {
			dirty: false,
			saved: false,
			uploading: [],
			timer: null,
			note_timer: null,
			listeners: [],

			init: function () {
				var self = this;
				// A note the user was writing.
				var note = notes()[conversation_id];
				if (!this.$wire.mode && note && note.note && stripTags(note.note).trim()) {
					this.$wire.openNote(note.note);
				}
				this.timer = setInterval(function () {
					if (self.dirty) {
						self.save(false);
					}
				}, fs_draft_autosave_period * 1000);
				this.listeners.push(Livewire.on('composer-draft-saved', function () {
					self.dirty = false;
					self.saved = true;
					setTimeout(function () {
						self.saved = false;
					}, 4000);
				}));
				this.listeners.push(Livewire.on('composer-note-forget', function () {
					self.forgetNote();
				}));
			},

			destroy: function () {
				clearInterval(this.timer);
				this.listeners.forEach(function (stop) {
					stop();
				});
			},

			// The editor's text, for the component's next request.
			sync: function () {
				if (editor()) {
					this.$wire.$set('body', editor().value, false);
				}
			},

			changed: function (event) {
				if (event.target !== editor()) {
					return;
				}
				this.sync();
				this.dirty = true;
				if (this.$wire.mode == 'note') {
					var self = this;
					clearTimeout(this.note_timer);
					this.note_timer = setTimeout(function () {
						self.rememberNote();
					}, 500);
				}
			},

			// The editor lost focus: save now.
			blurred: function (event) {
				if (event.target === editor() && this.dirty) {
					this.save(false);
				}
			},

			save: function (force) {
				if (this.$wire.mode == 'note') {
					this.rememberNote();
					return;
				}
				this.sync();
				this.$wire.saveDraft(force);
			},

			rememberNote: function () {
				var all = notes();
				var note = editor() ? editor().value : '';
				var expired = (new Date()).getTime() - fs_keep_conversation_notes*24*60*60*1000;
				Object.keys(all).forEach(function (key) {
					if (all[key].time && all[key].time < expired) {
						delete all[key];
					}
				});
				if (note && stripTags(note).trim()) {
					all[conversation_id] = {note: note, time: (new Date()).getTime()};
				} else {
					delete all[conversation_id];
				}
				saveNoteToStorage(all);
			},

			forgetNote: function () {
				var all = notes();
				delete all[conversation_id];
				saveNoteToStorage(all);
			},

			// Send (with a status from the send menu), after the uploads and the attachment reminder.
			submit: function (status) {
				var self = this;
				if (this.uploading.length) {
					return;
				}
				this.sync();
				this.attachmentReminder().then(function (ok) {
					if (ok) {
						self.dirty = false;
						// The chat view: back in the composer once the conversation is shown again.
						if (chat) {
							sessionStorageSet('tallport_chat_focus', conversation_id);
						}
						self.$wire.send(status === undefined ? null : status);
					}
				});
			},

			// Text mentioning an attachment, with none attached.
			attachmentReminder: function () {
				var info = document.getElementById('attachment-reminder');
				var text = stripTags(editor() ? editor().value : '').toLowerCase();
				var attached = this.$wire.attachments.some(function (attachment) {
					return !attachment.embed;
				});
				if (!info || attached || this.$wire.mode == 'note') {
					return Promise.resolve(true);
				}
				var phrases = JSON.parse(info.getAttribute('data-phrases') || '[]');
				for (var i = 0; i < phrases.length; i++) {
					if (phrases[i] && text.indexOf(phrases[i].toLowerCase()) != -1) {
						return Tallport.confirm({message: info.getAttribute('data-message').replace(':phrase', '“'+phrases[i]+'”'), confirm: info.getAttribute('data-send')});
					}
				}
				return Promise.resolve(true);
			},

			discard: function () {
				var self = this;
				Tallport.confirm({message: Lang.get('messages.confirm_discard_draft'), confirm: Lang.get('messages.discard'), tone: 'danger'}).then(function (ok) {
					if (ok) {
						self.dirty = false;
						self.$wire.discard();
					}
				});
			},

			// Files from the paperclip: attachments.
			upload: function (files) {
				for (var i = 0; i < files.length; i++) {
					this.sendFile(files[i], true);
				}
			},

			// Images pasted or dropped into the text (FruitUI's upload hook): embedded.
			embed: function (event) {
				for (var i = 0; i < event.detail.files.length; i++) {
					this.sendFile(event.detail.files[i], false, event.detail.insert);
				}
			},

			sendFile: function (file, attach, insert) {
				var self = this;
				// Only images are embedded.
				if (!attach && (file.type || '').indexOf('image/') !== 0) {
					attach = true;
				}
				var data = new FormData();
				data.append('file', file);
				data.append('attach', attach ? 1 : 0);
				this.uploading.push(file.name);
				Tallport.post(laroute.route('conversations.upload'), data).then(function (response) {
					self.uploading.splice(self.uploading.indexOf(file.name), 1);
					if (!Tallport.isSuccess(response) || !response.url) {
						Tallport.result(response);
						return;
					}
					if (!attach) {
						if (insert) {
							insert(response.url, file.name);
						} else {
							editorInsert('body', '<img src="'+response.url+'" alt="'+htmlEscape(file.name)+'">');
						}
					}
					if (response.attachment_id) {
						self.$wire.attach([{id: response.attachment_id, name: file.name, size: file.size, url: response.url, embed: !attach}]);
					}
					self.dirty = true;
				});
			},

			// Cmd/Ctrl+Enter sends (in the chat view too: a message can have several lines).
			enter: function (event) {
				if (!event.target.closest || !event.target.closest('.f-editor') || event.altKey || event.shiftKey) {
					return;
				}
				if (event.metaKey || event.ctrlKey) {
					event.preventDefault();
					this.submit();
				}
			}
		};
	});

	// After a dialog's action: where the server says, or this conversation again.
	var reloadAfter = function (response) {
		if (Tallport.isSuccess(response)) {
			window.location.href = response.redirect_url || window.location.href;
			return true;
		}
		Tallport.result(response);
		return false;
	};

	// Merge: conversations found by number, or previous ones, into this one.
	window.Alpine.data('tallportMerge', function (conversation_id) {
		return {
			number: '',
			found: [],
			selected: [],
			search: function (button) {
				var self = this;
				Tallport.busy(button, true);
				Tallport.post(laroute.route('conversations.ajax'), {action: 'merge_search', number: this.number, cur_conv_id: conversation_id}).then(function (response) {
					Tallport.busy(button, false);
					if (!Tallport.isSuccess(response) || !response.conversation) {
						Tallport.result(response);
						return;
					}
					var item = response.conversation;
					item.id = String(item.id);
					if (!self.found.some(function (found) { return found.id == item.id; })) {
						self.found.push(item);
					}
					if (self.selected.indexOf(item.id) == -1) {
						self.selected.push(item.id);
					}
				});
			},
			merge: function (button) {
				Tallport.busy(button, true);
				Tallport.post(laroute.route('conversations.ajax'), {action: 'conversation_merge', merge_conversation_id: this.selected, conversation_id: conversation_id}).then(function (response) {
					if (!reloadAfter(response)) {
						Tallport.busy(button, false);
					}
				});
			}
		};
	});

	// Move: to one of the user's mailboxes, or one given by its address.
	window.Alpine.data('tallportMove', function (conversation_id) {
		return {
			mailbox_id: '',
			email: '',
			init: function () {
				var select = this.$root.querySelector('select');
				this.mailbox_id = select ? select.value : '';
			},
			move: function (button) {
				Tallport.busy(button, true);
				Tallport.post(laroute.route('conversations.ajax'), {
					action: 'conversation_move',
					mailbox_id: this.email ? '' : this.mailbox_id,
					mailbox_email: this.email,
					conversation_id: conversation_id,
					folder_id: new URLSearchParams(window.location.search).get('folder_id') || ''
				}).then(function (response) {
					if (!reloadAfter(response)) {
						Tallport.busy(button, false);
					}
				});
			}
		};
	});

	// Change Customer: a customer found by name or email, or a new one.
	window.Alpine.data('tallportChangeCustomer', function (conversation_id, customer_email) {
		return {
			query: '',
			results: [],
			searched: false,
			creating: false,
			search: function () {
				var self = this;
				if (this.query.trim().length < 2) {
					this.results = [];
					this.searched = false;
					return;
				}
				var url = new URL(laroute.route('customers.ajax_search'), window.location.href);
				url.searchParams.set('q', this.query.trim());
				url.searchParams.set('exclude_email', customer_email || '');
				url.searchParams.set('search_by', 'all');
				fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
					.then(function (response) { return response.json(); })
					.then(function (data) {
						self.results = data.results || [];
						self.searched = true;
					});
			},
			choose: function (email) {
				Tallport.confirm({message: Lang.get('messages.confirm_change_customer', {customer_email: email})}).then(function (ok) {
					if (ok) {
						Tallport.post(laroute.route('conversations.ajax'), {action: 'conversation_change_customer', customer_email: email, conversation_id: conversation_id}).then(reloadAfter);
					}
				});
			},
			create: function (button) {
				var data = new FormData(button.form);
				data.append('action', 'create');
				Tallport.busy(button, true);
				Tallport.post(laroute.route('customers.ajax'), data).then(function (response) {
					Tallport.busy(button, false);
					if (Tallport.result(response) && response.email) {
						Tallport.post(laroute.route('conversations.ajax'), {action: 'conversation_change_customer', customer_email: response.email, conversation_id: conversation_id}).then(reloadAfter);
					}
				});
			}
		};
	});

	// Simple Markdown (paragraphs, lists, bold, italic) as HTML, escaped.
	var markdownToHtml = function (text) {
		var html = '';
		var paragraph = [];
		var list = '';
		var escape = function (value) {
			var div = document.createElement('div');
			div.textContent = value;
			return div.innerHTML;
		};
		var inline = function (line) {
			return escape(line).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\*([^*]+)\*/g, '<em>$1</em>');
		};
		var closeParagraph = function () {
			if (paragraph.length) {
				html += '<p>'+paragraph.map(inline).join('<br>')+'</p>';
				paragraph = [];
			}
		};
		var closeList = function () {
			if (list) {
				html += '</'+list+'>';
				list = '';
			}
		};
		String(text || '').replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
			line = line.trim();
			var item = line.match(/^[-*]\s+(.+)$/) || line.match(/^\d+[.)]\s+(.+)$/);
			if (!line) {
				closeParagraph();
				closeList();
			} else if (item) {
				var type = /^[-*]/.test(line) ? 'ul' : 'ol';
				closeParagraph();
				if (list != type) {
					closeList();
					list = type;
					html += '<'+type+'>';
				}
				html += '<li>'+inline(item[1])+'</li>';
			} else {
				closeList();
				paragraph.push(line);
			}
		});
		closeParagraph();
		closeList();

		return html;
	};

	/**
	 * AI Assistant reply drafts (App\Ai\Drafts): asked for by the toolbar's Draft
	 * with AI, polled until ready, then put into the reply (the composer).
	 */
	window.Alpine.data('tallportAiDraft', function (draft_url, translation_language, texts) {
		return {
			active: false,
			status: '',
			failed: false,
			detail: '',
			meta: '',
			html: '',
			draft: null,
			slow: false,
			timer: null,

			reset: function (status) {
				clearTimeout(this.timer);
				this.status = status || '';
				this.failed = false;
				this.detail = '';
				this.meta = '';
				this.html = '';
				this.draft = null;
				this.slow = false;
			},

			// Waiting for the draft: the card shows a placeholder.
			busy: function () {
				return !this.draft && !this.failed;
			},

			tone: function () {
				return this.slow ? 'warning' : (this.failed ? 'danger' : 'working');
			},

			host: function (url) {
				try {
					return new URL(url).host;
				} catch (e) {
					return '';
				}
			},

			close: function () {
				this.reset('');
				this.active = false;
			},

			fail: function (message, detail) {
				this.reset(message || texts.failed);
				this.failed = true;
				this.detail = detail || '';
			},

			request: function () {
				var self = this;
				this.active = true;
				// The draft is shown below the editor: open it, as Reply does.
				Livewire.dispatch('composer-open', {mode: 'reply'});
				this.reset(texts.queued);
				Tallport.post(draft_url, {}).then(function (response) {
					if (Tallport.isSuccess(response) && response.poll_url) {
						self.poll(response.poll_url, 1);
					} else {
						self.fail(response && response.msg);
					}
				});
			},

			poll: function (url, attempt) {
				var self = this;
				fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
					.then(function (response) {
						return response.json();
					})
					.then(function (response) {
						if (response.status != 'success') {
							self.fail(response.msg, response.detail);
						} else if (response.draft_status == 'completed') {
							self.reset('');
							self.draft = response;
							self.meta = response.language+' · '+response.confidence;
							self.html = markdownToHtml(response.draft);
						} else if (attempt >= 180) {
							self.fail(texts.slow);
							self.slow = true;
						} else {
							self.status = response.draft_status == 'running' ? texts.drafting : texts.queued;
							self.timer = setTimeout(function () {
								self.poll(url, attempt + 1);
							}, 2000);
						}
					})
					.catch(function () {
						self.fail();
					});
			},

			insert: function () {
				if (!this.draft) {
					return;
				}
				Livewire.dispatch('composer-ai-draft', {
					html: markdownToHtml(this.draft.draft),
					translation: this.draft.translation || '',
					language: this.draft.translation ? translation_language : ''
				});
			}
		};
	});

	/**
	 * Search: filters shown from the Filters menu, hidden ones disabled (not sent).
	 * Any [data-filter] in #search-filters, modules' too (an .active .form-group).
	 */
	window.Alpine.data('tallportSearchFilters', function () {
		return {
			active: {},
			form: null,
			init: function () {
				var self = this;
				// The form: $root is a menu's in the Filters menu.
				this.form = this.$root;
				this.form.querySelectorAll('#search-filters [data-filter]').forEach(function (filter) {
					self.set(filter, !filter.hidden && (!filter.classList.contains('form-group') || filter.classList.contains('active')));
				});
			},
			set: function (filter, on) {
				filter.hidden = !on;
				filter.classList.toggle('active', on);
				filter.querySelectorAll('input, select, textarea').forEach(function (control) {
					control.disabled = !on;
				});
				this.active[filter.getAttribute('data-filter')] = on;
			},
			toggle: function (name) {
				var filter = this.form.querySelector('#search-filters [data-filter="'+name+'"]');
				if (!filter) {
					return;
				}
				this.set(filter, !this.active[name]);
				if (this.active[name]) {
					var control = filter.querySelector('input:not([type=hidden]), select');
					if (control) {
						control.focus();
					}
				}
			}
		};
	});
});
