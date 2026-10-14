<?php

namespace App\Helpers;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\TicketMessage;
use App\Models\User;
use App\Rules\ValidTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MyCS\MailTemplate\Html;
use MyCS\MailTemplate\Renderer;
use MyCS\MailTemplate\TemplateError;

/**
 * Laravel side of the mycs/mail-template renderer: staff-edited templates (email,
 * in-app notifications, mail header/footer, product email snippets) are rendered
 * from data, never compiled as Blade (docs/audit-security.md H1).
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

            return Renderer::read($target, $key);
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function render(?string $template, array $data, bool $escape = true): string
    {
        return $template === null || $template === '' ? '' : self::renderer()->render($template, $data, $escape);
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
            'invoice' => $invoice instanceof Invoice ? route('invoices.show', $invoice) : null,
            'service' => $service instanceof Service ? route('services.show', $service) : null,
            'ticket' => $ticketMessage instanceof TicketMessage ? route('tickets.show', $ticketMessage->ticket_id) : null,
        ]);

        if ($ticketMessage instanceof TicketMessage) {
            $data['message_html'] = new Html(Str::markdown((string) $ticketMessage->message, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]));
        }

        // The product's own snippet (e.g. login details) is a template too, rendered with the same data.
        $snippet = $service instanceof Service ? $service->product?->email_template : null;
        if ($snippet) {
            try {
                $data['product_info'] = new Html(Str::markdown(self::render($snippet, $data)));
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
            'mail_css' => (string) config('settings.mail_css'),
        ]);
    }

    public static function mailFooter(): string
    {
        return self::render(config('settings.mail_footer'), ['app' => self::app()]);
    }
}
