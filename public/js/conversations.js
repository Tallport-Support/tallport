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
	// A row says its conversation (its address may be a folder's: /mailbox/support/drafts/1042).
	var id = link.getAttribute('data-conversation_id');
	var match = id ? null : (link.getAttribute('href') || '').match(/\/conversation\/(\d+)/);
	return id || match ? {id: parseInt(id || match[1]), folder_id: document.body.getAttribute('data-folder_id') || ''} : null;
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
	var state = {tallport_conversation: open, tallport_folder: {folder_id: document.body.getAttribute('data-folder_id')}};
	// Offline: only what the browser's copy has; else nothing moves.
	if (offline()) {
		paneFromCopy(open.id).then(function (shown) {
			if (!shown) {
				offlineToast('unavailable');
				return;
			}
			window.history.pushState(state, '', link.href);
			conversationMark(open.id);
		});
		return;
	}
	window.history.pushState(state, '', link.href);
	conversationOpen(open);
});

/**
 * Another folder opens in place beside an open conversation (its sidebar link, a
 * wire:navigate link): the list, its toolbar and the conversation it opens at
 * (App\Livewire\ConversationList, ConversationListToolbar, ConversationPane…).
 * The sidebar stays, with the folder marked; an empty folder loads its page.
 */
var folderOpen = function (folder_id, conversation_id) {
	folderMark(folder_id);
	document.getElementById('app-content').setAttribute('aria-busy', 'true');
	var drawn = listFromCopy(folder_id, conversation_id);
	// Offline: the browser's copy alone (or nothing to show).
	if (offline()) {
		drawn.then(function (shown) {
			document.getElementById('app-content').removeAttribute('aria-busy');
			if (!shown) {
				offlineToast('unavailable');
			} else if (typeof shown == 'string') {
				window.history.replaceState(window.history.state, '', shown);
			}
		});
		return;
	}
	Livewire.dispatch('folder-open', {folder_id: parseInt(folder_id), conversation_id: conversation_id || null});
};
/** The sidebar and the page say which folder is open. */
var folderMark = function (folder_id) {
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
};

/**
 * A folder's list at once from the browser's copy (public/js/inbox.js), until the server's
 * list (or page) is there.
 */
var listWanted = null;
var listFromCopy = function (folder_id, conversation_id) {
	listWanted = folder_id;
	if (!window.TallportInbox || !document.querySelector('.conv-list-host')) {
		return Promise.resolve(false);
	}
	return TallportInbox.list(folder_id, 50).then(function (list) {
		if (!list || listWanted !== folder_id) {
			return false;
		}
		var opened_id = listDraw(folder_id, list, conversation_id || listOpened(folder_id));
		listDrawing = folder_id;
		// Offline and empty: no conversation beside it (as its page has none).
		if (!list.rows.length && offline()) {
			paneUndraw();
			PANE_PARTS.forEach(function (selector) {
				var real = document.querySelector(selector);
				if (real) {
					real.style.display = 'none';
				}
			});
			return true;
		}
		// Beside it, the conversation it opens at (offline: at its address).
		if (opened_id && document.querySelector('.conv-pane')) {
			// Shown: its address, for the page (offline).
			return paneFromCopy(opened_id).then(function (shown) {
				var row = document.querySelector('.conv-list-local a.conv-row__link[data-conversation_id="'+opened_id+'"]');
				if (shown && offline() && row) {
					document.body.setAttribute('data-conversation_id', opened_id);
					return row.href;
				}
				return true;
			});
		}
		return true;
	});
};
/** The conversation last opened in a folder, which it opens at again (as the server remembers). */
var listOpened = function (folder_id, conversation_id) {
	try {
		var opened = JSON.parse(sessionStorage.getItem('tallport_folder_opened') || '{}');
		if (conversation_id) {
			opened[folder_id] = conversation_id;
			sessionStorage.setItem('tallport_folder_opened', JSON.stringify(opened));
		}
		return opened[folder_id] || null;
	} catch (e) {
		return null;
	}
};

/**
 * A folder's list drawn from the browser's copy as conversations_table draws it (its rows'
 * main parts; the server's list, with the rest, replaces it in a moment).
 */
var listDrawing = null;
var listTime = function (timestamp, config) {
	if (!timestamp) {
		return '';
	}
	var seconds = Math.floor(Date.now() / 1000) - timestamp;
	if (seconds <= 60) {
		return config.labels.just_now;
	}
	var days = Math.floor(seconds / 86400);
	if (days > 7) {
		var parts = {};
		new Intl.DateTimeFormat(config.locale, {timeZone: config.timezone, month: 'short', day: 'numeric', year: 'numeric'}).formatToParts(new Date(timestamp * 1000)).forEach(function (part) {
			parts[part.type] = part.value;
		});
		var this_year = new Intl.DateTimeFormat('en', {timeZone: config.timezone, year: 'numeric'}).format(new Date());
		return parts.month+' '+parts.day+(parts.year != this_year ? ', '+parts.year : '');
	}
	if (days) {
		return config.times.d[days];
	}
	var hours = Math.floor(seconds / 3600);
	return hours ? config.times.h[hours] : config.times.m[Math.floor(seconds / 60)];
};
var listDraw = function (folder_id, list, opened_id) {
	var host = document.querySelector('.conv-list-host');
	var parts = document.getElementById('conv-row-parts');
	var folder_link = document.querySelector('.app-sidebar a[data-folder_id="'+folder_id+'"]');
	if (!host || !parts || !folder_link) {
		return;
	}
	var config = list.config;
	var type = list.spec.type;
	var all = parseInt(folder_id) < 0;
	var el = function (tag, class_name, text) {
		var node = document.createElement(tag);
		if (class_name) {
			node.className = class_name;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	};
	var part = function (name, label) {
		var node = parts.content.querySelector('[data-part="'+name+'"]').cloneNode(true);
		node.removeAttribute('data-part');
		if (label) {
			node.setAttribute('aria-label', label);
			node.setAttribute('title', label);
		}
		return node;
	};
	var section = el('section', 'table-conversations conv-list conv-list-local'+(all ? ' show-mailbox' : ''));
	section.setAttribute('aria-busy', 'true');
	// Empty: as conversations_table shows it.
	if (!list.rows.length) {
		section = el('div', 'conv-list-local');
		section.appendChild(part('empty'));
	}
	if (list.rows.length) {
		var header = host.querySelector('.conv-list__header');
		header = header ? header.cloneNode(true) : part('header');
		header.inert = true;
		section.appendChild(header);
	}
	var scroll = el('div', 'f-pane__scroll split-view__list');
	var items = el('ul', 'f-item-list conv-list__items');
	items.setAttribute('role', 'list');
	// It opens at the conversation last opened in it, else its first.
	var current = list.rows.some(function (row) { return row.id == opened_id; }) ? opened_id : (list.rows[0] && list.rows[0].id);
	list.rows.forEach(function (row) {
		if (row.id == current && document.querySelector('.conv-pane')) {
			row.unread = false;
		}
		var li = el('li', 'conv-row'+(row.status == 1 ? ' conv-active' : '')+(row.status == 4 ? ' conv-spam' : ''));
		li.setAttribute('data-conversation_id', row.id);
		var link = el('a', 'f-item-row conv-row__link');
		link.href = folder_link.href+'/'+row.number;
		link.setAttribute('data-conversation_id', row.id);
		if (row.id == current && document.querySelector('.conv-pane')) {
			link.setAttribute('aria-current', 'page');
		}
		if (all) {
			link.setAttribute('data-fruit-mark', row.mark[0]);
		}
		if (row.unread) {
			li.setAttribute('data-unread', '');
			link.setAttribute('data-unread', '');
		}
		var top = el('span', 'f-item-row__top');
		top.appendChild(el('span', 'f-item-row__title', row.customer.name || row.customer.email));
		top.appendChild(el('span', 'f-item-row__time', listTime(row[list.spec.time] || row.updated, config)));
		if (row.unread) {
			top.appendChild(el('span', 'f-item-row__unread')).setAttribute('aria-hidden', 'true');
			top.appendChild(el('span', 'f-sr-only', config.labels.unread));
		}
		link.appendChild(top);
		var subtitle = el('span', 'f-item-row__subtitle');
		if (row.status == 3 && type != 60) {
			subtitle.append(part('closed', config.labels.closed), ' ');
		}
		if (row.status == 4 && type != 80) {
			subtitle.append(part('spam', config.labels.spam), ' ');
		}
		if (row.state == 3 && type != 70) {
			subtitle.append(part('deleted'), ' ');
		}
		if (row.state == 1 && type != 30) {
			subtitle.append(part('drafts', config.labels.drafts), ' ');
		}
		if (row.translated_subject) {
			subtitle.appendChild(el('span', '', row.translated_subject)).title = row.subject;
		} else {
			subtitle.append(row.subject);
		}
		link.appendChild(subtitle);
		link.appendChild(el('span', 'f-item-row__preview', row.list_preview));
		var meta = el('span', 'f-item-row__meta conv-row__meta');
		if (row.channel || row.type == 2) {
			var channel = el('span', 'conv-channel');
			channel.title = row.channel ? row.channel_name : config.labels.phone;
			channel.append(part(row.channel ? 'chat' : 'phone'), el('span', 'f-sr-only', channel.title));
			meta.appendChild(channel);
		}
		if (row.assignee) {
			var owner = el('span', 'conv-owner-name');
			owner.append(part('user'), ' ', el('span', 'conv-owner-name__text', row.assignee));
			meta.appendChild(owner);
		}
		if (row.attachments) {
			meta.appendChild(part('attachments', config.labels.attachments));
		}
		if (type != 60 && type != 80 && row.status != 1 && config.statuses[row.status]) {
			var status = el('span', 'conv-row__status');
			var dot = el('span', 'f-badge f-badge--'+config.statuses[row.status][1]+' conv-status-dot');
			dot.setAttribute('aria-hidden', 'true');
			status.append(dot, config.statuses[row.status][0]);
			meta.appendChild(status);
		}
		if (meta.childNodes.length) {
			link.appendChild(meta);
		}
		if (all) {
			link.appendChild(el('span', 'f-sr-only', row.mark[1]));
		}
		li.appendChild(link);
		items.appendChild(li);
	});
	scroll.appendChild(items);
	if (list.rows.length) {
		section.appendChild(scroll);
	}
	listUndraw();
	host.after(section);
	host.hidden = true;
	document.documentElement.classList.add('inbox-copy');
	// The list's heading: the folder, its mailbox and how many.
	var heading = document.querySelector('.app-list-title');
	if (heading) {
		var group = folder_link.closest('.app-sidebar__folders');
		var mailbox_name = ((all ? group && group.previousElementSibling : group && group.closest('details') && group.closest('details').querySelector('.mailbox-name')) || {textContent: ''}).textContent.trim();
		heading.querySelector('h1').textContent = folder_link.querySelector('.f-sidebar__identity').textContent.trim();
		heading.querySelector('p').textContent = mailbox_name+' · '+list.spec.count;
	}

	return current;
};
/** No copy shown any more: the page's own loading bar again (style.css). */
var copyGone = function () {
	if (!document.querySelector('.conv-list-local, .pane-local')) {
		document.documentElement.classList.remove('inbox-copy');
	}
};
var listUndraw = function () {
	document.querySelectorAll('.conv-list-local').forEach(function (section) {
		section.remove();
	});
	var host = document.querySelector('.conv-list-host');
	if (host) {
		host.hidden = false;
	}
	copyGone();
};
// Livewire's loading bar (page loads): not read out (its role is one ARIA doesn't have).
document.addEventListener('livewire:navigate', function () {
	[200, 600].forEach(function (delay) {
		setTimeout(function () {
			var bar = document.getElementById('nprogress');
			if (bar) {
				bar.setAttribute('aria-hidden', 'true');
			}
		}, delay);
	});
});
document.addEventListener('livewire:navigated', function () {
	document.documentElement.classList.remove('inbox-copy');
	listDrawing = null;
	listWanted = null;
	paneDrawing = null;
	paneWanted = null;
	paneUndraw();
});
// The server's list is there (or didn't come): it shows.
document.addEventListener('livewire:init', function () {
	// The live conversation (or list) shown again, by whichever request: no copy beside it.
	Livewire.hook('morphed', function (hook) {
		var name = hook.component && hook.component.name;
		if (['conversation-pane', 'conversation-toolbar', 'conversation-inspector'].indexOf(name) >= 0) {
			paneDrawing = null;
			paneWanted = null;
			paneUndraw();
		} else if (name == 'conversation-list') {
			listDrawing = null;
			listWanted = null;
			listUndraw();
			// A late answer may mark an earlier conversation: the one opened last.
			if (conversationMarked) {
				conversationMark(conversationMarked);
			}
		}
	});
	Livewire.hook('commit', function (hook) {
		// A change shown at once (tallportRowMenu.rowNow()): kept when saved, undone if not.
		if (hook.component.name == 'conversation-list') {
			hook.succeed(function () {
				rowPending = [];
				clearTimeout(rowPendingTimer);
			});
			hook.fail(rowUndo);
		}
		if (hook.component.name == 'conversation-pane') {
			var pane_done = function () {
				paneDrawing = null;
				paneWanted = null;
				paneUndraw();
			};
			hook.succeed(pane_done);
			hook.fail(pane_done);
		}
		if (hook.component.name == 'conversation-list') {
			var done = function () {
				listDrawing = null;
				listWanted = null;
				listUndraw();
			};
			hook.succeed(done);
			hook.fail(done);
		}
	});
});
// The sidebar's folders: in place beside an open conversation, else the folder's page
// (wire:navigate, with its progress bar).
document.addEventListener('click', function (e) {
	var link = e.target.closest && e.target.closest('a.app-folder-link');
	if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
		return;
	}
	e.preventDefault();
	// Offline: only what the browser's copy has; else nothing moves.
	if (offline()) {
		var offline_folder = link.getAttribute('data-folder_id');
		listFromCopy(offline_folder).then(function (shown) {
			if (!shown) {
				offlineToast('unavailable');
				return;
			}
			window.history.pushState({tallport_folder: {folder_id: offline_folder}}, '', typeof shown == 'string' ? shown : link.href);
			folderMark(offline_folder);
		});
		return;
	}
	if (!document.querySelector('.conv-pane') || !document.querySelector('.conv-list-host')) {
		// A page without a conversation (an empty folder's, a narrow one): the list at once too.
		listFromCopy(link.getAttribute('data-folder_id'));
		Livewire.navigate(link.href);
		return;
	}
	window.history.pushState({tallport_folder: {folder_id: link.getAttribute('data-folder_id')}}, '', link.href);
	folderOpen(link.getAttribute('data-folder_id'));
});
/** The list says which conversation is open. */
var conversationMarked = null;
var conversationMark = function (id) {
	conversationMarked = id;
	document.querySelectorAll('a.conv-row__link[aria-current]').forEach(function (row) {
		row.removeAttribute('aria-current');
	});
	document.querySelectorAll('a.conv-row__link').forEach(function (row) {
		var row_open = conversationOpenLink(row);
		if (row_open && row_open.id == id) {
			row.setAttribute('aria-current', 'page');
		}
	});
	if (offline()) {
		document.body.setAttribute('data-conversation_id', id);
	}
};
var conversationOpen = function (open) {
	conversationMark(open.id);
	document.getElementById('app-content').setAttribute('aria-busy', 'true');
	var drawn = paneFromCopy(open.id);
	if (offline()) {
		drawn.then(function (shown) {
			document.getElementById('app-content').removeAttribute('aria-busy');
			if (!shown) {
				offlineToast('unavailable');
			}
		});
		return;
	}
	Livewire.dispatch('conversation-open', open);
};

/**
 * A conversation as it was last shown (its pane, toolbar and customer), from the browser's
 * copy (public/js/inbox.js) while the server's comes, when nothing in it changed since. Saved
 * each time one is shown; inert, without Livewire or Alpine.
 */
var PANE_PARTS = ['.conv-pane', '#conv-toolbar', '#conv-layout-customer'];
var rowPending = [];
var rowPendingTimer = null;
var rowUndo = function () {
	clearTimeout(rowPendingTimer);
	if (!rowPending.length) {
		return;
	}
	rowPending.forEach(function (was) {
		was.li.innerHTML = was.html;
		was.li.hidden = was.hidden;
		if (was.unread) {
			was.li.dataset.unread = '';
		} else {
			delete was.li.dataset.unread;
		}
	});
	rowPending = [];
	if (offline()) {
		offlineToast('undone');
	} else {
		Tallport.toast(Lang.get('messages.error_occurred'), 'danger');
	}
};
/**
 * Offline (the connection gone in an open tab): what the browser's copy holds still shows
 * (lists, conversations with a snapshot); what needs the server is disabled. Back online,
 * the live ones replace the copies.
 */
var offline = function () {
	return navigator.onLine === false;
};
var offlineToast = function (which) {
	var notice = document.querySelector('.app-offline');
	Tallport.toast(notice ? notice.getAttribute('data-'+which) : Lang.get('messages.error_occurred'), 'danger');
};
// Disabled: buttons as such, menus and links with aria-disabled (FruitUI keeps them shut).
var OFFLINE_ACTIONS = '#conv-toolbar button, #conv-toolbar summary, .conv-pane button, .conv-pane summary, .conv-list__header button, .conv-list__header summary, .conv-list-host .f-context-menu button, .conv-list-host .f-context-menu a';
var offlineDisable = function () {
	var off = offline();
	document.querySelectorAll('.app-offline').forEach(function (notice) {
		notice.hidden = !off;
	});
	document.querySelectorAll(off ? OFFLINE_ACTIONS : '[data-offline-disabled]').forEach(function (node) {
		if (off && !node.hasAttribute('data-offline-disabled') && !node.disabled && node.getAttribute('aria-disabled') != 'true') {
			node.setAttribute('data-offline-disabled', '');
			node.tagName == 'BUTTON' ? node.disabled = true : node.setAttribute('aria-disabled', 'true');
		} else if (!off) {
			node.removeAttribute('data-offline-disabled');
			node.tagName == 'BUTTON' ? node.disabled = false : node.removeAttribute('aria-disabled');
		}
	});
};
window.addEventListener('offline', function () {
	// A change shown at once wasn't saved.
	rowUndo();
	offlineDisable();
});
window.addEventListener('online', function () {
	offlineDisable();
	// The live list and conversation in place of the copies.
	if (listDrawing !== null && document.querySelector('.conv-list-local')) {
		Livewire.dispatch('folder-open', {folder_id: parseInt(listDrawing), conversation_id: paneDrawing || null});
	} else if (paneDrawing !== null && document.querySelector('.pane-local')) {
		Livewire.dispatch('conversation-open', {id: paneDrawing, folder_id: document.body.getAttribute('data-folder_id') || ''});
	}
});
document.addEventListener('livewire:navigated', offlineDisable);
document.addEventListener('livewire:init', function () {
	Livewire.hook('morphed', function () {
		if (offline()) {
			offlineDisable();
		}
	});
});
var paneDrawing = null;
var paneDrawTimer = null;
var paneWanted = null;
var paneFromCopy = function (id) {
	paneWanted = parseInt(id);
	if (!window.TallportInbox || !document.querySelector('.conv-pane')) {
		return Promise.resolve(false);
	}
	return TallportInbox.pane(id).then(function (parts) {
		if (parts && paneWanted === parseInt(id)) {
			paneDraw(parts);
			paneDrawing = parseInt(id);
			return true;
		}
		return false;
	});
};
var paneSnapshot = function () {
	var id = document.body.getAttribute('data-conversation_id');
	var version = document.body.getAttribute('data-pane-version');
	if (!id || !version || !window.TallportInbox || document.querySelector('.pane-local')) {
		return;
	}
	var parts = {};
	PANE_PARTS.forEach(function (selector) {
		var real = document.querySelector(selector);
		if (!real) {
			return;
		}
		var copy = real.cloneNode(true);
		// Where it was scrolled to (a chat at its newest message).
		var from = real.querySelectorAll('*');
		var to = copy.querySelectorAll('*');
		for (var i = 0; i < from.length; i++) {
			if (from[i].scrollHeight > from[i].clientHeight + 4 && (from[i].scrollTop || from[i].scrollHeight - from[i].clientHeight - from[i].scrollTop < 24)) {
				to[i].setAttribute('data-snap-scroll', from[i].scrollHeight - from[i].clientHeight - from[i].scrollTop < 24 ? 'bottom' : Math.round(from[i].scrollTop));
			}
		}
		parts[selector] = paneClean(copy, false);
	});
	TallportInbox.savePane(id, version, parts);
};
/**
 * A copy without what runs (scripts, Livewire, Alpine) and what shows only while loading. One
 * the server rendered (fetched ahead) has no Alpine state yet: what Alpine shows only on
 * occasion (x-show, x-cloak) is left out, and a chat opens at its newest message.
 */
var paneClean = function (copy, rendered) {
	copy.querySelectorAll('script, template').forEach(function (node) {
		node.remove();
	});
	copy.querySelectorAll('*').forEach(function (node) {
		if (Array.prototype.some.call(node.attributes, function (attribute) {
			return (/^wire:(loading|offline)/.test(attribute.name) && !/\.remove/.test(attribute.name))
				|| (rendered && /^x-(show|cloak|if)/.test(attribute.name));
		})) {
			node.remove();
		}
	});
	if (rendered) {
		copy.querySelectorAll('.f-history').forEach(function (node) {
			node.setAttribute('data-snap-scroll', 'bottom');
		});
	}
	[copy].concat(Array.prototype.slice.call(copy.querySelectorAll('*'))).forEach(function (node) {
		Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
			if (/^(x-|wire:|@|:)/.test(attribute.name)) {
				node.removeAttribute(attribute.name);
			}
		});
	});
	return copy.outerHTML;
};

/**
 * Fetched ahead (InboxController::pane()): the next conversation in the list and the top of
 * the folder, one at a time, those whose snapshot is missing or out of date.
 */
var PANE_AHEAD = 10;
var paneAheadQueue = [];
var paneAheadBusy = false;
var paneAhead = function () {
	if (!window.TallportInbox || !document.querySelector('.conv-pane')) {
		return;
	}
	var rows = Array.prototype.map.call(document.querySelectorAll('.conv-list-host li.conv-row'), function (row) {
		return parseInt(row.getAttribute('data-conversation_id'));
	});
	var current = parseInt(document.body.getAttribute('data-conversation_id'));
	var next = rows[rows.indexOf(current) + 1];
	paneAheadQueue = [next].concat(rows.slice(0, PANE_AHEAD)).filter(function (id, i, ids) {
		return id && id !== current && ids.indexOf(id) === i;
	});
	paneAheadNext();
};
var paneAheadNext = function () {
	if (paneAheadBusy || !paneAheadQueue.length) {
		return;
	}
	paneAheadBusy = true;
	var id = paneAheadQueue.shift();
	var done = function () {
		paneAheadBusy = false;
		setTimeout(function () {
			(window.requestIdleCallback || setTimeout)(paneAheadNext);
		}, 300);
	};
	TallportInbox.paneWanted(id).then(function (wanted) {
		if (!wanted) {
			return null;
		}
		return TallportInbox.fetchPane(id).then(function (data) {
			if (!data || data.version !== wanted) {
				return null;
			}
			var parts = {};
			Object.keys(data.parts).forEach(function (selector) {
				var template = document.createElement('template');
				template.innerHTML = data.parts[selector];
				if (template.content.firstElementChild) {
					parts[selector] = paneClean(template.content.firstElementChild, true);
				}
			});
			return TallportInbox.savePane(id, data.version, parts);
		});
	}).catch(function () {}).then(done);
};
var paneDraw = function (parts) {
	paneUndraw();
	PANE_PARTS.forEach(function (selector) {
		var real = document.querySelector(selector);
		if (!real || !parts[selector]) {
			return;
		}
		var template = document.createElement('template');
		template.innerHTML = parts[selector];
		var copy = template.content.firstElementChild;
		copy.classList.add('pane-local');
		copy.setAttribute('x-ignore', '');
		copy.setAttribute('aria-busy', 'true');
		copy.inert = true;
		real.after(copy);
		real.style.display = 'none';
		document.documentElement.classList.add('inbox-copy');
		copy.querySelectorAll('[data-snap-scroll]').forEach(function (node) {
			var scroll = node.getAttribute('data-snap-scroll');
			node.scrollTop = scroll == 'bottom' ? node.scrollHeight : parseInt(scroll);
		});
	});
	// Never for long, whatever happens to the request (offline: while it lasts).
	clearTimeout(paneDrawTimer);
	paneDrawTimer = setTimeout(function undrawLater() {
		if (offline()) {
			paneDrawTimer = setTimeout(undrawLater, 15000);
		} else {
			paneUndraw();
		}
	}, 15000);
	offlineDisable();
};
var paneUndraw = function () {
	paneDrawing = null;
	document.querySelectorAll('.pane-local').forEach(function (node) {
		node.remove();
	});
	PANE_PARTS.forEach(function (selector) {
		var real = document.querySelector(selector);
		if (real) {
			real.style.display = '';
		}
	});
	copyGone();
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
		conversationOpen(Object.assign({}, state.tallport_conversation, {
			folder_id: state.tallport_conversation.folder_id || (state.tallport_folder && state.tallport_folder.folder_id) || ''
		}));
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
		document.body.setAttribute('data-pane-version', data.version || '');
		conversationMark(data.id);
		paneDrawing = null;
		paneWanted = null;
		paneUndraw();
		listOpened(data.folder_id, data.id);
		// As it's shown now, for the next time (once it has settled); then those after it.
		setTimeout(paneSnapshot, 600);
		setTimeout(paneAhead, 1500);
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
	listOpened(document.body.getAttribute('data-folder_id'), parseInt(document.body.getAttribute('data-conversation_id')));
	setTimeout(paneSnapshot, 600);
	setTimeout(paneAhead, 1500);
	// Back to this page after conversations opened in place opens it again (with
	// Livewire's own entry kept).
	window.history.replaceState(Object.assign({}, window.history.state || {}, {tallport_conversation: {
		id: parseInt(document.body.getAttribute('data-conversation_id')),
		// The folder it's shown beside (its address's), so Back here opens it there again.
		folder_id: document.body.getAttribute('data-folder_id') || ''
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
		}).then(function (response) {
			if (response.sidebar_counts) {
				window.dispatchEvent(new CustomEvent('sidebar-counts', {detail: {counts: response.sidebar_counts}}));
			}
		});
	}
	// The message its address names (/mailbox/support/drafts/1042/58231): in view, marked a moment.
	var focus_thread = document.body.getAttribute('data-focus-thread');
	var focused = focus_thread ? document.getElementById('thread-'+focus_thread) : null;
	if (focused) {
		focused.scrollIntoView({block: 'center'});
		// Keyboard and screen readers start there too.
		focused.setAttribute('tabindex', '-1');
		focused.focus({preventScroll: true});
		focused.classList.add('thread-focused');
		setTimeout(function () {
			focused.classList.remove('thread-focused');
		}, 3000);
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
					// Notes kept before Markdown are rich text.
					this.$wire.openNote(note.note, !!note.markdown);
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
				// A reply turned into a note: kept in the browser at once, as its draft is gone.
				this.listeners.push(Livewire.on('composer-note-remember', function () {
					setTimeout(function () {
						self.rememberNote();
					});
				}));
				// The editor stays when the mode changes (Reply, Note, Forward): its placeholder follows.
				this.$wire.$watch('mode', function (mode) {
					var body = self.$root.querySelector('.conv-reply-body[data-placeholders]');
					var text = body && (JSON.parse(body.getAttribute('data-placeholders'))[mode || 'reply'] || '');
					if (text) {
						body.querySelectorAll('textarea, .tiptap').forEach(function (field) {
							field.setAttribute(field.tagName == 'TEXTAREA' ? 'placeholder' : 'data-placeholder', text);
							if (field.tagName != 'TEXTAREA') {
								field.setAttribute('aria-placeholder', text);
							}
						});
					}
				});
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
					all[conversation_id] = {note: note, markdown: !!this.$wire.markdown, time: (new Date()).getTime()};
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
							window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: html, markdown: html}}));
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
						window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: html, markdown: html}}));
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
				if (this.$wire.markdown) {
					// Markdown as written, until the history shows the message.
					body.classList.add('conv-sending__markdown');
					body.textContent = html;
				} else {
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
				}
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
	// chat: the chat view's composer (inline: no card, the draft is written into the chat field,
	// which is locked meanwhile; translated: replies are translated on send, so the agent's language goes in).
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
				this.stopStep();
				this.status = status || '';
				this.failed = false;
				this.detail = '';
				this.meta = '';
				this.html = '';
				this.draft = null;
			},

			// A step of the draft (AiDraftsController::store()): shown with the seconds it takes, until
			// the next one or the draft's text.
			startStep: function (step) {
				var self = this;
				var started = Date.now();
				this.stopStep();
				// Each step's timer stops itself once it's no longer the current one, or the draft is done.
				var token = this.step_token;
				var timer = null;
				var show = function () {
					if (token !== self.step_token || !self.drafting) {
						clearInterval(timer);
						return;
					}
					var seconds = Math.floor((Date.now() - started) / 1000);
					self.status = texts[step] + (seconds ? ' ' + seconds + ' s' : '');
				};
				this.stepping = true;
				show();
				timer = setInterval(show, 1000);
			},

			stopStep: function () {
				this.step_token = (this.step_token || 0) + 1;
				this.stepping = false;
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
					// What was in the field before goes back.
					if (this.written) {
						window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: this.before, markdown: this.before}}));
					}
					this.reset('');
					this.lock(false);
					Tallport.toast(message || texts.failed, 'danger');
					return;
				}
				this.reset(message || texts.failed);
				this.failed = true;
				this.detail = detail || '';
			},

			// The chat field, as the draft is written: the draft's Markdown, as HTML for rich text.
			setField: function (markdown) {
				window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: 'body', html: markdownToHtml(markdown), markdown: markdown}}));
			},

			// The chat field (the editor and its buttons) while the draft is written: inert, as the
			// AI writes into it (FruitUI's editor takes no content while readonly or disabled), and
			// Draft with AI busy. Unlocked, the cursor goes to the end of the text.
			lock: function (on) {
				var field = this.$root.closest('.conv-action-wrapper');
				field = field ? field.querySelector('.conv-reply-body') : null;
				if (field) {
					field.inert = on;
					if (on) {
						field.setAttribute('aria-busy', 'true');
					} else {
						field.removeAttribute('aria-busy');
					}
				}
				document.querySelectorAll('.ai-draft-action').forEach(function (button) {
					Tallport.busy(button, on);
				});
				if (!on) {
					editorFocus('body', true);
				}
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
				if (chat.inline) {
					var field = document.getElementById('body');
					this.before = field ? field.value : '';
					this.written = false;
					this.lock(true);
				}
				// The draft is shown below the editor: open it, as Reply does.
				Livewire.dispatch('composer-open', {mode: 'reply'});
				this.reset(texts.drafting);
				var timer = setTimeout(function () {
					controller.abort();
				}, 285000);
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
							if (data.step && texts[data.step]) {
								self.startStep(data.step);
							} else if (data.status == 'success') {
								finished = true;
								if (!data.draft.trim()) {
									self.fail((data.staff_notes || []).join('\n'));
									return;
								}
								self.drafting = false;
								self.reset('');
								self.draft = data;
								self.meta = data.language+' · '+data.confidence;
								self.html = markdownToHtml(data.draft);
								if (chat.inline) {
									self.insert().finally(function () {
										self.lock(false);
									});
								}
							} else if (data.status == 'error') {
								finished = true;
								self.fail(data.msg, data.detail);
							} else if (typeof data.draft == 'string') {
								if (self.stepping) {
									self.stopStep();
									self.status = texts.drafting;
								}
								self.html = markdownToHtml(data.draft);
								// Translated on send: its translation, once it's being written.
								var written = chat.translated ? data.translation : data.draft;
								if (chat.inline && typeof written == 'string') {
									self.written = true;
									self.setField(written);
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

			// Into the reply (ConversationComposer::aiDraft()): done when the composer has it.
			insert: function () {
				if (!this.draft) {
					return Promise.resolve();
				}
				// Translated on send: the version in the agent's language is what they write, and the
				// draft as written (the customer's language) its translation, not made again.
				if (chat.translated && this.draft.translation) {
					var self = this;
					var draft = this.draft;
					return this.$wire.aiDraft(markdownToHtml(draft.translation), '', '', draft.translation).then(function () {
						return new Promise(function (resolve) {
							setTimeout(resolve, 50);
						});
					}).then(function () {
						var body = document.getElementById('body');
						return body ? self.$wire.aiDraftTranslated(body.value, markdownToHtml(draft.draft)) : null;
					});
				}
				return this.$wire.aiDraft(markdownToHtml(this.draft.draft), this.draft.translation || '', this.draft.translation ? translation_language : '', this.draft.draft);
			}
		};
	});

	/**
	 * The list's one row menu (conversations_table): what it acts on and its labels,
	 * read from the rows' data attributes when it opens. A selected row's menu acts
	 * on the selection, else on the row alone (ConversationList::rowTargets).
	 */
	window.Alpine.data('tallportRowMenu', function () {
		var rows = function (row) {
			// Not a row any more: the list changed since the menu last opened.
			if (!row || !row.parentElement) {
				return [];
			}
			var checked = Array.prototype.filter.call(row.parentElement.querySelectorAll(':scope > li.conv-row'), function (li) {
				var box = li.querySelector('input.conv-checkbox');
				return box && box.checked;
			});
			return checked.indexOf(row) >= 0 ? checked : [row];
		};
		return {
			menuAny: function (row, key) {
				return rows(row).some(function (li) { return key in li.dataset; });
			},
			menuAll: function (row, key) {
				var all = rows(row);
				return all.length > 0 && all.every(function (li) { return key in li.dataset; });
			},
			/**
			 * A change shown at once, before the server's list: read or unread, or out of this
			 * folder. After the click (wire:click reads the rows as they were).
			 */
			rowNow: function (row, change) {
				var targets = rows(row);
				setTimeout(function () {
					// As they were, should the server refuse it (rowUndo()).
					targets.forEach(function (li) {
						rowPending.push({li: li, html: li.innerHTML, hidden: li.hidden, unread: 'unread' in li.dataset});
					});
					// No answer (a dropped connection): undone after a while.
					clearTimeout(rowPendingTimer);
					rowPendingTimer = setTimeout(rowUndo, navigator.onLine === false ? 0 : 10000);
					var focused = document.activeElement;
					var lost = !focused || focused === document.body || targets.some(function (li) { return li.contains(focused); }) || focused.closest('.f-context-menu');
					targets.forEach(function (li) {
						var link = li.querySelector('a.conv-row__link');
						if (change == 'leave') {
							li.hidden = true;
						} else if (change == 'read' && link) {
							delete li.dataset.unread;
							link.removeAttribute('data-unread');
							link.querySelectorAll('.f-item-row__unread, .f-item-row__unread + .f-sr-only').forEach(function (node) {
								node.remove();
							});
						} else if (change == 'unread' && link && !('unread' in li.dataset)) {
							li.dataset.unread = '';
							link.setAttribute('data-unread', '');
							var top = link.querySelector('.f-item-row__top');
							var list = li.closest('[data-unread-label]');
							if (top && !top.querySelector('.f-item-row__unread')) {
								var dot = document.createElement('span');
								dot.className = 'f-item-row__unread';
								dot.setAttribute('aria-hidden', 'true');
								var label = document.createElement('span');
								label.className = 'f-sr-only';
								label.textContent = list ? list.dataset.unreadLabel : '';
								top.append(dot, label);
							}
						}
					});
					// The keyboard stays in the list: on the next row (or the one before).
					if (change == 'leave' && lost) {
						var all = Array.prototype.slice.call(targets[0].parentElement.querySelectorAll(':scope > li.conv-row'));
						var last = all.indexOf(targets[targets.length - 1]);
						var first = all.indexOf(targets[0]);
						var next = all.slice(last + 1).concat(all.slice(0, first).reverse()).find(function (li) { return !li.hidden; });
						// Also once the menu has closed (it gives the focus back to its row, gone now).
						var focus = function () {
							var link = next && next.querySelector('a.conv-row__link');
							var active = document.activeElement;
							if (link && (!active || active === document.body || active.closest('.f-context-menu') || targets.some(function (li) { return li.contains(active); }))) {
								link.focus();
							}
						};
						[0, 50, 200].forEach(function (delay) {
							setTimeout(focus, delay);
						});
					}
				}, 0);
			},
			// The selection's title comes from the server (its plural rules).
			menuTitle: function (row, title) {
				var list = row && row.closest('[data-selection-title]');
				return (rows(row).length > 1 && list && list.dataset.selectionTitle) || title;
			},
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
