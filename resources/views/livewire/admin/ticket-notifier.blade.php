{{--
    The tickets' live count and the «New ticket» notice on every admin page (App\Livewire\Admin\TicketNotifier).
    The count goes to the sidebar's «Tickets» queue (it listens for mycs-tickets-awaiting) and to a badge of the
    Filament menu already drawn for the tickets. The browser keeps the last ticket it showed in localStorage, so a
    ticket is shown once, not once per tab; the first visit in a browser only remembers. Without localStorage it
    remembers in the tab.
--}}
<div wire:poll.45s.keep-alive hidden class="mycs-ticket-notifier"
    data-staff="{{ $staffId }}" data-count="{{ $count }}" data-latest="{{ $latest }}"
    data-title="{{ $title }}" data-subject="{{ $subject }}" data-url="{{ $url }}" data-index="{{ $index }}"
    data-open="{{ __('Open') }}"
    x-data="{
        observer: null,
        key() { return 'mycs-ticket-shown-' + this.$el.dataset.staff },
        remember(value) {
            window.mycsTicketShown ??= {};
            try {
                if (value !== undefined) localStorage.setItem(this.key(), value);
                return localStorage.getItem(this.key()) ?? window.mycsTicketShown[this.key()] ?? null;
            } catch (e) {
                if (value !== undefined) window.mycsTicketShown[this.key()] = value;
                return window.mycsTicketShown[this.key()] ?? null;
            }
        },
        init() {
            this.check();
            this.observer = new MutationObserver(() => this.check());
            this.observer.observe(this.$el, { attributes: true, attributeFilter: ['data-count', 'data-latest'] });
        },
        destroy() { this.observer?.disconnect() },
        badge() {
            const d = this.$el.dataset;
            const count = Number(d.count);
            window.mycsTicketsAwaiting = count;
            window.dispatchEvent(new CustomEvent('mycs-tickets-awaiting', { detail: count }));
            // The Filament menu's own badge, when it is drawn (it has one only while there was a count at page load).
            const path = new URL(d.index, location.href).pathname;
            document.querySelectorAll('.fi-sidebar-item a, .fi-topbar a').forEach((link) => {
                if (new URL(link.href, location.href).pathname !== path) return;
                const shown = link.querySelector('.fi-badge');
                if (shown) { (shown.querySelector('.fi-badge-label') ?? shown).textContent = count; shown.hidden = count === 0 }
            });
        },
        check() {
            this.badge();
            const d = this.$el.dataset;
            const latest = Number(d.latest);
            const shown = this.remember();
            if (shown === null) { this.remember(String(latest)); return }
            if (!latest || latest <= Number(shown)) return;
            this.remember(String(latest));
            // Not for the ticket that is already open in front of us.
            if (d.url && location.pathname === new URL(d.url, location.href).pathname) return;
            if (!window.FilamentNotification) return;
            new window.FilamentNotification()
                .title(d.title).body(d.subject).icon('heroicon-o-ticket').info().duration(15000)
                .actions([new window.FilamentNotificationAction('open').label(d.open).url(d.url).button()])
                .send();
        },
    }"
    x-on:livewire:navigated.document="badge()"></div>
