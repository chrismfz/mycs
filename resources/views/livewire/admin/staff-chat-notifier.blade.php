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
        destroy() { clearInterval(this.timer) },
        away() { return document.visibilityState !== 'visible' || !document.hasFocus() },
        title() {
            const total = Number(this.$el.dataset.total);
            const bare = document.title.replace(/^\(\d+\+?\) /, '');
            document.title = total > 0 ? '(' + (total > 99 ? '99+' : total) + ') ' + bare : bare;
        },
        check() {
            this.title();
            const onPage = this.onPage();
            // The Live chat page marks itself in front, and unmarks itself as soon as it is not.
            this.front();
            const latest = Number(this.$el.dataset.latest);
            const alerted = this.store(this.key());
            if (alerted === null) { this.store(this.key(), String(latest)); return }
            if (!latest || latest <= Number(alerted)) return;
            this.store(this.key(), String(latest));
            if (onPage && !this.away()) return;
            // Another tab has the Live chat page in front: the member is reading there.
            if (Date.now() - Number(this.store('mycs-chat-front') ?? 0) < 15000) return;
            window.mycsChatAlert?.(this.$el.dataset.title, this.$el.dataset.url, this.away());
        },
    }"
    x-init="
        window.mycsChatAlert ??= (title, url, notify) => {
            let muted = false;
            try { muted = localStorage.getItem('mycs-chat-muted') === '1' } catch (e) {}
            if (!muted) {
                try {
                    const ctx = window.mycsChatAudio ??= new AudioContext();
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
        document.addEventListener('click', () => window.mycsChatAudio?.resume?.(), { once: true });
        check();
        // A quiet poll changes nothing, so the front mark is kept fresh by a timer of its own (re-review of #382).
        timer = setInterval(() => front(), 5000);
        new MutationObserver(() => check()).observe($el, { attributes: true, attributeFilter: ['data-latest', 'data-total'] });
    "
    x-on:livewire:navigated.document="title()"
    x-on:focus.window="check()" x-on:blur.window="front()" x-on:visibilitychange.document="check()"></div>
