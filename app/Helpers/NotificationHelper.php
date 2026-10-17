<?php

namespace App\Helpers;

use App\Classes\PDF;
use App\Classes\Price;
use App\Mail\Mail;
use App\Mail\SystemMail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Mail\MailAudit;
use App\Support\Redact;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail as FacadesMail;
use Illuminate\Support\Facades\URL;
use MyCS\MailTemplate\TemplateError;
use Throwable;

class NotificationHelper
{
    /** Templates whose «url» is a live token (mail audit review R1). */
    public const TOKEN_LINK_TEMPLATES = ['password_reset', 'email_verification'];

    /** The account a mail is about (docs/accounts.md, PR 2a): its email log is the account's. */
    private static ?Client $account = null;

    /**
     * A notification about the account (invoice, service, ticket…): to its owner until the contacts (PR 3) choose
     * the recipients per kind; none when the account has no owner left.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private static function toAccount(Client $client, string $key, array $data, array $attachments = []): void
    {
        $owner = $client->owner();
        if ($owner === null) {
            return;
        }
        $previous = self::$account;
        self::$account = $client;
        try {
            self::sendNotification($key, $data, $owner, $attachments);
        } finally {
            self::$account = $previous;
        }
    }

    /**
     * The mail as the log keeps it: rendered again with every secret of its data (under a secret's name: service_password,
     * a module's password) replaced, not searched for afterwards (Markdown and escaping change how a password reads:
     * review R1). Without secrets, the mail itself.
     */
    private static function bodyForLog(Mail $mail, NotificationTemplate $template, array $data): string
    {
        $secrets = array_filter($data, fn ($value, $key) => Redact::isSecretKey($key) && is_string($value) && $value !== '', ARRAY_FILTER_USE_BOTH);
        // A link that is a login (password reset, email verification): a live token, never kept, never resent (mail
        // audit review R1).
        if (in_array($template->key, self::TOKEN_LINK_TEMPLATES, true) && is_string($data['url'] ?? null) && $data['url'] !== '') {
            $secrets['url'] = $data['url'];
        }
        if ($secrets === []) {
            return $mail->render();
        }
        $masked = (new Mail($template, array_merge($data, array_fill_keys(array_keys($secrets), Redact::MASK))))->locale($mail->locale);

        return Redact::values($masked->render(), $secrets);
    }

    /**
     * Send an email notification.
     */
    public static function sendEmailNotification(
        NotificationTemplate $notificationTemplate,
        array $data,
        User $user,
        array $attachments = []
    ): void {
        $mail = new Mail($notificationTemplate, $data);
        // Rendered (header and footer too) in the language it was written in, also by the queue worker.
        $mail->locale(app()->getLocale());

        // The log keeps the mail without its secrets (sweep §6 #10): the welcome mail's password, or what a module gave
        // under a secret's name. The customer's mail keeps them.
        $emailLog = EmailLog::create([
            'client_id' => self::$account?->id,
            'subject' => $mail->envelope()->subject,
            'to' => $user->email,
            'body' => self::bodyForLog($mail, $notificationTemplate, $data),
            // The mail audit (docs/mail.md): from, copies, template and what it is about.
            'from_address' => config('settings.mail_from_address'),
            'cc' => MailAudit::addresses($notificationTemplate->cc) ?: null,
            'bcc' => MailAudit::addresses($notificationTemplate->bcc) ?: null,
            'template_key' => $notificationTemplate->key,
            ...MailAudit::related($data),
        ]);

        // Add the email log id to the payload
        $mail->email_log_id = $emailLog->id;

        foreach ($attachments as $attachment) {
            $mail->attachFromStorage($attachment['path'], $attachment['name'], $attachment['options'] ?? []);
        }

        FacadesMail::to($user->email)
            ->bcc($notificationTemplate->bcc)
            ->cc($notificationTemplate->cc)
            ->queue($mail);
    }

    public static function sendSystemEmailNotification(
        string $subject,
        string $body,
        array $attachments = [],
        ?string $email = null,
    ): void {
        if (!$email) {
            $email = config('settings.system_email_address');
        }
        if (!$email || config('settings.mail_disable')) {
            return;
        }
        $mail = new SystemMail([
            'subject' => $subject,
            'body' => $body,
        ]);
        $emailLog = EmailLog::create([
            'subject' => $mail->envelope()->subject,
            'to' => $email,
            'body' => $mail->render(),
            'from_address' => config('settings.mail_from_address'),
            'template_key' => 'system', // to staff: the cron's notices, failures
        ]);

        // Add the email log id to the payload
        $mail->email_log_id = $emailLog->id;

        foreach ($attachments as $attachment) {
            $mail->attachFromStorage($attachment['path'], $attachment['name'], $attachment['options'] ?? []);
        }

        FacadesMail::to($email)
            ->queue($mail);
    }

    public static function sendInAppNotification(
        NotificationTemplate $notification,
        array $data,
        User $user,
        bool $show_in_app = true,
        bool $show_as_push = true
    ): void {
        Notification::create([
            'user_id' => $user->id,
            'title' => TemplateRenderer::render($notification->in_app_title, $data, escape: false),
            'body' => TemplateRenderer::render($notification->in_app_body, $data, escape: false),
            'url' => isset($notification->in_app_url) ? TemplateRenderer::render($notification->in_app_url, $data, escape: false) : null,
            'show_in_app' => $show_in_app,
            'show_as_push' => $show_as_push,
        ]);
    }

    public static function sendNotification(
        $notificationTemplateKey,
        array $data,
        User $user,
        array $attachments = [],
        bool $show_in_app = true,
        bool $show_as_push = true
    ): void {
        $notification = NotificationTemplate::where('key', $notificationTemplateKey)->first();
        if (!$notification || !$notification->enabled) {
            return;
        }
        $locale = self::localeFor($user);
        self::withLocale($locale, fn () => self::deliver($notification, $locale, $data, $user, $attachments, $show_in_app, $show_as_push));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private static function withLocale(string $locale, \Closure $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * The language a customer's mails are written in. The company's default language for now; the customer's own when
     * the accounts work gives clients one (docs/accounts.md: `language`, imported from WHMCS).
     */
    public static function localeFor(User|Client|null $recipient): string
    {
        return (string) config('settings.app_language', config('app.locale', 'en'));
    }

    private static function deliver(NotificationTemplate $template, string $locale, array $data, User $user, array $attachments, bool $show_in_app, bool $show_as_push): void
    {
        // Preferences are kept on the template itself, whatever the language it is written in.
        $notification = $template->inLocale($locale);
        $data = TemplateRenderer::context($data, $user);

        $userPreference = $user->notificationsPreferences()->where('notification_template_id', $template->id)->first();

        // A template that does not parse must not break what triggered it (provisioning, cron, …):
        // the error is reported and that notification is skipped.
        try {
            if ($notification->isEnabledForPreference($userPreference, 'mail') && !config('settings.mail_disable')) {
                self::sendEmailNotification($notification, $data, $user, $attachments);
            }
        } catch (TemplateError $e) {
            report($e);
        }

        // After the mail: any failure here is reported, not thrown, so the queued listener is not retried into mailing
        // the customer twice (review R-3 of Σ4).
        try {
            if ($notification->isEnabledForPreference($userPreference, 'app')) {
                self::sendInAppNotification($notification, $data, $user, $show_in_app, $show_as_push);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * An invitation to an account (PR 2b): to the invited address, which may not have a login yet. Not a user's
     * notification (no preferences, no in-app); logged on the account.
     */
    public static function clientInviteNotification(ClientInvite $invite, string $token): void
    {
        $template = NotificationTemplate::where('key', 'client_invite')->first();
        if (!$template || !$template->enabled || config('settings.mail_disable')) {
            return;
        }
        $client = $invite->client;
        $locale = self::localeFor($client);
        $recipient = new User(['email' => $invite->email]);
        $by = $invite->invitedByUser->name ?? config('app.name');

        self::withLocale($locale, function () use ($template, $locale, $invite, $token, $client, $recipient, $by) {
            $previous = self::$account;
            self::$account = $client;
            try {
                $data = TemplateRenderer::context([
                    'account' => ['name' => $client->name],
                    'invite' => ['url' => route('invites.accept', ['token' => $token]), 'by' => $by, 'expires_at' => $invite->expires_at],
                ], $recipient);
                self::sendEmailNotification($template->inLocale($locale), $data, $recipient);
                // The link is the invitation: the email log keeps the mail without it (only its hash is kept).
                $log = EmailLog::query()->where('client_id', $client->id)->where('to', $invite->email)->latest('id')->first();
                $log?->forceFill(['body' => str_replace($token, '…', (string) $log->body)])->save();
            } catch (TemplateError $e) {
                report($e);
            } finally {
                self::$account = $previous;
            }
        });
    }

    public static function loginDetectedNotification(User $user, array $data = []): void
    {
        self::sendNotification('new_login_detected', $data, $user);
    }

    /** @param  array<string, mixed>  $extra  more for the template (an overdue notice's days) */
    public static function invoiceNotification(Client $client, Invoice $invoice, $key = 'new_invoice_created', array $extra = []): void
    {
        $data = $extra + [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'total' => $invoice->formattedTotal,
            // Δ1: the lines are net; when there is tax, the subtotal and the tax for the template (empty otherwise).
            'subtotal' => $invoice->taxTotal != 0 ? $invoice->formattedTotal->format($invoice->subtotal) : '',
            'tax' => $invoice->taxTotal != 0 ? $invoice->formattedTotal->format($invoice->taxTotal) : '',
            // Only a gateway subscription is charged on its own later (review R1/A2); a saved method was charged once.
            'has_subscription' => PaymentReminders::chargedOnItsOwn($invoice),
            // What is left to pay, for a partly paid invoice (review R5/A10).
            'remaining' => $invoice->formattedRemaining,
            'partly_paid' => $invoice->remaining > 0 && $invoice->remaining < $invoice->total,
        ];

        // Generate the invoice PDF, in the customer's language
        $pdf = self::withLocale(self::localeFor($client), fn () => PDF::generateInvoice($invoice));
        // Generate path
        if (!file_exists(storage_path('app/invoices'))) {
            // Create the directory if it doesn't exist
            mkdir(storage_path('app/invoices'), 0755, true);
        }
        // Save the PDF to a temporary location
        $pdfPath = storage_path('app/invoices/' . ($invoice->number ?? $invoice->id) . '.pdf');
        $pdf->save($pdfPath);

        // Attach the PDF to the email
        $attachments = [
            [
                'path' => 'invoices/' . ($invoice->number ?? $invoice->id) . '.pdf',
                'name' => 'invoice.pdf',
            ],
        ];

        self::toAccount($client, $key, $data, $attachments);
    }

    public static function invoiceCreatedNotification(Client $client, Invoice $invoice): void
    {
        self::invoiceNotification($client, $invoice, 'new_invoice_created');
    }

    public static function invoicePaidNotification(Client $client, Invoice $invoice): void
    {
        self::invoiceNotification($client, $invoice, 'invoice_paid');
    }

    /** Account credit paid part of an invoice on its own (MT-1e): how much, and what is left to pay. */
    public static function creditAppliedNotification(Client $client, Invoice $invoice, InvoiceTransaction $transaction): void
    {
        $price = new Price(['price' => 0, 'currency' => $invoice->currency]);
        self::toAccount($client, 'credit_applied', [
            'invoice' => $invoice,
            'credit' => [
                'applied' => $price->format((float) $transaction->amount),
                'remaining' => $price->format(max(0, (float) $invoice->remaining)),
            ],
        ]);
    }

    /**
     * A payment of the customer's went back (PR 2c-2, WHMCS's «Invoice Refund Confirmation»): to them (through the
     * gateway, or by hand) or to their account credit.
     */
    public static function invoiceRefundedNotification(Client $client, Invoice $invoice, int $cents, bool $toCredit, bool $byGateway): void
    {
        $price = new Price(['price' => 0, 'currency' => $invoice->currency]);
        self::toAccount($client, 'invoice_refunded', [
            'invoice' => $invoice,
            'refund' => [
                'amount' => $price->format($cents / 100),
                'to_credit' => $toCredit,
                'by_gateway' => $byGateway,
            ],
        ]);
    }

    /**
     * Once per invoice within an hour (EVT-14): a renewal charge the cron saw fail and the gateway's own failed
     * transaction for the same attempt (its webhook, minutes later) are one failure to the customer. A new attempt
     * later (another card) is mailed again. Not sent (the PDF or the mail failed): the key goes, so the retry sends it.
     */
    public static function invoicePaymentFailedNotification(Client $client, Invoice $invoice): void
    {
        $key = "invoice:{$invoice->id}:payment-failed-mailed";
        if (!Cache::add($key, true, now()->addHour())) {
            return;
        }
        try {
            self::invoiceNotification($client, $invoice, 'invoice_payment_failed');
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }
    }

    /**
     * A saved payment method expires at the end of this month (PR H2b, WHMCS CCDaySendExpiryNotices): its customer adds a
     * new one before their invoices can no longer be charged to it.
     */
    public static function cardExpiringNotification(Client $client, BillingAgreement $agreement): void
    {
        self::toAccount($client, 'card_expiring', [
            'method_name' => (string) $agreement->name,
            'expiry' => Carbon::parse($agreement->expiry)->format('m/Y'),
        ]);
    }

    public static function orderCreatedNotification(Client $client, Order $order, array $data = []): void
    {
        $data = [
            'order' => $order,
            'items' => $order->services,
            // What the order costs: its invoice's total when it has one (its domains too, D8b code review M3).
            'total' => $order->formattedTotal,
            'domains' => $order->domains->pluck('fqdn')->implode(', '),
        ];
        self::toAccount($client, 'new_order_created', $data);
    }

    public static function serverCreatedNotification(Client $client, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        // The welcome mail only (WHMCS's {$service_password}): customers keep their login from it. No other template
        // can read the password (Service::TEMPLATE_HIDDEN).
        $data['service_password'] ??= rescue(fn () => $service->password, null);
        self::toAccount($client, 'new_server_created', $data);
    }

    /** A domain's expiry notice (D7, DomainReminders): before it expires, or after, while it can be recovered. */
    public static function domainNotification(Client $client, Domain $domain, string $key, array $data = []): void
    {
        $data['domain'] = $domain;
        self::toAccount($client, $key, $data);
    }

    public static function serverSuspendedNotification(Client $client, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::toAccount($client, 'server_suspended', $data);
    }

    public static function serverUnsuspendedNotification(Client $client, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::toAccount($client, 'server_unsuspended', $data);
    }

    /** A service ended at the end of its period because its customer asked (not «terminated»: sweep modules §21). */
    public static function serviceCancelledNotification(Client $client, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::toAccount($client, 'service_cancelled', $data);
    }

    public static function serverTerminatedNotification(Client $client, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::toAccount($client, 'server_terminated', $data);
    }

    public static function ticketMessageNotification(Client $client, TicketMessage $ticketMessage, array $data = []): void
    {
        $data['ticketMessage'] = $ticketMessage;
        self::toAccount($client, 'new_ticket_message', $data);
    }

    public static function emailVerificationNotification(User $user, array $data = []): void
    {
        $data['user'] = $user;
        $data['url'] = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->email),
            ]
        );
        self::sendNotification('email_verification', $data, $user);
    }

    public static function passwordResetNotification(User $user, array $data = []): void
    {
        $data['user'] = $user;
        self::sendNotification('password_reset', $data, $user);
    }

    public static function serviceCancellationReceivedNotification(Client $client, ServiceCancellation $cancellation, array $data = []): void
    {
        $data['cancellation'] = $cancellation;
        $data['service'] = $cancellation->service;
        self::toAccount($client, 'service_cancellation_received', $data);
    }
}
