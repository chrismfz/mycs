/*! MyCS chat launcher v1 (docs/livechat.md §4). Opens our chat popup on a click: no fetch, no cookies, nothing read from
 * the page but location.pathname. A button with data-mycs-chat="<the popup's address>" is all it needs. */
(function () {
    'use strict';
    var buttons = document.querySelectorAll('[data-mycs-chat]');
    for (var i = 0; i < buttons.length; i++) {
        // Once, however many copies of this code a page carries.
        if (buttons[i].hasAttribute('data-mycs-bound')) {
            continue;
        }
        buttons[i].setAttribute('data-mycs-bound', '');
        buttons[i].addEventListener('click', function (event) {
            var url = new URL(event.currentTarget.getAttribute('data-mycs-chat'));
            url.searchParams.set('page', location.pathname);
            window.open(url.toString(), 'mycs-chat', 'noopener,popup,width=420,height=640');
            if (typeof window.gtag === 'function') {
                window.gtag('event', 'chat_opened');
            }
        });
    }
})();
