<?php

namespace App\Services\Chat;

/**
 * One message as a card, the way WHMCS posts it (an «attachment» in Mattermost's and Slack's incoming webhooks): a
 * coloured bar, a title that links to the admin page, a line of what happened, and short fields side by side.
 *
 * The title and the fallback are plain text in both chats, so a customer's text in them only has its mentions broken
 * (ChatNotifier::plain()); the line and the fields' values are markdown, so a customer's text there goes through
 * ChatNotifier::escape() or ChatNotifier::link() before it is added.
 */
final class ChatCard
{
    public const GREEN = '#2eb886';

    public const BLUE = '#3aa3e3';

    public const ORANGE = '#e8a33d';

    public const RED = '#d0021b';

    public const PURPLE = '#8e5cd9';

    public const GREY = '#9e9e9e';

    /** @var list<array{title: string, value: string, short: bool}> */
    private array $fields = [];

    /**
     * @param  string  $title  plain text (a customer's part through ChatNotifier::plain())
     * @param  string  $text  markdown (a customer's part through ChatNotifier::escape())
     */
    public function __construct(private string $title, private ?string $url, private string $text, private string $color) {}

    /** A field; its value is markdown, already escaped or a link. An empty value is left out. */
    public function field(string $label, ?string $value, bool $short = true): self
    {
        if ($value !== null && trim($value) !== '') {
            $this->fields[] = ['title' => $label, 'value' => $value, 'short' => $short];
        }

        return $this;
    }

    /** What a phone's notification and a chat without cards show: one plain line. */
    public function fallback(): string
    {
        return $this->title . ' · ' . $this->plainText();
    }

    /** @return array<string, mixed> the attachment, as both chats take it */
    public function toArray(): array
    {
        return array_filter([
            'fallback' => mb_substr($this->fallback(), 0, 300),
            'color' => $this->color,
            'title' => mb_substr($this->title, 0, 250),
            'title_link' => $this->url,
            'text' => $this->text,
            'fields' => array_map(fn (array $field) => ['title' => $field['title'], 'value' => self::cut($field['value'], 1000), 'short' => $field['short']], $this->fields),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    /** Cut to $length, never in the middle of an escape (a lone «\\» at the end would escape what follows it). */
    private static function cut(string $markdown, int $length): string
    {
        if (mb_strlen($markdown) <= $length) {
            return $markdown;
        }
        $cut = mb_substr($markdown, 0, $length - 1);
        preg_match('/\\\\+$/', $cut, $tail);

        return (isset($tail[0]) && strlen($tail[0]) % 2 === 1 ? substr($cut, 0, -1) : $cut) . '…';
    }

    /** The line without its markdown's escapes, for the fallback. */
    private function plainText(): string
    {
        return (string) preg_replace('/\\\\([\\\\`*_\[\]()~>|!])/', '$1', $this->text);
    }
}
