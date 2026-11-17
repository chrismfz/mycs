{{--
    The «Chat» button on MyCS's own pages for a visitor who is not signed in (docs/livechat.md §4, step 4): the same popup
    the launcher on our sites opens, with this instance's host as the site and the page's path. No fetch, no polling: it
    only opens the window. The address is escaped into an attribute; the path is added by the URL API in the browser.
--}}
<button type="button" data-mycs-guest-chat="{{ route('chat', ['src' => (string) parse_url((string) config('app.url'), PHP_URL_HOST)]) }}"
    x-data x-on:click="const url = new URL($el.dataset.mycsGuestChat); url.searchParams.set('page', location.pathname); window.open(url.toString(), 'mycs-chat', 'noopener,popup,width=420,height=640')"
    @class(['fixed bottom-4 right-4 z-40 inline-flex items-center justify-center gap-2 rounded-full bg-primary text-sm font-semibold text-white shadow-lg hover:opacity-90',
        'h-12 w-12' => \App\Support\ChatLauncher::ownStyle() === 'icon', 'px-4 py-2' => \App\Support\ChatLauncher::ownStyle() !== 'icon'])
    @if (\App\Support\ChatLauncher::ownStyle() === 'icon') title="{{ __('Chat') }}" @endif>
    <x-chat-button-face />
</button>
