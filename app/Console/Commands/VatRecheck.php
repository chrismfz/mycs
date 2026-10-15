<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\Tax\EuVat;
use App\Services\Tax\VatNumberCheck;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Checks EU VAT numbers with VIES (PR G, docs/eu-vat.md): the pending ones (never answered), and those last checked more
 * than RECHECK_DAYS ago, so a reverse-charged customer's number is known valid at least once a month. A number VIES
 * cannot check now is tried again on the next run; its status stays.
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
        Contact::query()->whereNotNull('vat_number')->where('vat_number', '!=', '')
            ->where(fn (Builder $query) => $query->whereNull('vat_status')->orWhere('vat_status', EuVat::STATUS_PENDING)
                ->orWhereNull('vat_checked_at')->orWhere('vat_checked_at', '<', now()->subDays(self::RECHECK_DAYS)))
            ->orderByRaw('vat_checked_at is not null')->orderBy('vat_checked_at')->orderBy('id')
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
