/**
 * The conversation page: the composer, chat mode's Accept Chat and End Chat,
 * and the Merge, Move and Change Customer dialogs (FruitUI remote dialogs).
 */
document.addEventListener('alpine:init', function () {
	// Accept Chat (assign to me) and End Chat (close), then the chat again.
	window.Alpine.data('tallportChatAction', function (data) {
		return {
			run: function (button) {
				Tallport.busy(button, true);
				Tallport.post(laroute.route('conversations.ajax'), data).then(function (response) {
					if (Tallport.isSuccess(response)) {
						window.location.reload();
					} else {
						Tallport.result(response);
						Tallport.busy(button, false);
					}
				});
			}
		};
	});

	/**
	 * The composer (App\Livewire\ConversationComposer): gives the component
	 * the editor's text, saves drafts while the user writes, keeps an unsent
	 * note in the browser, uploads files and asks about a forgotten attachment.
	 */
	window.Alpine.data('tallportComposer', function (conversation_id) {
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

			// Cmd/Ctrl+Enter sends; in chat mode Enter does.
			enter: function (event) {
				if (!event.target.closest || !event.target.closest('.f-editor') || event.altKey || event.shiftKey) {
					return;
				}
				var chat = document.body.classList.contains('chat-mode') && this.$wire.mode != 'note';
				if (event.metaKey || event.ctrlKey || chat) {
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
});
