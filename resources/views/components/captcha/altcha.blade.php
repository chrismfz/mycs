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
    // A solution counts once: after each request (a failed login, a validation error) solve a new one.
    Livewire.hook('request', ({ succeed }) => {
        succeed(() => {
            $wire.set('captcha', '', false);
            widget.reset?.();
        });
    });
</script>
@endscript
