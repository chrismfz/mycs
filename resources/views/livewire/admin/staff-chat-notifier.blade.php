{{--
    The staff chat's alert on every admin page (docs/livechat.md §7γ). The server says only how many messages wait, the
    newest one's id and who wrote (F21); the browser keeps the last id it alerted for in localStorage, so a tab alerts
    once per new message and two tabs do not both ring. The first visit in a browser only remembers, never rings.
    Without localStorage it remembers in the tab, so it still rings (review of #382). «Away» is a hidden tab or a window
    without focus (PuTTY in front, the page on another screen); a Live chat page in front and focused, in any tab of
    this browser, keeps the others quiet.
--}}
<div wire:poll.10s.keep-alive hidden
    data-staff="{{ $staffId }}" data-total="{{ $news['total'] }}" data-latest="{{ $news['latest'] }}"
    data-title="{{ $news['title'] }}" data-url="{{ $url }}" data-page="{{ $page }}"
    {{-- Customers' chats (step 3α): a new chat waiting in the member's departments, a new message in their own chats. --}}
    data-waiting="{{ $customers['waiting'] }}"
    data-chat-latest="{{ $customers['chat_latest'] }}" data-chat-title="{{ $customers['chat_title'] }}" data-chat-url="{{ $page }}"
    data-msg-latest="{{ $customers['message_latest'] }}" data-msg-title="{{ $customers['message_title'] }}" data-msg-url="{{ $customers['message_url'] }}"
    x-data="{
        key() { return 'mycs-chat-alerted-' + this.$el.dataset.staff },
        timer: null,
        store(name, value) {
            window.mycsChatMemory ??= {};
            if (value !== undefined) window.mycsChatMemory[name] = value;
            // Once localStorage fails to write (blocked, private mode, quota), this tab keeps to its memory (re-review of #382).
            if (!window.mycsChatNoStorage) {
                try {
                    if (value !== undefined) localStorage.setItem(name, value);
                    return localStorage.getItem(name);
                } catch (e) {
                    window.mycsChatNoStorage = true;
                }
            }
            return window.mycsChatMemory[name] ?? null;
        },
        onPage() { return location.pathname === new URL(this.$el.dataset.page, location.href).pathname },
        front() { if (this.onPage()) this.store('mycs-chat-front', this.away() ? '0' : String(Date.now())) },
        observer: null,
        destroy() { clearInterval(this.timer); this.observer?.disconnect() },
        // The set-up lives in init(), never in x-init: Alpine calls an x-init whose value is a function, and the first
        // statement there (`window.mycsChatAlert ??= …`) was that function, so every page rang (review of #396).
        init() {
            window.mycsChatAlert ??= (title, url, notify) => {
                let muted = false;
                try { muted = localStorage.getItem('mycs-chat-muted') === '1' } catch (e) {}
                // A tone only on a running audio context: one scheduled on a suspended context would wait for a click.
                const ctx = window.mycsChatAudio;
                if (!muted && ctx && ctx.state === 'running') {
                    try {
                        const tone = ctx.createOscillator(); const gain = ctx.createGain();
                        tone.frequency.value = 880; gain.gain.setValueAtTime(0.08, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.3);
                        tone.connect(gain).connect(ctx.destination); tone.start(); tone.stop(ctx.currentTime + 0.3);
                    } catch (e) {}
                }
                if (notify && 'Notification' in window && Notification.permission === 'granted') {
                    const note = new Notification(title, { tag: 'mycs-staff-chat', renotify: true });
                    note.onclick = () => { window.focus(); window.location.href = url; note.close() };
                }
            };
            // The browser lets a page make sound only after a gesture: the first click or key of the window unlocks it,
            // and again whenever the browser suspends it (Safari, iOS).
            // The listeners go only once the context really runs: a touch's pointerdown or an Esc does not count as a
            // gesture to the browser (re-review of #396).
            window.mycsChatUnlock ??= () => {
                try {
                    const ctx = window.mycsChatAudio ??= new AudioContext();
                    ctx.onstatechange = () => { if (ctx.state !== 'running') window.mycsChatListen() };
                    ctx.resume().then(() => {
                        if (ctx.state === 'running') ['pointerdown', 'pointerup', 'keydown'].forEach((type) => document.removeEventListener(type, window.mycsChatUnlock));
                    }).catch(() => {});
                } catch (e) {}
            };
            window.mycsChatListen ??= () => ['pointerdown', 'pointerup', 'keydown'].forEach((type) => document.addEventListener(type, window.mycsChatUnlock));
            if (!window.mycsChatListening) { window.mycsChatListening = true; window.mycsChatListen() }
            this.check();
            // A quiet poll changes nothing, so the front mark is kept fresh by a timer of its own (re-review of #382).
            this.timer = setInterval(() => this.front(), 5000);
            this.observer = new MutationObserver(() => this.check());
            this.observer.observe(this.$el, { attributes: true, attributeFilter: ['data-latest', 'data-total', 'data-waiting', 'data-chat-latest', 'data-msg-latest'] });
        },
        away() { return document.visibilityState !== 'visible' || !document.hasFocus() },
        title() {
            const total = Number(this.$el.dataset.total) + Number(this.$el.dataset.waiting ?? 0);
            const bare = document.title.replace(/^\(\d+\+?\) /, '');
            document.title = total > 0 ? '(' + (total > 99 ? '99+' : total) + ') ' + bare : bare;
        },
        check() {
            this.title();
            // The Live chat page marks itself in front, and unmarks itself as soon as it is not.
            this.front();
            const d = this.$el.dataset;
            // Three channels, each with its own last id (ids of different tables never compare): the staff's chat, a new
            // customer's chat waiting, a new message of a customer in the member's own chats.
            this.ring('', Number(d.latest), d.title, d.url);
            this.ring('-waiting', Number(d.chatLatest), d.chatTitle, d.chatUrl);
            this.ring('-customer', Number(d.msgLatest), d.msgTitle, d.msgUrl);
        },
        ring(channel, latest, title, url) {
            const key = this.key() + channel;
            const alerted = this.store(key);
            // The tab's own memory guards too: whatever storage says, a tab never rings twice for one message.
            if (typeof window.mycsChatRung !== 'object' || window.mycsChatRung === null) window.mycsChatRung = {};
            const rung = window.mycsChatRung[channel] ?? 0;
            if (alerted === null) { this.store(key, String(latest)); window.mycsChatRung[channel] = Math.max(rung, latest); return }
            if (!latest || latest <= Math.max(Number(alerted), rung)) return;
            this.store(key, String(latest));
            window.mycsChatRung[channel] = latest;
            if (this.onPage() && !this.away()) return;
            // Another tab has the Live chat page in front: the member is reading there.
            if (Date.now() - Number(this.store('mycs-chat-front') ?? 0) < 15000) return;
            window.mycsChatAlert?.(title, url, this.away());
        },
    }"
    x-on:livewire:navigated.document="title()"
    x-on:focus.window="check()" x-on:blur.window="front()" x-on:visibilitychange.document="check()"></div>
