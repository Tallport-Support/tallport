/**
 * Realtime updates (Polycast): notifications, who else is viewing or replying
 * to the open conversation, its new messages, assignee and status, the
 * sidebar's folders and the lists, and chats, with their sound.
 */
var poly;
var poly_data_closures = [];

(function () {
	var attr = function (name) {
		return document.body.getAttribute('data-'+name);
	};

	// The tab's title blinks until the window gets focus (a new message in the open conversation).
	var title_timer = null;
	var blinkTitle = function (text) {
		if (document.hasFocus() || title_timer) {
			return;
		}
		var original = document.title;
		var shown = false;
		title_timer = setInterval(function () {
			document.title = shown ? original : text;
			shown = !shown;
		}, 600);
		window.addEventListener('focus', function () {
			clearInterval(title_timer);
			title_timer = null;
			document.title = original;
		}, {once: true});
	};

	// A chat message's sound, once across the user's tabs.
	var sound_blocked = false;
	var playSound = function () {
		var audio = new Audio(Vars.public_url+'/audio/chat.mp3');
		audio.play().catch(function (error) {
			if (error.name === 'NotAllowedError' && !sound_blocked && !document.querySelector('dialog[open]')) {
				sound_blocked = true;
				Tallport.confirm({message: Lang.get('messages.autoplay')}).then(function (ok) {
					if (ok) {
						audio.play();
					}
				});
			}
		});
	};
	window.playAudioNotification = function (data) {
		if (!data || !data.audio || !data.audio.thread_id) {
			return;
		}
		var thread_id = data.audio.thread_id+'';
		var now = Math.floor(Date.now() / 1000);
		var played = localStorageGetObject('audio_notifications') || {};
		if (!(thread_id in played)) {
			played[thread_id] = now;
			playSound();
		}
		Object.keys(played).forEach(function (key) {
			if (parseInt(played[key]) < now - 3600) {
				delete played[key];
			}
		});
		localStorageSetObject('audio_notifications', played);
	};

	// Who else is viewing (or replying to) the open conversation, replying first.
	var viewer = function (data) {
		var viewers = document.getElementById('conv-viewers');
		if (!viewers) {
			return;
		}
		var item = viewers.querySelector('.viewer-'+data.user_id);
		if (!item) {
			item = document.createElement('span');
			item.className = 'viewer-'+data.user_id;
			var photo;
			if (data.user_photo_url) {
				photo = document.createElement('img');
				photo.src = data.user_photo_url;
			} else {
				photo = document.createElement('i');
				photo.className = 'person-photo-auto';
				photo.setAttribute('data-initial', data.user_initials || '');
			}
			photo.classList.add('person-photo');
			item.appendChild(photo);
			viewers.prepend(item);
		}
		item.hidden = false;
		item.classList.toggle('viewer-replying', !!data.replying);
		item.title = Lang.get(data.replying ? 'messages.user_replying' : 'messages.user_viewing', {user: data.user_name});
		Array.prototype.slice.call(viewers.querySelectorAll('.viewer-replying')).reverse().forEach(function (replying) {
			viewers.prepend(replying);
		});
	};

	// The toolbar's assignee and status menus after someone else changed them.
	var flash = function (element) {
		if (element) {
			element.classList.remove('is-updated');
			void element.offsetWidth;
			element.classList.add('is-updated');
		}
	};
	var menuChanged = function (menu_id, selector, value, label_class) {
		var menu = document.getElementById(menu_id);
		var item = menu ? menu.querySelector('['+selector+'="'+value+'"]') : null;
		if (!item || item.getAttribute('aria-current') == 'true') {
			return false;
		}
		menu.querySelectorAll('['+selector+'][aria-current]').forEach(function (current) {
			current.classList.remove('active');
			current.removeAttribute('aria-current');
		});
		item.classList.add('active');
		item.setAttribute('aria-current', 'true');
		var label = menu.querySelector('.conv-info-val span');
		if (label) {
			label.textContent = item.textContent.trim();
		}
		flash(menu);
		return true;
	};

	var connect = function () {
		var user_id = attr('auth_user_id');
		if (!user_id) {
			return;
		}

		if (attr('conversation_id')) {
			var had_focus = true;
			poly_data_closures.push(function (data) {
				data.replying = getReplyFormMode() == 'reply' ? 1 : 0;
				if (!had_focus && document.hasFocus()) {
					data.conversation_view_focus = 1;
				}
				had_focus = document.hasFocus();
				// The conversation_id says the user is viewing the conversation.
				if (document.hasFocus() || data.replying) {
					data.conversation_id = attr('conversation_id');
				}
				return data;
			});
		}

		poly = new Polycast(Vars.public_url+'/polycast', {
			token: getCsrfToken(),
			data: poly_data_closures
		});

		// Notifications: in the menu (tallportNotifications), from the browser, and a chat's sound.
		poly.subscribe('private-App.User.'+user_id).on('App\\Events\\RealtimeBroadcastNotificationCreated', function (data, event) {
			if (!event.data) {
				return;
			}
			if (event.data.web && event.data.web.html) {
				window.dispatchEvent(new CustomEvent('tallport-notification', {detail: {html: event.data.web.html}}));
			}
			if (event.data.browser && event.data.browser.text) {
				var url = event.data.browser.url;
				Push.create(event.data.browser.text, {
					body: '',
					icon: Vars.public_url+'/img/logo-icon-white-300.png',
					tag: url,
					timeout: 5000,
					onClick: function () {
						if (url) {
							window.open(url, '_blank').focus();
							this.close();
						}
					}
				});
			}
			playAudioNotification(event.data);
		});

		var conv = poly.subscribe('conv');
		conv.on('App\\Events\\RealtimeConvView', function (data) {
			if (data && data.conversation_id == attr('conversation_id') && data.user_id != attr('auth_user_id')) {
				viewer(data);
			}
		});
		conv.on('App\\Events\\RealtimeConvViewFinish', function (data) {
			if (data && data.conversation_id == attr('conversation_id') && data.user_id != attr('auth_user_id')) {
				var item = document.querySelector('#conv-viewers .viewer-'+data.user_id);
				if (item) {
					item.hidden = true;
				}
			}
		});

		// The open conversation: new messages, and its assignee and status.
		var conversation_id = attr('conversation_id');
		if (conversation_id) {
			poly.subscribe('conv.'+conversation_id).on('App\\Events\\RealtimeConvNewThread', function (data) {
				if (!data || data.conversation_id != attr('conversation_id') || data.user_id == attr('auth_user_id')) {
					return;
				}
				if (data.thread_html && !document.getElementById('thread-'+data.thread_id)) {
					Livewire.dispatch('conversation-thread-created');
					blinkTitle('✉ '+Lang.get('messages.new_message'));
				}
				if (data.conversation_user_id) {
					menuChanged('conv-assignee', 'data-user_id', data.conversation_user_id);
				}
				if (data.conversation_status && menuChanged('conv-status', 'data-status', data.conversation_status) && data.conversation_status_class) {
					var tones = {success: 'success', info: 'accent', warning: 'warning', danger: 'danger'};
					var dot = document.querySelector('#conv-status .conv-status-dot');
					if (dot) {
						dot.className = 'f-badge conv-status-dot f-badge--'+(tones[data.conversation_status_class] || 'neutral');
					}
				}
				playAudioNotification(data);
			});
		}

		// New messages: the sidebar's folders (of every mailbox) and the conversations list.
		if (!document.body.classList.contains('chat-mode')) {
			document.querySelectorAll('.app-sidebar__folders[data-mailbox_id]').forEach(function (element) {
				var folders_mailbox_id = element.getAttribute('data-mailbox_id');
				poly.subscribe('mailbox.'+folders_mailbox_id).on('App\\Events\\RealtimeMailboxNewThread', function (data) {
					if (!data || data.mailbox_id != folders_mailbox_id) {
						return;
					}
					// Looked up now: wire:navigate may have replaced the page since.
					var folders = document.querySelector('.app-sidebar__folders[data-mailbox_id="'+folders_mailbox_id+'"]');
					if (folders && data.folders_html) {
						folders.innerHTML = data.folders_html;
						// The open folder's number of active conversations in the page title.
						var current = folders.querySelector(':scope > [aria-current="page"]');
						if (current && !attr('conversation_id')) {
							var count = parseInt(current.getAttribute('data-active-count'));
							document.title = (count > 0 ? '('+count+') ' : '')+document.title.replace(/^\(\d+\) /, '');
						}
					}
					// The list of this mailbox, or of All Mailboxes; not while conversations are selected.
					var list = document.querySelector('.table-conversations');
					var list_mailbox_id = (list && list.getAttribute('data-mailbox_id')) || attr('mailbox_id');
					if (list && (list_mailbox_id == folders_mailbox_id || parseInt(list_mailbox_id) < 0) && !list.querySelector('.conv-checkbox:checked')) {
						Livewire.dispatch('conversations-changed');
					}
					playAudioNotification(data);
				});
			});
		}

		// Chat mode: the mailbox's chats.
		var chats = document.querySelector('#folders.chat-list');
		var mailbox_id = attr('mailbox_id');
		if (chats && mailbox_id) {
			poly.subscribe('chat.'+mailbox_id).on('App\\Events\\RealtimeChat', function (data) {
				if (!data) {
					return;
				}
				playAudioNotification(data);
				if (data.mailbox_id == mailbox_id && data.chats_html) {
					chats.innerHTML = data.chats_html;
					var current = chats.querySelector('[data-chat_id="'+attr('conversation_id')+'"] .f-item-row');
					if (current) {
						current.setAttribute('aria-current', 'true');
					}
				}
			});
		}
	};

	// Chat mode: more chats.
	document.addEventListener('click', function (e) {
		var button = e.target.closest('.chats-load-more');
		if (!button) {
			return;
		}
		e.preventDefault();
		Tallport.busy(button, true);
		Tallport.post(laroute.route('conversations.ajax'), {
			action: 'chats_load_more',
			mailbox_id: attr('mailbox_id'),
			offset: document.querySelectorAll('#folders .chat-item').length
		}).then(function (response) {
			if (Tallport.result(response)) {
				button.closest('li').insertAdjacentHTML('beforebegin', response.html);
				button.closest('li').remove();
			} else {
				Tallport.busy(button, false);
			}
		});
	});

	document.addEventListener('DOMContentLoaded', connect);

	/**
	 * The sidebar's notifications: new ones as they come, more on request, all read.
	 */
	document.addEventListener('alpine:init', function () {
		window.Alpine.data('tallportNotifications', function (unread) {
			return {
				unread: unread,
				page: 2,
				init: function () {
					var self = this;
					window.addEventListener('tallport-notification', function (event) {
						var list = self.$root.querySelector('.web-notifications-list');
						var empty = list.querySelector('.web-notifications-empty');
						if (empty) {
							empty.remove();
						}
						list.insertAdjacentHTML('afterbegin', event.detail.html);
						// One heading per day.
						var first = list.querySelector('.web-notification-date');
						if (first) {
							list.querySelectorAll('.web-notification-date[data-date="'+first.getAttribute('data-date')+'"]').forEach(function (date, i) {
								if (i > 0) {
									date.remove();
								}
							});
						}
						self.unread++;
					});
				},
				more: function (button) {
					var self = this;
					Tallport.busy(button, true);
					Tallport.post(laroute.route('users.ajax'), {action: 'web_notifications', wn_page: this.page}).then(function (response) {
						Tallport.busy(button, false);
						if (Tallport.isSuccess(response)) {
							button.closest('li').insertAdjacentHTML('beforebegin', response.html);
							self.page++;
						} else {
							Tallport.result(response);
						}
						if (!response.has_more_pages) {
							button.closest('li').remove();
						}
					});
				},
				markRead: function (button) {
					var self = this;
					Tallport.busy(button, true);
					Tallport.post(laroute.route('users.ajax'), {action: 'mark_notifications_as_read'}).then(function (response) {
						Tallport.busy(button, false);
						if (Tallport.result(response)) {
							self.unread = 0;
							self.$root.querySelectorAll('.web-notification.is-unread').forEach(function (item) {
								item.classList.remove('is-unread');
							});
						}
					});
				}
			};
		});
	});
})();
