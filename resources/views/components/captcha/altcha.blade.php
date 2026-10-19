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
    // Solved: the payload goes with the form's next request (as the other captchas' tokens).
    widget.addEventListener('statechange', (ev) => {
        if (ev.detail.state === 'verified') {
            $wire.set('captcha', ev.detail.payload, false);
        }
    });
    // A solution counts once: after this form was sent (a failed login, a validation error) solve a new one. Only this
    // component's requests that call an action, not a live field's update (code review of #222); cleaned up with it.
    $wire.$hook('commit', ({ commit, succeed }) => {
        if (!(commit.calls ?? []).length) {
            return;
        }
        succeed(() => {
            $wire.set('captcha', '', false);
            widget.reset?.();
        });
    });
</script>
@endscript
