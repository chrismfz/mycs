{{--
    The staff chat's alert on every admin page (docs/livechat.md §7γ). The server says only how many messages wait, the
    newest one's id and who wrote (F21); the browser keeps the last id it alerted for in localStorage, so a tab alerts
    once per new message and two tabs do not both ring. The first visit in a browser only remembers, never rings.
--}}
<div wire:poll.10s.keep-alive hidden
    data-staff="{{ $staffId }}" data-total="{{ $news['total'] }}" data-latest="{{ $news['latest'] }}"
    data-title="{{ $news['title'] }}" data-url="{{ $url }}" data-page="{{ $page }}"
    x-data="{
        key() { return 'mycs-chat-alerted-' + this.$el.dataset.staff },
        store(name, value) { try { value === undefined ? null : localStorage.setItem(name, value); return localStorage.getItem(name) } catch (e) { return null } },
        title() {
            const total = Number(this.$el.dataset.total);
            const bare = document.title.replace(/^\(\d+\+?\) /, '');
            document.title = total > 0 ? '(' + (total > 99 ? '99+' : total) + ') ' + bare : bare;
        },
        check() {
            this.title();
            const latest = Number(this.$el.dataset.latest);
            const alerted = this.store(this.key());
            if (alerted === null) { this.store(this.key(), String(latest)); return }
            if (!latest || latest <= Number(alerted)) return;
            this.store(this.key(), String(latest));
            const onPage = location.pathname === new URL(this.$el.dataset.page, location.href).pathname;
            if (onPage && document.visibilityState === 'visible') return;
            window.mycsChatAlert?.(this.$el.dataset.title, this.$el.dataset.url, document.visibilityState !== 'visible');
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
                const note = new Notification(title, { tag: 'mycs-staff-chat' });
                note.onclick = () => { window.focus(); window.location.href = url; note.close() };
            }
        };
        document.addEventListener('click', () => window.mycsChatAudio?.resume?.(), { once: true });
        check();
        new MutationObserver(() => check()).observe($el, { attributes: true, attributeFilter: ['data-latest', 'data-total'] });
    "
    x-on:livewire:navigated.document="title()"></div>
