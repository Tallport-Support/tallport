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
        // The chime: the visitor's choice, kept in this browser (the website may start it off), and when it last played.
        var soundKey = 'tallport:sound';
        var audio = null;
        var chimed = 0;
        // The newest team answer the visitor has been told about, where the browser refuses storage.
        var toldMemory = 0;

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
            sound: params.get('sound') !== 'off',

            init: function () {
                var self = this;
                try {
                    var chosen = localStorage.getItem(soundKey);
                    if (chosen) this.sound = chosen === 'on';
                } catch (e) {
                    // Without storage the website's choice holds for this visit.
                }
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
                document.addEventListener('visibilitychange', function () { self.schedule(document.hidden ? undefined : 0); });
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
                    // The card beside the launcher, for an answer.
                    text: resolve('--f-text'),
                    secondary: resolve('--f-secondary'),
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
                    entries.unshift({ id: 'greeting', kind: 'team', team: true, author: config.team, initials: config.team.charAt(0).toUpperCase(), photo: config.teamPhoto, body: this.greeting, files: [] });
                }
                // Where replies also go, after the greeting; or, after their first message, the offer
                // (only from a mailbox with email).
                if (config.emailReplies && this.email) {
                    entries.splice(this.greeting ? 1 : 0, 0, { id: 'email-event', kind: 'event', body: this.text.repliesTo.replace(':email', this.email) });
                } else if (config.emailReplies) {
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
             * Asking for new messages: every few seconds while open and in use, slower while open and idle;
             * only with a conversation, more slowly while closed (for the unread count and an answer's card),
             * and slowest while the website's tab is hidden (for its title and the chime).
             */
            schedule: function (delay) {
                var self = this;
                clearTimeout(timer);
                if (!this.token()) return;
                if (delay === undefined) {
                    var seen = this.shown && !document.hidden;
                    var active = seen && Date.now() - this.activeAt < 120000;
                    if (!seen && !this.hasConversation) return;
                    delay = (active ? config.poll.active : seen ? config.poll.idle : document.hidden ? config.poll.hidden : config.poll.closed) * 1000;
                }
                timer = setTimeout(function () { self.poll(); }, delay);
            },

            poll: function () {
                var self = this;
                // From a tab in the background: not the visitor being on the website (a reply they don't see goes by email too).
                return this.api('GET', 'm?after=' + this.cursor + (document.hidden ? '&hidden=1' : '')).then(function (data) {
                    self.hasConversation = data.messages.length > 0 || self.hasConversation;
                    // The team's newest answer the visitor hasn't read or been told about (told()): news, once.
                    var told = Math.max(self.told(), data.read || 0);
                    var news = null;
                    data.messages.forEach(function (message) {
                        if (self.receive(message) && message.kind === 'team' && message.id > told) news = message;
                    });
                    self.cursor = Math.max(self.cursor, data.cursor || 0);
                    if (data.greeting !== undefined) self.greeting = data.greeting;
                    self.emailState(data);
                    self.unread = data.unread;
                    if (self.shown) self.markRead();
                    else self.post('unread', Object.assign({ count: self.unread, label: data.unread_label }, news ? self.news(news) : {}));
                    if (news) {
                        self.notice(news);
                        self.told(news.id);
                    }
                }).catch(function () {}).then(function () { self.schedule(); });
            },

            /**
             * The newest of the team's answers the visitor has been told about (announced: the card, the
             * tab's title, the chime; or read), kept in this browser for their token: only newer ones are
             * news. A new page or a sign-in isn't: an answer waiting for them is announced once, on the
             * next page. Raised with a value.
             */
            told: function (value) {
                var marker = key + ':told:' + String(this.token() || '').slice(-16);
                var stored = toldMemory;
                try {
                    stored = Math.max(stored, parseInt(localStorage.getItem(marker)) || 0);
                } catch (e) {
                    // Without storage, for this page.
                }
                if (value === undefined || value <= stored) return stored;
                toldMemory = value;
                try {
                    localStorage.setItem(marker, String(value));
                } catch (e) {}

                return value;
            },

            markRead: function () {
                var last = this.entries.filter(function (entry) { return entry.kind === 'team' && entry.serverId; }).pop();
                this.unread = 0;
                this.post('unread', { count: 0, label: this.text.label });
                if (last) this.told(last.serverId);
                if (last && last.serverId > (this.readId || 0)) {
                    this.readId = last.serverId;
                    var form = new FormData();
                    form.append('thread_id', last.serverId);
                    this.api('POST', 'r', form).catch(function () {});
                }
            },

            /** A message from the server, once (true when it's new): the team's are announced while the window is open. */
            receive: function (message) {
                var known = this.entries.some(function (entry) { return entry.serverId === message.id; });
                if (known) return false;
                this.entries.push(this.entry(message));
                if (message.kind === 'team' && this.shown) this.announcement = message.author + ': ' + message.body;

                return true;
            },

            /**
             * An answer while the window is closed, for the launcher: the website's tab's title while it's
             * in the background ("(1) Alex replied") and the card beside it (who, and the answer's start).
             */
            news: function (message) {
                var who = message.team ? message.author : message.author.split(' ')[0];
                var text = message.body || message.files.map(function (file) { return file.name; }).join(', ');

                return { title: this.text.replied.replace(':name', who), preview: { author: message.author, initials: message.initials, text: text, dismiss: this.text.dismiss } };
            },

            /**
             * An answer the visitor may not see (the window closed, or the website's tab in the background):
             * a soft chime, unless muted; open in a background tab, the tab's title says so too.
             */
            notice: function (message) {
                if (!this.shown || document.hidden) this.chime();
                if (this.shown && document.hidden) this.post('attention', { title: this.news(message).title });
            },

            /**
             * Two soft notes, once for answers that arrive close together. The visitor has typed in this
             * window, which lets it play sound; without audio the launcher and the title still say it.
             */
            chime: function () {
                if (!this.sound || Date.now() - chimed < 8000) return;
                chimed = Date.now();
                try {
                    audio = audio || new AudioContext();
                    audio.resume();
                    var now = audio.currentTime;
                    [[880, 0], [1318.5, 0.12]].forEach(function (note) {
                        var tone = audio.createOscillator();
                        var volume = audio.createGain();
                        tone.frequency.value = note[0];
                        volume.gain.setValueAtTime(0.0001, now + note[1]);
                        volume.gain.exponentialRampToValueAtTime(0.08, now + note[1] + 0.02);
                        volume.gain.exponentialRampToValueAtTime(0.0001, now + note[1] + 0.5);
                        tone.connect(volume).connect(audio.destination);
                        tone.start(now + note[1]);
                        tone.stop(now + note[1] + 0.55);
                    });
                } catch (e) {
                    // No audio here.
                }
            },

            /** Sound on or off, kept in this browser; turned on, it plays once so the visitor hears it. */
            toggleSound: function () {
                this.sound = !this.sound;
                try {
                    localStorage.setItem(soundKey, this.sound ? 'on' : 'off');
                } catch (e) {
                    // The choice holds for this visit.
                }
                if (this.sound) {
                    chimed = 0;
                    this.chime();
                }
            },

            /**
             * A message's text in parts, its web addresses (http, https, www.) as links: shown as text and
             * links, never as HTML. Punctuation after an address (a sentence's end, a closing bracket
             * without its opening one) isn't part of it.
             */
            parts: function (text) {
                var parts = [];
                var pattern = /\b(?:https?:\/\/|www\.)[^\s<>"]+/gi;
                var last = 0;
                var match;
                text = String(text || '');
                while ((match = pattern.exec(text))) {
                    var address = match[0].replace(/[.,:;!?'’]+$/, '');
                    while (/[)\]}]$/.test(address) && (address.match(/[([{]/g) || []).length < (address.match(/[)\]}]/g) || []).length) {
                        address = address.slice(0, -1).replace(/[.,:;!?'’]+$/, '');
                    }
                    if (match.index > last) parts.push({ text: text.slice(last, match.index) });
                    parts.push({ text: address, url: /^www\./i.test(address) ? 'https://' + address : address });
                    last = match.index + address.length;
                    pattern.lastIndex = last;
                }
                if (last < text.length) parts.push({ text: text.slice(last) });

                return parts;
            },

            entry: function (message) {
                return {
                    id: 'm' + message.id,
                    serverId: message.id,
                    kind: message.kind,
                    team: message.team,
                    author: message.kind === 'visitor' ? this.text.you : message.author,
                    initials: message.initials,
                    photo: message.photo,
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
                // Shown counting down; said once at the start and once when it's over (not every second).
                var first = true;
                var tick = function () {
                    var left = Math.ceil((self.waitUntil - Date.now()) / 1000);
                    if (left <= 0) {
                        clearInterval(self.waitTimer);
                        self.waitText = '';
                        self.announcement = self.text.waitOver;
                        return;
                    }
                    var when = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' })
                        .format(left < 90 ? left : Math.ceil(left / 60), left < 90 ? 'second' : 'minute');
                    self.waitText = (kind === 'files' ? self.text.waitFiles : self.text.waitMessages).replace(':time', when);
                    if (first) self.announcement = self.waitText;
                    first = false;
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
                    // Someone else: what they were told is their own (told()).
                    if (data.token) {
                        self.token(data.token);
                        toldMemory = 0;
                    }
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
                toldMemory = 0;
                Object.assign(this, { entries: [], greeting: config.greeting, draft: '', files: [], error: '', unread: 0, announcement: '', cursor: 0, hasConversation: false,
                    email: null, emailStep: 'address', emailDraft: '', emailError: '', codeDraft: '', codeError: '', codeNotice: '', codesLeft: 3 });
                this.post('unread', { count: 0, label: this.text.label });
            },
        };
    });
});
