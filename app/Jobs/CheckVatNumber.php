<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Tax\VatNumberCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks a contact's new EU VAT number with VIES (PR G). Once: while VIES gives no answer the number stays pending (VAT
 * charged) and the hourly vat:recheck tries again.
 */
class CheckVatNumber implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 60;

    public function __construct(public int $contactId) {}

    public function handle(VatNumberCheck $check): void
    {
        $contact = Contact::query()->find($this->contactId);
        if ($contact !== null) {
            $check->check($contact);
        }
    }
}
