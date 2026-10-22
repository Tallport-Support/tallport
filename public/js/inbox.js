/*
 * The local inbox: a copy of the inbox's list rows in the browser (IndexedDB), kept up to date
 * from the server (App\Inbox\Sync, /inbox/sync). A folder opened in place draws its list from it
 * at once (conversations.js), until the server's list is there.
 *
 * - What's kept: unread conversations, those assigned to the user and not closed, and the last
 *   few days of everything (the server sends a row's "keep"); rows fetched besides go after 3 days.
 * - Encrypted: each row's contents with the session's key (AES-GCM, not extractable); only IDs,
 *   timestamps and folder keys stay readable, for the indexes. A new session can't read an old
 *   copy, which is wiped and synced anew; logging out deletes it.
 * - One tab syncs (Web Locks); the server's hint on the realtime channel (or a minute) says when.
 */
(function () {
    'use strict';

    var VERSION = 1;
    var TEMPORARY_DAYS = 3;
    var POLL_MS = 60000;
    var PAGE_PAUSE_MS = 250;

    var config = null;
    var dbName = null;
    var dbPromise = null;
    var keyPromise = null;
    var channel = null;
    var wake = null;

    function read() {
        var body = document.body;
        if (!body || !body.dataset.inbox || !window.indexedDB || !window.crypto || !crypto.subtle || !navigator.locks) {
            return null;
        }
        try {
            var data = JSON.parse(body.dataset.inbox);
            // The key stays in this script, not on the page.
            body.removeAttribute('data-inbox');
            return data;
        } catch (e) {
            return null;
        }
    }

    function request(req) {
        return new Promise(function (resolve, reject) {
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function open() {
        if (!dbPromise) {
            var req = indexedDB.open(dbName, VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                var rows = db.createObjectStore('rows', { keyPath: 'id' });
                rows.createIndex('mailbox', 'mailbox_id');
                rows.createIndex('folders', 'folders', { multiEntry: true });
                rows.createIndex('activity', 'last_activity');
                db.createObjectStore('meta', { keyPath: 'k' });
            };
            dbPromise = request(req);
        }
        return dbPromise;
    }

    function store(name, mode) {
        return open().then(function (db) { return db.transaction(name, mode || 'readonly').objectStore(name); });
    }

    function key() {
        if (!keyPromise) {
            var raw = Uint8Array.from(atob(config.key), function (c) { return c.charCodeAt(0); });
            keyPromise = crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['encrypt', 'decrypt']);
        }
        return keyPromise;
    }

    function encrypt(value) {
        var iv = crypto.getRandomValues(new Uint8Array(12));
        return key().then(function (k) {
            return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, k, new TextEncoder().encode(JSON.stringify(value)));
        }).then(function (data) { return { iv: iv, data: data }; });
    }

    function decrypt(box) {
        return key().then(function (k) {
            return crypto.subtle.decrypt({ name: 'AES-GCM', iv: box.iv }, k, box.data);
        }).then(function (data) { return JSON.parse(new TextDecoder().decode(data)); });
    }

    function getMeta(name) {
        return store('meta').then(function (s) { return request(s.get(name)); }).then(function (entry) { return entry ? entry.v : undefined; });
    }

    function setMeta(name, value) {
        return store('meta', 'readwrite').then(function (s) { return request(s.put({ k: name, v: value })); });
    }

    /** Everything gone (a new session, another scope, a cursor too old): synced anew. */
    function wipe() {
        return open().then(function (db) {
            var tx = db.transaction(['rows', 'meta'], 'readwrite');
            tx.objectStore('rows').clear();
            tx.objectStore('meta').clear();
            return new Promise(function (resolve) { tx.oncomplete = resolve; tx.onerror = resolve; });
        });
    }

    /** The copy was made in this session (its key reads it); else it starts over. */
    function checkKey() {
        return getMeta('check').then(function (box) {
            if (!box) {
                return encrypt('tallport').then(function (box) { return setMeta('check', box); });
            }
            return decrypt(box).catch(function () {
                return wipe().then(function () { return encrypt('tallport'); }).then(function (box) { return setMeta('check', box); });
            });
        });
    }

    function recentFrom() {
        return Date.now() / 1000 - config.recent_days * 86400;
    }

    /**
     * Rows as stored: readable ID, mailbox, folders, activity, "keep" and when fetched; the rest
     * encrypted. Kept: what the server says to keep, the recent, and rows already here.
     */
    function put(rows, onlyKept) {
        if (!rows.length) {
            return Promise.resolve();
        }
        var now = Date.now() / 1000;
        return Promise.all(rows.map(function (row) {
            return encrypt(row).then(function (box) {
                return {
                    id: row.id,
                    mailbox_id: row.mailbox_id,
                    folders: row.folders,
                    last_activity: row.last_activity || 0,
                    keep: row.keep ? 1 : 0,
                    fetched: now,
                    box: box,
                };
            });
        })).then(function (entries) {
            return store('rows', 'readwrite').then(function (s) {
                return Promise.all(entries.map(function (entry) {
                    if (!onlyKept || entry.keep || entry.last_activity >= recentFrom()) {
                        return request(s.put(entry));
                    }
                    // Outside what's kept: only updated when it's here already.
                    return request(s.get(entry.id)).then(function (existing) {
                        return existing ? request(s.put(entry)) : null;
                    });
                }));
            });
        });
    }

    function remove(ids) {
        if (!ids.length) {
            return Promise.resolve();
        }
        return store('rows', 'readwrite').then(function (s) {
            return Promise.all(ids.map(function (id) { return request(s.delete(id)); }));
        });
    }

    /** Rows no longer kept, fetched more than a few days ago, go. */
    function purge() {
        var from = recentFrom();
        var fetchedBefore = Date.now() / 1000 - TEMPORARY_DAYS * 86400;
        return store('rows', 'readwrite').then(function (s) {
            return new Promise(function (resolve) {
                var cursor = s.index('activity').openCursor(IDBKeyRange.upperBound(from, true));
                cursor.onsuccess = function () {
                    var c = cursor.result;
                    if (!c) {
                        return resolve();
                    }
                    if (!c.value.keep && c.value.fetched < fetchedBefore) {
                        c.delete();
                    }
                    c.continue();
                };
                cursor.onerror = function () { resolve(); };
            });
        });
    }

    function get(params) {
        var url = config.url + '?' + new URLSearchParams(params).toString();
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(function (response) {
            if (!response.ok) {
                throw new Error('sync ' + response.status);
            }
            return response.json();
        });
    }

    function pause(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    /** The folder in view (the list's), whose rows come first in a first sync. */
    function folderInView() {
        var params = new URLSearchParams(location.search);
        var list = document.querySelector('.table-conversations');
        return params.get('folder_id') || (list && list.getAttribute('data-folder_id')) || '';
    }

    /** A first sync: the folder in view, then the rest, quietly, a page at a time. */
    function full() {
        var page = 0;
        var cursor = null;
        var scope = null;
        var next = function () {
            return get(page == 0 ? { page: 0, folder: folderInView() } : { page: page }).then(function (data) {
                if (page == 0) {
                    cursor = data.cursor;
                    scope = data.scope;
                } else if (data.scope !== scope) {
                    // What they may see changed meanwhile: from the start.
                    return wipe().then(checkKey).then(full);
                }
                return put(data.rows, false).then(function () {
                    return data.lists ? setMeta('lists', data.lists) : null;
                }).then(function () {
                    return data.folders ? encrypt(data.folders).then(function (box) { return setMeta('folders', box); }) : null;
                }).then(function () {
                    if (data.more) {
                        page++;
                        return pause(PAGE_PAUSE_MS).then(next);
                    }
                    return setMeta('scope', scope).then(function () { return setMeta('cursor', cursor); });
                });
            });
        };
        return next();
    }

    /** What changed since the cursor; a new scope or a cursor too old: from the start. */
    function since(cursor, scope) {
        return get({ cursor: cursor }).then(function (data) {
            if (data.restart || data.scope !== scope) {
                return wipe().then(checkKey).then(full);
            }
            return put(data.rows, true).then(function () {
                return remove(data.removed);
            }).then(function () {
                return data.lists ? setMeta('lists', data.lists) : null;
            }).then(function () {
                return data.folders ? encrypt(data.folders).then(function (box) { return setMeta('folders', box); }) : null;
            }).then(function () {
                return setMeta('cursor', data.cursor);
            }).then(function () {
                return data.more ? since(data.cursor, scope) : null;
            });
        });
    }

    function sync() {
        return Promise.all([getMeta('cursor'), getMeta('scope')]).then(function (meta) {
            return meta[0] === undefined ? full() : since(meta[0], meta[1]);
        }).then(function () {
            if (channel) {
                channel.postMessage('changed');
            }
            return setMeta('synced', Date.now());
        });
    }

    /**
     * The tab that syncs: holds the lock while open; the next tab takes over when it closes.
     */
    function lead() {
        navigator.locks.request(dbName, function () {
            return checkKey().then(purge).then(function loop() {
                return sync().catch(function () {
                    // Offline, signed out or a server error: the next hint or minute tries again.
                }).then(function () {
                    return new Promise(function (resolve) {
                        var timer = setTimeout(resolve, POLL_MS);
                        wake = function () {
                            clearTimeout(timer);
                            resolve();
                        };
                    });
                }).then(loop);
            });
        });
    }

    /** A row's field to order by: null first going up, last going down (as the database). */
    function compare(a, b, order) {
        for (var i = 0; i < order.length; i++) {
            var field = order[i][0];
            var x = a[field];
            var y = b[field];
            if (x === y) {
                continue;
            }
            var result = x === null || x === undefined ? -1 : (y === null || y === undefined ? 1 : (typeof x == 'string' ? x.localeCompare(y) : x - y));
            if (result) {
                return order[i][1] == 'desc' ? -result : result;
            }
        }
        return 0;
    }

    /**
     * A folder's first page from the copy, in its list's order (App\Inbox\Sync::lists()), or
     * null when the copy may not have all of it: the server's list then.
     */
    function list(folder_id, size) {
        if (!dbName) {
            return Promise.resolve(null);
        }
        return getMeta('lists').then(function (lists) {
            var spec = lists && lists[folder_id];
            if (!spec) {
                return null;
            }
            return store('rows').then(function (s) {
                return request(s.index('folders').getAll(IDBKeyRange.only(parseInt(folder_id))));
            }).then(function (entries) {
                if (entries.length < Math.min(size, spec.total)) {
                    return null;
                }
                return Promise.all(entries.map(function (entry) { return decrypt(entry.box); })).then(function (rows) {
                    rows.sort(function (a, b) { return compare(a, b, spec.order); });
                    return { rows: rows.slice(0, size), spec: spec, config: config };
                });
            });
        }).catch(function () {
            return null;
        });
    }

    /** Changed here (a Livewire action): synced now, not at the server's hint a few seconds on. */
    var soon = null;
    function syncSoon() {
        clearTimeout(soon);
        soon = setTimeout(function () {
            if (wake) {
                wake();
            } else if (channel) {
                channel.postMessage('sync');
            }
        }, 300);
    }

    /** Signed out: the copy goes (the session's key with it). */
    function forget() {
        var deleting = (dbPromise || Promise.resolve(null)).then(function (db) {
            if (db) {
                db.close();
            }
            return request(indexedDB.deleteDatabase(dbName)).catch(function () {});
        });
        // Never holds up the logout for long.
        return Promise.race([deleting, pause(1000)]);
    }

    function start() {
        config = read();
        if (!config) {
            return;
        }
        dbName = 'tallport-inbox-' + config.install + '-' + config.user;
        channel = 'BroadcastChannel' in window ? new BroadcastChannel(dbName) : null;
        if (channel) {
            channel.onmessage = function (event) {
                if (event.data === 'sync' && wake) {
                    wake();
                }
            };
        }
        // The server's hint (realtime.js): whichever tab syncs, does.
        window.addEventListener('tallport:inbox-changed', function () {
            if (wake) {
                wake();
            } else if (channel) {
                channel.postMessage('sync');
            }
        });
        lead();
    }

    window.TallportInbox = {
        forget: function () { return dbName ? forget() : Promise.resolve(); },
        list: list,
    };

    // Livewire starts before the page is loaded.
    document.addEventListener('livewire:init', function () {
        Livewire.hook('commit', function (hook) {
            if (hook.commit.calls && hook.commit.calls.length) {
                hook.succeed(syncSoon);
            }
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
