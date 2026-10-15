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
