/**
 * Realtime updates (Polycast): notifications, who else is viewing or replying
 * to the open conversation, its new messages, assignee and status, the
 * sidebar's folders and the lists.
 */
var poly;
var poly_data_closures = [];

// Polycast's requests: after three failures in a row, a toast; another when they work again.
var fs_connection_errors = 0;

function maybeShowConnectionError()
{
	fs_connection_errors++;
	if (fs_connection_errors == 3) {
		Tallport.toast(Lang.get('messages.lost_connection'), 'danger');
	}
}

function maybeShowConnectionRestored()
{
	if (fs_connection_errors >= 3) {
		Tallport.toast(Lang.get('messages.connection_restored'));
	}
	fs_connection_errors = 0;
}

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

	// After missed events: what could have changed, refreshed (also a page that was
	// prefetched a while before it was shown: tallport.js).
	var catchUp = window.tallportCatchUp = function () {
		if (attr('conversation_id')) {
			Livewire.dispatch('conversation-thread-created');
		}
		if (document.querySelector('.table-conversations') && !document.querySelector('.table-conversations .conv-checkbox:checked')) {
			Livewire.dispatch('conversations-changed');
		}
		if (!document.querySelector('.app-sidebar__folders[data-mailbox_id]')) {
			return;
		}
		fetch(window.location.href, {credentials: 'same-origin', headers: {'X-Requested-With': 'fetch'}}).then(function (response) {
			return response.ok ? response.text() : '';
		}).then(function (html) {
			var page = new DOMParser().parseFromString(html, 'text/html');
			page.querySelectorAll('.app-sidebar__folders[data-mailbox_id]').forEach(function (fresh) {
				var folders = document.querySelector('.app-sidebar__folders[data-mailbox_id="'+fresh.getAttribute('data-mailbox_id')+'"]');
				if (folders) {
					folders.innerHTML = fresh.innerHTML;
				}
			});
			var fresh = page.querySelector('.app-team-chat-link');
			var link = document.querySelector('.app-team-chat-link');
			if (fresh && link) {
				link.replaceWith(fresh);
			}
		}).catch(function () {});
	};

	var connect = function () {
		var user_id = attr('auth_user_id');
		if (!user_id) {
			return;
		}

		// Who is viewing the open conversation: read from the page at each request
		// (wire:navigate changes it without a new connection).
		var had_focus = true;
		poly_data_closures.push(function (data) {
			if (!attr('conversation_id')) {
				return data;
			}
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

		poly = new Polycast(Vars.public_url+'/polycast', {
			token: getCsrfToken(),
			data: poly_data_closures
		});

		// The server keeps events for two minutes (broadcasting delete_old): after a longer
		// gap (sleep, a frozen background tab) some were missed, so the open conversation,
		// the list and the sidebar's folders are brought up to date.
		var last_receive = Date.now();
		poly.on('receive', function () {
			var gap = Date.now() - last_receive;
			last_receive = Date.now();
			if (gap > 90 * 1000) {
				catchUp();
			}
		});
		// A long sleep can outlast the session: "remember me" then starts a new one and
		// the page's token is refused (419) at every poll, so nothing arrives any more.
		// A fresh token from the page, for the polling, Livewire and Tallport.post (the
		// meta); signed out, the page itself (the login).
		var refreshing = false;
		poly.on('failed', function (status) {
			if (status != 419 || refreshing) {
				return;
			}
			refreshing = true;
			fetch(window.location.href, {credentials: 'same-origin', headers: {'X-Requested-With': 'fetch'}}).then(function (response) {
				if (response.redirected) {
					window.location.reload();
					return '';
				}
				return response.ok ? response.text() : '';
			}).then(function (html) {
				var fresh = html && new DOMParser().parseFromString(html, 'text/html').querySelector('meta[name="csrf-token"]');
				var meta = document.querySelector('meta[name="csrf-token"]');
				if (fresh && meta && fresh.getAttribute('content')) {
					meta.setAttribute('content', fresh.getAttribute('content'));
					poly.options.token = fresh.getAttribute('content');
					catchUp();
				}
			}).catch(function () {}).finally(function () {
				refreshing = false;
			});
		});

		var pollIfIdle = function () {
			if (document.visibilityState == 'visible' && Date.now() - last_receive > 30 * 1000) {
				poly.fetchNow();
			}
		};
		document.addEventListener('visibilitychange', pollIfIdle);
		window.addEventListener('online', pollIfIdle);
		window.addEventListener('focus', pollIfIdle);

		// Notifications: in the menu (tallportNotifications) and from the browser.
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

		// The open conversation: new messages, and its assignee and status. Subscribed for
		// each conversation opened, also after wire:navigate (the page changes, the
		// connection stays).
		var subscribed = {};
		var subscribeConversation = function () {
			var conversation_id = attr('conversation_id');
			if (!conversation_id || subscribed[conversation_id]) {
				return;
			}
			subscribed[conversation_id] = true;
			poly.subscribe('conv.'+conversation_id).on('App\\Events\\RealtimeConvNewThread', function (data) {
				if (!data || data.conversation_id != attr('conversation_id') || data.user_id == attr('auth_user_id')) {
					return;
				}
				// A message that couldn't be sent: shown as such (and the conversation active again).
				if (data.send_failed) {
					Livewire.dispatch('conversation-thread-created');
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
			});
		};
		subscribeConversation();
		document.addEventListener('livewire:navigated', subscribeConversation);
		// Opened in place (public/js/conversations.js).
		document.addEventListener('tallport:conversation-opened', subscribeConversation);

		// New messages: the sidebar's folders (of every mailbox) and the conversations list;
		// each mailbox subscribed once, when its folders are first on a page.
		var subscribed_mailboxes = {};
		var subscribeMailboxes = function () {
			document.querySelectorAll('.app-sidebar__folders[data-mailbox_id]').forEach(function (element) {
				if (subscribed_mailboxes[element.getAttribute('data-mailbox_id')]) {
					return;
				}
				subscribed_mailboxes[element.getAttribute('data-mailbox_id')] = true;
				var folders_mailbox_id = element.getAttribute('data-mailbox_id');
				poly.subscribe('mailbox.'+folders_mailbox_id).on('App\\Events\\RealtimeMailboxNewThread', function (data) {
					if (!data || data.mailbox_id != folders_mailbox_id) {
						return;
					}
					// Looked up now: wire:navigate may have replaced the page since.
					var folders = document.querySelector('.app-sidebar__folders[data-mailbox_id="'+folders_mailbox_id+'"]');
					if (folders && data.folders_html) {
						folders.innerHTML = data.folders_html;
						// The open folder stays marked (it may have been opened in place).
						folders.querySelectorAll('a[data-folder_id]').forEach(function (item) {
							if (item.getAttribute('data-folder_id') == attr('folder_id')) {
								item.setAttribute('aria-current', 'page');
							} else {
								item.removeAttribute('aria-current');
							}
						});
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
				}).on('App\\Events\\RealtimeTeamMessage', function (data) {
					if (!data || data.mailbox_id != folders_mailbox_id) {
						return;
					}
					// The open room shows it (and reads it); elsewhere the unread count follows.
					if (attr('team_chat') == folders_mailbox_id) {
						Livewire.dispatch('team-message-created');
						return;
					}
					var link = document.querySelector('.app-team-chat-link');
					var badge = link && link.querySelector('.app-team-chat-badge');
					if (badge && typeof data.unread != 'undefined') {
						badge.textContent = data.unread;
						badge.hidden = !data.unread;
						if (data.unread) {
							link.setAttribute('aria-label', link.getAttribute('data-label-unread').replace(':count', data.unread));
						} else {
							link.removeAttribute('aria-label');
						}
					}
				});
			});
		};
		subscribeMailboxes();
		document.addEventListener('livewire:navigated', subscribeMailboxes);
	};

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
