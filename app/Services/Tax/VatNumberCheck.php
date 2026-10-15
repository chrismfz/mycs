<?php

namespace App\Services\Tax;

use App\Helpers\NotificationHelper;
use App\Models\Contact;
use App\Models\Staff;
use App\Models\VatCheck;
use Illuminate\Support\Facades\DB;
use MyCS\Vies\Vies;
use MyCS\Vies\ViesInvalidFormat;
use MyCS\Vies\ViesUnavailable;

/**
 * Checks a contact's EU VAT number with VIES and keeps what it said (PR G, docs/eu-vat.md). Fail closed:
 * - valid or invalid is the new status, with a check row (the consultation number is the proof);
 * - no answer changes nothing: a pending number stays pending (VAT charged), a valid one stays valid until VIES says
 *   otherwise (a VIES outage must not start charging VAT to a known business, nor stop it);
 * - a number that turns invalid is news for staff.
 * The number may change while VIES answers: the answer is kept only for the number it was asked about.
 */
final class VatNumberCheck
{
    public function __construct(private readonly Vies $vies) {}

    /** @return string|null the check's result (VatCheck::RESULT_*), or null when there is no number to check */
    public function check(Contact $contact): ?string
    {
        $number = EuVat::normalized($contact->vat_number, $contact->country);
        if ($number === null) {
            return null;
        }
        $answer = ['name' => null, 'address' => null, 'request_identifier' => null, 'error' => null];
        try {
            $result = $this->vies->check($number, EuVat::iso($contact->country));
            $outcome = $result->valid ? VatCheck::RESULT_VALID : VatCheck::RESULT_INVALID;
            $answer = ['name' => $result->name, 'address' => $result->address, 'request_identifier' => $result->requestIdentifier,
                'error' => null];
        } catch (ViesInvalidFormat $e) {
            $outcome = VatCheck::RESULT_INVALID;
            $answer['error'] = mb_substr($e->getMessage(), 0, 250);
        } catch (ViesUnavailable $e) {
            $outcome = VatCheck::RESULT_UNAVAILABLE;
            $answer['error'] = mb_substr($e->getMessage(), 0, 250);
        }

        return $this->record($contact, $number, $outcome, $answer);
    }

    /** Staff set the status themselves (VIES wrong or down for long), with a reason; it stays until the next check. */
    public function set(Contact $contact, bool $valid, string $reason, ?Staff $staff): string
    {
        $number = EuVat::normalized($contact->vat_number, $contact->country);
        if ($number === null) {
            throw new \InvalidArgumentException('The contact has no VAT number.');
        }

        return $this->record($contact, $number, $valid ? VatCheck::RESULT_VALID : VatCheck::RESULT_INVALID,
            ['reason' => $reason, 'staff_id' => $staff?->id], VatCheck::SOURCE_STAFF);
    }

    /** @param array<string, mixed> $answer */
    private function record(Contact $contact, string $number, string $outcome, array $answer, string $source = VatCheck::SOURCE_VIES): string
    {
        $turnedInvalid = DB::transaction(function () use ($contact, $number, $outcome, $answer, $source) {
            $locked = Contact::query()->whereKey($contact->id)->lockForUpdate()->first();
            VatCheck::query()->create(['contact_id' => $contact->id, 'vat_number' => $number, 'result' => $outcome, 'source' => $source,
                'checked_at' => now()] + $answer);
            // Changed meanwhile, or no answer: the status stays.
            if ($locked === null || EuVat::normalized($locked->vat_number, $locked->country) !== $number || $outcome === VatCheck::RESULT_UNAVAILABLE) {
                return false;
            }
            $was = $locked->vat_status;
            $locked->forceFill(['vat_status' => $outcome, 'vat_checked_number' => $number, 'vat_checked_at' => now()])->save();
            $contact->setRawAttributes($locked->getAttributes(), true);

            return $was === EuVat::STATUS_VALID && $outcome === VatCheck::RESULT_INVALID && $source === VatCheck::SOURCE_VIES;
        });
        if ($turnedInvalid) {
            NotificationHelper::sendSystemEmailNotification('VAT number no longer valid', e(sprintf(
                'VIES no longer finds the VAT number %s of customer #%d valid. Its invoices are charged VAT from now on.',
                $number, $contact->client_id)));
        }

        return $outcome;
    }
}
