<?php

namespace App\Services\BankAlerts;

use App\Admin\Resources\InvoiceResource;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\BankMovement;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Setting;
use App\Services\Chat\ChatNotifier;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The bank's credits matched to the invoices they pay (owner 2026-10-25, as our WHMCS addon PaymentsTrackingMatch did,
 * stricter): a credit that our mail server vouches for, about one of our accounts, still pending and recent, whose
 * reference names exactly one invoice (of any status) and that one is unpaid, in EUR, with no payment on its way and
 * exactly that amount left to pay, is recorded on it through Bank transfer. Anything else stays for staff, with why.
 * - Each credit is tried once: once it is left for staff (its note written), it is theirs; it is never matched later,
 *   when they may have recorded it by hand elsewhere (adversarial of #458).
 * - One credit is paid once, ever: its transaction id is its own («bank-alert:<id>»), looked for before anything.
 * - Locks as every payment: the credit's row, then the customer, then the invoice (RecordPaymentService::lockInvoice),
 *   its balance read under them (reviews of #458: a deadlock against staff's payments, a stale balance).
 */
final class PaymentMatcher
{
    public const TRANSACTION_PREFIX = 'bank-alert:';

    /** Since when the matching has been on: only credits read after it are matched (adversarial round 2 of #458). */
    public const SINCE_KEY = 'bank_alerts.match_since';

    /** @return array{matched: int, left: int} */
    public static function run(int $days = 7): array
    {
        $result = ['matched' => 0, 'left' => 0];
        $since = self::since();
        $credits = BankMovement::query()->credits()->where('status', BankMovement::PENDING)->where('authentic', true)
            ->whereNull('invoice_id')->whereNull('match_note')->where('executed_at', '>=', now()->subDays(max(1, $days)))
            // After since, strictly: created_at has second precision, and the first run sets since after its read, in
            // the second that read's last credits were inserted. Those are from before the knob too (adversarial of #467).
            ->where('created_at', '>', $since)->orderBy('id')->pluck('id');
        foreach ($credits as $id) {
            try {
                self::matchOne((int) $id) ? $result['matched']++ : $result['left']++;
            } catch (Throwable $e) {
                report($e);
                $result['left']++;
            }
        }

        return $result;
    }

    /**
     * When the matching was turned on, set on its first run: what was read before (staff may have recorded it by hand
     * already) is never matched.
     */
    public static function since(): Carbon
    {
        $stored = Setting::query()->where('key', self::SINCE_KEY)->where('settingable_type', BankMovement::class)->value('value');
        if (is_string($stored) && $stored !== '') {
            return Carbon::parse($stored);
        }
        $now = now();
        Setting::query()->updateOrCreate(['key' => self::SINCE_KEY, 'settingable_type' => BankMovement::class, 'settingable_id' => null],
            ['value' => $now->toDateTimeString(), 'type' => 'string']);

        return $now;
    }

    /** The matching turned off: turned on again, it starts afresh from then. */
    public static function stop(): void
    {
        Setting::query()->where('key', self::SINCE_KEY)->where('settingable_type', BankMovement::class)->delete();
    }

    /** One credit: matched and recorded, or left for staff with its note. */
    public static function matchOne(int $id): bool
    {
        $matched = DB::transaction(function () use ($id) {
            $movement = BankMovement::query()->whereKey($id)->lockForUpdate()->first();
            if ($movement === null || $movement->status !== BankMovement::PENDING || !$movement->authentic || $movement->invoice_id !== null
                || $movement->match_note !== null || $movement->direction !== 'credit') {
                return null;
            }
            $leave = function (string $why) use ($movement) {
                $movement->update(['match_note' => mb_substr($why, 0, 150)]);

                return null;
            };
            $transactionId = self::TRANSACTION_PREFIX . $movement->id;
            if (InvoiceTransaction::query()->where('transaction_id', $transactionId)->exists()) {
                return $leave('Already recorded once: staff');
            }
            // Every invoice its reference names, whatever its status: a number paid already and another's id is no match.
            $named = $movement->namedInvoices()->limit(2)->pluck('id');
            if ($named->count() !== 1) {
                return $leave($named->isEmpty() ? 'No invoice named in its reference' : 'Its reference names more than one invoice');
            }
            $invoice = (new RecordPaymentService)->lockInvoice($named->first())->withLockedBalance();
            if ($invoice->status !== Invoice::STATUS_UNPAID) {
                return $leave("Invoice {$invoice->number} is {$invoice->status}");
            }
            if ($invoice->currency_code !== 'EUR') {
                return $leave("Invoice {$invoice->number} is in {$invoice->currency_code}");
            }
            if ($invoice->transactions->contains(fn (InvoiceTransaction $t) => $t->status === InvoiceTransactionStatus::Processing)) {
                return $leave("Invoice {$invoice->number} has a payment on its way");
            }
            $remaining = number_format((float) $invoice->remaining, 2, '.', '');
            if (bccomp($remaining, (string) $movement->amount, 2) !== 0) {
                return $leave("Invoice {$invoice->number}: {$remaining} left to pay, not {$movement->amount}");
            }
            $transaction = ExtensionHelper::addPayment($invoice, 'BankTransfer', (string) $movement->amount, null, $transactionId, currency: 'EUR');
            if ($transaction === null || $transaction->status !== InvoiceTransactionStatus::Succeeded || !$transaction->wasRecentlyCreated
                || (int) $transaction->invoice_id !== (int) $invoice->id) {
                return $leave('The payment was not recorded as expected: staff');
            }
            $movement->update(['status' => BankMovement::MATCHED, 'invoice_id' => $invoice->id, 'matched_at' => now(), 'acknowledged_at' => now(), 'match_note' => null]);

            return [$movement->id, $invoice->id];
        });
        if ($matched === null) {
            return false;
        }
        [$movementId, $invoiceId] = $matched;
        // Read after the commit: a proforma took its number when paid.
        ChatNotifier::notify('payment_matched', function () use ($movementId, $invoiceId) {
            $movement = BankMovement::query()->findOrFail($movementId);
            $invoice = Invoice::query()->findOrFail($invoiceId);

            return '🏦 Payment auto matched: ' . number_format((float) $movement->amount, 2, ',', '.') . ' € (' . $movement->bankName() . ') → '
                . ChatNotifier::link('#' . ($invoice->number ?: $invoice->id), InvoiceResource::getUrl('edit', ['record' => $invoice], panel: 'admin'));
        });

        return true;
    }
}
