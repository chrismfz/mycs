<?php

namespace App\Support\Tickets;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketGuestLink;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A duplicate ticket merged by staff into another of the same owner (docs/tickets.md §6κ), after Ekdosi's: its messages
 * (with their files), the Message-IDs of the mails we sent from it (a reply to one joins the target) and the guest's
 * links move to the target; it is closed and points there, so its number in a subject and its page lead there too.
 * The target keeps its number, subject and owner; it waits on us when the merged one did. A note on each says what
 * happened. Nothing is mailed.
 */
final class MergeTickets
{
    /** Statuses in which a ticket waits on staff. */
    private const WAITING = [Ticket::STATUS_OPEN, Ticket::STATUS_CUSTOMER_REPLY];

    public function handle(Ticket $source, Ticket $target, ?Staff $staff = null): Ticket
    {
        return DB::transaction(function () use ($source, $target, $staff) {
            // Both read again under a lock: two staff merging the same tickets at once cannot cross them.
            $ids = [$source->id, $target->id];
            sort($ids);
            $locked = Ticket::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked->get($source->id);
            $target = $locked->get($target->id);
            if ($source === null || $target === null || !$source->canMergeInto($target)) {
                throw new InvalidArgumentException(__('These tickets cannot be merged: another owner, or one already merged.'));
            }

            // Moved as rows, not saved one by one: no event fires (no mail, no status from the listener).
            TicketMessage::query()->where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
            TicketMailId::query()->where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
            TicketGuestLink::query()->where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);

            if (in_array($source->status, self::WAITING, true) && !in_array($target->status, self::WAITING, true)) {
                $answered = $target->messages()->whereNotNull('staff_id')->where('is_internal', false)->exists();
                $target->status = $answered ? Ticket::STATUS_CUSTOMER_REPLY : Ticket::STATUS_OPEN;
            }
            $target->save();

            $this->note($target, $staff, __('Ticket #:reference was merged into this one.', ['reference' => $source->reference]));
            $this->note($source, $staff, __('Merged into ticket #:reference.', ['reference' => $target->reference]));
            $source->forceFill(['status' => Ticket::STATUS_CLOSED, 'merged_into_id' => $target->id])->save();

            return $target->refresh();
        });
    }

    private function note(Ticket $ticket, ?Staff $staff, string $text): void
    {
        TicketMessage::withoutEvents(fn () => $ticket->messages()->create(['staff_id' => $staff?->id, 'message' => $text, 'is_internal' => true]));
    }
}
