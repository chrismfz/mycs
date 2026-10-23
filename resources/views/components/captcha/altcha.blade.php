{{-- Our captcha (docs/captcha.md): the browser does a little work, we check it; nothing goes to anyone else. --}}
@assets
@vite('resources/js/altcha-entry.js')
@endassets

<altcha-widget
    challenge="{{ route('captcha.altcha') }}"
    language="{{ app()->getLocale() === 'el' ? 'el' : 'en' }}"
    configuration='{"hideFooter": true}'
></altcha-widget>

@script
<script>
    const widget = $wire.$el.querySelector('altcha-widget');
    // Solved: the payload is sent now, so the error of the try before goes (Captchable::updated); counted only when the
    // form is sent.
    widget.addEventListener('statechange', (ev) => {
        if (ev.detail.state === 'verified') {
            $wire.set('captcha', ev.detail.payload);
        }
    });
    // A solution counts once: after this form was sent (a failed login, a validation error) solve a new one. Only this
    // component's requests that call an action, not a live field's update (code review of #222); cleaned up with it.
    // Livewire 4 sends a live $wire.set as a «$set» call: not the form sent, so never this reset (owner 2026-10-23: the
    // solution was cleared the moment it was found, and no form ever passed).
    $wire.$hook('commit', ({ commit, succeed }) => {
        if (!(commit.calls ?? []).some((call) => !String(call.method ?? '').startsWith('$'))) {
            return;
        }
        succeed(() => {
            $wire.set('captcha', '', false);
            widget.reset?.();
        });
    });
</script>
@endscript
