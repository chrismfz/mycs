<?php

namespace App\Events\Invoice;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An invoice staff had marked paid by hand (no payment for all of it, `paid_by_staff_at`) is now covered by real money.
 * What pays money out on a paid invoice (the affiliates' reward) waited for this: Paid itself fired when staff marked it,
 * and is not fired again (its mails went then).
 */
class PaidByStaffCovered
{
    use Dispatchable, SerializesModels;

    public function __construct(public Invoice $invoice) {}
}
