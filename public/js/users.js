/**
 * Users: the profile's actions (invite, password reset, photo, deleting the
 * user), the notifications table, and passkeys (adding one on the security
 * page, signing in with one on the login page).
 */
document.addEventListener('alpine:init', function () {
	/**
	 * A user's profile, and the user setup wizard (photo only). The user is
	 * the page's data-user_id.
	 */
	window.Alpine.data('tallportUserProfile', function () {
		return {
			post: function (action, data) {
				var form = data || new FormData();
				form.append('action', action);
				if (document.body.dataset.user_id) {
					form.append('user_id', document.body.dataset.user_id);
				}
				return Tallport.post(laroute.route('users.ajax'), form);
			},
			// Send or re-send the invite.
			sendInvite: function (button, is_resend) {
				Tallport.busy(button, true);
				this.post('send_invite').then(function (response) {
					if (Tallport.isSuccess(response)) {
						Tallport.toast(Lang.get(is_resend ? 'messages.invite_resent' : 'messages.invite_sent'));
					} else {
						Tallport.result(response);
					}
					Tallport.busy(button, false);
				});
			},
			resetPassword: function (button) {
				var self = this;
				Tallport.confirm({message: Lang.get('messages.confirm_reset_password'), confirm: button.textContent.trim()}).then(function (ok) {
					if (!ok) {
						return;
					}
					Tallport.busy(button, true);
					self.post('reset_password').then(function (response) {
						Tallport.result(response);
						Tallport.busy(button, false);
					});
				});
			},
			deletePhoto: function (button) {
				var self = this;
				Tallport.confirm({message: Lang.get('messages.confirm_delete_photo'), confirm: Lang.get('messages.delete'), tone: 'danger'}).then(function (ok) {
					if (!ok) {
						return;
					}
					Tallport.busy(button, true);
					self.post('delete_photo').then(function () {
						var photo = document.getElementById('user-profile-photo');
						if (photo) {
							photo.remove();
						}
					});
				});
			},
			// The delete dialog's form: who gets the user's conversations, per mailbox.
			deleteUser: function (form) {
				this.$dispatch('fruit-dialog-close', {name: 'delete-user'});
				this.post('delete_user', new FormData(form)).then(function (response) {
					if (Tallport.isSuccess(response)) {
						window.location.href = laroute.route('users');
					} else {
						Tallport.result(response);
					}
				});
			}
		};
	});

	/**
	 * The notifications table (users/subscriptions_table): a column's "all"
	 * checkbox, and on the user's own profile, the browser's permission for
	 * push notifications.
	 */
	window.Alpine.data('tallportSubscriptions', function () {
		return {
			init: function () {
				var self = this;
				this.$el.addEventListener('click', function (e) {
					var checkbox = e.target;
					if (checkbox.tagName != 'INPUT' || checkbox.type != 'checkbox') {
						return;
					}
					if (checkbox.classList.contains('sel-all')) {
						self.selectAll(checkbox);
					}
					if (document.body.dataset.own_profile && (checkbox.matches('.sel-all[value="browser"]') || checkbox.closest('.subscriptions-browser'))) {
						self.browserPermission(checkbox);
					}
				});
			},
			selectAll: function (checkbox) {
				if (checkbox.checked && checkbox.value == 'browser' && !Push.Permission.has()) {
					return;
				}
				this.$el.querySelectorAll('.subscriptions-' + checkbox.value + ' input').forEach(function (input) {
					input.checked = checkbox.checked;
				});
			},
			browserPermission: function (checkbox) {
				if (!checkbox.checked) {
					return;
				}
				if (location.protocol != 'https:') {
					Tallport.toast(Lang.get('messages.push_protocol_alert'), 'danger');
					checkbox.checked = false;
					return;
				}
				if (!Push.Permission.has()) {
					var self = this;
					Push.Permission.request(function () {}, function () {
						self.$dispatch('fruit-dialog-open', {name: 'enable-push'});
						checkbox.checked = false;
					});
				}
			}
		};
	});

	/**
	 * Passkeys (WebAuthn). The server (laravel/passkeys) sends and expects the
	 * standard WebAuthn JSON, binary values in base64url.
	 */
	window.Alpine.data('tallportPasskeyAdd', function (options_url, store_url) {
		return {
			add: function (form) {
				if (!passkeys.supported()) {
					Tallport.toast(Lang.get('messages.passkeys_unsupported'), 'danger');
					return;
				}
				var button = form.querySelector('button');
				var name = form.querySelector('#passkey-name').value;
				Tallport.busy(button, true);

				passkeys.request('GET', options_url).then(function (response) {
					var options = PublicKeyCredential.parseCreationOptionsFromJSON
						? PublicKeyCredential.parseCreationOptionsFromJSON(response.options)
						: passkeys.decodeOptions(response.options);
					return navigator.credentials.create({publicKey: options}).then(function (credential) {
						return passkeys.request('POST', store_url, {name: name, credential: passkeys.encodeCredential(credential)}).then(function () {
							window.location.reload();
						});
					}, function () {
						// Cancelled in the browser.
						Tallport.busy(button, false);
					});
				}).catch(function () {
					passkeys.error();
					Tallport.busy(button, false);
				});
			}
		};
	});

	window.Alpine.data('tallportPasskeyLogin', function (options_url, login_url) {
		return {
			supported: passkeys.supported(),
			login: function (button) {
				var remember = button.closest('form').querySelector('input[name="remember"]');
				Tallport.busy(button, true);

				passkeys.request('GET', options_url).then(function (response) {
					var options = PublicKeyCredential.parseRequestOptionsFromJSON
						? PublicKeyCredential.parseRequestOptionsFromJSON(response.options)
						: passkeys.decodeOptions(response.options);
					return navigator.credentials.get({publicKey: options}).then(function (credential) {
						return passkeys.request('POST', login_url, {
							credential: passkeys.encodeCredential(credential),
							remember: !!(remember && remember.checked)
						}).then(function (result) {
							window.location.href = result.redirect;
						});
					}, function () {
						Tallport.busy(button, false);
					});
				}).catch(function () {
					passkeys.error();
					Tallport.busy(button, false);
				});
			}
		};
	});

	var passkeys = {
		supported: function () {
			return !!(window.PublicKeyCredential && navigator.credentials);
		},
		b64ToBuffer: function (value) {
			var base64 = value.replace(/-/g, '+').replace(/_/g, '/');
			while (base64.length % 4) {
				base64 += '=';
			}
			var binary = atob(base64);
			var bytes = new Uint8Array(binary.length);
			for (var i = 0; i < binary.length; i++) {
				bytes[i] = binary.charCodeAt(i);
			}
			return bytes.buffer;
		},
		bufferToB64: function (buffer) {
			var bytes = new Uint8Array(buffer);
			var binary = '';
			for (var i = 0; i < bytes.length; i++) {
				binary += String.fromCharCode(bytes[i]);
			}
			return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
		},
		// Browsers without PublicKeyCredential.parse*OptionsFromJSON().
		decodeOptions: function (options) {
			options = JSON.parse(JSON.stringify(options));
			options.challenge = passkeys.b64ToBuffer(options.challenge);
			if (options.user && options.user.id) {
				options.user.id = passkeys.b64ToBuffer(options.user.id);
			}
			['excludeCredentials', 'allowCredentials'].forEach(function (key) {
				(options[key] || []).forEach(function (credential) {
					credential.id = passkeys.b64ToBuffer(credential.id);
				});
			});
			return options;
		},
		// Browsers without credential.toJSON().
		encodeCredential: function (credential) {
			if (typeof credential.toJSON == 'function') {
				return credential.toJSON();
			}
			var response = {};
			['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle'].forEach(function (key) {
				if (credential.response[key]) {
					response[key] = passkeys.bufferToB64(credential.response[key]);
				}
			});
			if (typeof credential.response.getTransports == 'function') {
				response.transports = credential.response.getTransports();
			}
			return {
				id: credential.id,
				rawId: passkeys.bufferToB64(credential.rawId),
				type: credential.type,
				response: response,
				authenticatorAttachment: credential.authenticatorAttachment || null,
				clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {}
			};
		},
		// JSON in and out; rejects when the server refuses.
		request: function (method, url, data) {
			return fetch(url, {
				method: method,
				body: data ? JSON.stringify(data) : null,
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': Tallport.csrf(), 'Accept': 'application/json'}
			}).then(function (response) {
				if (!response.ok) {
					throw new Error(response.status);
				}
				return response.json();
			});
		},
		error: function () {
			Tallport.toast(Lang.get('messages.passkey_failed'), 'danger');
		}
	};
});
