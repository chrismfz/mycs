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
use Illuminate\Support\Facades\Log;
use Throwable;

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
            // A gateway refund's note is numbered after its commit (numberAfterCommit): nothing after the money left
            // waits for the counter.
            if (!$event->adjustmentNote->number && !$event->adjustmentNote->numberAfterCommit) {
                $this->setNumber($event->adjustmentNote, 'credit_note');
            }

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
        $model->number = DB::transaction(fn () => self::takeNumber(self::lockCounter($prefix), $prefix, $model::class, $date));
    }

    /**
     * A gateway refund's credit note (ExtensionHelper::refund): numbered once the refund is committed, in a short
     * transaction of its own. The counter row is shared by every credit note, so it is neither locked over the gateway
     * call (every other credit note would wait for that HTTP call, REV2-FCONC-1) nor waited for after it, inside the
     * refund's transaction (a lock timeout would roll back a refund the gateway made, F-CONCURRENCY-3). If this fails the
     * refund stays recorded and the note unnumbered: logged for staff. Written by its key: a note is never edited
     * through the model (AdjustmentNoteObserver::updating), and its number was never given.
     */
    public static function numberAfterCommit(AdjustmentNote $note): void
    {
        $id = $note->getKey();
        DB::afterCommit(function () use ($id) {
            try {
                DB::transaction(function () use ($id) {
                    $counter = self::lockCounter('credit_note');
                    if (!AdjustmentNote::query()->whereKey($id)->whereNull('number')->lockForUpdate()->exists()) {
                        return;
                    }
                    AdjustmentNote::query()->whereKey($id)->whereNull('number')
                        ->update(['number' => self::takeNumber($counter, 'credit_note', AdjustmentNote::class)]);
                });
            } catch (Throwable $e) {
                Log::critical('A refund\'s credit note was recorded without its number: number it by hand.', [
                    'adjustment_note_id' => $id, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /** The counter's row ($prefix_number), locked for the rest of the caller's transaction. */
    public static function lockCounter(string $prefix): Setting
    {
        $settingKey = $prefix . '_number';
        // The row first, so that the lock holds from the very first number (no row: nothing to lock, two first
        // numbers alike; adversarial review of #238).
        Setting::query()->insertOrIgnore(['key' => $settingKey, 'settingable_type' => null, 'value' => '0', 'type' => 'string',
            'created_at' => now(), 'updated_at' => now()]);

        return Setting::where('key', $settingKey)->whereNull('settingable_type')->lockForUpdate()->firstOrFail();
    }

    /**
     * The next number from a counter locked by lockCounter() in this transaction. Nothing is taken until it is called,
     * so a counter locked and then not used leaves no gap.
     *
     * A number for another period than today's (a proforma paid by a transfer of 31/12, recorded in January) whose
     * sequence the counter no longer follows (set back to 0 for the new {year}, as Settings allows) takes the next number
     * of that period, and the counter, today's sequence, is left as it is: no gap in the new year, never a number taken
     * (sweep 2, F-TAX-DOCUMENTS-1). A counter that runs on across the years gives its next number, as before.
     *
     * @param  class-string<Invoice|AdjustmentNote>  $model
     */
    public static function takeNumber(Setting $counter, string $prefix, string $model, ?CarbonInterface $date = null): string
    {
        $m = new $model;
        $current = (int) ($counter->value ?? 0);
        $number = $current + 1;
        $formatted = self::formatInvoiceNumber($number, $prefix, $date);
        $otherPeriod = $date !== null && self::formatInvoiceNumber(1, $prefix, $date) !== self::formatInvoiceNumber(1, $prefix);
        // The counter set back for today's period: its first number given already (2027-1), or the counter's
        // candidate in $date's period taken. Then $date's period goes on by its own sequence, whether or not the
        // new period has passed the old one's total (review REV2-F-TAX-DOCUMENTS-1).
        $restarted = $otherPeriod && self::taken($m, self::formatInvoiceNumber(1, $prefix));
        if (!$restarted && !self::taken($m, $formatted)) {
            self::store($counter, $number);

            return $formatted;
        }

        if ($otherPeriod) {
            $periodMax = self::highestOfPeriod($m, $prefix, $date);
            $own = $restarted || $periodMax > $current;
            $number = ($own ? $periodMax : max($periodMax, $current)) + 1;
            while (self::taken($m, $formatted = self::formatInvoiceNumber($number, $prefix, $date))) {
                $number++;
            }
            // Its period's own sequence, apart from the counter's (the counter was set back): the counter stays.
            if (!$own) {
                self::store($counter, $number);
            }

            return $formatted;
        }

        // Taken already (the number = id of another mode, a counter set back by hand, an import): the next one, so a
        // payment never fails on the unique key after its gateway charged (adversarial ADV-4 of #238). Past many
        // taken in a row, one past the highest of the period.
        for ($tries = 0; $tries < 1000 && self::taken($m, $formatted); $tries++) {
            $formatted = self::formatInvoiceNumber(++$number, $prefix, $date);
        }
        if (self::taken($m, $formatted)) {
            $number = max($number, self::highestOfPeriod($m, $prefix, $date)) + 1;
            while (self::taken($m, $formatted = self::formatInvoiceNumber($number, $prefix, $date))) {
                $number++;
            }
        }
        self::store($counter, $number);

        return $formatted;
    }

    /**
     * The counter's new value, by its key, without the model's events: Setting\Saved reads the row again by its name,
     * after a gateway refund too (F-CONCURRENCY-3). What that event did for a counter is the config value (no override,
     * no cache flush: FlushCacheListener), kept here.
     */
    private static function store(Setting $counter, int $number): void
    {
        $counter->value = (string) $number;
        Setting::query()->whereKey($counter->getKey())->update(['value' => $counter->value, 'updated_at' => now()]);
        $counter->syncOriginalAttribute('value');
        // As Setting\Saved does: config('settings') may be a collection (SettingsProvider), which a dotted key would replace.
        $settings = config('settings', []);
        $settings[$counter->key] = $counter->value;
        config(['settings' => $settings]);
    }

    private static function taken(Invoice|AdjustmentNote $model, string $formatted): bool
    {
        return $model::query()->where('number', $formatted)->exists();
    }

    /** The highest {number} given in $date's period (its year, month or day as the format has them), 0 when none. */
    private static function highestOfPeriod(Invoice|AdjustmentNote $model, string $prefix, ?CarbonInterface $date): int
    {
        $marker = "\u{0}";
        $pattern = str_replace('{number}', $marker, (string) config("settings.{$prefix}_number_format", '{number}'));
        $date ??= now();
        $pattern = str_replace(['{year}', '{month}', '{day}'], [$date->format('Y'), $date->format('m'), $date->format('d')], $pattern);
        $parts = explode($marker, $pattern);
        if (count($parts) !== 2) {
            return 0;
        }
        [$before, $after] = $parts;
        $regex = '/^' . preg_quote($before, '/') . '(\d+)' . preg_quote($after, '/') . '$/';
        $highest = 0;
        // LIKE on what comes before the number narrows it (its wildcards only widen it); the pattern decides.
        $model::query()->where('number', 'like', $before . '%')->pluck('number')->each(function ($number) use ($regex, &$highest) {
            if (preg_match($regex, (string) $number, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        });

        return $highest;
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
