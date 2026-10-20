/*
 * The website chat's window (webchat/window): from FruitUI's Widget example (examples/widget/chat.js),
 * talking to Tallport (WebChatController) instead of a demo. No cookies: the visitor's token is kept in
 * the frame's storage (which browsers keep apart per website) and sent as a bearer token. Every
 * address is relative, so a website can serve the folder (/t/) through its own domain.
 */
// FruitUI's standalone build (Alpine with FruitUI) loads after this and starts Alpine.
document.addEventListener('alpine:init', function () {
    Alpine.data('tallportWebChat', function (config) {
        var params = new URLSearchParams(location.search);
        // The website the window answers to (the script sends it, the server checked it).
        var host = config.origin;
        var key = 'tallport:' + config.widget;
        var memory = null;
        var timer = null;
        var sequence = 0;
        var clock = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { hour: 'numeric', minute: '2-digit' });

        function size(bytes) {
            return bytes < 1024 * 1024 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1024 / 1024).toFixed(1) + ' MB';
        }

        return {
            embedded: window.parent !== window && Boolean(host),
            // Shown: opened from the launcher, or the whole window on its own.
            shown: window.parent === window || !host,
            online: navigator.onLine,
            text: config.text,
            entries: [],
            // The team's first message: to anyone, and by name once the website signs them in (the server's, with each poll).
            greeting: config.greeting,
            draft: '',
            files: [],
            error: '',
            unread: 0,
            announcement: '',
            // The newest message the server says won't change (the team's can still be undone a moment).
            cursor: 0,
            hasConversation: false,
            activeAt: Date.now(),
            // New chats taken now (the widget's hourly limit); a visitor with a conversation keeps it.
            available: config.available,
            // Spam (App\WebChat\Spam): the proof of work being found, and a wait over a limit.
            proof: null,
            waitUntil: 0,
            waitKind: '',
            waitText: '',
            waitTimer: null,
            // Replies by email: a confirmed or signed-in address, or one being confirmed with a code.
            email: null,
            emailStep: 'address',
            emailDraft: '',
            emailError: '',
            codeDraft: '',
            codeError: '',
            codeNotice: '',
            codesLeft: 3,
            busy: false,

            init: function () {
                var self = this;
                addEventListener('message', function (event) {
                    if (!self.embedded || event.source !== parent || event.origin !== host) return;
                    var data = event.data;
                    if (!data || data.tallport !== 1) return;
                    if (data.type === 'open') self.opened(data.focus);
                    else if (data.type === 'closed') self.shown = false;
                    else if (data.type === 'identify') self.identify(data.token);
                    else if (data.type === 'logout') self.logout();
                    else if (data.type === 'appearance') self.appearance(data.value);
                });
                addEventListener('online', function () {
                    self.online = true;
                    self.entries.forEach(function (entry) {
                        if (entry.state === 'waiting') self.deliver(entry);
                    });
                });
                addEventListener('offline', function () { self.online = false; });
                document.addEventListener('visibilitychange', function () { self.schedule(0); });
                ['keydown', 'pointerdown'].forEach(function (type) {
                    addEventListener(type, function () { self.activeAt = Date.now(); });
                });
                matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () { self.post('theme', self.colors()); });
                // Not in an animation frame: browsers skip those in a hidden frame from another origin.
                // While new chats aren't taken, the button shows only for a visitor with a conversation.
                var ready = function () {
                    self.post('ready', Object.assign({ label: self.text.label, available: self.available || self.hasConversation }, self.colors()));
                };
                if (this.token()) {
                    var polled = this.poll();
                    if (!this.available) polled.then(ready);
                    else this.$nextTick(ready);
                } else {
                    this.$nextTick(ready);
                }
            },

            // The visitor's token: in the frame's storage, or (where storage is refused) for this page only.
            token: function (value) {
                try {
                    if (value === undefined) return localStorage.getItem(key) || memory;
                    if (value === null) localStorage.removeItem(key);
                    else localStorage.setItem(key, value);
                } catch (e) {}
                memory = value === undefined ? memory : value;
                return memory;
            },

            api: function (method, path, body) {
                var self = this;
                var url = path + (path.indexOf('?') < 0 ? '?' : '&') + 'widget=' + encodeURIComponent(config.widget);
                var headers = { Accept: 'application/json' };
                if (this.token()) headers.Authorization = 'Bearer ' + this.token();
                return fetch(url, { method: method, headers: headers, body: body, credentials: 'omit' }).then(function (response) {
                    if (response.status === 401) self.token(null);
                    return response.json().catch(function () { return {}; }).then(function (data) {
                        // A server error's own text isn't for visitors.
                        if (!response.ok) throw Object.assign(new Error(response.status < 500 && data.message ? data.message : self.text.error), { status: response.status, data: data });
                        return data;
                    });
                });
            },

            post: function (type, data) {
                if (this.embedded) parent.postMessage(Object.assign({ tallport: 1, type: type }, data || {}), host);
            },

            /** The launcher takes the window's accent and edge, so it matches in either appearance. */
            colors: function () {
                var probe = document.createElement('span');
                document.body.append(probe);
                var resolve = function (token) {
                    probe.style.color = 'var(' + token + ')';
                    return getComputedStyle(probe).color;
                };
                var theme = document.documentElement.dataset.theme;
                var colors = {
                    fill: resolve('--f-accent-fill'),
                    fillHover: resolve('--f-accent-fill-hover'),
                    canvas: resolve('--f-surface'),
                    dark: theme === 'dark' || (theme !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches),
                };
                probe.remove();
                return colors;
            },

            appearance: function (value) {
                if (['system', 'light', 'dark'].indexOf(value) < 0) return;
                document.documentElement.dataset.theme = value;
                this.post('theme', this.colors());
            },

            get timeline() {
                var entries = this.entries.slice();
                // Not to a new visitor while new chats aren't taken.
                if (this.greeting && (this.available || this.hasConversation)) {
                    entries.unshift({ id: 'greeting', kind: 'team', team: true, author: config.team, initials: config.team.charAt(0).toUpperCase(), body: this.greeting, files: [] });
                }
                // Where replies also go, after the greeting; or, after their first message, the offer.
                if (this.email) {
                    entries.splice(this.greeting ? 1 : 0, 0, { id: 'email-event', kind: 'event', body: this.text.repliesTo.replace(':email', this.email) });
                } else {
                    var first = entries.findIndex(function (entry) { return entry.kind === 'visitor'; });
                    if (first >= 0) entries.splice(first + 1, 0, { id: 'email-card', kind: 'email' });
                }
                var talks = function (entry) { return entry && (entry.kind === 'team' || entry.kind === 'visitor'); };
                var together = function (one, other) {
                    return talks(one) && talks(other) && one.kind === other.kind && one.author === other.author;
                };
                return entries.map(function (entry, index) {
                    return Object.assign({}, entry, { first: !together(entry, entries[index - 1]), last: !together(entry, entries[index + 1]) });
                });
            },

            /** The window opened: what arrived meanwhile is read, and when the visitor opened it, typing can start. */
            opened: function (focus) {
                var self = this;
                this.shown = true;
                this.activeAt = Date.now();
                this.markRead();
                this.schedule(0);
                if (!focus) return;
                this.$nextTick(function () {
                    if (matchMedia('(pointer: fine)').matches) self.$refs.message.focus();
                    else self.$refs.title.focus();
                });
            },

            close: function () {
                this.post('close');
            },

            escape: function (event) {
                if (!this.embedded || event.defaultPrevented) return;
                event.preventDefault();
                this.close();
            },

            /**
             * Asking for new messages: every few seconds while open and in use, slower while open and idle,
             * slowly while closed (only with a conversation, for the unread count), never while the
             * website's tab is hidden.
             */
            schedule: function (delay) {
                var self = this;
                clearTimeout(timer);
                if (document.hidden || !this.token()) return;
                if (delay === undefined) {
                    var active = this.shown && Date.now() - this.activeAt < 120000;
                    if (!this.shown && !this.hasConversation) return;
                    delay = (active ? config.poll.active : this.shown ? config.poll.idle : config.poll.closed) * 1000;
                }
                timer = setTimeout(function () { self.poll(); }, delay);
            },

            poll: function () {
                var self = this;
                return this.api('GET', 'm?after=' + this.cursor).then(function (data) {
                    self.hasConversation = data.messages.length > 0 || self.hasConversation;
                    data.messages.forEach(function (message) { self.receive(message); });
                    self.cursor = Math.max(self.cursor, data.cursor || 0);
                    if (data.greeting !== undefined) self.greeting = data.greeting;
                    self.emailState(data);
                    self.unread = data.unread;
                    if (self.shown) self.markRead();
                    else self.post('unread', { count: self.unread, label: data.unread_label });
                }).catch(function () {}).then(function () { self.schedule(); });
            },

            markRead: function () {
                var last = this.entries.filter(function (entry) { return entry.kind === 'team' && entry.serverId; }).pop();
                this.unread = 0;
                this.post('unread', { count: 0, label: this.text.label });
                if (last && last.serverId > (this.readId || 0)) {
                    this.readId = last.serverId;
                    var form = new FormData();
                    form.append('thread_id', last.serverId);
                    this.api('POST', 'r', form).catch(function () {});
                }
            },

            /** A message from the server, once: the team's are announced while the window is open. */
            receive: function (message) {
                var known = this.entries.some(function (entry) { return entry.serverId === message.id; });
                if (known) return;
                this.entries.push(this.entry(message));
                if (message.kind === 'team' && this.shown) this.announcement = message.author + ': ' + message.body;
            },

            entry: function (message) {
                return {
                    id: 'm' + message.id,
                    serverId: message.id,
                    kind: message.kind,
                    author: message.kind === 'visitor' ? this.text.you : message.author,
                    initials: message.initials,
                    body: message.body,
                    files: message.files,
                    time: clock.format(new Date(message.time)),
                    state: '',
                };
            },

            addFiles: function (input) {
                var self = this;
                this.error = '';
                Array.prototype.forEach.call(input.files, function (file) {
                    if (file.size > config.maxFileMb * 1024 * 1024) self.error = self.text.tooLarge;
                    else if (self.files.length >= config.maxFiles) self.error = self.text.tooMany;
                    else self.files.push({ id: ++sequence, name: file.name, size: size(file.size), file: file });
                });
                input.value = '';
                this.$refs.message.focus();
            },

            removeFile: function (file) {
                this.files = this.files.filter(function (item) { return item !== file; });
                this.$refs.message.focus();
            },

            send: function () {
                var body = this.draft.trim();
                if (!body && !this.files.length) return;
                this.error = '';
                this.activeAt = Date.now();
                var entry = {
                    id: 'local' + ++sequence,
                    kind: 'visitor',
                    author: this.text.you,
                    body: body,
                    files: this.files,
                    time: clock.format(new Date()),
                    state: 'sending',
                };
                this.entries.push(entry);
                this.draft = '';
                this.files = [];
                this.deliver(this.entries[this.entries.length - 1]);
            },

            /** Sending; offline, the message waits for the connection; a refusal can be tried again. */
            deliver: function (entry, challenge) {
                var self = this;
                if (!this.online) return void (entry.state = 'waiting');
                if (this.waitUntil > Date.now()) return void (entry.state = 'failed');
                entry.state = 'sending';
                var started = this.token() ? Promise.resolve() : this.api('POST', 'v').then(function (data) { self.token(data.token); });
                // A new chat needs the proof of work (begun while they typed; again, harder, when asked).
                var proved = started.then(function () {
                    return self.hasConversation ? null : self.prove(challenge);
                });
                proved.then(function (proof) {
                    var form = new FormData();
                    form.append('body', entry.body);
                    // Their language, until detected in what they write (only the first: browsers add English).
                    form.append('language', navigator.language || '');
                    form.append('page', params.get('page') || '');
                    form.append('elapsed', Math.round(performance.now()));
                    form.append('website', self.$refs.trap ? self.$refs.trap.value : '');
                    if (proof) form.append('proof', proof);
                    entry.files.forEach(function (file) { form.append('files[]', file.file || file); });
                    return self.api('POST', 'm', form);
                }).then(function (data) {
                    // Taken: shown as sent (a blocked visitor's isn't kept, and gets no ID).
                    var index = self.entries.indexOf(entry);
                    if (index >= 0 && data.message.id) self.entries.splice(index, 1, self.entry(data.message));
                    else entry.state = '';
                    self.proof = null;
                    self.hasConversation = true;
                    self.schedule(config.poll.active * 1000);
                }).catch(function (error) {
                    var data = error.data || {};
                    self.proof = null;
                    if (error.status === 428 && data.challenge && !challenge) return self.deliver(entry, data.challenge);
                    if (!navigator.onLine) return void (entry.state = 'waiting');
                    entry.state = 'failed';
                    if (error.status === 429 && data.wait) self.wait(data.wait, data.kind);
                    else if (error.status === 503) self.unavailable(error.message);
                    else if (error.status === 422 && error.message) self.error = error.message;
                });
            },

            /**
             * The proof of work for a new chat, in a worker: for the given challenge, or a new one
             * (started when they begin typing, so page views cost nothing).
             */
            prove: function (challenge) {
                var self = this;
                if (!challenge && this.proof) return this.proof;
                var asked = challenge ? Promise.resolve(challenge) : this.api('GET', 'p');
                var proof = asked.then(function (data) {
                    return new Promise(function (resolve, reject) {
                        var worker = new Worker('x?v=' + encodeURIComponent(config.workerVersion));
                        worker.onmessage = function (event) { worker.terminate(); resolve(event.data.proof); };
                        worker.onerror = function (event) { worker.terminate(); reject(event); };
                        worker.postMessage(data);
                    });
                });
                if (!challenge) this.proof = proof;
                return proof;
            },

            typing: function () {
                this.activeAt = Date.now();
                if (!this.hasConversation && !this.proof) this.prove().catch(function () {});
            },

            /** Over a limit: how long until the next message (or files), counted down. */
            wait: function (seconds, kind) {
                var self = this;
                this.waitUntil = Date.now() + seconds * 1000;
                this.waitKind = kind;
                clearInterval(this.waitTimer);
                var tick = function () {
                    var left = Math.ceil((self.waitUntil - Date.now()) / 1000);
                    if (left <= 0) {
                        clearInterval(self.waitTimer);
                        self.waitText = '';
                        return;
                    }
                    var when = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' })
                        .format(left < 90 ? left : Math.ceil(left / 60), left < 90 ? 'second' : 'minute');
                    self.waitText = (kind === 'files' ? self.text.waitFiles : self.text.waitMessages).replace(':time', when);
                };
                tick();
                this.waitTimer = setInterval(tick, 1000);
            },

            /** New chats aren't taken now: said, and (for a new visitor) nothing to write in. */
            unavailable: function (message) {
                this.available = false;
                this.error = message || this.text.unavailable;
            },

            /**
             * Signed in on the website, which signed who they are: what they wrote here moves to their
             * account, and with nothing written yet, their latest chat continues; shown anew.
             */
            identify: function (identity) {
                var self = this;
                if (!identity) return;
                var form = new FormData();
                form.append('identity', identity);
                form.append('language', navigator.language || '');
                this.api('POST', 'i', form).then(function (data) {
                    if (data.token) self.token(data.token);
                    clearTimeout(timer);
                    Object.assign(self, { entries: [], cursor: 0, hasConversation: false, email: null, emailStep: 'address', emailDraft: '' });
                    return self.poll();
                }).catch(function () {});
            },

            /** Where replies also go, from the server; an address waiting for its code shows that step. */
            emailState: function (data) {
                this.email = data.email || null;
                if (data.pending && this.emailStep === 'address' && !this.emailDraft) {
                    this.emailDraft = data.pending;
                    this.emailStep = 'code';
                }
                if (data.codes_left !== undefined && data.codes_left !== null) this.codesLeft = data.codes_left;
            },

            get codeIntro() {
                var strong = document.createElement('strong');
                strong.textContent = this.emailDraft;
                var span = document.createElement('span');
                span.textContent = config.codeIntro;
                return span.innerHTML.replace('%EMAIL%', strong.outerHTML);
            },

            emailPost: function (path, name, value) {
                var form = new FormData();
                form.append(name, value);
                this.busy = true;
                var self = this;
                return this.api('POST', path, form).finally(function () { self.busy = false; });
            },

            sendCode: function () {
                var self = this;
                var input = document.getElementById('widget-email');
                if (!this.emailDraft.trim() || !input.checkValidity()) {
                    this.emailError = this.text.emailInvalid;
                    return void input.focus();
                }
                this.emailPost('e', 'email', this.emailDraft.trim()).then(function (data) {
                    self.emailState(data);
                    self.codeNotice = self.text.codeSent;
                    self.emailStep = 'code';
                    self.$nextTick(function () { document.getElementById('widget-code').focus(); });
                }).catch(function (error) {
                    self.emailError = error.message;
                    input.focus();
                });
            },

            sendAgain: function () {
                var self = this;
                this.emailPost('e', 'email', this.emailDraft.trim()).then(function (data) {
                    self.emailState(data);
                    self.codeNotice = self.codesLeft > 0 ? self.text.codeAgain : self.text.codeLast;
                }).catch(function (error) {
                    self.codeNotice = error.message;
                }).then(function () { document.getElementById('widget-code').focus(); });
            },

            changeAddress: function () {
                this.emailStep = 'address';
                this.codeDraft = '';
                this.codeError = '';
                this.$nextTick(function () { document.getElementById('widget-email').focus(); });
            },

            /** Only a confirmed address gets email: a mistyped one never receives the conversation. */
            confirmCode: function () {
                var self = this;
                if (!/^\d{6}$/.test(this.codeDraft.trim())) {
                    this.codeError = this.text.codeInvalid;
                    return void document.getElementById('widget-code').focus();
                }
                this.emailPost('c', 'code', this.codeDraft.trim()).then(function (data) {
                    self.emailState(data);
                    self.announcement = self.text.confirmed;
                    self.$refs.message.focus();
                }).catch(function (error) {
                    self.codeError = error.message;
                    document.getElementById('widget-code').focus();
                });
            },

            /** Signed out on the website: a new, anonymous visitor. */
            logout: function () {
                clearTimeout(timer);
                this.token(null);
                memory = null;
                Object.assign(this, { entries: [], greeting: config.greeting, draft: '', files: [], error: '', unread: 0, announcement: '', cursor: 0, hasConversation: false,
                    email: null, emailStep: 'address', emailDraft: '', emailError: '', codeDraft: '', codeError: '', codeNotice: '', codesLeft: 3 });
                this.post('unread', { count: 0, label: this.text.label });
            },
        };
    });
});
