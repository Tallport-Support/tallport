/**
 * Manage screens: settings (test email, AI customer context test), System
 * Status (updates, failed job details) and Modules.
 */
document.addEventListener('alpine:init', function () {
	// Settings » Mail Settings: the test email, and the log when it fails.
	window.Alpine.data('tallportMailSettings', function () {
		return {
			log: '',
			sendTest: function (event) {
				var self = this;
				var button = event.currentTarget;
				Tallport.busy(button, true);
				this.log = '';
				Tallport.post(laroute.route('settings.ajax'), {
					action: 'send_test',
					to: document.getElementById('send_test').value
				}).then(function (response) {
					if (Tallport.isSuccess(response)) {
						Tallport.toast(Lang.get('messages.email_sent'));
					} else {
						Tallport.result(response);
						self.log = response.log || '';
					}
					Tallport.busy(button, false);
				});
			}
		};
	});

	// Settings » AI Assistant: a mailbox's customer context, sent a test request with the settings shown.
	window.Alpine.data('tallportAiContextTest', function (mailbox_id) {
		return {
			result: '',
			test: function (event) {
				var self = this;
				var button = event.currentTarget;
				var value = function (selector) {
					return self.$root.querySelector(selector).value;
				};
				Tallport.busy(button, true);
				Tallport.post(laroute.route('ai.customer_context.test'), {
					mailbox_id: mailbox_id,
					email: value('.ai-context-test-email'),
					url: value('.ai-context-url'),
					secret_key: value('.ai-context-secret'),
					signature_header: value('.ai-context-header')
				}).then(function (response) {
					Tallport.busy(button, false);
					self.result = Tallport.isSuccess(response)
						? 'HTTP ' + response.http_status + '\n' + response.signature_header + ': ' + response.signature + '\n\n' + response.body
						: (response.msg || Lang.get('messages.error_occurred'));
				});
			}
		};
	});

	// System » Status: the protocol, updating the app, checking for updates, failed jobs' details.
	window.Alpine.data('tallportSystemStatus', function () {
		return {
			https: location.protocol == 'https:',
			update: function (event) {
				var button = event.currentTarget;
				Tallport.confirm({message: Lang.get('messages.confirm_update'), confirm: Lang.get('messages.update'), tone: 'danger'}).then(function (ok) {
					if (!ok) {
						return;
					}
					Tallport.busy(button, true);
					// Not to receive 'Internet connection broken' messages while updating.
					if (typeof(poly) != 'undefined' && poly) {
						poly.disconnect();
					}
					Tallport.post(laroute.route('system.ajax'), {action: 'update'}).then(function (response) {
						if (Tallport.isSuccess(response)) {
							Tallport.result(response);
							window.location.href = '';
						} else {
							Tallport.toast(response.msg || htmlDecode(Lang.get('messages.error_occurred_updating')), 'danger');
							Tallport.busy(button, false);
						}
					});
				});
			},
			checkUpdates: function (event) {
				var button = event.currentTarget;
				Tallport.busy(button, true);
				Tallport.post(laroute.route('system.ajax'), {action: 'check_updates'}).then(function (response) {
					if (Tallport.isSuccess(response) && response.new_version_available) {
						window.location.href = '';
						return;
					}
					Tallport.result(response);
					Tallport.busy(button, false);
				});
			}
		};
	});

	// Modules: activating, installing, updating and deleting modules, and their licenses.
	window.Alpine.data('tallportModules', function () {
		// A modules.ajax request; the page reloads when it is done.
		var request = function (button, data, reload_on) {
			Tallport.busy(button, true);
			Tallport.post(laroute.route('modules.ajax'), data).then(function (response) {
				if (Tallport.isSuccess(response) || (reload_on && reload_on(response))) {
					window.location.href = '';
				} else {
					Tallport.result(response);
					Tallport.busy(button, false);
				}
			});
		};
		var alias = function (button) {
			return button.closest('.module-card').getAttribute('data-alias');
		};

		return {
			// activate, deactivate, update
			action: function (event, action) {
				request(event.currentTarget, {action: action, alias: alias(event.currentTarget)});
			},
			remove: function (event) {
				var button = event.currentTarget;
				Tallport.confirm({message: Lang.get('messages.confirm_delete_module'), confirm: Lang.get('messages.delete'), tone: 'danger'}).then(function (ok) {
					if (ok) {
						request(button, {action: 'delete', alias: alias(button)});
					}
				});
			},
			updateAll: function (event) {
				var aliases = Array.from(document.querySelectorAll('#new_versions_list a[data-module-alias]')).map(function (link) {
					return link.getAttribute('data-module-alias');
				});
				request(event.currentTarget, {action: 'update_all', aliases: aliases});
			},
			// Install, or activate the license of an installed module.
			install: function (event) {
				var form = event.currentTarget;
				var button = form.querySelector('.install-trigger');
				request(button, {
					action: button.getAttribute('data-action'),
					alias: form.getAttribute('data-module-alias'),
					license: form.querySelector('.license-key').value
				}, function (response) {
					return !!response.reload;
				});
			},
			// The license of a module: deactivated for this domain.
			deactivateLicense: function (event) {
				var button = event.currentTarget;
				request(button, {
					action: 'deactivate_license',
					alias: alias(button),
					license: button.closest('.module-card').querySelector('.license-key-text').textContent
				});
			},
			// The Deactivate License dialog: any module's license, on any domain.
			deactivateAnyLicense: function (event) {
				var form = event.currentTarget;
				request(event.submitter || form.querySelector('[type="submit"]'), {
					action: 'deactivate_license',
					alias: form.querySelector('.deactivate-license-module').value,
					license: form.querySelector('.deactivate-license-key').value,
					any_url: 1
				});
			}
		};
	});
});
