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

// Images from other servers (App\Misc\ExternalImages): shown in this message, always for
// the customer (reloads), or hidden again for the customer (its sidebar menu).
document.addEventListener('click', function (e) {
	var button = e.target.closest && e.target.closest('.external-images-show, .external-images-block');
	if (!button) {
		return;
	}
	e.preventDefault();
	var notice = button.closest('.external-images-notice');
	Tallport.busy(button, true);
	Tallport.post(laroute.route('conversations.external_images'), {
		action: button.classList.contains('external-images-block') ? 'block_customer' : button.getAttribute('data-action'),
		thread_id: notice ? notice.getAttribute('data-thread-id') : '',
		customer_id: button.getAttribute('data-customer-id') || ''
	}).then(function (response) {
		Tallport.busy(button, false);
		if (!Tallport.isSuccess(response)) {
			Tallport.result(response);
		} else if (response.reload) {
			window.location.reload();
		} else {
			var content = notice.parentElement.querySelector('.thread-content');
			if (content) {
				content.innerHTML = response.html;
			}
			notice.remove();
		}
	});
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
	window.history.pushState({tallport_conversation: open, tallport_folder: {folder_id: document.body.getAttribute('data-folder_id')}}, '', link.href);
	conversationOpen(open);
});

/**
 * Another folder opens in place beside an open conversation (its sidebar link, a
 * wire:navigate link): the list, its toolbar and the conversation it opens at
 * (App\Livewire\ConversationList, ConversationListToolbar, ConversationPane…).
 * The sidebar stays, with the folder marked; an empty folder loads its page.
 */
var folderOpen = function (folder_id, conversation_id) {
	var link = document.querySelector('.app-sidebar a[data-folder_id="'+folder_id+'"]');
	document.querySelectorAll('.app-sidebar a[data-folder_id][aria-current]').forEach(function (item) {
		item.removeAttribute('aria-current');
	});
	if (link) {
		link.setAttribute('aria-current', 'page');
		var mailbox_id = (link.closest('[data-mailbox_id]') || link).getAttribute('data-mailbox_id');
		if (mailbox_id) {
			document.body.setAttribute('data-mailbox_id', mailbox_id);
			// The list's search: within this mailbox (All Mailboxes: all of them).
			var search = document.querySelector('.conv-list-search');
			var scope = search ? search.querySelector('input[name="f[mailbox]"]') : null;
			if (search && parseInt(mailbox_id) > 0) {
				if (!scope) {
					scope = document.createElement('input');
					scope.type = 'hidden';
					scope.name = 'f[mailbox]';
					search.prepend(scope);
				}
				scope.value = mailbox_id;
			} else if (scope) {
				scope.remove();
			}
		}
	}
	document.body.setAttribute('data-folder_id', folder_id);
	document.getElementById('app-content').setAttribute('aria-busy', 'true');
	Livewire.dispatch('folder-open', {folder_id: parseInt(folder_id), conversation_id: conversation_id || null});
};
// The sidebar's folders: in place beside an open conversation, else the folder's page
// (wire:navigate, with its progress bar).
document.addEventListener('click', function (e) {
	var link = e.target.closest && e.target.closest('a.app-folder-link');
	if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
		return;
	}
	e.preventDefault();
	if (!document.querySelector('.conv-pane') || !document.querySelector('.conv-list-host')) {
		Livewire.navigate(link.href);
		return;
	}
	window.history.pushState({tallport_folder: {folder_id: link.getAttribute('data-folder_id')}}, '', link.href);
	folderOpen(link.getAttribute('data-folder_id'));
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
// Back and Forward between conversations and folders opened in place.
window.addEventListener('popstate', function (e) {
	var state = e.state || {};
	if (!document.querySelector('.conv-pane')) {
		return;
	}
	if (state.tallport_folder && state.tallport_folder.folder_id != document.body.getAttribute('data-folder_id')) {
		folderOpen(state.tallport_folder.folder_id, state.tallport_conversation ? state.tallport_conversation.id : null);
	} else if (state.tallport_conversation) {
		conversationOpen(state.tallport_conversation);
	}
});
document.addEventListener('livewire:init', function () {
	Livewire.on('conversation-opened', function (event) {
		var data = Array.isArray(event) ? event[0] : event;
		document.getElementById('app-content').removeAttribute('aria-busy');
		document.title = data.title;
		document.body.setAttribute('data-conversation_id', data.id);
		document.body.setAttribute('data-mailbox_id', data.mailbox_id);
		document.body.setAttribute('data-folder_id', data.folder_id);
		document.body.removeAttribute('data-page-url');
		window.history.replaceState(Object.assign({}, window.history.state || {}, {tallport_conversation: {id: data.id, folder_id: data.folder_id}, tallport_folder: {folder_id: data.folder_id}}), '', data.url);
		// The styles changed since this page was loaded (an update): the conversation loads anew.
		if (data.styles && !document.querySelector('link[rel="stylesheet"][href="'+data.styles+'"]')) {
			window.location.reload();
			return;
		}
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
	}, tallport_folder: {folder_id: document.body.getAttribute('data-folder_id')}}), '');
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
			// The message shows at once (sending takes a moment, delivery longer); the chat view's
			// field is ready for the next one right away. If it can't be saved, it's back in the editor.
			submit: function (status) {
				var self = this;
				if (this.uploading.length) {
					return;
				}
				this.sync();
				this.attachmentReminder().then(function (ok) {
					if (!ok) {
						return;
					}
					var html = editor() ? editor().value : '';
					// A translated conversation (App\Ai\ChatTranslation): the translation first, for a
					// look; sent later with the status chosen now.
					if (self.$root.hasAttribute('data-translating') && !self.as_written) {
						self.send_status = status;
						self.translate(html);
						return;
					}
					self.as_written = false;
					self.dirty = false;
					var shown = self.showSending(html);
					if (chat) {
						window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: ''}}));
						editorFocus('body');
					} else {
						self.$root.classList.add('conv-action-wrapper--sent');
					}
					self.$wire.send(status === undefined ? null : status, html).then(function (sent) {
						if (sent) {
							// The history's render may have taken the focus: the next message.
							if (chat && (!document.activeElement || !document.activeElement.closest('.conv-action-wrapper'))) {
								editorFocus('body');
							}
							return;
						}
						if (shown) {
							shown.remove();
						}
						self.$root.classList.remove('conv-action-wrapper--sent');
						if (chat) {
							window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: html}}));
						}
					});
				});
			},

			// A translated conversation's reply: its translation is shown (livewire/conversation-composer); sent
			// when the same text is sent again (Enter), or as written if it's in that language already.
			translate: function (html) {
				var self = this;
				var translation = this.$wire.translation;
				if (!stripTags(html).trim() || this.translating_now) {
					return;
				}
				if (translation && translation.html && translation.source === html) {
					this.sendPreview();
					return;
				}
				this.translating_now = true;
				this.$wire.previewTranslation(html).then(function (result) {
					self.translating_now = false;
					if (result == 'same') {
						self.sendAsWritten();
					}
				});
			},

			retryTranslation: function () {
				this.translate(editor() ? editor().value : '');
			},

			sendPreview: function () {
				var self = this;
				var html = editor() ? editor().value : '';
				var translation = this.$wire.translation;
				var shown = this.showSending(translation ? translation.html : html);
				this.dirty = false;
				if (chat) {
					window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: ''}}));
					editorFocus('body');
				} else {
					this.$root.classList.add('conv-action-wrapper--sent');
				}
				this.$wire.sendTranslation(html, this.send_status === undefined ? null : this.send_status).then(function (result) {
					if (result == 'sent') {
						return;
					}
					if (shown) {
						shown.remove();
					}
					self.$root.classList.remove('conv-action-wrapper--sent');
					if (chat) {
						window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: html}}));
					}
					// Changed since it was translated: translated again.
					if (result == 'changed') {
						self.translate(html);
					}
				});
			},

			sendAsWritten: function () {
				this.as_written = true;
				this.submit(this.send_status);
			},

			editTranslation: function () {
				this.$wire.discardTranslation();
				editorFocus('body');
			},

			// The message, shown while it's sent: at the end of the chat's history, or above the
			// email view's messages. The history's next render replaces it.
			showSending: function (html) {
				var list = chat
					? Array.prototype.slice.call(document.querySelectorAll('.conv-history .f-thread')).pop()
					: document.getElementById('conv-layout-main');
				if (!list || !stripTags(html).trim()) {
					return null;
				}
				var item = document.createElement('li');
				item.className = 'conv-sending';
				var message = document.createElement('article');
				message.className = 'f-message f-message--outgoing f-message--mine'+(chat ? '' : ' f-message--stacked')+(this.$wire.mode == 'note' ? ' f-message--note' : '');
				message.setAttribute('aria-busy', 'true');
				var header = document.createElement('header');
				header.className = 'f-message__header';
				var identity = document.createElement('span');
				identity.className = 'f-message__identity';
				var author = document.createElement('strong');
				author.className = 'f-message__author';
				author.textContent = this.$root.getAttribute('data-author') || '';
				identity.appendChild(author);
				var time = document.createElement('span');
				time.className = 'f-message__time';
				time.textContent = this.$root.getAttribute('data-sending') || '';
				header.appendChild(identity);
				header.appendChild(time);
				var body = document.createElement('div');
				body.className = 'f-message__body';
				// The user's own editor text, without scripts or handlers.
				var parsed = new DOMParser().parseFromString(html, 'text/html');
				parsed.querySelectorAll('script, style, iframe, object').forEach(function (element) {
					element.remove();
				});
				parsed.querySelectorAll('*').forEach(function (element) {
					Array.prototype.slice.call(element.attributes).forEach(function (attribute) {
						if (/^on/i.test(attribute.name)) {
							element.removeAttribute(attribute.name);
						}
					});
				});
				body.innerHTML = parsed.body.innerHTML;
				message.appendChild(header);
				message.appendChild(body);
				item.appendChild(message);
				if (chat) {
					list.appendChild(item);
				} else {
					list.insertBefore(item, list.firstChild);
				}
				return item;
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

			// The send keys (App\Misc\KeyboardShortcuts): Cmd/Ctrl+Enter, caught on the way down,
			// before the editor takes it for a line break. In the chat view the editor sends (Enter).
			enter: function (event) {
				if (chat || !event.target.closest || !event.target.closest('.f-editor')) {
					return;
				}
				if (tallportSendKey(event, 'message')) {
					event.preventDefault();
					event.stopPropagation();
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

	// Merge: conversations found by number, or previous ones, into this one. The selection is
	// in a store: the Merge button is in the dialog's footer, outside the component.
	window.Alpine.store('merge', {
		conversation_id: null,
		selected: [],
		merge: function (button) {
			Tallport.busy(button, true);
			Tallport.post(laroute.route('conversations.ajax'), {action: 'conversation_merge', merge_conversation_id: this.selected, conversation_id: this.conversation_id}).then(function (response) {
				if (!reloadAfter(response)) {
					Tallport.busy(button, false);
				}
			});
		}
	});
	// Each conversation shows once: the one just found on top (out of Previous Conversations
	// meanwhile), others found before on top too unless they're among the previous ones.
	window.Alpine.data('tallportMerge', function (conversation_id, previous_ids, texts) {
		var store = window.Alpine.store('merge');
		store.conversation_id = conversation_id;
		store.selected = [];
		previous_ids = (previous_ids || []).map(String);
		return {
			number: '',
			found: [],
			current: null,
			status: '',
			init: function () {
				var self = this;
				this.$watch('number', function (number) {
					if (String(number).trim() === '') {
						self.current = null;
					}
				});
			},
			get shown() {
				var self = this;
				return this.found.filter(function (item) {
					return item.id === self.current || previous_ids.indexOf(item.id) == -1;
				}).sort(function (a, b) {
					return (b.id === self.current) - (a.id === self.current);
				});
			},
			get selected() {
				return store.selected;
			},
			set selected(value) {
				store.selected = value;
			},
			search: function (button) {
				var self = this;
				Tallport.busy(button, true);
				var number = String(this.number).replace(/^#/, '').trim();
				self.status = '';
				Tallport.post(laroute.route('conversations.ajax'), {action: 'merge_search', number: number, cur_conv_id: conversation_id}).then(function (response) {
					Tallport.busy(button, false);
					if (!Tallport.isSuccess(response) || !response.conversation) {
						self.current = null;
						self.status = texts.none.replace(':number', number);
						Tallport.result(response);
						return;
					}
					var item = response.conversation;
					item.id = String(item.id);
					self.current = item.id;
					self.status = texts.found.replace(':number', item.number);
					if (!self.found.some(function (found) { return found.id == item.id; })) {
						self.found.push(item);
					}
					if (self.selected.indexOf(item.id) == -1) {
						self.selected.push(item.id);
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
	 * with AI, shown as they're written, then put into the reply (the composer).
	 */
	// chat: the chat view's composer (inline: no card, the draft goes into the chat field;
	// translated: replies are translated on send, so the agent's language goes in).
	window.Alpine.data('tallportAiDraft', function (draft_url, translation_language, texts, chat) {
		chat = chat || {};
		return {
			active: false,
			drafting: false,
			status: '',
			failed: false,
			detail: '',
			meta: '',
			html: '',
			draft: null,

			reset: function (status) {
				this.status = status || '';
				this.failed = false;
				this.detail = '';
				this.meta = '';
				this.html = '';
				this.draft = null;
			},

			// Waiting for the draft to start: the card shows a placeholder.
			busy: function () {
				return !this.draft && !this.failed && !this.html;
			},

			tone: function () {
				return this.failed ? 'danger' : 'working';
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
				this.drafting = false;
				if (chat.inline) {
					this.reset('');
					Tallport.toast(message || texts.failed, 'danger');
					return;
				}
				this.reset(message || texts.failed);
				this.failed = true;
				this.detail = detail || '';
			},

			// The chat field, as the draft is written (not when it's translated before sending).
			setField: function (html) {
				window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: html}}));
			},

			// The draft is written into the card as the AI writes it (server-sent events from
			// AiDraftsController::store()), then its translation and details.
			request: function () {
				var self = this;
				var meta = document.querySelector('meta[name="csrf-token"]');
				var controller = new AbortController();
				if (this.drafting) {
					return;
				}
				this.drafting = true;
				this.active = !chat.inline;
				// The draft is shown below the editor: open it, as Reply does.
				Livewire.dispatch('composer-open', {mode: 'reply'});
				this.reset(texts.drafting);
				var timer = setTimeout(function () {
					controller.abort();
				}, 165000);
				fetch(draft_url, {
					method: 'POST',
					signal: controller.signal,
					credentials: 'same-origin',
					headers: {
						'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '',
						'X-Requested-With': 'XMLHttpRequest',
						'Accept': 'text/event-stream, application/json'
					}
				}).then(function (response) {
					// Refused (limits, permissions): JSON.
					if ((response.headers.get('Content-Type') || '').indexOf('text/event-stream') == -1) {
						return response.json().catch(function () {
							return {};
						}).then(function (result) {
							self.fail(result.msg);
						});
					}
					return self.read(response.body.getReader());
				}).catch(function () {
					if (!self.draft) {
						self.fail();
					}
				}).finally(function () {
					clearTimeout(timer);
				});
			},

			read: function (reader) {
				var self = this;
				var decoder = new TextDecoder();
				var buffer = '';
				var finished = false;
				var next = function () {
					return reader.read().then(function (chunk) {
						buffer += decoder.decode(chunk.value || new Uint8Array(), {stream: !chunk.done});
						var events = buffer.split('\n\n');
						buffer = chunk.done ? '' : events.pop();
						events.forEach(function (event) {
							if (event.indexOf('data: ') !== 0) {
								return;
							}
							var data = JSON.parse(event.substring(6));
							if (data.status == 'success') {
								finished = true;
								self.drafting = false;
								self.reset('');
								self.draft = data;
								self.meta = data.language+' · '+data.confidence;
								self.html = markdownToHtml(data.draft);
								if (chat.inline) {
									self.insert();
								}
							} else if (data.status == 'error') {
								finished = true;
								self.fail(data.msg, data.detail);
							} else if (typeof data.draft == 'string') {
								self.html = markdownToHtml(data.draft);
								if (chat.inline && !chat.translated) {
									self.setField(self.html);
								}
							}
						});
						if (chunk.done) {
							if (!finished) {
								self.fail();
							}
							return;
						}
						return next();
					});
				};
				return next();
			},

			insert: function () {
				if (!this.draft) {
					return;
				}
				// Translated on send: the version in the agent's language is what they write.
				if (chat.translated && this.draft.translation) {
					Livewire.dispatch('composer-ai-draft', {html: markdownToHtml(this.draft.translation), translation: '', language: ''});
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
