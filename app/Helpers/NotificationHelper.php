<?php

namespace App\Helpers;

use App\Classes\PDF;
use App\Classes\Price;
use App\Enums\NotificationEnabledStatus;
use App\Mail\Mail;
use App\Mail\SystemMail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Mail\MailAudit;
use App\Services\Money\BankTransferDetails;
use App\Support\EmailKinds;
use App\Support\Locales;
use App\Support\LogText;
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

    /**
     * Mails that are a key to an account or carry a secret: they go to their recipient only, never to a template's CC
     * or BCC (whoever edits templates must not read every reset link, invitation or welcome password).
     */
    public const PRIVATE_TEMPLATES = ['password_reset', 'email_verification', 'client_invite', 'new_server_created'];

    /** The account a mail is about (docs/accounts.md, PR 2a): its email log is the account's. */
    private static ?Client $account = null;

    /**
     * A notification about the account (invoice, service, ticket…): to its owner; none when the account has no owner
     * left. Its mail only when the primary contact takes the template's kind (EmailKinds, owner 2026-10-30), unless
     * not $gated: staff send it by hand, or its caller chose already (a mass mail). The in-app notification goes anyway.
     * The account's other contacts get their copy of the kinds they take (toContacts), unless not $toContacts.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private static function toAccount(Client $client, string $key, array $data, array $attachments = [], bool $mailOnly = false, bool $gated = true, bool $toContacts = true): void
    {
        $owner = $client->owner();
        if ($owner === null) {
            return;
        }
        $noMail = $gated && !EmailKinds::sends($client, $key);
        $previous = self::$account;
        self::$account = $client;
        try {
            if (!($noMail && $mailOnly)) {
                self::sendNotification($key, $data, $owner, $attachments, mailOnly: $mailOnly, noMail: $noMail);
            }
            if ($toContacts) {
                // The owner's address is theirs to choose for (their own preference, ADV-550-R2-2), unless only the
                // primary's kinds kept the mail from them: then a contact sharing it gets its copy (ADV-550-3).
                self::toContacts($client, $owner, $key, $data, $attachments, ownerAddressTaken: !$noMail);
            }
        } finally {
            self::$account = $previous;
        }
    }

    /**
     * The account's mail to its other contacts (WHMCS's tblcontacts flags, owner 2026-10-30): each one, by its own
     * address, that takes the template's kind (Contact::takesEmail: one never chosen takes none). In the account's
     * language, logged on the account; no in-app, the contacts have no login. Never a mail that is the owner's alone
     * (EmailKinds::OWNER_ONLY: a password, a ticket's thread) or that carries a secret, nor twice to one address.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private static function toContacts(Client $client, User $owner, string $key, array $data, array $attachments, bool $ownerAddressTaken): void
    {
        if (in_array($key, EmailKinds::OWNER_ONLY, true) || config('settings.mail_disable')) {
            return;
        }
        $kind = EmailKinds::of($key);
        $contacts = $client->contacts()->active()->whereNull('primary_of_client_id')->whereNotNull('email')->orderBy('id')->get()
            ->filter(fn (Contact $contact) => $contact->takesEmail($kind) && filter_var($contact->email, FILTER_VALIDATE_EMAIL) !== false);
        if ($contacts->isEmpty()) {
            return;
        }
        // A template whose mail staff set to never, or off unless chosen, mails no contact (ADV-550-R2-1): they have no
        // preferences of their own to choose it with.
        $template = NotificationTemplate::where('key', $key)->first();
        if (!$template || !$template->enabled
            || !in_array($template->mail_enabled, [NotificationEnabledStatus::Force, NotificationEnabledStatus::ChoiceOn], true)) {
            return;
        }
        $locale = self::localeFor($client);
        $sent = $ownerAddressTaken ? [mb_strtolower((string) $owner->email) => true] : [];
        // The template's CC and BCC had the owner's mail already: not a second copy (round 2 of #550).
        foreach ([...(array) $template->cc, ...(array) $template->bcc] as $copy) {
            $sent[mb_strtolower(trim((string) $copy))] = true;
        }
        foreach ($contacts as $contact) {
            $address = mb_strtolower(trim((string) $contact->email));
            if (isset($sent[$address])) {
                continue;
            }
            $sent[$address] = true;
            // Greeted by name, else by its company (a business contact, or a domain's with only an email: code review 5).
            $named = filled($contact->first_name);
            $recipient = new User(['email' => trim((string) $contact->email), 'first_name' => $named ? $contact->first_name : ($contact->company_name ?: null),
                'last_name' => $named ? $contact->last_name : null]);
            self::withLocale($locale, function () use ($template, $locale, $data, $recipient, $attachments) {
                // Whatever fails here is reported, never thrown: the owner had the mail already, and a queued caller's
                // retry would mail them twice (code review 6 of #550); the next contact still gets theirs.
                try {
                    $context = TemplateRenderer::context(array_diff_key($data, ['user' => true]), $recipient);
                    // What is the owner's alone (a login link, a secret under its name, at any depth: a module's
                    // ['account' => ['password' => …]], code review 2): not for a contact.
                    if (self::secretsOf($template, $context) !== [] || self::hasNestedSecret($data)) {
                        return;
                    }
                    self::sendEmailNotification($template->inLocale($locale), $context, $recipient, $attachments, copies: false);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
    }

    /** Some value under a secret's name (Redact::isSecretKey), at any depth of the caller's data. */
    private static function hasNestedSecret(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (Redact::isSecretKey($key) && $value !== null && $value !== '' && $value !== []) {
                return true;
            }
            if (is_array($value) && self::hasNestedSecret($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The mail as the log keeps it: rendered again with every secret of its data (under a secret's name: service_password,
     * a module's password) replaced, not searched for afterwards (Markdown and escaping change how a password reads:
     * review R1). Without secrets, the mail itself.
     */
    private static function bodyForLog(Mail $mail, NotificationTemplate $template, array $data): string
    {
        $secrets = self::secretsOf($template, $data);
        if ($secrets === []) {
            return $mail->render();
        }
        $masked = (new Mail($template, array_merge($data, array_fill_keys(array_keys($secrets), Redact::MASK))))->locale($mail->locale);

        return Redact::values($masked->render(), $secrets);
    }

    /**
     * What a mail carries that is not for anyone else: a secret under its own name, a login link, a guest's link to
     * their ticket, an owner's «stop these emails».
     *
     * @return array<string, string>
     */
    private static function secretsOf(NotificationTemplate $template, array $data): array
    {
        $secrets = array_filter($data, fn ($value, $key) => Redact::isSecretKey($key) && is_string($value) && $value !== '', ARRAY_FILTER_USE_BOTH);
        // A link that is a login (password reset, email verification): a live token, never kept, never resent (mail
        // audit review R1).
        if (in_array($template->key, self::TOKEN_LINK_TEMPLATES, true) && is_string($data['url'] ?? null) && $data['url'] !== '') {
            $secrets['url'] = $data['url'];
        }
        // A guest's link to their ticket is their key to it (docs/tickets.md §3 Δ): never in the log, never resent.
        if (is_string($data['ticket_url'] ?? null) && str_contains($data['ticket_url'], '/t/')) {
            $secrets['ticket_url'] = $data['ticket_url'];
        }
        // A mass mail's «stop these emails» is the owner's signed link, kept for good (review of #311): a member who reads
        // the account's mails must not take the owner off the news.
        if (is_string($data['unsubscribe_url'] ?? null) && $data['unsubscribe_url'] !== '') {
            $secrets['unsubscribe_url'] = $data['unsubscribe_url'];
        }

        return $secrets;
    }

    /** A template's CC and BCC may get this mail: not a private one, nor one carrying something of its recipient's. */
    private static function mayCopy(Mail $mail, NotificationTemplate $template, array $data): bool
    {
        // A ticket's mail goes to its owner only (docs/tickets.md §3 Γ): its Message-ID is a key, never copied to anyone.
        return $mail->threadMessageId === null
            && !in_array($template->key, self::PRIVATE_TEMPLATES, true)
            && self::secretsOf($template, $data) === [];
    }

    /**
     * Send an email notification.
     */
    public static function sendEmailNotification(
        NotificationTemplate $notificationTemplate,
        array $data,
        User $user,
        array $attachments = [],
        bool $copies = true,
    ): void {
        $mail = new Mail($notificationTemplate, $data);
        // Rendered (header and footer too) in the language it was written in, also by the queue worker.
        $mail->locale(app()->getLocale());
        // Not $copies: a contact's copy of the owner's mail, whose CC and BCC had theirs already (toContacts).
        $copies = $copies && self::mayCopy($mail, $notificationTemplate, $data);

        // The log keeps the mail without its secrets (sweep §6 #10): the welcome mail's password, or what a module gave
        // under a secret's name. The customer's mail keeps them.
        $emailLog = EmailLog::create([
            'client_id' => self::$account?->id,
            'subject' => $mail->envelope()->subject,
            'to' => $user->email,
            'body' => self::bodyForLog($mail, $notificationTemplate, $data),
            // The mail audit (docs/mail.md): from, copies, template and what it is about.
            'from_address' => config('settings.mail_from_address'),
            'cc' => $copies ? (MailAudit::addresses($notificationTemplate->cc) ?: null) : null,
            'bcc' => $copies ? (MailAudit::addresses($notificationTemplate->bcc) ?: null) : null,
            'template_key' => $notificationTemplate->key,
            ...MailAudit::related($data),
        ]);

        // Add the email log id to the payload
        $mail->email_log_id = $emailLog->id;

        foreach ($attachments as $attachment) {
            $mail->attachFromStorage($attachment['path'], $attachment['name'], $attachment['options'] ?? []);
        }

        FacadesMail::to($user->email)
            ->bcc($copies ? (array) $notificationTemplate->bcc : [])
            ->cc($copies ? (array) $notificationTemplate->cc : [])
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
        // To staff, and kept in the email log: an error in it shows no secret, unless «Full logs» (App\Support\LogText).
        $mail = new SystemMail([
            'subject' => (string) LogText::of($subject),
            'body' => (string) LogText::of($body),
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
        bool $show_as_push = true,
        bool $mailOnly = false,
        bool $noMail = false,
    ): void {
        $notification = NotificationTemplate::where('key', $notificationTemplateKey)->first();
        if (!$notification || !$notification->enabled) {
            return;
        }
        // A mail about the account in the account's language (code review 1); one to the login alone in the login's.
        $locale = self::localeFor(self::$account ?? $user);
        self::withLocale($locale, fn () => self::deliver($notification, $locale, $data, $user, $attachments, $show_in_app, $show_as_push, $mailOnly, $noMail));
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
     * The language of what goes to them (docs/accounts.md): an account's mails and PDFs in its own (WHMCS's
     * tblclients.language), a login's in its own; else the instance's.
     */
    public static function localeFor(User|Client|null $recipient): string
    {
        return Locales::allowed($recipient?->language) ?? Locales::main();
    }

    private static function deliver(NotificationTemplate $template, string $locale, array $data, User $user, array $attachments, bool $show_in_app, bool $show_as_push, bool $mailOnly = false, bool $noMail = false): void
    {
        // Preferences are kept on the template itself, whatever the language it is written in.
        $notification = $template->inLocale($locale);
        $data = TemplateRenderer::context($data, $user);

        $userPreference = $user->notificationsPreferences()->where('notification_template_id', $template->id)->first();

        // A template that does not parse must not break what triggered it (provisioning, cron, …):
        // the error is reported and that notification is skipped.
        try {
            // $noMail: the account's primary contact does not take this kind of mail (toAccount).
            if (!$noMail && $notification->isEnabledForPreference($userPreference, 'mail') && !config('settings.mail_disable')) {
                self::sendEmailNotification($notification, $data, $user, $attachments);
            }
        } catch (TemplateError $e) {
            report($e);
        }

        // After the mail: any failure here is reported, not thrown, so the queued listener is not retried into mailing
        // the customer twice (review R-3 of Σ4).
        try {
            // A mail sent again (the welcome's resend) is not news in the app or by push (ADV-533-3).
            if (!$mailOnly && $notification->isEnabledForPreference($userPreference, 'app')) {
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

    /**
     * @param  array<string, mixed>  $extra  more for the template (an overdue notice's days)
     * @param  bool  $byStaff  staff's «Send Email»: sent whatever the account's contact takes (owner 2026-10-30)
     */
    public static function invoiceNotification(Client $client, Invoice $invoice, $key = 'new_invoice_created', array $extra = [], bool $byStaff = false): void
    {
        // The total as the PDF and the portal show it: after the account credit used on it (ROADMAP, «Total»).
        $statement = $invoice->statement();
        $data = $extra + [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'total' => $invoice->formattedTotal->format($statement['total']),
            'credit' => $statement['credit'] != 0 ? $invoice->formattedTotal->format($statement['credit']) : '',
            // Δ1: the lines are net; when there is tax, the subtotal and the tax for the template (empty otherwise).
            'subtotal' => $invoice->taxTotal != 0 ? $invoice->formattedTotal->format($invoice->subtotal) : '',
            'tax' => $invoice->taxTotal != 0 ? $invoice->formattedTotal->format($invoice->taxTotal) : '',
            // Only a gateway subscription is charged on its own later (review R1/A2); a saved method was charged once.
            'has_subscription' => PaymentReminders::chargedOnItsOwn($invoice),
            // What is left to pay, for a partly paid invoice (review R5/A10).
            'remaining' => $invoice->formattedRemaining,
            // Against the total after credit (credit used is not «partly paid»: code review of #388).
            'partly_paid' => $invoice->remaining > 0 && $invoice->remaining < $statement['total'],
            // Unpaid: the bank transfer accounts, line by line, and the reference to write (null without them).
            'bank_transfer' => BankTransferDetails::forTemplate($invoice),
            // Its preferred payment method's name, for the button («Pay with PayPal»): its page opens with it chosen.
            'preferred_method' => self::preferredMethod($invoice),
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

        self::toAccount($client, $key, $data, $attachments, gated: !$byStaff);
    }

    /**
     * Its preferred payment method's name, only when unpaid and that gateway can take it (as its page offers it), else ''.
     * A gateway that cannot be read never stops the email: only its button's label goes (R2-2 of #539).
     */
    public static function preferredMethod(Invoice $invoice): string
    {
        // Paid on its own (a subscription, a saved method on its due date): no button names a way to pay it (R3-1 of #539).
        $gateway = $invoice->status === Invoice::STATUS_UNPAID && !PaymentReminders::chargedOnItsOwn($invoice) ? $invoice->preferredGateway : null;
        if ($gateway === null) {
            return '';
        }
        try {
            $usable = array_column(ExtensionHelper::getCheckoutGateways($invoice->total, $invoice->currency_code, 'invoice', $invoice->items), 'id');
        } catch (Throwable $e) {
            report($e);

            return '';
        }

        return in_array($gateway->id, $usable) ? (string) $gateway->name : '';
    }

    /** $byStaff: an invoice staff made with «Send Email» on (CreateInvoice): sent whatever the contact takes. */
    public static function invoiceCreatedNotification(Client $client, Invoice $invoice, bool $byStaff = false): void
    {
        self::invoiceNotification($client, $invoice, 'new_invoice_created', byStaff: $byStaff);
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
    public static function invoiceRefundedNotification(Client $client, Invoice $invoice, int $cents, bool $toCredit, bool $byGateway, bool $byStaff = false): void
    {
        $price = new Price(['price' => 0, 'currency' => $invoice->currency]);
        self::toAccount($client, 'invoice_refunded', [
            'invoice' => $invoice,
            'refund' => [
                'amount' => $price->format($cents / 100),
                'to_credit' => $toCredit,
                'by_gateway' => $byGateway,
            ],
        ], gated: !$byStaff);
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

    /**
     * $resend: staff's «Resend welcome email», the mail only (WHMCS's), no new in-app or push notification. $resend and
     * $byStaff (staff's «Create» with «Send notification»): sent whatever the account's contact takes (owner 2026-10-30).
     */
    public static function serverCreatedNotification(Client $client, Service $service, array $data = [], bool $resend = false, bool $byStaff = false): void
    {
        // Its license as it is now: one read before the module issued it is not the welcome mail's (code review of #291).
        $service->unsetRelation('license');
        $data['service'] = $service;
        // The welcome mail only (WHMCS's {$service_password}): customers keep their login from it. No other template
        // can read the password (Service::TEMPLATE_HIDDEN).
        $data['service_password'] ??= rescue(fn () => $service->password, null);
        self::toAccount($client, 'new_server_created', $data, mailOnly: $resend, gated: !$resend && !$byStaff);
    }

    /**
     * A mass mail's message to an account (BulkMailer): its owner, as every account mail, logged on the account. The
     * mailer chose already whether the account takes it (a skipped recipient, EmailKinds::ofBulkMail).
     */
    public static function announcementNotification(Client $client, string $key, array $data): void
    {
        self::toAccount($client, $key, $data, gated: false, toContacts: false);
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

    /** $byStaff: staff's «Unsuspend» with «Send notification» (ADV-545-1): sent whatever the contact takes. */
    public static function serverUnsuspendedNotification(Client $client, Service $service, array $data = [], bool $byStaff = false): void
    {
        $data['service'] = $service;
        self::toAccount($client, 'server_unsuspended', $data, gated: !$byStaff);
    }

    /** An SSL certificate issued or reissued (OpenproviderSsl): its name, until when, the service page to take it from. */
    public static function sslCertificateIssuedNotification(Client $client, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::toAccount($client, 'ssl_certificate_issued', $data);
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

    /**
     * A mail about a ticket to an address with no login (docs/tickets.md A3): a guest's, or whoever opened it by mail.
     * No preferences, no in-app; its Message-ID is the one TicketMailer kept.
     *
     * @param  array<string, mixed>  $data
     */
    public static function guestTicketNotification(Ticket $ticket, string $key, array $data, string $threadMessageId, ?string $to = null): void
    {
        $template = NotificationTemplate::where('key', $key)->first();
        $to ??= $ticket->guest_email;
        if (!$template || !$template->enabled || config('settings.mail_disable') || blank($to)) {
            return;
        }
        $locale = self::localeFor($ticket->client);
        // A guest's name only when our server vouched for the address: a typed-in one may be anyone's (review of #251);
        // and only on the guest's own address, never a copied one's (§6λ).
        $theGuest = strcasecmp((string) $to, (string) $ticket->guest_email) === 0;
        $recipient = new User(['email' => $to, 'first_name' => $ticket->sender_vouched === false || !$theGuest ? null : $ticket->guest_name]);
        self::withLocale($locale, function () use ($template, $locale, $ticket, $data, $recipient, $threadMessageId) {
            $previous = self::$account;
            self::$account = $ticket->client;
            try {
                $data = TemplateRenderer::context($data + ['ticket' => $ticket, 'thread_message_id' => $threadMessageId], $recipient);
                self::sendEmailNotification($template->inLocale($locale), $data, $recipient);
            } catch (TemplateError $e) {
                report($e);
            } finally {
                self::$account = $previous;
            }
        });
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
