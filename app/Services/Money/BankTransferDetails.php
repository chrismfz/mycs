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
 * that is paid, cancelled, that bills a service that has ended (it buys nothing: the payment window refuses it too, review
 * BT-ADV-1), that a saved method pays on its own, in a currency the accounts do not take, nor when the method is not set
 * up or staff turned it off for invoices. One method record: the first.
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
        $accounts = self::accounts($invoice->currency_code, forInvoices: true);
        if ($accounts === null || $invoice->billsEndedService() || PaymentReminders::chargedOnItsOwn($invoice)) {
            return null;
        }

        return ['accounts' => $accounts, 'reference' => self::reference($invoice)];
    }

    /**
     * For a mail template: the accounts line by line (a blank line between two accounts kept), and the reference. Each line
     * is text, never Markdown: the mail is Markdown, so a line's «- », «# » or «[x](url)» would be a list, a heading or a
     * link (reviews of #287: a javascript: link, a tracking image).
     *
     * @return array{lines: list<string>, reference: string}|null
     */
    public static function forTemplate(Invoice $invoice): ?array
    {
        $details = self::for($invoice);

        return $details === null ? null : [
            'lines' => array_map(fn (string $line) => self::markdownText(trim($line)), preg_split('/\R/', $details['accounts']) ?: []),
            'reference' => self::markdownText($details['reference']),
        ];
    }

    /** What the customer writes in the transfer's reference: the number on the invoice (a proforma's is its id). */
    public static function reference(Invoice $invoice): string
    {
        return $invoice->isProforma() ? (string) $invoice->id : (string) ($invoice->number ?: $invoice->id);
    }

    /** Whether the accounts take this currency: any, when staff named none. */
    public static function takes(?string $currencies, ?string $currency): bool
    {
        $list = array_filter(array_map(fn (string $code) => strtoupper(trim($code)), explode(',', (string) $currencies)));

        return $list === [] || in_array(strtoupper((string) $currency), $list, true);
    }

    /** Markdown's punctuation escaped (HTML's is escaped by the template): the line reads as typed. */
    private static function markdownText(string $text): string
    {
        // A dot only where it would start a list («1. »): a web address keeps its own, and is a plain link at most.
        $text = (string) preg_replace('/([\\\\`*_{}\[\]()#+\-!|~])/', '\\\\$1', $text);

        return (string) preg_replace('/^(\d+)([.)])/', '$1\\\\$2', $text);
    }

    /** The accounts staff set for this currency, when the method is installed and enabled (and shown on invoices). */
    private static function accounts(?string $currency, bool $forInvoices): ?string
    {
        $gateway = Gateway::query()->where('extension', self::EXTENSION)->where('enabled', true)->orderBy('id')->with('settings')->first();
        if ($gateway === null) {
            return null;
        }
        try {
            $extension = ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings);
        } catch (Throwable) {
            return null;
        }
        if ($forInvoices && !filter_var($extension->config('show_on_invoices') ?? true, FILTER_VALIDATE_BOOL)) {
            return null;
        }
        if (!self::takes($extension->config('currencies'), $currency)) {
            return null;
        }
        $accounts = trim((string) $extension->config('accounts'));

        return $accounts === '' ? null : $accounts;
    }
}
