// Our captcha's widget (docs/captcha.md), built and served here: it only fetches its challenge from /captcha/altcha.
import 'altcha'
import 'altcha/i18n/el'
import 'altcha/i18n/en'

// The widget draws itself inside its own element (no shadow DOM), and Livewire's navigation keeps the page's HTML for the
// browser's «back»: kept drawn, the widget restored from it draws itself again next to the old one, one more on each
// «back» (Ilias, 2026-11-06). Emptied before the page is kept, so a widget restored draws once.
document.addEventListener('livewire:navigating', () => {
    document.querySelectorAll('altcha-widget').forEach((widget) => widget.replaceChildren())
})
