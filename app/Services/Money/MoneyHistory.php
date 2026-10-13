<?php

namespace App\Services\Money;

use App\Classes\Price;
use App\Enums\MoneyEventKind;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;

/**
 * What the money trail shows (docs/money-trail.md §2, §3, MT-1d), read only: an invoice's movements for staff (the
 * journal and the status changes the audit kept) and a customer's credit history (for staff, and for the customer
 * without the internal fields: who, source, staff reason).
 */
class MoneyHistory
{
    /** The events that moved a customer's credit (each has the balance after it), newest first. */
    public static function creditEvents(int $userId): Builder
    {
        return MoneyEvent::query()->where('user_id', $userId)->whereNotNull('credit_balance_after')
            ->with('reverses')->orderByDesc('id');
    }

    /**
     * An invoice's movements for staff, oldest first: every journal event on it and every status change in its audit.
     *
     * @return array<string, array{at: ?Carbon, what: string, amount: ?string, credit: ?string, source: ?string, transaction: ?int, by: ?string, note: ?string}>
     */
    public function invoiceMovements(Invoice $invoice): array
    {
        $rows = [];
        $events = MoneyEvent::query()->where('invoice_id', $invoice->id)->with(['reverses', 'note', 'actor'])->orderBy('id')->get();
        foreach ($events as $event) {
            $rows['e' . $event->id] = [
                'at' => $event->occurred_at,
                'what' => self::staffLabel($event),
                'amount' => self::money($event->amount, $event->currency_code),
                'credit' => $event->credit_balance_after !== null ? self::money($event->credit_balance_after, $event->currency_code) : null,
                'source' => $event->source,
                'transaction' => $event->invoice_transaction_id,
                'by' => self::actorName($event->actor),
                'note' => self::staffNote($event),
            ];
        }

        $audits = Audit::query()->where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->whereIn('event', ['created', 'updated'])->orderBy('id')->get(); // few rows: each audit's user is read on its own
        foreach ($audits as $audit) {
            $new = (array) $audit->new_values;
            if (!array_key_exists('status', $new)) {
                continue;
            }
            $old = (array) $audit->old_values;
            $rows['a' . $audit->id] = [
                'at' => $audit->created_at,
                'what' => isset($old['status'])
                    ? __('Status: :from → :to', ['from' => $old['status'], 'to' => $new['status']])
                    : __('Status: :to', ['to' => $new['status']]),
                'amount' => null,
                'credit' => null,
                'source' => null,
                'transaction' => null,
                'by' => self::actorName($audit->user),
                'note' => null,
            ];
        }

        uasort($rows, fn (array $a, array $b) => ($a['at']?->getTimestamp() ?? 0) <=> ($b['at']?->getTimestamp() ?? 0));

        return $rows;
    }

    /** What an event did, for staff: the kind and its cause. */
    public static function staffLabel(MoneyEvent $event): string
    {
        $kind = match ($event->kind) {
            MoneyEventKind::Payment => __('Payment'),
            MoneyEventKind::CreditIn => __('Credit in'),
            MoneyEventKind::CreditOut => __('Credit out'),
            MoneyEventKind::Refund => __('Refund'),
            MoneyEventKind::Reversal => __('Reversal of #:id', ['id' => $event->reverses_id]),
        };

        return $event->cause !== null ? $kind . ' (' . $event->cause . ')' : $kind;
    }

    /** What an event did to the customer's credit, in words for the customer (lang/*\/account.php). */
    public static function customerLabel(MoneyEvent $event): string
    {
        $key = match ($event->cause) {
            MoneyTrail::CAUSE_OPENING_BALANCE => 'opening_balance',
            MoneyTrail::CAUSE_OVERPAYMENT => 'overpayment',
            MoneyTrail::CAUSE_CREDIT_PAYMENT => 'credit_payment',
            MoneyTrail::CAUSE_PURCHASE => 'purchase',
            MoneyTrail::CAUSE_PURCHASE_REFUNDED => 'purchase_refunded',
            MoneyTrail::CAUSE_TRANSACTION_DELETED => 'correction',
            MoneyTrail::CAUSE_STAFF_ADJUSTMENT => 'adjustment',
            MoneyTrail::CAUSE_DOWNGRADE => 'downgrade',
            MoneyTrail::CAUSE_AFFILIATE_REWARD => 'affiliate_reward',
            default => $event->kind === MoneyEventKind::Reversal ? 'correction'
                : ($event->creditEffectCents() >= 0 ? 'credit_added' : 'credit_used'),
        };

        return __('account.credit_history_causes.' . $key);
    }

    /** The change to the credit, signed (+12.00 / −5.00), with the currency. */
    public static function signedCredit(MoneyEvent $event): string
    {
        $cents = $event->creditEffectCents();

        return ($cents < 0 ? '−' : '+') . self::money(abs($cents) / 100, $event->currency_code);
    }

    /** An amount in the currency's own format (prefix, suffix, separators). */
    public static function money(float|string|null $amount, string $currency): string
    {
        $model = Currency::query()->find($currency);
        if ($model === null) {
            return number_format((float) $amount, 2, '.', '') . ' ' . $currency;
        }

        return (new Price(['price' => 0, 'currency' => $model], dontShowUnavailablePrice: true))->format((float) $amount);
    }

    /** Staff only: the reason given with the event, and the note kept apart from the journal (§9). */
    public static function staffNote(MoneyEvent $event): ?string
    {
        $parts = array_filter([$event->reason, $event->note?->note], fn (?string $part) => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /** Who did it: a user by name and id, anything else by type and id, nobody as «System». */
    public static function actorName(mixed $actor): string
    {
        if ($actor instanceof User) {
            return trim($actor->first_name . ' ' . $actor->last_name) . ' (#' . $actor->id . ')';
        }

        return $actor === null ? __('System') : class_basename($actor) . ' #' . $actor->getKey();
    }
}
