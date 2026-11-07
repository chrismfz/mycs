<?php

namespace App\Services\Mail;

use App\Mail\ResentMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/** The mail audit's helpers (docs/mail.md): what a mail is about, and staff's resend. */
class MailAudit
{
    /**
     * What a template's data is about, most specific first: an invoice, a service (also a cancellation's), an order,
     * a ticket (a reply's).
     *
     * @param  array<string, mixed>  $data
     * @return array{related_type: string|null, related_id: int|null}
     */
    public static function related(array $data): array
    {
        $model = match (true) {
            ($data['invoice'] ?? null) instanceof Invoice => $data['invoice'],
            ($data['service'] ?? null) instanceof Service => $data['service'],
            ($data['order'] ?? null) instanceof Order => $data['order'],
            ($data['ticketMessage'] ?? null) instanceof TicketMessage => $data['ticketMessage']->ticket,
            default => null,
        };

        return $model instanceof Model
            ? ['related_type' => $model->getMorphClass(), 'related_id' => (int) $model->getKey()]
            : ['related_type' => null, 'related_id' => null];
    }

    /** @return list<string> */
    public static function addresses(mixed $list): array
    {
        $list = is_string($list) ? preg_split('/[\s,;]+/', $list) : (array) $list;

        return array_values(array_filter(array_map('trim', array_map('strval', $list)), fn ($address) => $address !== ''));
    }

    /**
     * Staff send a logged mail again, to its address or another, with or without its Cc/Bcc: a new row (manual or
     * batch), linked to the original, with the body the log keeps.
     */
    /**
     * Mails that ask the customer to pay their invoice (or to renew by it), or tell of a state their service is in: after
     * a while (a mail outage) they may no longer be true (L-NOTIFICATIONS-4).
     */
    public const STALE_WHEN_SETTLED = ['new_invoice_created', 'invoice_payment_reminder', 'card_payment_due', 'invoice_overdue',
        'invoice_payment_failed', 'domain_renewal_notice', 'domain_expired_notice'];

    /**
     * Why a logged mail is no longer true, or null: one asking to pay an invoice that is paid, cancelled or settled since
     * (or gone), or an overdue notice of one not overdue now (given more time), a suspension notice of a service no longer
     * suspended, a hold's warning of one no longer active, past due or held. The
     * batch resend leaves those out (app:mail-resend-failed); staff's own Resend is staff's to judge.
     */
    public static function stale(EmailLog $log): ?string
    {
        if ($log->related_id === null || $log->related_type === null) {
            return null;
        }
        $class = Model::getActualClassNameForMorph($log->related_type);
        if (in_array($log->template_key, self::STALE_WHEN_SETTLED, true) && $class === Invoice::class) {
            $invoice = Invoice::query()->find($log->related_id);

            return match (true) {
                $invoice === null => 'its invoice is gone',
                $invoice->status !== Invoice::STATUS_UNPAID => "its invoice is {$invoice->status} now",
                $invoice->remaining <= 0 => 'its invoice has nothing left to pay',
                // An overdue notice of one given more time since (its due date moved past today): not overdue now (REV3-LN-2).
                $log->template_key === 'invoice_overdue' && !Carbon::parse($invoice->due_at ?? $invoice->created_at)->startOfDay()->lessThan(Carbon::today()) => 'its invoice is not overdue now',
                default => null,
            };
        }
        $wants = ['server_suspended' => Service::STATUS_SUSPENDED, 'service_hold_ending' => Service::STATUS_ACTIVE][$log->template_key] ?? null;
        if ($wants !== null && $class === Service::class) {
            $service = Service::query()->find($log->related_id);
            if ($service === null || $service->status !== $wants) {
                return 'its service is ' . ($service->status ?? 'gone') . ' now';
            }
            // A hold's warning («pay, or it is suspended on D, the day after the hold») of one paid since (its due date
            // moved on, Suspend Days after it no longer ending by its hold, as the cron's heldBack counts it) or whose hold
            // staff took off: no longer true (REV3-LN-1).
            if ($log->template_key === 'service_hold_ending') {
                $days = max(0, (int) config('settings.cronjob_order_suspend', 8));

                return match (true) {
                    $service->suspend_hold_until === null => 'its service is no longer held',
                    $service->expires_at === null
                        || Carbon::parse($service->expires_at)->startOfDay()->addDays($days)->greaterThan(Carbon::parse($service->suspend_hold_until)->startOfDay()) => 'its service is no longer past due',
                    default => null,
                };
            }
        }

        return null;
    }

    public static function resend(EmailLog $original, string $to, bool $withCopies = true, ?Staff $staff = null, string $trigger = EmailLog::TRIGGER_MANUAL): EmailLog
    {
        $cc = $withCopies ? ($original->cc ?? []) : [];
        $bcc = $withCopies ? ($original->bcc ?? []) : [];
        $log = EmailLog::query()->create([
            'client_id' => $original->client_id,
            'subject' => $original->subject,
            'to' => $to,
            'body' => $original->body,
            'from_address' => config('settings.mail_from_address') ?: $original->from_address,
            'cc' => $cc ?: null,
            'bcc' => $bcc ?: null,
            'template_key' => $original->template_key,
            'related_type' => $original->related_type,
            'related_id' => $original->related_id,
            'trigger' => $trigger,
            'staff_id' => $staff?->id,
            'resent_from_id' => $original->id,
        ]);
        $mail = new ResentMail($original->subject, $original->body);
        $mail->email_log_id = $log->id;
        Mail::to($to)->cc($cc)->bcc($bcc)->queue($mail);

        return $log;
    }
}
