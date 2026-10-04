/**
 * Mailbox settings: the settings form, deleting a mailbox, the connection
 * pages (sending method, test email, protocol, IMAP folders, test connection)
 * and muting a mailbox from the sidebar.
 */
document.addEventListener('alpine:init', function () {
	/**
	 * Mailbox settings form: the custom From name, and the email header
	 * that is filled in with its default when switched on.
	 */
	window.Alpine.data('tallportMailboxUpdate', function (from_name_custom) {
		return {
			init: function () {
				fsDoAction('mailbox.update_init');
			},
			fromNameChanged: function (event) {
				var container = document.getElementById('from_name_custom_container');
				if (container) {
					container.classList.toggle('hidden', event.target.value != from_name_custom);
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
	window.Alpine.data('tallportDeleteMailbox', function (mailbox_id) {
		return {
			remove: function (event) {
				var button = event.submitter || this.$el.querySelector('[type="submit"]');
				var password = this.$el.querySelector('input[type="password"]');
				Tallport.busy(button, true);
				Tallport.post(laroute.route('mailboxes.ajax'), {
					action: 'delete_mailbox',
					mailbox_id: mailbox_id,
					password: password ? password.value : undefined
				}).then(function (response) {
					if (Tallport.isSuccess(response)) {
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
			retrieveFolders: function (event) {
				var button = event.currentTarget;
				var select = document.getElementById('in_imap_folders');
				Tallport.busy(button, true);
				Tallport.post(laroute.route('mailboxes.ajax'), {
					action: 'imap_folders',
					mailbox_id: mailbox_id
				}).then(function (response) {
					var added = false;
					(response.folders || []).forEach(function (folder) {
						var exists = Array.prototype.some.call(select.options, function (option) {
							return option.value == folder;
						});
						if (!exists) {
							select.add(new Option(folder, folder, true, true));
							added = true;
						}
					});
					if (added) {
						// Shows the new folders in the select2 control too.
						select.dispatchEvent(new Event('change', {bubbles: true}));
					}
					Tallport.result(response);
					Tallport.busy(button, false);
				});
			}
		};
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
