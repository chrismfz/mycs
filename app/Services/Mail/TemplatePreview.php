<?php

namespace App\Services\Mail;

use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainNameserver;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\License;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use MyCS\MailTemplate\Html;
use RuntimeException;

/**
 * An email template as a customer would get it (owner 2026-10-25: «that it renders right before it goes live»): its
 * subject and the HTML of the real layout, in a language, with made-up data for its kind. The data are models in memory
 * only: nothing is written while it renders (a write is refused: an invoice row, even rolled back, would use up an id,
 * and on myip the invoice number is its id). Their ids are negative (no row has one) and every relation a template may
 * read (TemplateRenderer::READS) is set: nothing real is ever read into a preview or a test mail (review of #457).
 */
final class TemplatePreview
{
    private static bool $guarding = false;

    /** The connection the guard is on (a new connection, e.g. after a reconnect, needs its own). */
    private static ?int $guardedConnection = null;

    /** @return array{subject: string, html: string} */
    public static function render(NotificationTemplate $template, string $locale): array
    {
        self::installGuard();
        $previous = App::getLocale();
        App::setLocale($locale);
        self::$guarding = true;
        try {
            [$data, $user] = self::sample((string) $template->key);
            $mail = new Mail($template->inLocale($locale), TemplateRenderer::context($data, $user));

            return ['subject' => $mail->renderedSubject, 'html' => $mail->render()];
        } finally {
            self::$guarding = false;
            App::setLocale($previous);
        }
    }

    /** The languages a template is previewed in: the instance's main one first. */
    public static function locales(): array
    {
        return array_values(array_unique([Locales::main(), ...array_keys(Locales::options()), 'el', 'en']));
    }

    private static function installGuard(): void
    {
        $connection = DB::connection();
        if (self::$guardedConnection === spl_object_id($connection)) {
            return;
        }
        self::$guardedConnection = spl_object_id($connection);
        $connection->beforeExecuting(function (string $query) {
            if (self::$guarding && preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query) === 1) {
                throw new RuntimeException('A preview never writes: ' . Str::limit($query, 60));
            }
        });
    }

    /**
     * Made-up data for a template's kind, as its notification passes them; the models in memory only.
     *
     * @return array{0: array<string, mixed>, 1: User}
     */
    public static function sample(string $key): array
    {
        $currency = (string) config('settings.default_currency', 'EUR');
        $user = new User(['first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου', 'email' => 'maria@example.com']);
        $user->setAttribute('id', -1001);
        $contact = new Contact(['first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου', 'company_name' => 'Παράδειγμα Α.Ε.', 'email' => 'maria@example.com', 'country' => 'GR']);
        $client = new Client;
        $client->setAttribute('id', -1001);
        $client->setRelation('primaryContact', $contact);
        $client->setRelation('users', collect([$user]));
        $product = new Product(['name' => 'Web Hosting Pro']);
        $product->setAttribute('id', -1);
        $plan = new Plan(['name' => 'Μηνιαίο', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month']);
        $plan->setAttribute('id', -1);
        $service = new Service(['domain' => 'example.gr', 'status' => 'active', 'price' => '15.00', 'quantity' => 1, 'currency_code' => $currency,
            'expires_at' => now()->addDays(7)->startOfDay(), 'username' => 'example']);
        $service->setAttribute('id', -4242);
        $service->client_id = $client->id;
        $license = new License(['license_key' => 'EXAMPLE-0000-0000-0000', 'status' => 'Active', 'valid_domain' => 'example.gr']);
        $license->setAttribute('id', -1);
        $service->setRelation('product', $product);
        $service->setRelation('plan', $plan);
        $service->setRelation('client', $client);
        $service->setRelation('license', $license);
        $due = $key === 'invoice_overdue' ? now()->subDays(3)->startOfDay() : now()->addDays(7)->startOfDay();
        $line = new InvoiceItem(['description' => 'Web Hosting Pro – example.gr (' . $due->format('d/m/Y') . ' - ' . $due->copy()->addMonth()->subDay()->format('d/m/Y') . ')',
            'quantity' => 1, 'price' => '15.00']);
        $line->line_total = '15.00';
        $line->tax_amount = '0.00';
        $invoice = new Invoice(['status' => 'unpaid', 'currency_code' => $currency, 'due_at' => $due]);
        $invoice->setAttribute('id', -32999);
        $invoice->number = '32999';
        $invoice->client_id = $client->id;
        $invoice->created_at = now();
        $invoice->setRelation('items', collect([$line]));
        $invoice->setRelation('transactions', collect());
        $invoice->setRelation('adjustmentNotes', collect());
        $invoice->setRelation('client', $client);
        $line->setRelation('invoice', $invoice);
        $total = (string) $invoice->formattedTotal;
        $money = fn (string $amount) => (string) $invoice->formattedTotal->format((float) $amount);
        $department = new TicketDepartment(['name' => 'Τεχνική υποστήριξη']);
        $department->setAttribute('id', -1);
        $ticket = new Ticket(['subject' => 'Δεν λειτουργεί το email μου', 'status' => 'open', 'priority' => 'medium', 'reference' => '483920']);
        $ticket->setAttribute('id', -5150);
        $ticket->client_id = $client->id;
        $ticket->setRelation('client', $client);
        $ticket->setRelation('service', $service);
        $ticket->setRelation('department', $department);
        $message = new TicketMessage(['message' => "Καλησπέρα σας,\n\nΔεν μπορώ να στείλω email από το **Outlook** από χθες.\n\nΕυχαριστώ"]);
        $message->setAttribute('id', -9001);
        $message->ticket_id = $ticket->id;
        $message->setRelation('ticket', $ticket);
        $message->setRelation('user', $user);
        $domain = new Domain(['sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr', 'expires_at' => now()->addDays(30)->startOfDay()]);
        $domain->setAttribute('id', -777);
        $domain->client_id = $client->id;
        $domain->setRelation('nameservers', collect(['ns1.example.net', 'ns2.example.net'])
            ->map(fn (string $host, int $i) => new DomainNameserver(['host' => $host, 'sort' => $i])));
        $order = new Order(['currency_code' => $currency]);
        $order->setAttribute('id', -1234);
        $order->client_id = $client->id;
        $order->setRelation('services', collect([$service]));
        $order->setRelation('client', $client);
        $cancellation = new ServiceCancellation(['type' => 'end_of_period', 'reason' => 'Μεταφορά αλλού']);
        $cancellation->setRelation('service', $service);
        $ticketUrl = url('/tickets/guest/' . str_repeat('x', 43));
        $invoiceData = ['invoice' => $invoice, 'items' => $invoice->items, 'total' => $total, 'credit' => '', 'subtotal' => '', 'tax' => '',
            'has_subscription' => false, 'remaining' => (string) $invoice->formattedRemaining, 'partly_paid' => false, 'bank_transfer' => null];

        $data = match ($key) {
            'new_login_detected' => ['ip' => '203.0.113.7', 'device' => 'Firefox on Windows', 'time' => now()->format('d/m/Y H:i')],
            'card_expiring' => ['method_name' => 'Visa •••• 4242', 'expiry' => now()->addMonth()->format('m/Y')],
            'new_invoice_created', 'invoice_paid', 'invoice_payment_failed', 'invoice_payment_reminder', 'card_payment_due', 'invoice_modified' => $invoiceData,
            'invoice_overdue' => $invoiceData + ['overdue' => ['days' => 3, 'last' => true, 'suspend_on' => now()->addDays(5)->format('d/m/Y')]],
            'credit_applied' => ['invoice' => $invoice, 'credit' => ['applied' => $money('5.00'), 'remaining' => $money('10.00')]],
            'invoice_refunded' => ['invoice' => $invoice, 'refund' => ['amount' => $money('5.00'), 'to_credit' => false, 'by_gateway' => true]],
            'new_order_created' => ['order' => $order, 'items' => collect([$service]), 'total' => $total],
            'new_server_created' => ['service' => $service, 'service_password' => 'Ex4mple-Pass'],
            'server_suspended', 'server_unsuspended', 'server_terminated', 'service_cancelled' => ['service' => $service],
            'service_cancellation_received' => ['service' => $service, 'cancellation' => $cancellation],
            'ssl_certificate_issued' => ['service' => $service, 'common_name' => 'shop.example.gr', 'expires' => now()->addYear()->format('d/m/Y')],
            'new_ticket_message' => ['ticketMessage' => $message, 'author_name' => 'Γιώργος (Υποστήριξη)'],
            'guest_ticket_message' => ['ticket' => $ticket, 'ticketMessage' => $message, 'ticket_url' => $ticketUrl, 'author_name' => 'Γιώργος (Υποστήριξη)'],
            'ticket_received', 'ticket_link' => ['ticket' => $ticket, 'ticket_url' => $ticketUrl],
            'ticket_cc_message' => ['ticket' => $ticket, 'ticketMessage' => $message, 'author_name' => 'Γιώργος (Υποστήριξη)'],
            'email_verification', 'password_reset' => ['url' => url('/example-link')],
            'client_invite' => ['account' => ['name' => 'Παράδειγμα Α.Ε.'], 'invite' => ['url' => url('/invite/example'), 'by' => 'Μαρία', 'expires_at' => now()->addWeek()]],
            'domain_renewal_notice', 'domain_free_renewal_notice', 'domain_expired_notice' => ['domain' => $domain, 'fqdn' => 'example.gr',
                'expires' => now()->addDays(30)->format('d/m/Y'), 'days_left' => 30, 'days_past' => 0, 'last_day' => now()->addDays(70)->format('d/m/Y'),
                'stage_end' => now()->addDays(40)->format('d/m/Y'), 'free' => $key === 'domain_free_renewal_notice', 'price' => $money('12.00'),
                'late_fee' => $money('30.00'), 'next_fee' => $money('80.00'), 'years' => 1, 'invoice' => $invoice, 'auto_charged' => false],
            'domain_registered', 'domain_transfer_started', 'domain_transferred', 'domain_renewed', 'domain_transfer_failed' => ['domain' => $domain, 'fqdn' => 'example.gr',
                'expires' => now()->addYear()->format('d/m/Y'), 'years' => 1, 'nameservers' => 'ns1.example.net, ns2.example.net', 'reason' => 'auth'],
            'announcement', 'announcement_news' => ['subject' => 'Προγραμματισμένη συντήρηση', 'content' => new Html('<p>Την Κυριακή 02:00–04:00 θα γίνει συντήρηση.</p>'),
                'unsubscribe_url' => $key === 'announcement_news' ? url('/unsubscribe/example') : null],
            default => ['invoice' => $invoice, 'service' => $service, 'ticket' => $ticket],
        };

        return [$data, $user];
    }

    /** The HTML in a frame of its own (its styles never reach the admin's page), for a modal. */
    public static function frame(string $html, int $height = 640): HtmlString
    {
        return new HtmlString('<iframe sandbox="" style="width:100%;height:' . $height . 'px;border:1px solid rgba(127,127,127,.3);border-radius:8px;background:#fff" srcdoc="'
            . e($html) . '"></iframe>');
    }
}
