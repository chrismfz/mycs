<?php

namespace App\Services\Money;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Services\Invoice\PaymentReminders;
use Throwable;

/**
 * The bank transfer method's accounts, for an unpaid invoice's PDF and mail (owner 2026-10-21): with the reference the
 * customer writes in the transfer, the invoice's number (a proforma's id), so staff find what it pays. Not on an invoice
 * that is paid, cancelled, or that a saved method pays on its own, nor when the method is not set up or staff turned it
 * off for invoices.
 */
final class BankTransferDetails
{
    public const EXTENSION = 'BankTransfer';

    /** @return array{accounts: string, reference: string}|null */
    public static function for(Invoice $invoice): ?array
    {
        if ($invoice->status !== Invoice::STATUS_UNPAID || (float) $invoice->remaining <= 0) {
            return null;
        }
        $accounts = self::accounts();
        if ($accounts === null || PaymentReminders::chargedOnItsOwn($invoice)) {
            return null;
        }

        return ['accounts' => $accounts, 'reference' => self::reference($invoice)];
    }

    /**
     * For a mail template: the accounts line by line (a blank line between two accounts kept), and the reference.
     *
     * @return array{lines: list<string>, reference: string}|null
     */
    public static function forTemplate(Invoice $invoice): ?array
    {
        $details = self::for($invoice);

        return $details === null ? null : [
            'lines' => array_map('rtrim', preg_split('/\R/', $details['accounts']) ?: []),
            'reference' => $details['reference'],
        ];
    }

    /** What the customer writes in the transfer's reference: the number on the invoice (a proforma's is its id). */
    public static function reference(Invoice $invoice): string
    {
        return $invoice->isProforma() ? (string) $invoice->id : (string) ($invoice->number ?: $invoice->id);
    }

    /** The accounts staff set, when the method is installed and shown on invoices. */
    private static function accounts(): ?string
    {
        $gateway = Gateway::query()->where('extension', self::EXTENSION)->with('settings')->first();
        if ($gateway === null) {
            return null;
        }
        try {
            $extension = ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings);
        } catch (Throwable) {
            return null;
        }
        if (!filter_var($extension->config('show_on_invoices') ?? true, FILTER_VALIDATE_BOOL)) {
            return null;
        }
        $accounts = trim((string) $extension->config('accounts'));

        return $accounts === '' ? null : $accounts;
    }
}
