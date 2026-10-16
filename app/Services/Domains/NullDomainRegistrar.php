<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Models\Domain;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\TldPricing;

/**
 * The 'manual' registrar (Πυλώνας A) — a first-class API-less adapter, not just
 * a fallback: domains handled entirely by hand (registered elsewhere, tracked
 * here) attach to a 'manual' connection and every API-shaped operation refuses
 * loudly. Also what DomainRegistrarRegistry returns for unknown/empty keys, so
 * a misconfigured connection can never fake a registrar success (the
 * NullProviderTransport discipline). docs/domains/README.md §4.2.
 */
class NullDomainRegistrar implements DomainRegistrar
{
    public function key(): string
    {
        return 'manual';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return DomainRegistrarCapabilities::none();
    }

    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        return false;
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — έλεγχος διαθεσιμότητας δεν υποστηρίζεται σε αυτή τη σύνδεση.'
        );
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — το domain συντηρείται χειροκίνητα.'
        );
    }

    public function getTldPricing(string $tld, DomainRegistrarCredentials $credentials): TldPricing
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — οι τιμές κόστους καταχωρούνται χειροκίνητα στα «TLDs & τιμές».'
        );
    }

    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — τα domains καταχωρούνται χειροκίνητα (ή με import από CSV).'
        );
    }

    public function getContact(string $handle, DomainRegistrarCredentials $credentials): ?RegistrarContact
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — οι επαφές καταχωρούνται χειροκίνητα.'
        );
    }

    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — η ανανέωση γίνεται χειροκίνητα στο portal του registrar (ενημερώστε μετά τη λήξη στο domain).'
        );
    }

    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — η καταχώρηση γίνεται χειροκίνητα στο portal του registrar (ενημερώστε μετά λήξη/στοιχεία στο domain).'
        );
    }

    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — η μεταφορά γίνεται χειροκίνητα στο portal του registrar (ενημερώστε μετά το domain).'
        );
    }

    public function getEppCode(Domain $domain, DomainRegistrarCredentials $credentials): ?string
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — πάρτε τον κωδικό EPP από το portal του registrar.'
        );
    }

    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — αλλάξτε NS/κλείδωμα/privacy/επαφές στο portal του registrar (και ενημερώστε το domain εδώ).'
        );
    }

    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainRegistrarNotConfigured(
            'Ο registrar «manual» δεν έχει API — η επαναφορά από redemption γίνεται στο portal του registrar.'
        );
    }
}
