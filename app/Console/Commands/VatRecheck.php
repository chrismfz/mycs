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

    /** A valid number not confirmed for this long is news for staff. */
    public const UNVERIFIED_DAYS = 60;

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
                // One contact's failure does not stop the others (adversarial ADV-2b).
                try {
                    $result = $check->check($contact) ?? 'skipped';
                } catch (\Throwable $e) {
                    report($e);
                    $result = 'error';
                }
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            });
        // A number kept valid while VIES does not confirm it is reverse charged without proof: staff hear of it
        // (adversarial ADV-7), once a day.
        $unverified = Contact::query()->whereNotNull('primary_of_client_id')->whereNull('archived_at')
            ->where('vat_status', EuVat::STATUS_VALID)->where('vat_checked_at', '<', now()->subDays(self::UNVERIFIED_DAYS))->count();
        if ($unverified > 0) {
            VatNumberCheck::tellStaffOnce('vat-unverified', 'VAT numbers not confirmed by VIES', sprintf(
                '%d customer(s) are reverse charged with an EU VAT number VIES has not confirmed for more than %d days (VIES gives no answer). Check them in their contacts, or set their status by hand with a reason.',
                $unverified, self::UNVERIFIED_DAYS));
        }
        foreach ($counts as $result => $count) {
            $this->line("{$result}: {$count}");
        }

        return self::SUCCESS;
    }
}
