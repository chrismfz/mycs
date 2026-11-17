{{-- Our captcha (docs/captcha.md): the browser does a little work, we check it; nothing goes to anyone else. A form
     that always uses ours, whatever the others use (the chat popup, F14), names its own challenge route. --}}
@props(['challenge' => null])
@assets
@vite('resources/js/altcha-entry.js')
@endassets

{{-- The glue is Alpine's, on the widget's own wrapper, not a Livewire @script: Livewire 4 runs a script that comes with a
     later render before it puts that render's HTML on the page, so a widget that appears later (the chat popup's form,
     after «Continue as a visitor») was not there yet, its solution was never sent and every try was refused
     (2026-11-17). Alpine's init runs once the element is on the page, the first render or a later one alike. --}}
<div data-mycs-altcha x-data="{
    unhook: null,
    widget: null,
    // Solved: the payload is sent now, so the error of the try before goes (Captchable::updated); counted only when
    // the form is sent.
    solved(ev) {
        if (ev.detail.state === 'verified') {
            this.$wire.set('captcha', ev.detail.payload);
        }
    },
    init() {
        const widget = this.widget = this.$el.querySelector('altcha-widget');
        this.solved = this.solved.bind(this);
        widget.addEventListener('statechange', this.solved);
        // A solution counts once: after this form was sent (a failed login, a validation error) solve a new one. Only
        // this component's requests that call an action, not a live field's update (code review of #222): Livewire 4
        // sends a live $wire.set as a «$set» call and an event as «__dispatch», never this reset (owner 2026-10-23: the
        // solution was cleared the moment it was found, and no form ever passed).
        this.unhook = this.$wire.$hook('commit', ({ commit, succeed }) => {
            if (!(commit.calls ?? []).some((call) => !/^(\$|__)/.test(String(call.method ?? '')))) {
                return;
            }
            succeed(() => {
                this.$wire.set('captcha', '', false);
                widget.reset?.();
            });
        });
    },
    // Gone with its form (the popup's screens): its hook and listener go too, not only with the component.
    destroy() {
        this.unhook?.();
        this.widget?.removeEventListener('statechange', this.solved);
    },
}">
    <altcha-widget
        challenge="{{ $challenge ?? route('captcha.altcha') }}"
        language="{{ app()->getLocale() === 'el' ? 'el' : 'en' }}"
        configuration='{"hideFooter": true}'
    ></altcha-widget>
</div>
