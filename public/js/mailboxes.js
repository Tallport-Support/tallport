/**
 * Mailbox settings: the settings form, deleting a mailbox, the connection
 * pages (sending method, test email, protocol, IMAP folders, test connection),
 * translating its greeting and auto reply, muting a mailbox from the sidebar, emptying its Deleted or Spam folder,
 * and the focus in the list of its settings pages.
 */
document.addEventListener('alpine:init', function () {
	/**
	 * Mailbox settings form: the custom From name, and the email header
	 * that is filled in with its default when switched on.
	 */
	window.Alpine.data('tallportMailboxUpdate', function (from_name_custom) {
		return {
			fromNameChanged: function (event) {
				var container = document.getElementById('from_name_custom_container');
				if (container) {
					container.hidden = event.target.value != from_name_custom;
				}
			},
			beforeReplyToggled: function (event) {
				var input = document.getElementById('before_reply');
				if (event.target.checked) {
					input.readOnly = false;
					input.value = input.getAttribute('data-default');
				} else {
					input.readOnly = true;
					if (input.value) {
						input.setAttribute('data-default', input.value);
						input.setAttribute('placeholder', input.value);
						input.value = '';
					}
				}
			}
		};
	});

	// The Delete Mailbox dialog: asks for the password, then goes to the mailboxes.
	// Delete Mailbox… from a row's menu (Settings › Mailboxes): closed, the focus goes back to that
	// menu's button, as FruitUI's confirmer does; deleted, to the next row (or New Mailbox) on the
	// list as it loads again.
	window.Alpine.data('tallportDeleteMailbox', function (mailbox_id) {
		var row = function () {
			return document.querySelector('.mailbox-row[data-mailbox_id="'+mailbox_id+'"]');
		};
		return {
			deleted: false,
			init: function () {
				var self = this;
				this.$el.closest('dialog').addEventListener('close', function () {
					var trigger = row() && row().querySelector('summary');
					if (!self.deleted && trigger) {
						trigger.focus();
					}
				});
			},
			remove: function (event) {
				var self = this;
				var button = event.submitter || this.$el.querySelector('[type="submit"]');
				var password = this.$el.querySelector('input[type="password"]');
				Tallport.busy(button, true);
				Tallport.post(laroute.route('mailboxes.ajax'), {
					action: 'delete_mailbox',
					mailbox_id: mailbox_id,
					password: password ? password.value : undefined
				}).then(function (response) {
					if (Tallport.isSuccess(response)) {
						var next = row() && (row().nextElementSibling || row().previousElementSibling);
						sessionStorageSet('tallport_mailboxes_focus', next && next.matches('.mailbox-row') ? next.getAttribute('data-mailbox_id') : 'new');
						self.deleted = true;
						window.location.href = laroute.route('mailboxes');
						return;
					}
					Tallport.result(response);
					Tallport.busy(button, false);
				});
			}
		};
	});

	// Outgoing connection: the options of the chosen method, and the test email.
	window.Alpine.data('tallportMailboxConnection', function (mailbox_id, out_method_smtp) {
		return {
			methodChanged: function (event) {
				if (event.target.name != 'out_method') {
					return;
				}
				var method = event.target.value;
				document.querySelectorAll('.out_method_options').forEach(function (options) {
					options.classList.toggle('hidden', options.id != 'out_method_' + method + '_options');
				});
				var smtp = document.getElementById('out_method_' + out_method_smtp + '_options');
				if (!smtp) {
					return;
				}
				smtp.querySelectorAll('input, select, textarea').forEach(function (input) {
					if (parseInt(method) == parseInt(out_method_smtp)) {
						if (input.getAttribute('data-smtp-required') == 'true') {
							input.required = true;
						}
					} else {
						input.required = false;
					}
				});
			},
			sendTest: function (event) {
				var button = event.currentTarget;
				var log = document.getElementById('send_test_log');
				Tallport.busy(button, true);
				log.classList.add('hidden');
				Tallport.post(laroute.route('mailboxes.ajax'), {
					action: 'send_test',
					mailbox_id: mailbox_id,
					to: document.getElementById('send_test').value
				}).then(function (response) {
					if (Tallport.isSuccess(response)) {
						Tallport.toast(Lang.get('messages.email_sent'));
					} else {
						Tallport.result(response);
						if (response.log) {
							log.textContent = response.log;
							log.classList.remove('hidden');
						}
					}
					Tallport.busy(button, false);
				});
			},
			// A new secret in the webhook URLs: the services' webhooks need the new URL.
			regenerateWebhook: function (event) {
				var button = event.currentTarget;
				Tallport.confirm({message: button.getAttribute('data-confirm'), confirm: button.textContent.trim(), tone: 'danger'}).then(function (ok) {
					if (!ok) {
						return;
					}
					Tallport.busy(button, true);
					Tallport.post(laroute.route('mailboxes.ajax'), {
						action: 'regenerate_webhook',
						mailbox_id: mailbox_id
					}).then(function (response) {
						if (Tallport.result(response)) {
							Object.keys(response.urls).forEach(function (provider) {
								var input = document.getElementById('out_webhook_' + provider);
								if (input) {
									input.value = response.urls[provider];
									input.parentNode.querySelector('[data-fruit-copy]').setAttribute('data-fruit-copy', response.urls[provider]);
								}
							});
						}
						Tallport.busy(button, false);
					});
				});
			}
		};
	});

	/**
	 * Incoming connection: the settings of the chosen protocol (modules add
	 * their own with data-in-protocol), the IMAP folders from the server,
	 * and the connection test, which waits for changes to be saved.
	 */
	window.Alpine.data('tallportMailboxIncoming', function (mailbox_id) {
		return {
			init: function () {
				this.showProtocol();
			},
			showProtocol: function () {
				var protocol = this.$el.querySelector('[name="in_protocol"]').value;
				var sections = document.querySelectorAll('[data-in-protocol]');
				var specific = document.querySelectorAll('[data-in-protocol="' + CSS.escape(protocol) + '"]');
				sections.forEach(function (section) {
					var show = specific.length ? section.getAttribute('data-in-protocol') == protocol : section.getAttribute('data-in-protocol') == 'default';
					section.style.display = show ? '' : 'none';
				});
			},
			changed: function (event) {
				if (event.target.name == 'in_protocol') {
					this.showProtocol();
				}
				if (event.target.id == 'after_fetch_action') {
					document.getElementById('after_fetch_folder').classList.toggle('hidden', event.target.value != 'move');
				}
				// Settings have to be saved before checking the connection.
				this.$refs.check.disabled = true;
			},
			checkConnection: function (event) {
				var button = event.currentTarget;
				var log = document.getElementById('fetch_test_log');
				Tallport.busy(button, true);
				log.classList.add('hidden');
				Tallport.post(laroute.route('mailboxes.ajax'), {
					action: 'fetch_test',
					mailbox_id: mailbox_id
				}).then(function (response) {
					if (Tallport.isSuccess(response)) {
						Tallport.toast(Lang.get('messages.connection_established'));
					} else {
						Tallport.result(response);
						if (response.log) {
							log.textContent = response.log;
							log.classList.remove('hidden');
						}
					}
					Tallport.busy(button, false);
				});
			},
			// The server's folders as suggestions in the IMAP Folders field (FruitUI token field).
			retrieveFolders: function (event) {
				var button = event.currentTarget;
				var list = document.querySelector('#in_imap_folders').closest('.f-token-field').querySelector('datalist');
				Tallport.busy(button, true);
				Tallport.post(laroute.route('mailboxes.ajax'), {
					action: 'imap_folders',
					mailbox_id: mailbox_id
				}).then(function (response) {
					list.innerHTML = '';
					(response.folders || []).forEach(function (folder) {
						list.appendChild(new Option(folder, folder));
					});
					Tallport.result(response);
					Tallport.busy(button, false);
				});
			}
		};
	});

	// Empty Trash / Delete All in the Deleted and Spam folders.
	window.Alpine.data('tallportEmptyFolder', function (folder_id, mailbox_id, message, confirm) {
		return {
			empty: function (button) {
				Tallport.confirm({message: message, confirm: confirm, tone: 'danger'}).then(function (ok) {
					if (!ok) {
						return;
					}
					Tallport.busy(button, true);
					Tallport.post(laroute.route('conversations.ajax'), {action: 'empty_folder', folder_id: folder_id, mailbox_id: mailbox_id}).then(function (response) {
						if (Tallport.isSuccess(response)) {
							window.location.reload();
						} else {
							Tallport.busy(button, false);
							Tallport.result(response);
						}
					});
				});
			}
		};
	});

	// A mailbox's Greeting and Auto Reply (mailboxes/partials/message_texts). state: whether the
	// mailbox's auto reply is on (enabled), or its greeting (greeting). Add Language saves what was
	// typed and comes back with the new version open; a channel set to Its Own without a text of its
	// own starts from the mailbox's Default; Translate from Default fills a group's other versions
	// with Default translated, one request per language, after asking. Nothing is kept until the
	// page is saved. Opened from a channel's page (#auto-reply-telegram), that channel's choice has
	// the focus.
	window.Alpine.data('tallportAutomaticMessages', function (translate_url, texts, state) {
		return Object.assign({
			init: function () {
				// The form: called from a channel's section, $root is that section.
				this.page = this.$el;
				var section = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
				var select = section && this.page.contains(section) ? section.querySelector('select') : null;
				if (select) {
					// Once the page shows (Alpine has started all of it).
					setTimeout(function () {
						select.focus();
					});
				}
			},
			version: function (group, language) {
				return this.page.querySelector('details.settings-version[data-group="' + CSS.escape(group) + '"][data-language="' + CSS.escape(language) + '"]');
			},
			// A version's text, and whether it's in the editor (Markdown or HTML) or plain.
			text: function (version) {
				var message = version ? version.querySelector('[data-message]') : null;
				var editor = message ? message.closest('.f-editor') : null;
				return {
					message: message,
					subject: version ? version.querySelector('[data-subject]') : null,
					format: editor ? (editor.getAttribute('data-fruit-mode') == 'markdown' ? 'markdown' : 'html') : 'text'
				};
			},
			fill: function (version, format, message, subject) {
				var target = this.text(version);
				if (target.subject && subject !== undefined && subject !== null) {
					target.subject.value = subject;
					target.subject.dispatchEvent(new Event('input', {bubbles: true}));
				}
				if (target.format != 'text') {
					var detail = {target: target.message.id};
					detail[format == 'markdown' ? 'markdown' : 'html'] = message;
					window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: detail}));
				} else {
					if (format == 'html') {
						message = (new DOMParser()).parseFromString(message.replace(/<br\s*\/?>|<\/p>/gi, '\n'), 'text/html').body.textContent.trim();
					}
					target.message.value = message;
					target.message.dispatchEvent(new Event('input', {bubbles: true}));
				}
				var preview = version.querySelector('summary .f-form-row__value');
				var lines = (new DOMParser()).parseFromString(message.replace(/<br\s*\/?>|<\/p>/gi, '\n'), 'text/html').body.textContent.trim().split('\n');
				if (preview) {
					preview.textContent = lines[0] || '';
				}
			},
			addLanguage: function (item, value) {
				var form = item.closest('form');
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = 'add_language';
				input.value = value;
				form.appendChild(input);
				form.requestSubmit();
			},
			chooseMode: function (select, group) {
				var own = this.text(this.version(group, 'default'));
				var mailbox = this.text(this.version('mailbox', 'default'));
				if (select.value != 'own' || !own.message || own.message.value.trim() || !mailbox.message || !mailbox.message.value.trim()) {
					return;
				}
				this.fill(this.version(group, 'default'), mailbox.format, mailbox.message.value, mailbox.subject ? mailbox.subject.value : null);
			},
			translate: function (button, group) {
				var self = this;
				var source = self.text(self.version(group, 'default'));
				var targets = Array.prototype.filter.call(self.page.querySelectorAll('details.settings-version[data-group="' + CSS.escape(group) + '"]'), function (version) {
					return version.getAttribute('data-language') != 'default';
				});
				if (!source.message || !source.message.value.trim()) {
					Tallport.toast(texts.empty, 'danger');
					return;
				}
				var names = new Intl.ListFormat(document.documentElement.lang || undefined, {type: 'conjunction'}).format(targets.map(function (version) {
					return version.querySelector('summary > span').textContent.trim();
				}));
				Tallport.confirm({message: texts.translate.replace(':languages', names), detail: texts.replaced, confirm: texts.confirm}).then(function (ok) {
					if (!ok) {
						return;
					}
					var failed = null;
					Tallport.busy(button, true);
					Promise.all(targets.map(function (version) {
						var language = version.getAttribute('data-language');
						return Tallport.post(translate_url, {
							message: source.message.value,
							subject: source.subject ? source.subject.value : null,
							format: source.format,
							languages: [language]
						}).then(function (response) {
							var translation = Tallport.isSuccess(response) ? response.translations[language] : null;
							if (translation) {
								self.fill(version, source.format, translation.message, translation.subject);
							} else {
								failed = failed || response;
							}
						});
					})).then(function () {
						Tallport.busy(button, false);
						if (failed) {
							Tallport.result(failed);
						} else {
							Tallport.toast(texts.translated.replace(':languages', names));
						}
					});
				});
			}
		}, state);
	});

	// Mute or unmute a mailbox's notifications (the sidebar's mailbox menu).
	window.Alpine.data('tallportMuteMailbox', function (mailbox_id, muted) {
		return {
			muted: muted,
			busy: false,
			toggle: function () {
				var self = this;
				if (self.busy) {
					return;
				}
				self.busy = true;
				Tallport.busy(self.$el, true);
				Tallport.post(laroute.route('mailboxes.ajax'), {
					action: 'mute',
					mailbox_id: mailbox_id,
					mute: self.muted ? 0 : 1
				}).then(function (response) {
					if (Tallport.isSuccess(response)) {
						self.muted = !self.muted;
					} else {
						Tallport.result(response);
					}
					self.busy = false;
					Tallport.busy(self.$el, false);
				});
			}
		};
	});
});

// A mailbox's settings pages (mailboxes/settings_menu): opened from the list, the focus stays there,
// on the page's row (current now); in a narrow window, where the page takes the list's place, on
// the page's title. Opened from a page's tabs (Email's, mailboxes/email_menu): on the tab.
var settingsFocusFrom = null;
var settingsFocusSource = function (target) {
	if (!target.closest) {
		return null;
	}
	return target.closest('.mailbox-settings a.f-form-row--link') ? 'row' : (target.closest('#connection-settings a') ? 'tab' : null);
};
document.addEventListener('click', function (e) {
	settingsFocusFrom = settingsFocusSource(e.target);
});
// Enter on a row or tab: wire:navigate takes it at keydown (no click follows).
document.addEventListener('keydown', function (e) {
	if (e.key === 'Enter' && settingsFocusSource(e.target)) {
		settingsFocusFrom = settingsFocusSource(e.target);
	}
});
document.addEventListener('livewire:navigated', function () {
	var from = settingsFocusFrom;
	settingsFocusFrom = null;
	if (!from) {
		return;
	}
	var target = from == 'tab'
		? document.querySelector('#connection-settings a[aria-current="page"]')
		: (window.matchMedia('(max-width: 900px)').matches
			? document.querySelector('.mailbox-settings-nav h1')
			: document.querySelector('.mailbox-settings a[aria-current="page"]'));
	if (target) {
		if (target.tagName == 'H1') {
			target.setAttribute('tabindex', '-1');
		}
		target.focus({preventScroll: true});
	}
});
document.addEventListener('livewire:navigated', function () {
	var focus = sessionStorageGet('tallport_mailboxes_focus');
	if (!focus) {
		return;
	}
	sessionStorageSet('tallport_mailboxes_focus', '');
	var target = focus == 'new' ? document.getElementById('mailboxes-new') : document.querySelector('.mailbox-row[data-mailbox_id="'+focus+'"] .mailbox-row__link');
	if (target) {
		target.focus();
	}
});
