<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Throwable;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * The mail's own CSS (the «Mail CSS» setting, in the header's <style>) written onto each element as it goes, as
 * Laravel's own Markdown mails are (owner 2026-10-24): Gmail and Outlook give a link their own colour and leave a
 * class's, so a button's white text came out blue on its dark background. The <style> stays, for the clients that
 * read it (the media queries). A mail it cannot read goes as it was.
 */
class InlineMailCss
{
    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        $html = $message->getHtmlBody();
        if (!is_string($html) || stripos($html, '<style') === false) {
            return;
        }
        try {
            $message->html((new CssToInlineStyles)->convert($html), $message->getHtmlCharset() ?? 'utf-8');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
