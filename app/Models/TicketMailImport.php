<?php

namespace App\Models;

use App\Support\Tickets\InboundOutcome;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MyCS\TicketMail\InboundEmail;
use Throwable;

/**
 * One mail the poller read and what came of it (the Ticket Mail Import Log, owner 2026-10-23): a new ticket, a reply,
 * the quarantine (and why), a blocked sender. A mail seen before is not read again, so it is not here twice.
 *
 * @property int $id
 * @property int|null $department_id
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string|null $subject
 * @property string $outcome
 * @property string|null $reason
 * @property int|null $ticket_id
 * @property int|null $quarantine_id
 */
class TicketMailImport extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['department_id', 'from_email', 'from_name', 'subject', 'outcome', 'reason', 'ticket_id', 'quarantine_id'];

    /** What the poller did with a mail, in the log; never the poll's own failure. */
    public static function record(TicketDepartment $department, ?InboundEmail $email, InboundOutcome $outcome): void
    {
        if ($outcome->kind === InboundOutcome::DUPLICATE) {
            return;
        }
        try {
            $quarantine = $outcome->quarantine;
            self::query()->create([
                'department_id' => $department->id,
                'from_email' => $email !== null && $email->fromEmail !== '' ? mb_substr($email->fromEmail, 0, 250) : $quarantine?->from_email,
                'from_name' => $email?->fromName !== null ? mb_substr($email->fromName, 0, 250) : null,
                'subject' => $email !== null ? mb_substr($email->subject, 0, 250) : $quarantine?->subject,
                'outcome' => $outcome->kind,
                'reason' => $quarantine?->reason,
                'ticket_id' => $outcome->ticket?->id,
                'quarantine_id' => $quarantine?->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return BelongsTo<TicketDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TicketQuarantine, $this> */
    public function quarantine(): BelongsTo
    {
        return $this->belongsTo(TicketQuarantine::class);
    }
}
