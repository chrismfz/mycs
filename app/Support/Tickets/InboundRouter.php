<?php

namespace App\Support\Tickets;

use App\Models\ClientUser;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\AuthVerdict;
use MyCS\TicketMail\Automation;
use MyCS\TicketMail\InboundEmail;
use MyCS\TicketMail\ReplyText;

/**
 * One inbound mail into a ticket, or into the quarantine (docs/tickets.md §3). In this order:
 *   1. a mail seen before (its Message-ID) is skipped; a blocked sender is dropped;
 *   2. no sender, two senders, our own address, automated mail, a giant: quarantine (§3 Ε);
 *   3. a reply joins a ticket ONLY by one of our Message-IDs (§3 Α), ONLY from its owner (the guest's address, or a login
 *      of its account with the tickets permission), and with «Require DMARC» ONLY when our server vouches for the From
 *      (§3 Β); otherwise quarantine, the ticket suggested to staff;
 *   4. a mail naming a ticket (its number in the subject) without our Message-ID: quarantine, the ticket suggested only
 *      when its owner wrote it (§5α: replies to old WHMCS mail);
 *   5. anything else is a new ticket: in an account only when our server vouches for the From and it is a login of
 *      exactly one account; else a guest's (§3 Δ). Never To or Cc into a ticket (§3 Γ).
 */
class InboundRouter
{
    /** New tickets by mail from one sender in an hour (§3 Ε). */
    public const NEW_TICKETS_PER_HOUR = 10;

    /** The bytes of a message's text (the column is TEXT); the whole mail stays in body_original. */
    private const MAX_MESSAGE_BYTES = 60_000;

    public function route(TicketDepartment $department, ?InboundEmail $email, string $raw): InboundOutcome
    {
        if ($email === null) {
            return $this->quarantine($department, null, $raw, 'unparseable', messageId: 'raw.' . hash('sha256', $raw));
        }
        $messageId = $email->messageId ?? 'gen.' . hash('sha256', $email->fromEmail . '|' . $email->subject . '|' . $email->date?->format('c') . '|' . mb_substr($email->body, 0, 2000));
        if ($this->seen($messageId)) {
            return new InboundOutcome(InboundOutcome::DUPLICATE);
        }

        $from = $email->fromEmail;
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $this->quarantine($department, $email, $raw, 'no_sender', $messageId);
        }
        if ($email->fromCount > 1) {
            return $this->quarantine($department, $email, $raw, 'multiple_from', $messageId);
        }
        if (TicketBlockedSender::blocks($from)) {
            return new InboundOutcome(InboundOutcome::BLOCKED);
        }
        if (in_array(TicketBlockedSender::base($from), array_map([TicketBlockedSender::class, 'base'], $this->ourAddresses()), true)) {
            return $this->quarantine($department, $email, $raw, 'own_address', $messageId);
        }
        if (($automated = Automation::reason($email)) !== null) {
            return $this->quarantine($department, $email, $raw, 'automated', $messageId, detail: $automated);
        }
        if ($email->tooLarge) {
            return $this->quarantine($department, $email, $raw, 'too_large', $messageId);
        }

        $verdict = AuthenticationResults::verdict($email->authenticationResults, (string) $department->authserv_id, $from);
        $vouched = $verdict->authenticated || !$department->require_dmarc;

        // 3. A reply: by our Message-ID only.
        $threaded = $this->threadedTicket($email);
        if ($threaded !== null) {
            $author = $this->owner($threaded, $from);
            if ($author === false) {
                return $this->quarantine($department, $email, $raw, 'not_owner', $messageId, $verdict, $threaded);
            }
            if (!$vouched) {
                return $this->quarantine($department, $email, $raw, 'not_authenticated', $messageId, $verdict, $threaded);
            }

            return $this->reply($threaded, $email, $messageId, $author);
        }

        // 4. Names a ticket, but is no reply to a mail of ours (an old WHMCS one): staff decide.
        if (($named = $this->namedTicket($email->subject)) !== null) {
            $suggest = $this->owner($named, $from) !== false && $vouched ? $named : null;

            return $this->quarantine($department, $email, $raw, 'no_thread_key', $messageId, $verdict, $suggest);
        }

        // 5. A new ticket.
        $account = $verdict->authenticated ? $this->accountOf($from) : null;
        if ($account === null && $department->clients_only) {
            return $this->quarantine($department, $email, $raw, 'clients_only', $messageId, $verdict);
        }
        // Vouched and unvouched mail count apart: forged mail in a customer's name never uses up their own hour.
        $limit = 'ticket-mail-new:' . ($verdict->authenticated ? 'vouched:' : 'unvouched:') . TicketBlockedSender::base($from);
        if (RateLimiter::tooManyAttempts($limit, self::NEW_TICKETS_PER_HOUR)) {
            return $this->quarantine($department, $email, $raw, 'rate_limited', $messageId, $verdict);
        }
        $outcome = $this->open($department, $email, $messageId, $account);
        if ($outcome->kind === InboundOutcome::OPENED) {
            RateLimiter::hit($limit, 3600);
            // «We received it», only to an address our server vouched for: never mail to a forged From (backscatter).
            if ($verdict->authenticated && $outcome->ticket !== null) {
                app(TicketMailer::class)->received($outcome->ticket, $from);
            }
        }

        return $outcome;
    }

    /**
     * The owner who wrote, if it is the owner: null for a guest's (no login), the login of the account; false when the
     * sender is not the owner.
     */
    public function owner(Ticket $ticket, string $from): User|null|false
    {
        $from = strtolower(trim($from));
        if ($ticket->isGuest()) {
            return strtolower(trim((string) $ticket->guest_email)) === $from && $from !== '' ? null : false;
        }
        $user = User::query()->whereRaw('LOWER(email) = ?', [$from])->first();
        if ($user === null) {
            return false;
        }
        $grant = ClientUser::query()->where('user_id', $user->id)->where('client_id', $ticket->client_id)->whereNull('revoked_at')->first();

        return $grant !== null && $grant->allows('tickets') ? $user : false;
    }

    private function threadedTicket(InboundEmail $email): ?Ticket
    {
        if ($email->references === []) {
            return null;
        }
        // Only the Message-IDs we gave our own mails (TicketMailId, A3): never one that came in.
        $key = TicketMailId::query()->whereIn('message_id', $email->references)->latest('id')->first();

        return $key?->ticket;
    }

    /** A ticket named in a subject as WHMCS and we write it: «[Ticket ID: 123456]», «[#123456]». */
    private function namedTicket(string $subject): ?Ticket
    {
        if (preg_match('/\[\s*(?:Ticket\s*ID:\s*|#)\s*([A-Za-z0-9_-]{4,64})\s*\]/i', $subject, $m) !== 1) {
            return null;
        }

        return Ticket::query()->where('reference', $m[1])->first();
    }

    /** The account of a login, when it is exactly one that lets it see tickets; otherwise none (staff link it). */
    private function accountOf(string $from): ?array
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($from)])->first();
        if ($user === null) {
            return null;
        }
        $grants = ClientUser::query()->where('user_id', $user->id)->whereNull('revoked_at')->get()->filter(fn (ClientUser $grant) => $grant->allows('tickets'));

        return $grants->count() === 1 ? ['client_id' => (int) $grants->first()->client_id, 'user' => $user] : null;
    }

    private function reply(Ticket $ticket, InboundEmail $email, string $messageId, ?User $author): InboundOutcome
    {
        return $this->unique(fn () => TicketFiles::transaction(function () use ($ticket, $email, $messageId, $author) {
            $message = $ticket->messages()->create($this->messageData($email, $messageId, $author, ReplyText::clean($email->body)));
            TicketFiles::storeInbound($message, $email->attachments);

            return new InboundOutcome(InboundOutcome::THREADED, $ticket);
        }));
    }

    /** @param  array{client_id: int, user: User}|null  $account */
    private function open(TicketDepartment $department, InboundEmail $email, string $messageId, ?array $account): InboundOutcome
    {
        return $this->unique(fn () => TicketFiles::transaction(function () use ($department, $email, $messageId, $account) {
            $ticket = Ticket::query()->create([
                'subject' => $this->subject($email->subject),
                'status' => Ticket::STATUS_OPEN,
                'priority' => 'medium',
                'department_id' => $department->id,
                'opened_via' => 'email',
                'client_id' => $account['client_id'] ?? null,
                'guest_name' => $account === null ? ($email->fromName ?? $email->fromEmail) : null,
                'guest_email' => $account === null ? $email->fromEmail : null,
            ]);
            $message = $ticket->messages()->create($this->messageData($email, $messageId, $account['user'] ?? null, trim($email->body)));
            TicketFiles::storeInbound($message, $email->attachments);

            return new InboundOutcome(InboundOutcome::OPENED, $ticket);
        }));
    }

    /** @return array<string, mixed> */
    private function messageData(InboundEmail $email, string $messageId, ?User $author, string $text): array
    {
        $text = $text !== '' ? $text : '(' . __('no text') . ')';
        if ($email->refused !== []) {
            $text .= "\n\n— " . __('Files not kept') . ': ' . implode(', ', array_map(fn (array $file) => $file['name'], $email->refused));
        }

        return [
            'user_id' => $author?->id,
            'message' => mb_strcut($text, 0, self::MAX_MESSAGE_BYTES, 'UTF-8'),
            'body_original' => $email->body,
            'via' => TicketMessage::VIA_EMAIL,
            // Its own Message-ID (or a made-up one): kept to skip it next time; by email, so never a thread key.
            'email_message_id' => mb_substr($messageId, 0, 250),
        ];
    }

    /** Two pollers, or one rerun, storing the same mail: the second is a duplicate, never an error. */
    private function unique(\Closure $store): InboundOutcome
    {
        try {
            return $store();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'email_message_id') || str_contains($e->getMessage(), 'message_id')) {
                return new InboundOutcome(InboundOutcome::DUPLICATE);
            }

            throw $e;
        }
    }

    private function quarantine(TicketDepartment $department, ?InboundEmail $email, string $raw, string $reason, string $messageId,
        ?AuthVerdict $verdict = null, ?Ticket $suggest = null, ?string $detail = null): InboundOutcome
    {
        $path = 'tickets/quarantine/' . Str::ulid() . '.eml';
        Storage::disk(TicketQuarantine::DISK)->put($path, $raw);
        try {
            $quarantine = TicketQuarantine::query()->create([
                'department_id' => $department->id,
                'reason' => $reason,
                'message_id' => mb_substr($messageId, 0, 250),
                'from_email' => $email?->fromEmail !== '' ? mb_substr((string) $email?->fromEmail, 0, 250) : null,
                'from_name' => $email?->fromName,
                'subject' => $email !== null ? mb_substr($email->subject, 0, 250) : null,
                'sent_at' => self::sentAt($email),
                'authenticated' => $verdict !== null && $verdict->authenticated,
                'auth_detail' => $detail ?? $verdict?->reason,
                'preview' => $email !== null ? mb_substr($email->body, 0, 5000) : null,
                'refused_files' => $email?->refused ?: null,
                'raw_path' => $path,
                'raw_size' => strlen($raw),
                'suggested_ticket_id' => $suggest?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            Storage::disk(TicketQuarantine::DISK)->delete($path);

            return new InboundOutcome(InboundOutcome::DUPLICATE);
        } catch (\Throwable $e) {
            Storage::disk(TicketQuarantine::DISK)->delete($path);

            throw $e;
        }

        return new InboundOutcome(InboundOutcome::QUARANTINED, quarantine: $quarantine);
    }

    /**
     * A mail that failed every time (the poller gave up on it): kept with the error, never left to wedge the mailbox. Only
     * what cannot fail: no value of the mail but its raw copy.
     */
    public function failed(TicketDepartment $department, ?InboundEmail $email, string $raw, string $error): InboundOutcome
    {
        $messageId = 'failed.' . hash('sha256', $raw);
        if (TicketQuarantine::query()->where('message_id', $messageId)->exists()) {
            return new InboundOutcome(InboundOutcome::DUPLICATE);
        }
        $path = 'tickets/quarantine/' . Str::ulid() . '.eml';
        Storage::disk(TicketQuarantine::DISK)->put($path, $raw);
        try {
            $quarantine = TicketQuarantine::query()->create(['department_id' => $department->id, 'reason' => 'failed', 'message_id' => $messageId,
                'from_email' => $email !== null && filter_var($email->fromEmail, FILTER_VALIDATE_EMAIL) ? mb_substr($email->fromEmail, 0, 250) : null,
                'subject' => $email !== null ? mb_substr($email->subject, 0, 250) : null,
                'preview' => mb_substr($error, 0, 2000), 'raw_path' => $path, 'raw_size' => strlen($raw)]);
        } catch (\Throwable $e) {
            Storage::disk(TicketQuarantine::DISK)->delete($path);

            throw $e;
        }

        return new InboundOutcome(InboundOutcome::QUARANTINED, quarantine: $quarantine);
    }

    /** The sender's Date, in our timezone; none when outside what a TIMESTAMP holds (1970–2038, spam's 2099). */
    private static function sentAt(?InboundEmail $email): ?Carbon
    {
        if ($email?->date === null) {
            return null;
        }
        $date = Carbon::instance($email->date)->setTimezone(config('app.timezone'));

        return $date->year > 1970 && $date->year < 2038 ? $date : null;
    }

    private function seen(string $messageId): bool
    {
        return TicketMessage::query()->where('email_message_id', $messageId)->exists()
            || TicketQuarantine::query()->where('message_id', $messageId)->exists();
    }

    /** @return list<string> every department's own addresses */
    private function ourAddresses(): array
    {
        return TicketDepartment::query()->get()->flatMap(fn (TicketDepartment $department) => $department->ownAddresses())->unique()->values()->all();
    }

    /** A new ticket's subject: without Re:/Fwd: and our tag, never empty. */
    private function subject(string $subject): string
    {
        $subject = trim((string) preg_replace('/\[\s*(?:Ticket\s*ID:\s*|#)[^\]]*\]/i', '', $subject));
        while (preg_match('/^\s*(re|fwd?|aw|wg|απ|σχετ|προς)\s*:\s*/iu', $subject) === 1) {
            $subject = (string) preg_replace('/^\s*(re|fwd?|aw|wg|απ|σχετ|προς)\s*:\s*/iu', '', $subject);
        }
        $subject = trim($subject);

        return $subject !== '' ? mb_substr($subject, 0, 250) : __('(no subject)');
    }
}
