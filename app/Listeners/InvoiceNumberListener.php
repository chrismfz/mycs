<?php

namespace App\Listeners;

use App\Events\AdjustmentNote\Creating as AdjustmentNoteCreating;
use App\Events\Invoice\Created;
use App\Events\Invoice\Creating;
use App\Events\Invoice\Updating;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * An invoice's number (Δ2, a setting per company, `settings-map.md`):
 * - by its counter, `invoice_number_format` (e.g. `{year}-{number}`): on creation, a draft once it is issued;
 * - proforma (`invoice_proforma`, nixpal): **only when paid**, so that a proforma cancelled unpaid leaves no gap in the
 *   real invoices (it used to take one on draft → unpaid too); the year is the payment's;
 * - the number is its id (`invoice_number_is_id`, myip as WHMCS): written once it has an id, the counter untouched.
 * A number given is never taken back or given again; a cancelled or refunded invoice never takes one.
 */
class InvoiceNumberListener
{
    /**
     * Handle the event.
     */
    public function handle(Creating|Created|Updating|AdjustmentNoteCreating $event): void
    {
        if ($event instanceof AdjustmentNoteCreating) {
            $this->setNumber($event->adjustmentNote, 'credit_note');

            return;
        }
        $invoice = $event->invoice;
        if ($invoice->number) {
            return;
        }
        $isId = (bool) config('settings.invoice_number_is_id', false);
        $proforma = !$isId && (bool) config('settings.invoice_proforma', false);

        if ($event instanceof Creating) {
            if (!$isId && !$proforma && $invoice->status !== Invoice::STATUS_DRAFT) {
                $this->setNumber($invoice, 'invoice');
            }
        } elseif ($event instanceof Created) {
            if ($isId && $invoice->status !== Invoice::STATUS_DRAFT) {
                // Saved already: written straight after the insert, without another round of events. Should it not be, the
                // next status change gives it (below).
                Invoice::query()->whereKey($invoice->getKey())->whereNull('number')->update(['number' => (string) $invoice->getKey()]);
                $invoice->number = (string) $invoice->getKey();
                $invoice->syncOriginalAttribute('number');
            }
        } elseif ($invoice->isDirty('status')) {
            $numbered = $proforma ? [Invoice::STATUS_PAID] : [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID];
            if (in_array($invoice->status, $numbered, true)) {
                if ($isId) {
                    $invoice->number = (string) $invoice->getKey();
                } else {
                    $this->setNumber($invoice, 'invoice');
                }
            }
        }
    }

    private function setNumber(Invoice|AdjustmentNote $model, string $prefix): void
    {
        $formattedNumber = DB::transaction(function () use ($prefix) {
            $settingKey = $prefix . '_number';
            $setting = Setting::where('key', $settingKey)->whereNull('settingable_type')->lockForUpdate()->first();
            $number = (int) ($setting->value ?? 0);
            $number++;

            Setting::updateOrCreate([
                'key' => $settingKey,
                'settingable_type' => null,
            ], [
                'value' => $number,
            ]);

            return self::formatInvoiceNumber($number, $prefix);
        });

        $model->number = $formattedNumber;
    }

    public static function formatInvoiceNumber(int $number, string $prefix): string
    {
        // Pad the invoice number with leading zeros
        $paddedNumber = str_pad($number, config("settings.{$prefix}_number_padding", 1), '0', STR_PAD_LEFT);

        $formattedNumber = config("settings.{$prefix}_number_format", '{number}');
        $formattedNumber = str_replace('{number}', $paddedNumber, $formattedNumber);
        $formattedNumber = str_replace('{year}', now()->format('Y'), $formattedNumber);
        $formattedNumber = str_replace('{month}', now()->format('m'), $formattedNumber);
        $formattedNumber = str_replace('{day}', now()->format('d'), $formattedNumber);

        return $formattedNumber;
    }
}
