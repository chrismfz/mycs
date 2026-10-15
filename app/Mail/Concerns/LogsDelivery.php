<?php

namespace App\Mail\Concerns;

use App\Models\EmailLog;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * A logged mail's way out (docs/mail.md), kept by the send itself, not by queue events:
 * - at most once: the send claims its row (pending/failed → sending); a row already sent, or left «sending» by a worker
 *   that stopped mid-send, is not sent again (a duplicate invoice mail is worse than a missing one, which staff resend);
 * - a permanent refusal of a Cc/Bcc at RCPT (SMTP refuses the whole mail on the first one) sends it once more to the
 *   To only, and the log says who was dropped; a permanent refusal of the To fails it at once, without retries;
 * - anything else (4xx, connection, auth) goes back to pending with its error, for the queue's next try.
 *
 * @property int|null $email_log_id
 */
trait LogsDelivery
{
    public function send($mailer)
    {
        $id = $this->email_log_id ?? null;
        if ($id === null) {
            return parent::send($mailer);
        }
        $claimed = EmailLog::query()->whereKey($id)->where(fn ($query) => self::claimable($query))
            ->update(['status' => EmailLog::STATUS_SENDING, 'sending_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
        if ($claimed === 0) {
            return null;
        }

        try {
            return $this->sent($id, parent::send($mailer));
        } catch (UnexpectedResponseException $e) {
            if (!self::refusedRecipient($e)) {
                $this->retryLater($id, $e);
            }
            $refused = self::refusedAddress($e);
            $to = array_column($this->to, 'address');
            $extras = [...array_column($this->cc, 'address'), ...array_column($this->bcc, 'address')];
            if ($refused !== null && !in_array(strtolower($refused), array_map('strtolower', [...$to, ...$extras]), true)) {
                $this->retryLater($id, $e); // not a recipient (the sender, a relay rule): the configuration's to fix
            }
            if ($extras === [] || ($refused !== null && in_array(strtolower($refused), array_map('strtolower', $to), true))) {
                return $this->refused($id, $e);
            }
            $primary = clone $this;
            $primary->cc = [];
            $primary->bcc = [];
            try {
                $sent = $primary->deliver($mailer);
            } catch (UnexpectedResponseException $again) {
                if (self::refusedRecipient($again)) {
                    return $this->refused($id, $again);
                }
                $this->retryLater($id, $again);
            } catch (Throwable $again) {
                $this->retryLater($id, $again); // a dropped connection: nothing went out (review R2)
            }
            $dropped = $refused !== null ? [$refused] : $extras;
            EmailLog::query()->whereKey($id)->update(['cc' => null, 'bcc' => null,
                'error' => 'Sent to the To only: the mail server refused ' . implode(', ', $dropped) . '.']);

            return $this->sent($id, $sent);
        } catch (Throwable $e) {
            $this->retryLater($id, $e);
        }
    }

    /** The send without the log (the To-only send after a refused Cc/Bcc). */
    protected function deliver($mailer): ?SentMessage
    {
        return parent::send($mailer);
    }

    private function sent(int $id, ?SentMessage $sent): ?SentMessage
    {
        EmailLog::query()->whereKey($id)->update(['status' => EmailLog::STATUS_SENT, 'sent_at' => now(), 'failed_at' => null,
            'message_id' => $sent?->getMessageId()]);

        return $sent;
    }

    /** The To refused for good: failed now, not retried (it cannot help); staff see why. */
    private function refused(int $id, Throwable $e): null
    {
        EmailLog::query()->whereKey($id)->update(['status' => EmailLog::STATUS_FAILED, 'failed_at' => now(),
            'error' => 'The mail server refused the recipient: ' . mb_substr($e->getMessage(), 0, 1000)]);
        report($e);

        return null;
    }

    /**
     * Back to pending with the error: the queue tries again (the last failure marks it failed, AppServiceProvider).
     * Nothing went out: sending_at is cleared, so a failed row with sending_at is one that may have gone out.
     */
    private function retryLater(int $id, Throwable $e): never
    {
        EmailLog::query()->whereKey($id)->update(['status' => EmailLog::STATUS_PENDING, 'sending_at' => null, 'error' => mb_substr($e->getMessage(), 0, 1000)]);

        throw $e;
    }

    /**
     * A row this send may take (reviews R3, R4, R6): pending, or failed without ever going out (sending_at null: not
     * one left mid-send, nor a refused To), and never one staff or a batch already resent.
     */
    public static function claimable($query)
    {
        return $query->where(fn ($query) => $query->where('status', EmailLog::STATUS_PENDING)
            ->orWhere(fn ($query) => $query->where('status', EmailLog::STATUS_FAILED)->whereNull('sending_at')))
            ->whereNotExists(fn ($query) => $query->from('email_logs as resends')->whereColumn('resends.resent_from_id', 'email_logs.id'));
    }

    /** A 5xx to RCPT TO: the one command Symfony checks against 250/251/252. */
    public static function refusedRecipient(Throwable $e): bool
    {
        return $e instanceof UnexpectedResponseException && $e->getCode() >= 500 && $e->getCode() < 600
            && str_contains($e->getMessage(), '"250/251/252"');
    }

    /** The <address> the server named, if it did. */
    public static function refusedAddress(Throwable $e): ?string
    {
        return preg_match('/<([^<>\s]+@[^<>\s]+)>/', $e->getMessage(), $match) ? $match[1] : null;
    }
}
