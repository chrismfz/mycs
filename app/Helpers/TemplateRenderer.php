<?php

namespace App\Helpers;

use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Rules\ValidTemplate;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MyCS\MailTemplate\Html;
use MyCS\MailTemplate\Renderer;
use MyCS\MailTemplate\TemplateError;

/**
 * Laravel side of the mycs/mail-template renderer: staff-edited templates (email,
 * in-app notifications, mail header/footer, product email snippets) are rendered
 * from data, never compiled as Blade (docs/history/audit-security.md H1).
 */
class TemplateRenderer
{
    private static ?Renderer $renderer = null;

    public static function renderer(): Renderer
    {
        // A model's $hidden fields (password, 2FA secret, …) are not readable from a template.
        return self::$renderer ??= new Renderer(function (mixed $target, string $key): mixed {
            if ($target instanceof Model && in_array($key, $target->getHidden(), true)) {
                return null;
            }
            // Settings are a module's or a registrar's credentials, already decrypted when read: never in a template
            // (`service.server.settings`, in any case). A setting reached any other way reads as nothing too.
            if ($target instanceof Setting || ($target instanceof Model && strcasecmp($key, 'settings') === 0)) {
                return null;
            }
            // A path READS: a stored field, or a relation or accessor listed in READS; never a method (Eloquent would
            // call any argument-free one: `{{ invoice.createCancellationCreditNote }}` wrote a credit note, PR #158).
            if ($target instanceof Model && !self::readable($target, $key)) {
                return null;
            }
            // Staff-only fields that are not $hidden (staff edit them in forms): a service's notes (review F2).
            if ($target instanceof Model && defined($target::class . '::TEMPLATE_HIDDEN') && in_array($key, constant($target::class . '::TEMPLATE_HIDDEN'), true)) {
                return null;
            }

            $value = Renderer::read($target, $key);

            return $value instanceof Setting ? null : $value;
        },
            // A record or a list printed whole is all its fields as JSON (`{{ service }}`: its notes too): nothing, nor
            // anything else that is JSON (Arrayable, Jsonable). Fields are read one by one; a loop still walks a list.
            fn (object $value): bool => !$value instanceof Arrayable && !$value instanceof Jsonable);
    }

    /**
     * What a template may follow beyond a record's stored fields: per model, its relations and its accessors, by name
     * (docs/templates.md). Anything else reads as nothing: a method (Eloquent would call it), an accessor that does
     * work (an invoice's PDF), a trait's relation (audits: staff notes, password hashes), a relation to other
     * customers' records (a login shared by two accounts, a server's accounts) or to staff's notes (money events).
     *
     * @var array<class-string, array<string, list<string>>>
     */
    public const READS = [
        Client::class => ['relations' => ['primaryContact'], 'accessors' => ['name', 'email', 'firstName', 'lastName', 'country']],
        Domain::class => ['relations' => ['nameservers']],
        Invoice::class => ['relations' => ['items', 'client'], 'accessors' => ['total', 'remaining', 'formattedTotal', 'formattedRemaining']],
        InvoiceItem::class => ['accessors' => ['formattedPrice', 'formattedTotal']],
        Order::class => ['relations' => ['services', 'client'], 'accessors' => ['formattedTotal']],
        Service::class => ['relations' => ['product', 'plan', 'client', 'license'], 'accessors' => ['formattedPrice', 'label']],
        ServiceCancellation::class => ['relations' => ['service']],
        Ticket::class => ['relations' => ['client', 'service', 'department']],
        TicketMessage::class => ['relations' => ['ticket', 'user']],
        User::class => ['accessors' => ['name']],
    ];

    /** A stored field, or a relation or accessor its model lists in READS (exact name). */
    private static function readable(Model $model, string $key): bool
    {
        if (array_key_exists($key, $model->getAttributes())) {
            return true;
        }
        $reads = self::READS[$model::class] ?? [];

        return in_array($key, $reads['relations'] ?? [], true) || in_array($key, $reads['accessors'] ?? [], true);
    }

    /** @param  array<string, mixed>  $data */
    public static function render(?string $template, array $data, bool $escape = true): string
    {
        return $template === null || $template === '' ? '' : self::renderer()->render($template, $data, $escape);
    }

    /**
     * A template written in Markdown, made HTML: its tags run first (ifs and loops on the real data, as they always
     * did), but each value goes into the Markdown as a key of letters and digits, and in as escaped text only after
     * it. A customer's name, a ticket's subject or a service's label is text in the mail, never a link, an image or a
     * list of their own (reviews of #294 and #319). Every link's or image's address is checked once the values are in.
     *
     * @param  array<string, mixed>  $data
     */
    public static function renderMarkdown(?string $template, array $data): string
    {
        if ($template === null || $template === '') {
            return '';
        }
        // A key of this call only: text that looks like it (staff's or a value's) stays text.
        $key = 'MYCSVAL' . bin2hex(random_bytes(6)) . 'N';
        $values = [];
        $blocks = [];
        $marked = self::renderer()->renderWith($template, $data, function (string $text, bool $raw) use (&$values, &$blocks, $key): string {
            $values[] = $text;
            if ($raw) {
                $blocks[count($values) - 1] = true;
            }

            return $key . (count($values) - 1) . 'X';
        });
        $html = Str::markdown($marked, ['allow_unsafe_links' => false]);
        $k = preg_quote($key, '~');
        // An HTML value alone on its line (a product's snippet, a mass mail's message) is a block: not in a paragraph.
        $html = (string) preg_replace_callback('~<p>(' . $k . '(\d+)X)</p>~', fn (array $m) => isset($blocks[(int) $m[2]]) ? $m[1] : $m[0], $html);
        $html = (string) preg_replace_callback('~' . $k . '(\d+)X~', fn (array $m) => $values[(int) $m[1]] ?? $m[0], $html);

        return self::safeAddresses($html);
    }

    /** Attributes that open an address. */
    private const ADDRESS_ATTRIBUTES = ['href', 'src', 'srcset', 'data', 'action', 'formaction', 'background', 'poster', 'xlink:href'];

    /**
     * Every address an attribute opens (href, src, srcset, …), as it is with its values in and as a browser reads it: a
     * javascript: or data: one is "#". An image may also be inline (cid:, a data: PNG, JPEG, GIF or WebP). Tags are
     * read as a browser reads them (attribute after attribute, never inside a value); text between them is left alone.
     */
    private static function safeAddresses(string $html): string
    {
        $out = '';
        $at = 0;
        $length = strlen($html);
        while (($open = strpos($html, '<', $at)) !== false) {
            $out .= substr($html, $at, $open - $at);
            if (!ctype_alpha($html[$open + 1] ?? '')) {
                $out .= '<';
                $at = $open + 1;

                continue;
            }
            [$tag, $at] = self::safeTag($html, $open, $length);
            $out .= $tag;
        }

        return $out . substr($html, $at);
    }

    /**
     * One tag from `$open` ("<" and a letter), its address attributes checked: [the tag, where it ends].
     *
     * @return array{string, int}
     */
    private static function safeTag(string $html, int $open, int $length): array
    {
        $i = $open + 1 + strcspn($html, " \t\n\r\f/>", $open + 1); // past the tag's name
        $tag = substr($html, $open, $i - $open);
        while ($i < $length) {
            $gap = strspn($html, " \t\n\r\f/", $i);
            $tag .= substr($html, $i, $gap);
            $i += $gap;
            if ($i >= $length || $html[$i] === '>') {
                break;
            }
            $nameLength = max(1, strcspn($html, " \t\n\r\f/>=", $i + 1) + 1);
            $name = substr($html, $i, $nameLength);
            $i += $nameLength;
            $tag .= $name;
            $space = strspn($html, " \t\n\r\f", $i);
            if (($html[$i + $space] ?? '') !== '=') {
                continue; // an attribute without a value
            }
            $tag .= substr($html, $i, $space + 1);
            $i += $space + 1;
            $space = strspn($html, " \t\n\r\f", $i);
            $tag .= substr($html, $i, $space);
            $i += $space;
            $quote = $html[$i] ?? '';
            if ($quote === '"' || $quote === "'") {
                $end = strpos($html, $quote, $i + 1);
                $end = $end === false ? $length : $end + 1;
                $raw = substr($html, $i, $end - $i);
                $value = substr($raw, 1, $quote === ($raw[strlen($raw) - 1] ?? '') && strlen($raw) > 1 ? -1 : null);
            } else {
                $end = $i + strcspn($html, " \t\n\r\f>", $i);
                $raw = $value = substr($html, $i, $end - $i);
            }
            $i = $end;
            $tag .= in_array(strtolower($name), self::ADDRESS_ATTRIBUTES, true) && !self::safeAddress(strtolower($name), $value) ? '"#"' : $raw;
        }
        if ($i < $length) {
            $tag .= '>';
            $i++;
        }

        return [$tag, $i];
    }

    /** An address attribute's value, safe as a browser would read it. */
    private static function safeAddress(string $name, string $value): bool
    {
        $value = self::attributeText($value);
        $addresses = $name === 'srcset'
            ? array_map(fn (string $candidate) => (string) strtok(trim($candidate), " \t\n\r\f"), explode(',', $value))
            : [$value];
        foreach ($addresses as $address) {
            $image = in_array($name, ['src', 'srcset', 'poster', 'background'], true)
                && preg_match('~^\s*(cid:|data:image/(png|jpe?g|gif|webp)[;,])~i', $address) === 1;
            if (!$image && $address !== '#' && Renderer::safeUrl($address) === '#') {
                return false;
            }
        }

        return true;
    }

    /**
     * An attribute's value as a browser decodes it. A numeric character reference needs no ";" there, and where it
     * ends is the browser's to say: an address with one is no address of ours ("javascript:" is what it hides).
     */
    private static function attributeText(string $value): string
    {
        if (preg_match('/&#(x[0-9a-f]+|[0-9]+)(?!;)/i', $value) === 1) {
            return 'javascript:';
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Validation rule (Filament and Validator): the field must parse as a template. */
    public static function rule(): ValidTemplate
    {
        return new ValidTemplate;
    }

    /** @return array{name: string, url: string, logo_url: string, year: string} */
    public static function app(): array
    {
        $logo = config('settings.logo');

        return [
            'name' => (string) config('app.name'),
            'url' => url('/'),
            'logo_url' => $logo ? url(Storage::url($logo)) : '',
            'year' => date('Y'),
        ];
    }

    /**
     * The variables every notification template sees, on top of what the caller passed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function context(array $data, ?User $user = null): array
    {
        $invoice = $data['invoice'] ?? null;
        $service = $data['service'] ?? null;
        $ticketMessage = $data['ticketMessage'] ?? null;

        $data['app'] = self::app();
        $data['user'] ??= $user;
        // As WHMCS's {$invoice_num}: the number, or the id while a proforma has none yet.
        if ($invoice instanceof Invoice) {
            $data['invoice_number'] ??= $invoice->number ?: (string) $invoice->id;
        }
        $data['urls'] = array_filter([
            'services' => route('services'),
            'invoices' => route('invoices'),
            'new_ticket' => route('tickets.create'),
            'password_reset' => route('password.request'),
            'security' => route('account.security'),
            'payment_methods' => route('account.payment-methods'),
            'invoice' => $invoice instanceof Invoice ? route('invoices.show', $invoice) : null,
            'service' => $service instanceof Service ? route('services.show', $service) : null,
            'domain' => ($data['domain'] ?? null) instanceof Domain ? route('domains.show', $data['domain']) : null,
            'ticket' => $ticketMessage instanceof TicketMessage ? route('tickets.show', $ticketMessage->ticket_id) : null,
        ]);

        if ($ticketMessage instanceof TicketMessage) {
            // Who wrote it, by name: the staff member or the customer (the login was blank for staff).
            $data['author_name'] ??= (string) $ticketMessage->author()?->name;
            $data['message_html'] = new Html(Str::markdown((string) $ticketMessage->message, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]));
        }

        // The product's own snippet (e.g. login details) is a template too, rendered with the same data.
        $snippet = $service instanceof Service ? $service->product?->email_template : null;
        if ($snippet) {
            try {
                $data['product_info'] = new Html(self::renderMarkdown($snippet, $data));
            } catch (TemplateError $e) {
                report($e); // the email still goes out, without the broken snippet
            }
        }

        return $data;
    }

    public static function mailHeader(): string
    {
        return self::render(config('settings.mail_header'), [
            'app' => self::app(),
            'locale' => app()->getLocale(),
            'mail_css' => (string) config('settings.mail_css'),
        ]);
    }

    public static function mailFooter(): string
    {
        return self::render(config('settings.mail_footer'), ['app' => self::app(), 'locale' => app()->getLocale()]);
    }
}
