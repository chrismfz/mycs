<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\VatCheck;
use App\Services\Tax\EuVat;
use App\Services\Tax\VatNumberCheck;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Checks the primary contacts' EU VAT numbers with VIES (PR G, docs/eu-vat.md): the pending ones (never answered), and
 * those last checked more than RECHECK_DAYS ago, so a reverse-charged customer's number is known valid at least once a
 * month. A number VIES cannot check now is tried again on a later run; its status stays.
 */
class VatRecheck extends Command
{
    public const RECHECK_DAYS = 30;

    /** Per run: VIES throttles. */
    public const BATCH = 100;

    protected $signature = 'vat:recheck';

    protected $description = 'Checks pending EU VAT numbers, and the others once a month, with VIES';

    public function handle(VatNumberCheck $check): int
    {
        if (!EuVat::enabled()) {
            return self::SUCCESS;
        }
        $counts = [];
        // Only the numbers that bill: primary contacts (review CR-2/CR-3). Least recently tried first, by the last check
        // of any result: a number VIES keeps not answering goes to the back, so it cannot hold back the others.
        Contact::query()->whereNotNull('primary_of_client_id')->whereNull('archived_at')
            ->whereNotNull('vat_number')->where('vat_number', '!=', '')
            ->where(fn (Builder $query) => $query->whereNull('vat_status')->orWhere('vat_status', EuVat::STATUS_PENDING)
                ->orWhereNull('vat_checked_at')->orWhere('vat_checked_at', '<', now()->subDays(self::RECHECK_DAYS)))
            ->addSelect(['last_tried_at' => VatCheck::query()->selectRaw('max(checked_at)')->whereColumn('contact_id', 'contacts.id')])
            ->orderByRaw('last_tried_at is not null')->orderBy('last_tried_at')->orderBy('id')
            ->limit(self::BATCH)->get()
            ->each(function (Contact $contact) use ($check, &$counts) {
                $result = $check->check($contact) ?? 'skipped';
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            });
        foreach ($counts as $result => $count) {
            $this->line("{$result}: {$count}");
        }

        return self::SUCCESS;
    }
}
