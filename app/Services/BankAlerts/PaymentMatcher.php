<?php

namespace App\Services\BankAlerts;

use App\Admin\Resources\InvoiceResource;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\BankMovement;
use App\Models\Invoice;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The bank's credits matched to the invoices they pay (owner 2026-10-25, as our WHMCS addon PaymentsTrackingMatch did,
 * stricter): a credit that our mail server vouches for, about one of our accounts, still pending and recent, whose
 * reference names exactly one unpaid EUR invoice whose remaining is exactly its amount, is recorded on that invoice
 * through Bank transfer. Anything else stays for staff, with why. One credit is paid once, ever: its transaction id is
 * its own («bank-alert:<id>»), and it is matched under its row's lock.
 */
final class PaymentMatcher
{
    public const TRANSACTION_PREFIX = 'bank-alert:';

    /** @return array{matched: int, left: int} */
    public static function run(int $days = 7): array
    {
        $result = ['matched' => 0, 'left' => 0];
        $credits = BankMovement::query()->credits()->where('status', BankMovement::PENDING)->where('authentic', true)
            ->whereNull('invoice_id')->where('executed_at', '>=', now()->subDays(max(1, $days)))->orderBy('id')->pluck('id');
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

    /** One credit: matched and recorded, or left with its note. */
    public static function matchOne(int $id): bool
    {
        $matched = DB::transaction(function () use ($id) {
            $movement = BankMovement::query()->whereKey($id)->lockForUpdate()->first();
            if ($movement === null || $movement->status !== BankMovement::PENDING || !$movement->authentic || $movement->invoice_id !== null
                || $movement->direction !== 'credit') {
                return null;
            }
            $named = $movement->namedInvoices()->where('status', Invoice::STATUS_UNPAID)->where('currency_code', 'EUR')->limit(2)->pluck('id');
            if ($named->count() !== 1) {
                $movement->update(['match_note' => $named->isEmpty() ? 'No unpaid EUR invoice named in its reference' : 'Its reference names more than one unpaid invoice']);

                return null;
            }
            $invoice = Invoice::query()->whereKey($named->first())->lockForUpdate()->with(['items', 'transactions', 'adjustmentNotes'])->first();
            if ($invoice === null || $invoice->status !== Invoice::STATUS_UNPAID) {
                return null;
            }
            $remaining = number_format((float) $invoice->remaining, 2, '.', '');
            if (bccomp($remaining, (string) $movement->amount, 2) !== 0) {
                $movement->update(['match_note' => mb_substr("Invoice {$invoice->number}: {$remaining} left to pay, not {$movement->amount}", 0, 150)]);

                return null;
            }
            $transaction = ExtensionHelper::addPayment($invoice, 'BankTransfer', (string) $movement->amount, null, self::TRANSACTION_PREFIX . $movement->id, currency: 'EUR');
            if ($transaction === null || $transaction->status !== InvoiceTransactionStatus::Succeeded) {
                $movement->update(['match_note' => 'The payment was not recorded: staff']);

                return null;
            }
            $movement->update(['status' => BankMovement::MATCHED, 'invoice_id' => $invoice->id, 'matched_at' => now(), 'acknowledged_at' => now(), 'match_note' => null]);

            return [$movement, $invoice];
        });
        if ($matched === null) {
            return false;
        }
        [$movement, $invoice] = $matched;
        ChatNotifier::notify('payment_matched', fn () => '🏦 Payment auto matched: ' . number_format((float) $movement->amount, 2, ',', '.') . ' € ('
            . $movement->bankName() . ') → ' . ChatNotifier::link('#' . ($invoice->number ?: $invoice->id),
                InvoiceResource::getUrl('edit', ['record' => $invoice], panel: 'admin')));

        return true;
    }
}
