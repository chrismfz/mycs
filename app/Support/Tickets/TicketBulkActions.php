<?php

namespace App\Support\Tickets;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\TicketQuarantine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * «With selected» on staff's ticket list (docs/tickets.md §6μ): pin, close, merge, delete, block the senders and delete.
 * Each refuses the whole selection, naming the ticket at fault, rather than doing part of it. Permissions are the
 * caller's (the list's actions); what is checked here is what makes the action wrong whoever asks.
 */
final class TicketBulkActions
{
    public function __construct(private MergeTickets $merge, private QuarantineActions $quarantine) {}

    /** @param  Collection<int, Ticket>  $tickets */
    public function pin(Collection $tickets, bool $pinned): int
    {
        $count = 0;
        foreach ($tickets as $ticket) {
            if (($ticket->pinned_at !== null) === $pinned) {
                continue;
            }
            // Saved one by one: each is audited (who pinned what).
            $ticket->forceFill(['pinned_at' => $pinned ? now() : null])->save();
            $count++;
        }

        return $count;
    }

    /** @param  Collection<int, Ticket>  $tickets */
    public function close(Collection $tickets): int
    {
        $count = 0;
        foreach ($tickets as $ticket) {
            if ($ticket->status === Ticket::STATUS_CLOSED) {
                continue;
            }
            $ticket->update(['status' => Ticket::STATUS_CLOSED]);
            $count++;
        }

        return $count;
    }

    /**
     * The ticket a merge keeps by default: the oldest selected.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function oldest(Collection $tickets): ?Ticket
    {
        return $tickets->sortBy('id')->first();
    }

    /**
     * Every selected ticket but the one kept merges into it (MergeTickets, §6κ): all or none. Tickets of another owner
     * (another account, another guest address) or already merged refuse the whole merge.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function merge(Collection $tickets, Ticket $target, ?Staff $staff): Ticket
    {
        if ($tickets->count() < 2) {
            throw new InvalidArgumentException(__('Select at least two tickets to merge.'));
        }
        if (!$tickets->contains('id', $target->id)) {
            throw new InvalidArgumentException(__('The ticket to keep must be one of those selected.'));
        }
        $sources = $tickets->reject(fn (Ticket $ticket) => $ticket->id === $target->id)->sortBy('id')->values();
        foreach ($sources as $source) {
            if (!$source->canMergeInto($target)) {
                throw new InvalidArgumentException(__('#:reference cannot be merged into #:target: another requestor, or already merged.',
                    ['reference' => $source->reference, 'target' => $target->reference]));
            }
        }

        // One transaction: a refusal half-way (another staff member merged one meanwhile) undoes those before it.
        return DB::transaction(function () use ($sources, $target, $staff) {
            foreach ($sources as $source) {
                $target = $this->merge->handle($source, $target, $staff);
            }

            return $target;
        });
    }

    /**
     * Deletes the tickets and those merged into them (empty shells whose thread was theirs: left, they would turn into
     * ordinary closed tickets with nothing in them). Returns how many went.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function delete(Collection $tickets): int
    {
        return DB::transaction(function () use ($tickets) {
            $all = $tickets->merge($this->mergedInto($tickets));
            $this->deleteWithFiles($all);

            return $all->count();
        });
    }

    /**
     * As from the ticket's page (for good, audited, a WHMCS one tombstoned, §6ζ), and their files with them: the rows go
     * by cascade, which no model event sees, so the files are unlinked here once the deletion is committed.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    private function deleteWithFiles(Collection $tickets): void
    {
        $paths = TicketMessageAttachment::query()->whereIn('ticket_message_id', TicketMessage::query()->whereIn('ticket_id', $tickets->pluck('id'))->select('id'))
            ->pluck('path')->map(fn ($path) => (string) $path)->unique()->values()->all();
        foreach ($tickets as $ticket) {
            $ticket->delete();
        }
        DB::afterCommit(function () use ($paths) {
            foreach ($paths as $path) {
                // Only inside the tickets' folder, and only a file no other message still names.
                $file = TicketMessageAttachment::inFolder($path);
                if ($file !== null && TicketMessageAttachment::query()->where('path', $path)->doesntExist()) {
                    @unlink($file);
                }
            }
        });
    }

    /**
     * The tickets merged into these, however deep the chain, not themselves selected.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<int, Ticket>
     */
    public function mergedInto(Collection $tickets): Collection
    {
        $seen = $tickets->pluck('id')->all();
        $found = collect();
        $ids = $seen;
        for ($depth = 0; $ids !== [] && $depth < 20; $depth++) {
            $next = Ticket::query()->whereIn('merged_into_id', $ids)->whereNotIn('id', $seen)->get();
            $ids = $next->pluck('id')->all();
            $seen = array_merge($seen, $ids);
            $found = $found->merge($next);
        }

        return $found->values();
    }

    /**
     * The addresses that wrote the selected tickets: each guest's, and whoever else wrote in them by mail (a CC's reply).
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return list<string>
     */
    public function senders(Collection $tickets): array
    {
        $fromMessages = DB::table('ticket_messages')->whereIn('ticket_id', $tickets->pluck('id'))->whereNull('staff_id')
            ->whereNotNull('sender_email')->pluck('sender_email');

        return $tickets->pluck('guest_email')->merge($fromMessages)
            ->map(fn ($email) => strtolower(trim((string) $email)))->filter(fn ($email) => str_contains($email, '@'))
            ->unique()->sort()->values()->all();
    }

    /**
     * What blocking would refuse, as a message; null when it may go ahead. Only guests' tickets (a customer is never
     * blocked from here), and never one of our addresses or a customer's login, nor a domain one of them is in.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function blockRefusal(Collection $tickets, bool $wholeDomain): ?string
    {
        if ($tickets->isEmpty()) {
            return __('Select at least one ticket.');
        }
        $client = $tickets->first(fn (Ticket $ticket) => !$ticket->isGuest());
        if ($client !== null) {
            return __('#:reference belongs to a client\'s account: only guests\' senders are blocked from here. Delete it instead.', ['reference' => $client->reference]);
        }
        $senders = $this->senders($tickets);
        if ($senders === []) {
            return __('These tickets have no sender to block.');
        }
        $ours = $this->ourAddresses();
        $ourDomains = array_values(array_unique(array_map(fn (string $email) => self::domainOf($email), $ours)));
        foreach ($senders as $sender) {
            if (in_array(TicketBlockedSender::base($sender), $ours, true)) {
                return __(':email is one of our addresses.', ['email' => $sender]);
            }
            if ($this->isCustomersAddress($sender)) {
                return __(':email is a customer\'s login: it cannot be blocked from here.', ['email' => $sender]);
            }
            if ($wholeDomain) {
                // The domain as a block holds it: its ASCII form, googlemail as gmail.
                $domain = self::domainOf($sender);
                if (in_array($domain, self::FREE_MAIL, true)) {
                    return __('@:domain is a free mail provider: block the address, not the domain.', ['domain' => $domain]);
                }
                if (in_array($domain, $ourDomains, true)) {
                    return __('@:domain is our own domain.', ['domain' => $domain]);
                }
                if ($this->logins($domain)->isNotEmpty()) {
                    return __('A customer\'s login is at @:domain: block the address, not the domain.', ['domain' => $domain]);
                }
            }
        }

        return null;
    }

    /**
     * Blocks each sender (or its domain), dismisses their mail waiting in the quarantine, and deletes the tickets: all
     * or none. Each ticket's audit says which senders were blocked with it.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return list<string> the values blocked (an address, or @domain)
     */
    public function blockAndDelete(Collection $tickets, Staff $staff, bool $wholeDomain = false): array
    {
        $refusal = $this->blockRefusal($tickets, $wholeDomain);
        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
        $senders = $this->senders($tickets);
        $values = array_values(array_unique(array_map(fn (string $sender) => TicketBlockedSender::normalise(
            $wholeDomain ? '@' . self::domainOf($sender) : $sender), $senders)));

        DB::transaction(function () use ($tickets, $staff, $senders, $values) {
            foreach ($values as $value) {
                TicketBlockedSender::query()->firstOrCreate(['value' => $value], ['note' => __('Blocked from the ticket list'), 'created_by' => $staff->id]);
            }
            // Only the mail of what this action blocks: an earlier block's leftovers are another staff member's call.
            foreach (TicketQuarantine::query()->where('status', TicketQuarantine::PENDING)->whereNotNull('from_email')->get() as $pending) {
                $from = TicketBlockedSender::base((string) $pending->from_email);
                if (array_intersect([$from, '@' . self::domainOf($from)], $values) !== []) {
                    try {
                        $this->quarantine->dismiss($pending, $staff);
                    } catch (InvalidArgumentException) {
                        // Handled by someone else meanwhile: nothing left to dismiss.
                    }
                }
            }
            foreach ($tickets->merge($this->mergedInto($tickets)) as $ticket) {
                $ticket->auditEvent = 'blocked_and_deleted';
                $ticket->isCustomEvent = true;
                $ticket->auditCustomOld = ['reference' => $ticket->reference, 'subject' => $ticket->subject, 'guest_email' => $ticket->guest_email];
                $ticket->auditCustomNew = ['blocked' => $values, 'senders' => $senders, 'staff_id' => $staff->id];
                event(new AuditCustom($ticket));
                $ticket->isCustomEvent = false;
            }
            $this->deleteWithFiles($tickets->merge($this->mergedInto($tickets)));
        });

        return $values;
    }

    /**
     * Free mail providers: their whole domain is never blocked from here, however few customers are there today.
     *
     * @var list<string>
     */
    private const FREE_MAIL = ['gmail.com', 'yahoo.com', 'yahoo.gr', 'ymail.com', 'hotmail.com', 'hotmail.gr', 'outlook.com', 'live.com',
        'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.net', 'gmx.de',
        'mail.com', 'mail.ru', 'yandex.com', 'yandex.ru', 'zoho.com', 'otenet.gr', 'windowslive.com'];

    /**
     * @return list<string> our addresses (their base): a department's email or IMAP login, the address we mail from, and
     *                      staff's own (a colleague copied on a ticket)
     */
    private function ourAddresses(): array
    {
        return TicketDepartment::query()->get()->flatMap(fn (TicketDepartment $department) => $department->ownAddresses())
            ->push(config('settings.mail_from_address'))
            ->merge(Staff::query()->pluck('email'))
            ->filter(fn ($email) => is_string($email) && str_contains($email, '@'))
            ->map(fn ($email) => TicketBlockedSender::base((string) $email))->unique()->values()->all();
    }

    /** The domain of an address as a block holds it: ASCII (an IDN's xn-- form), googlemail as gmail. */
    private static function domainOf(string $email): string
    {
        $base = TicketBlockedSender::base($email);

        return substr($base, (int) strrpos($base, '@') + 1);
    }

    /**
     * Customers' logins at a domain (given as domainOf gives it), however their address is stored: in Unicode or
     * ASCII, gmail or googlemail.
     *
     * @return Collection<int, string>
     */
    private function logins(string $domain): Collection
    {
        $spellings = [$domain];
        $unicode = idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($unicode) && $unicode !== '') {
            $spellings[] = $unicode;
        }
        if ($domain === 'gmail.com') {
            $spellings[] = 'googlemail.com';
        }

        return User::query()->where(function ($query) use ($spellings) {
            foreach (array_unique($spellings) as $spelling) {
                $query->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", ['%@' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($spelling))]);
            }
        })->pluck('email')->map(fn ($email) => (string) $email)
            // A LIKE that a backend reads loosely is narrowed here, by the domain as a block holds it.
            ->filter(fn (string $email) => self::domainOf($email) === $domain)->values();
    }

    private function isCustomersAddress(string $email): bool
    {
        $base = TicketBlockedSender::base($email);

        // Spelled however (a +tag, Gmail's dots, an IDN either way): compared by their mailbox.
        return $this->logins(self::domainOf($email))->contains(fn (string $login) => TicketBlockedSender::base($login) === $base);
    }
}
