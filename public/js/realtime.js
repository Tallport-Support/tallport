/**
 * Realtime updates (Polycast): notifications, who else is viewing or replying
 * to a conversation (in its list row, and the open one's heading and typing
 * line), the open conversation's new messages, assignee and status, the
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
	var setTeamChatUnread = function (unread) {
		var link = document.querySelector('.app-team-chat-link');
		var badge = link && link.querySelector('.app-team-chat-badge');
		if (!badge || typeof unread == 'undefined') {
			return;
		}
		badge.textContent = unread;
		badge.hidden = !unread;
		if (unread) {
			link.setAttribute('aria-label', link.getAttribute('data-label-unread').replace(':count', unread));
		} else {
			link.removeAttribute('aria-label');
		}
	};
	window.addEventListener('team-chat-unread', function (event) {
		setTeamChatUnread(event.detail.unread);
	});

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

	// Who else has a conversation open (App\Events\RealtimeConvView, ...Finish), replying first:
	// in its list row (conversations/partials/presence), and for the open one in its heading
	// (presence_line) and the line above its composer (typing_line). Each keeps its viewers in
	// data-viewers, from the page and then from these events.
	var decode = function (text) {
		var area = document.createElement('textarea');
		area.innerHTML = text || '';
		return area.value;
	};
	var viewersOf = function (element) {
		try {
			return JSON.parse(element.getAttribute('data-viewers') || '[]');
		} catch (e) {
			return [];
		}
	};
	var withViewer = function (viewers, data, finished) {
		viewers = viewers.filter(function (viewer) {
			return viewer.id != data.user_id;
		});
		if (!finished) {
			viewers.push({
				id: data.user_id, first: decode(data.user_first_name || data.user_name), name: decode(data.user_name),
				initials: decode(data.user_initials), photo: data.user_photo_url || '', replying: !!data.replying
			});
		}
		return viewers.sort(function (a, b) {
			return (b.replying ? 1 : 0) - (a.replying ? 1 : 0);
		});
	};
	var presenceText = function (viewers, full) {
		return viewers.map(function (viewer) {
			return Lang.get(viewer.replying ? 'messages.user_replying' : 'messages.user_viewing', {user: full ? viewer.name : viewer.first});
		}).join(full ? ', ' : ' · ');
	};
	var showViewers = function (element, viewers) {
		element.setAttribute('data-viewers', JSON.stringify(viewers));
		element.toggleAttribute('data-replying', viewers.some(function (viewer) { return viewer.replying; }));
		element.hidden = !viewers.length;
	};
	var presence = function (data, finished) {
		// Its list row ("Noah is replying · Mia is viewing"), its last line made if it had none.
		document.querySelectorAll('.conv-row[data-conversation_id="'+data.conversation_id+'"] .conv-row__link').forEach(function (link) {
			var element = link.querySelector('.conv-presence');
			var viewers = withViewer(element ? viewersOf(element) : [], data, finished);
			var template = document.getElementById('conv-presence-template');
			if (!element) {
				if (!viewers.length || !template) {
					return;
				}
				element = template.content.firstElementChild.cloneNode(true);
				var meta = link.querySelector('.f-item-row__meta');
				if (!meta) {
					meta = document.createElement('span');
					meta.className = 'f-item-row__meta conv-row__meta';
					(link.querySelector('.f-item-row__preview') || link.querySelector('.f-item-row__subtitle') || link.querySelector('.f-item-row__top')).after(meta);
				}
				meta.appendChild(element);
			}
			showViewers(element, viewers);
			element.querySelector('.conv-presence__text').textContent = presenceText(viewers, false);
			// A last line with nothing else on it goes with them.
			var meta = element.parentElement;
			meta.hidden = !viewers.length && !Array.prototype.some.call(meta.children, function (child) { return child !== element && !child.hidden; });
		});
		if (data.conversation_id != attr('conversation_id')) {
			return;
		}
		// The open conversation's heading: their faces and full names.
		var line = document.getElementById('conv-presence');
		if (!line) {
			return;
		}
		var viewers = withViewer(viewersOf(line), data, finished);
		showViewers(line, viewers);
		var faces = line.querySelector('.conv-presence-line__faces');
		faces.textContent = '';
		viewers.forEach(function (viewer) {
			var face = document.createElement('span');
			face.className = 'f-avatar conv-presence-line__face';
			if (viewer.photo) {
				var photo = document.createElement('img');
				photo.src = viewer.photo;
				photo.alt = '';
				face.appendChild(photo);
			} else {
				face.textContent = viewer.initials;
			}
			faces.appendChild(face);
		});
		line.querySelector('.conv-presence-line__text').textContent = presenceText(viewers, true);
		// Above the composer: who is writing a reply.
		var typing = document.getElementById('conv-typing');
		var writer = viewers.filter(function (viewer) { return viewer.replying; })[0];
		if (typing) {
			typing.textContent = '';
			if (writer) {
				var dots = document.createElement('span');
				dots.className = 'f-typing__dots';
				dots.setAttribute('aria-hidden', 'true');
				dots.innerHTML = '<span></span><span></span><span></span>';
				typing.append(dots, Lang.get('messages.user_writing_reply', {user: writer.name}));
			}
		}
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
		// A checked choice (the status) or the current item (the assignee).
		var radio = item && item.getAttribute('role') == 'menuitemradio';
		if (!item || item.getAttribute(radio ? 'aria-checked' : 'aria-current') == 'true') {
			return false;
		}
		if (radio) {
			menu.querySelectorAll('['+selector+'][role="menuitemradio"]').forEach(function (choice) {
				choice.setAttribute('aria-checked', 'false');
			});
			item.setAttribute('aria-checked', 'true');
		} else {
			menu.querySelectorAll('['+selector+'][aria-current]').forEach(function (current) {
				current.classList.remove('active');
				current.removeAttribute('aria-current');
			});
			item.classList.add('active');
			item.setAttribute('aria-current', 'true');
		}
		var label = menu.querySelector('.conv-info-val span');
		if (label) {
			label.textContent = item.textContent.trim();
		}
		flash(menu);
		return true;
	};

	var markCurrentFolder = function () {
		document.querySelectorAll('.app-sidebar__folders a[data-folder_id]').forEach(function (item) {
			if (item.getAttribute('data-folder_id') == attr('folder_id')) {
				item.setAttribute('aria-current', 'page');
				// Spam or Deleted: their More group open.
				var more = item.closest('.app-sidebar__more');
				if (more) {
					more.open = true;
				}
			} else {
				item.removeAttribute('aria-current');
			}
		});
	};

	// A mailbox's folders, fresh from the server; More stays open if it was.
	var replaceFolders = function (folders, html) {
		var open = !!folders.querySelector('.app-sidebar__more[open]');
		folders.innerHTML = html;
		var more = folders.querySelector('.app-sidebar__more');
		if (more && open) {
			more.open = true;
		}
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
			if (!page.querySelector('.app-sidebar__folders[data-mailbox_id]')) {
				return;
			}
			page.querySelectorAll('.app-sidebar__folders[data-mailbox_id]').forEach(function (fresh) {
				var folders = document.querySelector('.app-sidebar__folders[data-mailbox_id="'+fresh.getAttribute('data-mailbox_id')+'"]');
				if (folders) {
					replaceFolders(folders, fresh.innerHTML);
				}
			});
			var counts = {folders: {}, mailboxes: {}, for_you: {folders: {}, mailboxes: {}}};
			page.querySelectorAll('.app-folder-link[data-folder_id]').forEach(function (link) {
				counts.folders[link.getAttribute('data-folder_id')] = parseInt(link.getAttribute('data-unread-count') || 0);
				counts.for_you.folders[link.getAttribute('data-folder_id')] = link.getAttribute('data-for-you') == '1';
			});
			page.querySelectorAll('.app-sidebar__mailbox[data-mailbox_id]').forEach(function (group) {
				var summary = group.querySelector('summary');
				counts.mailboxes[group.getAttribute('data-mailbox_id')] = parseInt(summary.getAttribute('data-unread-count') || 0);
				counts.for_you.mailboxes[group.getAttribute('data-mailbox_id')] = summary.getAttribute('data-for-you') == '1';
			});
			markCurrentFolder();
			window.tallportSidebarCounts(counts);
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
		// (wire:navigate changes it without a new connection). Writing a reply: typing its message
		// (a reply, not a note; not To or Cc) in the last REPLYING_MS, or a message there not sent
		// yet, the reply template alone not counting; an open composer alone isn't (a chat's always
		// is). Others hear at once when it starts, and when it stops.
		var REPLYING_MS = 10000;
		var typed_at = 0;
		var sent_replying = false;
		var replying_timer = null;
		var isReplying = function () {
			var message = document.querySelector('.conv-composer .conv-reply-body');
			if (!message || getReplyFormMode() != 'reply') {
				return false;
			}
			var text = stripTags(getReplyBody()).trim();
			return Date.now() - typed_at < REPLYING_MS || (text !== '' && text !== message.getAttribute('data-template-text'));
		};
		document.addEventListener('input', function (event) {
			if (!event.target.closest || !event.target.closest('.conv-composer .conv-reply-body') || !poly) {
				return;
			}
			typed_at = Date.now();
			if (!sent_replying && isReplying()) {
				poly.fetchNow();
			}
			clearTimeout(replying_timer);
			replying_timer = setTimeout(function () {
				if (sent_replying && !isReplying()) {
					poly.fetchNow();
				}
			}, REPLYING_MS + 500);
		}, true);
		var had_focus = true;
		poly_data_closures.push(function (data) {
			if (!attr('conversation_id')) {
				return data;
			}
			data.replying = isReplying() ? 1 : 0;
			sent_replying = !!data.replying;
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
		// Reads in another tab have no mailbox event; refresh when this tab returns.
		var away = false;
		var refreshOnReturn = function () {
			if (away && document.visibilityState == 'visible') {
				away = false;
				catchUp();
			}
		};
		// A click into a frame on the page (an attachment's preview) blurs the window too: not away.
		window.addEventListener('blur', function () {
			if (!document.activeElement || document.activeElement.tagName != 'IFRAME') {
				away = true;
			}
		});
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState == 'hidden') {
				away = true;
			} else {
				refreshOnReturn();
			}
		});
		window.addEventListener('focus', refreshOnReturn);

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
			if (data && data.user_id != attr('auth_user_id')) {
				presence(data, false);
			}
		});
		conv.on('App\\Events\\RealtimeConvViewFinish', function (data) {
			if (data && data.user_id != attr('auth_user_id')) {
				presence(data, true);
			}
		});

		// Customers' messages being translated (App\Events\RealtimeConvTranslating): the
		// translation so far in place of "Translating…" (the finished one follows, ai_updated),
		// polled every second while they come in.
		var translating_at = {};
		var translating_poll = null;
		var showTranslating = function (data) {
			if (!data || data.conversation_id != attr('conversation_id') || !data.translations) {
				return;
			}
			data.translations.forEach(function (translation) {
				var waiting = document.querySelector('#thread-'+translation.thread_id+' .ai-translation-waiting');
				if (!waiting || (translating_at[translation.thread_id] || 0) > data.at) {
					return;
				}
				translating_at[translation.thread_id] = data.at;
				waiting.removeAttribute('role');
				waiting.setAttribute('aria-busy', 'true');
				waiting.classList.add('ai-translation-waiting--writing');
				waiting.innerHTML = translation.content;
			});
			clearTimeout(translating_poll);
			translating_poll = setTimeout(function () {
				poly.fetchNow();
			}, 1000);
		};

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
				// A message that couldn't be sent (shown as such, the conversation active again), or
				// the AI Assistant's translation or summary ready.
				if (data.send_failed || data.ai_updated) {
					Livewire.dispatch('conversation-thread-created');
				}
				if (data.thread_html && !document.getElementById('thread-'+data.thread_id)) {
					Livewire.dispatch('conversation-thread-created');
					blinkTitle('✉ '+Lang.get('messages.new_message'));
				}
				if (data.conversation_user_id) {
					menuChanged('conv-assignee', 'data-user_id', data.conversation_user_id);
				}
				if (data.conversation_status && menuChanged('conv-status', 'data-status', data.conversation_status)) {
					// The trigger's dot as the menu's for that status (Conversation::STATUS_TONES).
					var dot = document.querySelector('#conv-status summary .conv-status-dot');
					var item_dot = document.querySelector('#conv-status [data-status="'+data.conversation_status+'"] .conv-status-dot');
					if (dot && item_dot) {
						dot.className = item_dot.className;
						// "Status: Active": the label's prefix kept, in the user's language.
						var trigger = dot.closest('summary');
						var prefix = (trigger.getAttribute('aria-label') || '').split(': ')[0];
						var name = item_dot.parentElement.textContent.trim();
						trigger.setAttribute('aria-label', prefix+': '+name);
						trigger.setAttribute('title', prefix+': '+name);
					}
				}
			}).on('App\\Events\\RealtimeConvTranslating', showTranslating);
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
				if (parseInt(element.getAttribute('data-mailbox_id')) < 0) {
					return;
				}
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
						replaceFolders(folders, data.folders_html);
					}
					var all_folders = document.querySelector('.app-sidebar__folders[data-mailbox_id="-1"]');
					if (all_folders && data.all_folders_html) {
						replaceFolders(all_folders, data.all_folders_html);
					}
					markCurrentFolder();
					if (data.sidebar_counts) {
						window.tallportSidebarCounts(data.sidebar_counts);
					}
					// The list of this mailbox, or of All Mailboxes; not while conversations are selected.
					var list = document.querySelector('.table-conversations');
					var list_mailbox_id = (list && list.getAttribute('data-mailbox_id')) || attr('mailbox_id');
					if (list && (list_mailbox_id == folders_mailbox_id || parseInt(list_mailbox_id) < 0) && !list.querySelector('.conv-checkbox:checked')) {
						Livewire.dispatch('conversations-changed');
					}
				}).on('App\\Events\\RealtimeInboxChanged', function (data) {
					// The local inbox syncs (inbox.js).
					if (data && data.mailbox_id == folders_mailbox_id) {
						window.dispatchEvent(new Event('tallport:inbox-changed'));
					}
				}).on('App\\Events\\RealtimeTeamMessage', function (data) {
					if (!data || data.mailbox_id != folders_mailbox_id) {
						return;
					}
					// The open room may be showing older messages, so its unread count still follows.
					if (attr('team_chat') == folders_mailbox_id) {
						Livewire.dispatch('team-message-created');
					}
					setTeamChatUnread(data.unread);
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
