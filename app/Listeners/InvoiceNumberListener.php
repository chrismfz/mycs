<?php

namespace App\Listeners;

use App\Enums\InvoiceTransactionStatus;
use App\Events\AdjustmentNote\Creating as AdjustmentNoteCreating;
use App\Events\Invoice\Created;
use App\Events\Invoice\Creating;
use App\Events\Invoice\Updating;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

        // Issued, i.e. unpaid: a draft (or no status yet, the column's default), a cancelled one never (adversarial ADV-2/3
        // of #238).
        if ($event instanceof Creating) {
            if (!$isId && !$proforma && $invoice->status === Invoice::STATUS_UNPAID) {
                $this->setNumber($invoice, 'invoice');
            }
        } elseif ($event instanceof Created) {
            if ($isId && $invoice->status === Invoice::STATUS_UNPAID) {
                // Saved already: written straight after the insert, without another round of events.
                $this->setId($invoice, save: true);
            }
        } elseif ($invoice->isDirty('status')) {
            $numbered = $proforma ? [Invoice::STATUS_PAID] : [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID];
            if (in_array($invoice->status, $numbered, true)) {
                if ($isId) {
                    $this->setId($invoice, save: false);
                } else {
                    // A proforma's year is its payment's: the latest payment's date (a transfer of 31/12 recorded on 02/01
                    // is 31/12's, adversarial ADV-1 of #238), else today.
                    $paid = $invoice->status === Invoice::STATUS_PAID ? $invoice->transactions()
                        ->where('status', InvoiceTransactionStatus::Succeeded)->max('created_at') : null;
                    $this->setNumber($invoice, 'invoice', $paid !== null ? Carbon::parse($paid) : null);
                }
            }
        }
    }

    /** The next number of its counter, under the counter's row lock; one already taken is passed over, never given twice. */
    private function setNumber(Invoice|AdjustmentNote $model, string $prefix, ?CarbonInterface $date = null): void
    {
        $formattedNumber = DB::transaction(function () use ($model, $prefix, $date) {
            $settingKey = $prefix . '_number';
            // The row first, so that the lock holds from the very first number (no row: nothing to lock, two first
            // numbers alike; adversarial review of #238).
            Setting::query()->insertOrIgnore(['key' => $settingKey, 'settingable_type' => null, 'value' => '0', 'type' => 'string',
                'created_at' => now(), 'updated_at' => now()]);
            $setting = Setting::where('key', $settingKey)->whereNull('settingable_type')->lockForUpdate()->first();
            $number = (int) ($setting->value ?? 0);
            // Taken already (the number = id of another mode, a counter set back by hand, an import): the next one, so a
            // payment never fails on the unique key after its gateway charged (adversarial ADV-4 of #238).
            for ($tries = 0; $tries < 1000; $tries++) {
                $formatted = self::formatInvoiceNumber(++$number, $prefix, $date);
                if (!$model::query()->where('number', $formatted)->exists()) {
                    break;
                }
            }
            $setting->update(['value' => $number]);

            return $formatted;
        });

        $model->number = $formattedNumber;
    }

    /**
     * Its id as its number (number = id). An id already taken as a number (by a counter, before the mode changed) cannot
     * be: it stays unnumbered and staff are told, instead of the invoice failing on the unique key (adversarial ADV-5).
     */
    private function setId(Invoice $invoice, bool $save): void
    {
        $id = (string) $invoice->getKey();
        if (Invoice::query()->where('number', $id)->whereKeyNot($invoice->getKey())->exists()) {
            report(new \RuntimeException("Invoice #{$id}: its id is already another invoice's number; left without a number (invoice_number_is_id)."));

            return;
        }
        if ($save) {
            Invoice::query()->whereKey($invoice->getKey())->whereNull('number')->update(['number' => $id]);
            $invoice->number = $id;
            $invoice->syncOriginalAttribute('number');
        } else {
            $invoice->number = $id;
        }
    }

    /** $date: the day the number is for (a proforma's payment), today when none. */
    public static function formatInvoiceNumber(int $number, string $prefix, ?CarbonInterface $date = null): string
    {
        $date ??= now();
        // Pad the invoice number with leading zeros
        $paddedNumber = str_pad((string) $number, (int) config("settings.{$prefix}_number_padding", 1), '0', STR_PAD_LEFT);

        $formattedNumber = config("settings.{$prefix}_number_format", '{number}');
        $formattedNumber = str_replace('{number}', $paddedNumber, $formattedNumber);
        $formattedNumber = str_replace('{year}', $date->format('Y'), $formattedNumber);
        $formattedNumber = str_replace('{month}', $date->format('m'), $formattedNumber);
        $formattedNumber = str_replace('{day}', $date->format('d'), $formattedNumber);

        return $formattedNumber;
    }
}
