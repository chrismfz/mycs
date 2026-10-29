<?php

namespace App\Livewire\Domains;

use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DnssecChangedSinceRead;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainRenewalYears;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Payment\ItemPaymentMethod;
use App\Support\Actor;
use App\Support\CurrentClient;
use App\Support\Domains\DnssecOptions;
use App\Support\Domains\DnssecRecord;
use App\Support\Domains\RegistryStatuses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * A customer's domain (D7-2, docs/domains.md §4): its dates, status and nameservers; with `managedomains` (DomainPolicy
 * update) its auto renew (off = do not renew), nameservers, transfer lock, ID protection and EPP code at its registrar,
 * and with `invoices` too «Renew» (its next period's invoice, D7a). The registrar calls are the staff's own
 * (DomainManagementService: one at a time per domain, logged with who asked), at most 10 a minute per login.
 *
 * Staff in «Login as client» act as themselves (review R1/R2 of #176): logged as staff, and only with the admin
 * permission the same change needs there (admin.domains.manage, .epp; admin.invoices.create to bill).
 */
class Show extends Component
{
    public Domain $domain;

    /** @var list<array{host: string}> */
    public array $nameservers = [];

    public bool $editNameservers = false;

    /** @var array<string, mixed> role => one of the account's contacts (P1 of the portal): as the browser sent it, checked on save */
    public array $contactRoles = [];

    public bool $editContacts = false;

    /** @var list<array<string, mixed>> DNSSEC records being edited (P3), as the browser sent them: checked on save */
    public array $dnssecRows = [];

    /** What the form started from (P3, as G8-4b): the registrar having another set now refuses the change. Set here only. */
    #[Locked]
    public string $dnssecSeen = '';

    public bool $editDnssec = false;

    public function mount(): void
    {
        $this->nameservers = $this->domain->nameservers->map(fn ($ns) => ['host' => $ns->host])->values()->all();
        $this->contactRoles = collect(Domain::ROLES)->mapWithKeys(fn (string $role) => [$role => $this->domain->{"{$role}_contact_id"}])->all();
    }

    /** The account's contacts a role may take (active ones; the one a role has now too, archived or not). */
    private function contactOptions(): array
    {
        $now = array_filter(array_map(fn (string $role) => $this->domain->{"{$role}_contact_id"}, Domain::ROLES));
        // Their emails only to who may see the account's contacts (review 2 of #219: names are enough to choose).
        $emails = $this->seesContacts();

        return Contact::query()->where('client_id', $this->domain->client_id)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $now))
            ->orderBy('first_name')->orderBy('last_name')->get()
            // A contact known by its tax id only is named by it (Contact::label(), review of #485), never blank.
            ->mapWithKeys(fn (Contact $c) => [$c->id => ($name = trim(trim($c->first_name . ' ' . $c->last_name) . ($c->company_name ? ' · ' . $c->company_name : '')) ?: $c->label())
                . ($emails && $c->email && $name !== $c->email ? ' <' . $c->email . '>' : '')])
            ->all();
    }

    /** May the login see the account's contacts (where the help's link leads)? Staff in «Login as client» may. */
    private function seesContacts(): bool
    {
        return Actor::impersonating() || CurrentClient::allows('contacts');
    }

    /** A .gr / .ελ name (its fqdn is kept in punycode). */
    private function atGrRegistry(): bool
    {
        return preg_match('/\.(gr|xn--qxam)\z/', mb_strtolower($this->domain->fqdn)) === 1;
    }

    /**
     * Whether its holder may be changed here (P1): only where the registrar says the owner may simply be modified
     * (Openprovider's modify_owner_allowed); never a .gr name (its holder's name change or transfer is a charged act
     * of the registry, G8-5: asked from us).
     */
    public function registrantChangeable(): bool
    {
        return !$this->atGrRegistry() && ($this->domain->meta['registrar']['modify_owner_allowed'] ?? null) === true;
    }

    /**
     * Its contacts by role, from the account's contacts (P1 of the portal): sent to the registrar as staff's «Change
     * contacts» does (DomainManagementService::setContacts: every role linked, the holder only where it may change). What
     * only staff can sort out (a role not linked to the registrar's, the registry's own refusals) is said in the customer's
     * words (review 1 of #219); the choices stay as typed when it is not done (review 3).
     */
    public function saveContacts(): void
    {
        $this->authorize('update', $this->domain);
        $options = $this->contactOptions();
        $wanted = [];
        foreach (Domain::ROLES as $role) {
            $id = $this->contactRoles[$role] ?? null;
            if ($id !== null && !is_scalar($id)) { // a tampered value (an array): refused, never read as none
                $this->addError("contactRoles.{$role}", __('domains.manage.contact_not_the_accounts'));

                return;
            }
            $id = $id !== '' && $id !== null ? (int) $id : null;
            if ($id === $this->domain->{"{$role}_contact_id"}) {
                continue; // unchanged: never checked again (review 5: one moved from another account blocks nothing)
            }
            if ($id !== null && !array_key_exists($id, $options)) {
                $this->addError("contactRoles.{$role}", __('domains.manage.contact_not_the_accounts'));

                return;
            }
            // An archived contact only where it is already (ADV-F5 of #219), never spread to another role.
            if ($id !== null && Contact::query()->whereKey($id)->whereNotNull('archived_at')->exists()) {
                $this->addError("contactRoles.{$role}", __('domains.manage.contact_not_the_accounts'));

                return;
            }
            if ($role === 'registrant' && !$this->registrantChangeable()) {
                $this->addError('contactRoles.registrant', __($this->atGrRegistry() ? 'domains.portal.registrant_locked' : 'domains.portal.registrant_locked_here'));

                return;
            }
            // A role is never emptied here (ADV-F4 of #219: the registrar keeps the one it has, so «none» would be a
            // change said and not made; the .gr registry keeps one on each role anyway): another contact instead.
            if ($id === null) {
                $this->addError("contactRoles.{$role}", __('domains.portal.contact_required'));

                return;
            }
            $wanted[$role] = $id;
        }
        if ($wanted === []) {
            $this->editContacts = false;

            return;
        }
        if (!$this->mayCallRegistrar()) {
            return;
        }
        // Linked first, as setContacts asks (staff's «Link here»): ours to do, said so before anything is sent.
        $management = $this->management();
        foreach (Domain::ROLES as $role) {
            if (!array_key_exists($role, $wanted) && $this->domain->{"{$role}_contact_id"} !== null && $management->isUnlinked($this->domain, $role)) {
                $this->notify(__('domains.portal.contacts_need_us'), 'error');

                return;
            }
        }
        if ($this->domain->registrant_contact_id !== null && !array_key_exists('registrant', $wanted) && trim((string) $this->domain->registrant_handle) === '') {
            $this->notify(__('domains.portal.contacts_need_us'), 'error');

            return;
        }
        $saved = $this->atRegistrar(function () use ($management, $wanted) {
            try {
                $management->setContacts($this->domain, $wanted, $this->actor());
            } catch (InvalidArgumentException $e) {
                report($e); // staff's words (or the registry's, in English): the customer's instead
                throw new DisplayException(__('domains.portal.contacts_need_us'));
            }
        }, __('domains.portal.contacts_saved'));
        if ($saved) {
            $this->editContacts = false;
            $this->domain->refresh();
            $this->contactRoles = collect(Domain::ROLES)->mapWithKeys(fn (string $role) => [$role => $this->domain->{"{$role}_contact_id"}])->all();
        }
    }

    private function management(): DomainManagementService
    {
        return app(DomainManagementService::class);
    }

    /** Who asks, for the registrar log (R1): staff while they look through the customer's eyes, else the login. */
    private function actor(): Staff|User|null
    {
        return Actor::current();
    }

    /** Staff in «Login as client» need the admin permission the same change needs there (R2); a customer does not. */
    private function staffMay(string $permission): bool
    {
        $actor = Actor::current();

        return !Actor::impersonating() || ($actor instanceof Staff && $actor->hasPermission($permission));
    }

    /** P5: the child nameserver being changed (its name), or a new one (''); null: no form. Set by the server only. */
    #[Locked]
    public ?string $glueEditing = null;

    public string $glueHost = '';

    public string $glueAddresses = '';

    /**
     * Child nameservers (P5, glue; staff have had them since #196): read from the registrar when asked, kept for the page.
     * Only a registrar that keeps them as objects (the .gr registry); Openprovider takes a nameserver's IP with the
     * nameservers themselves.
     */
    public function readChildHosts(): void
    {
        if ($this->mayCallRegistrar() && $this->management()->managesChildHosts($this->domain)) {
            $this->atRegistryGlue(fn () => $this->management()->readChildHosts($this->domain, $this->actor()), __('domains.portal.glue_read'));
            $this->glueEditing = null;
        }
    }

    /** The form: a new child nameserver (''), or one as last read (its addresses). */
    public function startChildHost(mixed $host = ''): void
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay('admin.domains.manage'), 404);
        if (!is_string($host)) {
            return; // a crafted call (F6 of #250)
        }
        $known = collect($this->childHostsRead())->firstWhere('name', $host);
        if ($host !== '' && ($known === null || ($known['read'] ?? true) === false)) {
            return; // only one the last read showed, and read
        }
        $this->glueEditing = $host;
        $this->glueHost = $host;
        $this->glueAddresses = $known !== null ? implode("\n", (array) ($known['addresses'] ?? [])) : '';
        $this->resetErrorBag();
    }

    public function cancelChildHost(): void
    {
        $this->glueEditing = null;
    }

    public function saveChildHost(): void
    {
        // A change only of one the last read showed (never another name sent in its place).
        if ($this->glueEditing === null || ($this->glueEditing !== '' && collect($this->childHostsRead())->firstWhere('name', $this->glueEditing) === null)) {
            return;
        }
        $this->validate(['glueAddresses' => ['required', 'string', 'max:1000']], [], ['glueAddresses' => __('domains.portal.glue_addresses')]);
        // A new one: under this domain (ns1, or ns1.example.gr; an IDN in its letters or punycode), said on the field before
        // the registry is asked. A change: the name read is used, whatever the field holds.
        if ($this->glueEditing === '') {
            $this->validate(['glueHost' => ['required', 'string', 'max:253']], [], ['glueHost' => __('domains.portal.glue_host')]);
            $host = self::asciiHost($this->glueHost);
            $fqdn = $this->domain->fqdn;
            if ($host === null || $host === $fqdn || (str_contains($host, '.') && !str_ends_with($host, '.' . $fqdn))) {
                $this->addError('glueHost', __('domains.portal.glue_outside', ['domain' => $this->domain->live_fqdn ?? $fqdn]));

                return;
            }
        }
        $addresses = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $this->glueAddresses) ?: []), fn (string $a) => $a !== ''));
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                $this->addError('glueAddresses', __('domains.portal.glue_bad_ip', ['ip' => $address]));

                return;
            }
            // Glue is reachable from anywhere: never a private, reserved or documentation address (the registry's own check
            // stays the last).
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false || IpUtils::checkIp($address, self::NOT_GLOBAL)) {
                $this->addError('glueAddresses', __('domains.portal.glue_not_public', ['ip' => $address]));

                return;
            }
        }
        if (!$this->mayCallRegistrar() || !$this->management()->managesChildHosts($this->domain)) {
            return;
        }
        $editing = $this->glueEditing;
        $done = $editing === ''
            ? $this->atRegistryGlue(fn () => $this->management()->createChildHost($this->domain, (string) self::asciiHost($this->glueHost), $addresses, $this->actor()), __('domains.portal.glue_created'))
            : $this->atRegistryGlue(fn () => $this->management()->updateChildHost($this->domain, $editing, $addresses, $this->actor()), __('domains.portal.glue_updated'));
        if ($done) {
            $this->glueEditing = null;
        }
    }

    /** Only one no domain uses (the registry refuses a linked one; the page offers only those). */
    public function deleteChildHost(mixed $host): void
    {
        if (!is_string($host)) {
            return;
        }
        $known = collect($this->childHostsRead())->firstWhere('name', $host);
        if ($known === null || ($known['linked'] ?? true)) {
            return;
        }
        if ($this->mayCallRegistrar() && $this->management()->managesChildHosts($this->domain)) {
            $this->atRegistryGlue(fn () => $this->management()->deleteChildHost($this->domain, $host, $this->actor()), __('domains.portal.glue_deleted'));
        }
    }

    /** Neither private nor reserved, yet never on the internet: shared (CGNAT), benchmarking, documentation (RFC 6890). */
    private const NOT_GLOBAL = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '2001:db8::/32'];

    /**
     * A glue call through atRegistrar, in the customer's words (reviews of #250): the registry's own check (the domain
     * cannot be changed now, another registrar's, a host in use) never in its English or with its data; a «maybe done»
     * said as such (the list is read again either way). Kept for staff in the log and reported.
     */
    private function atRegistryGlue(callable $call, string $done): bool
    {
        return $this->atRegistrar(function () use ($call) {
            try {
                $call();
            } catch (RegistrarNotAsked $e) {
                if (!$e->getPrevious() instanceof InvalidArgumentException) {
                    throw $e; // not sent (busy, a connection lost): the general words, as every registrar call
                }
                report($e);
                throw new DisplayException(__('domains.portal.glue_not_done'));
            } catch (RegistrarOutcomeUnknown $e) {
                report($e);
                throw new DisplayException(__('domains.portal.glue_maybe'));
            }
        }, $done);
    }

    /** The host typed, as the registry takes it: lowercase ASCII (an IDN in punycode), no trailing dot; null if not a name. */
    private static function asciiHost(string $host): ?string
    {
        $host = rtrim(trim(mb_strtolower($host)), '.');
        if ($host === '') {
            return null;
        }
        $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII) : $host;
        if (!is_string($ascii) || strlen($ascii) > 253) {
            return null;
        }
        foreach (explode('.', $ascii) as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return null;
            }
        }

        return $ascii;
    }

    /** @return list<array<string, mixed>> as last read (meta.child_hosts; stored data, each entry checked) */
    private function childHostsRead(): array
    {
        $hosts = $this->domain->meta['child_hosts']['hosts'] ?? [];

        return is_array($hosts) ? array_values(array_filter($hosts, fn ($h) => is_array($h) && is_string($h['name'] ?? null))) : [];
    }

    /** DNSSEC (P3): read from its registrar when asked (never on each view), kept for the page; logged with the login. */
    public function readDnssec(): void
    {
        if ($this->mayCallRegistrar() && $this->management()->dnssecKind($this->domain) !== null) {
            $this->atRegistrar(fn () => $this->management()->readDnssec($this->domain, $this->actor()), __('domains.portal.dnssec_read'));
            $this->editDnssec = false; // a form from an older read never stays (ADV-6 of #223): «Change» starts from this one
        }
    }

    /** The form from this registrar's last read (never another registrar's, never out of date: G8-4b). */
    public function startDnssec(): void
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay('admin.domains.manage'), 404); // as the change itself (review L4 of #223)
        $read = $this->management()->dnssecRead($this->domain);
        if ($read === null) {
            $this->notify(__('domains.portal.dnssec_read_first'), 'error');

            return;
        }
        $this->dnssecRows = array_map(fn ($r) => $r->toArray(), $read);
        $this->dnssecSeen = (string) json_encode($this->dnssecRows);
        $this->editDnssec = true;
    }

    public function addDnssecRow(): void
    {
        if (count($this->dnssecRows) < 16) {
            $this->dnssecRows[] = [];
        }
    }

    public function removeDnssecRow(int $index): void
    {
        unset($this->dnssecRows[$index]);
        $this->dnssecRows = array_values($this->dnssecRows);
    }

    /**
     * Its DNSSEC records set to the form's (the full set; none: unsigned), as staff's «Change DNSSEC» (G8-4b): checked
     * against what the form started from, the registry's rules first; its outcome in the customer's words.
     */
    public function saveDnssec(): void
    {
        $this->authorize('update', $this->domain);
        $kind = $this->management()->dnssecKind($this->domain);
        if ($kind === null) {
            $this->notify(__('domains.portal.not_manageable'), 'error'); // no longer ours to change here (ADV-7 of #223)
            $this->editDnssec = false;

            return;
        }
        $this->dnssecRows = array_values(array_filter($this->dnssecRows, 'is_array'));
        $in = fn (array $options): string => 'in:' . implode(',', array_keys($options));
        // The form's own choices for its kind, and whatever the registrar had (kept as read: ADV-4 of #223).
        $readAlgorithms = array_column(array_filter((array) json_decode($this->dnssecSeen, true), 'is_array'), 'algorithm');
        $algorithms = DnssecOptions::algorithms($kind) + array_fill_keys(array_filter($readAlgorithms, 'is_int'), '');
        $rules = $kind === DnssecRecord::DS ? [
            'dnssecRows.*.key_tag' => ['required', 'integer', 'min:0', 'max:65535'],
            'dnssecRows.*.algorithm' => ['required', 'integer', $in($algorithms)],
            'dnssecRows.*.digest_type' => ['required', 'integer', $in(DnssecOptions::DIGEST_TYPES)],
            'dnssecRows.*.digest' => ['required', 'string', 'max:200', 'regex:/\A[0-9A-Fa-f\s]+\z/'],
        ] : [
            'dnssecRows.*.flags' => ['required', 'integer', $in(DnssecOptions::FLAGS)],
            'dnssecRows.*.algorithm' => ['required', 'integer', $in($algorithms)],
            'dnssecRows.*.public_key' => ['required', 'string', 'max:4000', 'regex:/\A[A-Za-z0-9+\/=\s]+\z/'],
        ];
        // Its fields in the customer's words, never «dnssecRows.0.digest» (review M2 of #223).
        $this->validate(['dnssecRows' => 'array|max:16'] + $rules, [], collect(['key_tag' => __('domains.portal.dnssec_key_tag'), 'algorithm' => __('domains.portal.dnssec_algorithm'),
            'digest_type' => __('domains.portal.dnssec_digest_type'), 'digest' => __('domains.portal.dnssec_digest'), 'flags' => __('domains.portal.dnssec_flags'),
            'public_key' => __('domains.portal.dnssec_public_key')])
            ->mapWithKeys(fn (string $label, string $field) => ["dnssecRows.*.{$field}" => $label])->all() + ['dnssecRows' => 'DNSSEC']);
        if (!$this->mayCallRegistrar()) {
            return;
        }
        $rows = fn (array $list): array => array_map(fn (array $row) => DnssecRecord::fromArray(['kind' => $kind] + $row), array_values(array_filter($list, 'is_array')));
        $seen = json_decode($this->dnssecSeen, true);
        try {
            $records = $rows($this->dnssecRows);
            $expected = is_array($seen) ? $rows($seen) : null;
        } catch (InvalidArgumentException) {
            $this->notify(__('domains.portal.dnssec_refused'), 'error');

            return;
        }
        if ($expected === null) {
            $this->notify(__('domains.portal.dnssec_read_first'), 'error');

            return;
        }
        $done = $this->atRegistrar(function () use ($records, $expected) {
            try {
                $this->management()->setDnssec($this->domain, $records, $this->actor(), $expected);
            } catch (DnssecChangedSinceRead) {
                $this->editDnssec = false; // the records were read again: the form closes (review L2 of #223)
                throw new DisplayException(__('domains.portal.dnssec_changed'));
            } catch (RegistrarOutcomeUnknown $e) {
                report($e);
                throw new DisplayException(__('domains.portal.dnssec_maybe'));
            } catch (RegistrarNotAsked $e) {
                // Ours to say plainly: busy, or the registry's own rules (its words are staff's).
                throw new DisplayException(str_contains($e->getMessage(), 'busy') ? __('domains.manage.busy', ['domain' => $this->domain->fqdn]) : __('domains.portal.dnssec_refused'));
            }
        }, __('domains.portal.dnssec_saved'));
        $this->domain->refresh();
        if ($done) {
            $this->editDnssec = false;
        }
    }

    /** May the login see the account's invoices (where «Renew» leads, R3)? */
    private function seesInvoices(): bool
    {
        if (Actor::impersonating()) {
            return true;
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User && (bool) ClientUser::query()->where('client_id', $this->domain->client_id)->where('user_id', $user->id)
            ->whereNull('revoked_at')->first()?->allows('invoices');
    }

    private function limited(string $key): bool
    {
        if (RateLimiter::tooManyAttempts($key = $key . ':' . Auth::id(), 10)) {
            $this->notify(__('domains.portal.too_many'), 'error');

            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    /** May act at the registrar now: manages it (and staff may there), a registrar with an API, under the limit. */
    private function mayCallRegistrar(string $staffPermission = 'admin.domains.manage'): bool
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay($staffPermission), 404);
        if (!$this->management()->isManageable($this->domain)) {
            $this->notify(__('domains.portal.not_manageable'), 'error');

            return false;
        }

        return !$this->limited('domain-portal');
    }

    /**
     * Run a registrar change and say how it went (R5): our own messages as they are (a bad nameserver, another change
     * under way); the registrar's refusal in plain words, its text kept for staff in the log; anything else reported.
     */
    private function atRegistrar(callable $call, string $done): bool
    {
        try {
            $call();
            $this->domain->refresh();
            $this->notify($done, 'success');

            return true;
        } catch (DisplayException|InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');
        } catch (RegistrarRefused) {
            $this->notify(__('domains.portal.registrar_refused'), 'error');
        } catch (RuntimeException $e) {
            // Ours (another change under way, no EPP code): already in the customer's words; else the registrar's.
            $ours = in_array($e->getMessage(), [__('domains.manage.busy', ['domain' => $this->domain->fqdn]), __('domains.manage.no_epp_code')], true);
            if (!$ours) {
                report($e);
            }
            $this->notify($ours ? $e->getMessage() : __('domains.portal.registrar_failed'), 'error');
        } catch (Throwable $e) {
            report($e);
            $this->notify(__('domains.portal.registrar_failed'), 'error');
        }

        return false;
    }

    /**
     * Auto renew off is «do not renew» (D-14): no renewal invoice, no notices; an invoice already sent stays payable
     * (R8: the confirmation says so). On again, it is billed as usual.
     */
    public function toggleAutoRenew(): void
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay('admin.domains.update'), 404);
        $this->domain->update(['auto_renew' => !$this->domain->auto_renew]);
        $this->notify($this->domain->auto_renew ? __('domains.portal.auto_renew_turned_on') : __('domains.portal.auto_renew_turned_off'), 'success');
    }

    /**
     * Its renewal period (owner 2026-10-17): among the years its TLD prices, from its next renewal; its open, unpaid
     * renewal invoice changes with it (DomainRenewalYears). Staff in «Login as client» need the admin permission (R2).
     */
    public function setRenewalYears(int $years): void
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay('admin.domains.update'), 404);
        if ($this->limited('domain-years')) {
            return;
        }
        try {
            $outcome = app(DomainRenewalYears::class)->change($this->domain, $years, byStaff: false);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        if ($outcome !== DomainRenewalYears::UNCHANGED) {
            $this->notify(trans_choice($outcome === DomainRenewalYears::INVOICE ? 'domains.portal.years_changed_invoice' : 'domains.portal.years_changed_next',
                $years, ['years' => $years]), 'success');
        }
    }

    /** Its own payment method (ItemPaymentMethod): 0, the account's. Who manages it and pays the account's invoices. */
    public function setPaymentMethod(int $gatewayId): void
    {
        abort_unless($this->setsPaymentMethod(), 403);
        try {
            ItemPaymentMethod::set($this->domain, $gatewayId > 0 ? $gatewayId : null);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->notify(__('services.payment_method_saved'), 'success');
    }

    private function setsPaymentMethod(): bool
    {
        return Gate::allows('update', $this->domain) && $this->staffMay('admin.domains.update') && $this->seesInvoices();
    }

    public function addNameserver(): void
    {
        if (count($this->nameservers) < 13) {
            $this->nameservers[] = ['host' => ''];
        }
    }

    public function removeNameserver(int $index): void
    {
        unset($this->nameservers[$index]);
        $this->nameservers = array_values($this->nameservers);
    }

    public function saveNameservers(): void
    {
        // Who may, before anything else (R6); then as the registrar call checks them: 2-13 hosts, distinct, its pattern.
        $this->authorize('update', $this->domain);
        // As the browser sent them (any shape): a host each.
        $sent = (array) $this->nameservers;
        $this->nameservers = array_map(fn ($ns) => ['host' => is_scalar($host = data_get($ns, 'host')) ? mb_strtolower(trim((string) $host)) : ''], $sent);
        $this->validate([
            'nameservers' => 'array|min:2|max:13',
            'nameservers.*.host' => ['required', 'string', 'max:253', 'distinct', 'regex:' . DomainManagementService::HOST],
        ]);
        if (!$this->mayCallRegistrar()) {
            return;
        }
        $hosts = array_column($this->nameservers, 'host');
        // The form closes only when the registrar took them (what the customer typed is kept otherwise).
        if ($this->atRegistrar(fn () => $this->management()->setNameservers($this->domain, $hosts, $this->actor()), __('domains.portal.nameservers_saved'))) {
            $this->editNameservers = false;
            $this->mount();
        }
    }

    /** Offered as in the admin (R7): only where the registrar says the domain can be locked. */
    public function lockable(): bool
    {
        return ($this->domain->meta['registrar']['is_lockable'] ?? true) !== false
            && $this->management()->registrarSupports($this->domain, 'supportsTransferLock');
    }

    /**
     * ID protection, offered only where its TLD offers it (D-7) and the registrar allows it (R7): whether Openprovider
     * charges for it is not settled (docs/domains.md), so not where the company does not sell it.
     */
    public function privacyOffered(): bool
    {
        return (bool) $this->domain->domainTld?->id_protection && ($this->domain->meta['registrar']['is_private_whois_allowed'] ?? true) !== false
            && $this->management()->registrarSupports($this->domain, 'supportsPrivacy');
    }

    public function toggleTransferLock(): void
    {
        if ($this->mayCallRegistrar() && $this->lockable()) {
            $this->atRegistrar(fn () => $this->management()->setTransferLock($this->domain, !$this->domain->transfer_lock, $this->actor()), __('domains.portal.saved'));
        }
    }

    public function toggleIdProtection(): void
    {
        if ($this->mayCallRegistrar() && $this->privacyOffered()) {
            $this->atRegistrar(fn () => $this->management()->setWhoisPrivacy($this->domain, !$this->domain->id_protection, $this->actor()), __('domains.portal.saved'));
        }
    }

    /**
     * The EPP (auth) code, read from the registrar when asked: never kept, never in the page until then, logged as asked
     * (not its value). Staff in «Login as client» need admin.domains.epp, as in the admin (R2). Renderless: kept out of
     * the component's snapshot.
     */
    #[Renderless]
    public function revealEppCode(): ?string
    {
        if (!$this->mayCallRegistrar('admin.domains.epp')) {
            return null;
        }
        $code = null;
        $this->atRegistrar(function () use (&$code) {
            $code = $this->management()->eppCode($this->domain, $this->actor());
        }, __('domains.portal.epp_shown'));

        return $code;
    }

    /**
     * The registry emails the owner a link to reset the code (DACoR: the .gr registry never gives it; G4b of grepp).
     * Staff in «Login as client» need the admin permission (R2), as for the code itself.
     */
    public function emailAuthCode(): void
    {
        if (!$this->mayCallRegistrar('admin.domains.epp') || !$this->management()->emailsAuthCode($this->domain)) {
            return; // only where the registry emails it (review 3 of #191)
        }
        // Each call is a real email to the owner: 3 an hour per domain, whoever asks (review 2 of #191).
        $key = "domain-auth-code-email:{$this->domain->id}";
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->notify(__('domains.portal.too_many'), 'error');

            return;
        }
        RateLimiter::hit($key, 3600);
        $this->atRegistrar(fn () => $this->management()->emailAuthCode($this->domain, $this->actor()), __('domains.portal.auth_code_emailed'));
    }

    /**
     * «Renew»: to its renewal invoice if one is open; else its next period invoiced now (D7a: the cron's rules, once per
     * period), with the account's credit applied as to any renewal (the confirmation says so). Needs the invoices too
     * (R3: where it leads). A domain set not to renew renews again once its invoice is made (R9), not before.
     */
    public function renew()
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->seesInvoices() && $this->staffMay('admin.invoices.create'), 404);
        if (($open = $this->openInvoice()) !== null) {
            return $this->redirectRoute('invoices.show', $open, navigate: true);
        }
        if ($this->limited('domain-renew')) {
            return null;
        }
        $wasOff = !$this->domain->auto_renew;
        if ($wasOff) {
            $this->domain->update(['auto_renew' => true]); // billed only with auto renew on (InvoiceSelectedItems)
        }
        try {
            $result = (new InvoiceSelectedItems)->handle([], [$this->domain->fresh()]);
        } catch (Throwable $e) {
            report($e);
            $result = ['invoices' => []];
        }
        if ($result['invoices'] === []) {
            if ($wasOff) {
                $this->domain->update(['auto_renew' => false]);
            }
            // A click a moment ago made it (R9): to that one. Else, in the customer's words (R4), never the staff's reason.
            if (($open = $this->openInvoice()) !== null) {
                return $this->redirectRoute('invoices.show', $open, navigate: true);
            }
            $this->notify(__('domains.portal.cannot_renew_now'), 'error');

            return null;
        }

        return $this->redirectRoute('invoices.show', $result['invoices'][0], navigate: true);
    }

    /**
     * Its open renewal invoice the customer can see (unpaid; a draft is not theirs to see), read now.
     *
     * @phpstan-impure
     */
    private function openInvoice(): ?Invoice
    {
        if ($this->domain->expires_at === null) {
            return null;
        }
        $line = DomainRenewalService::billedLine($this->domain, $this->domain->expires_at->copy()->startOfDay(), payable: true);

        return $line?->invoice?->status === Invoice::STATUS_UNPAID ? $line->invoice : null;
    }

    public function render()
    {
        $canManage = Gate::allows('update', $this->domain);
        $manageable = $canManage && $this->staffMay('admin.domains.manage') && $this->management()->isManageable($this->domain);
        $seesInvoices = $this->seesInvoices();

        return view('domains.show', [
            'canManage' => $canManage && $this->staffMay('admin.domains.update'),
            'yearOptions' => $canManage && $this->staffMay('admin.domains.update') && DomainRenewalYears::clientMay($this->domain)
                ? DomainRenewalYears::choices($this->domain) : [],
            'manageable' => $manageable,
            'paymentMethodOptions' => ($setsPaymentMethod = $this->setsPaymentMethod() && in_array($this->domain->status, RenewalBilling::BILLED_DOMAINS, true))
                ? ItemPaymentMethod::options($this->domain) : null,
            'lockable' => $manageable && $this->lockable(),
            'privacyOffered' => $manageable && $this->privacyOffered(),
            'showEpp' => $manageable && $this->staffMay('admin.domains.epp'),
            'emailsAuthCode' => $manageable && $this->management()->emailsAuthCode($this->domain),
            'renewable' => $canManage && $seesInvoices && $this->staffMay('admin.invoices.create')
                && in_array($this->domain->status, RenewalBilling::BILLED_DOMAINS, true),
            'openInvoice' => $seesInvoices ? $this->openInvoice() : null,
            'contactOptions' => $manageable && $this->editContacts ? $this->contactOptions() : [],
            'registrantChangeable' => $this->registrantChangeable(),
            'registrantLocked' => __($this->atGrRegistry() ? 'domains.portal.registrant_locked' : 'domains.portal.registrant_locked_here'),
            'seesContacts' => $this->seesContacts(),
            'registry' => RegistryStatuses::forCustomer($this->domain),
            'glue' => $manageable && $this->management()->managesChildHosts($this->domain),
            'childHosts' => $this->childHostsRead(),
            'childHostsReadAt' => is_string($at = $this->domain->meta['child_hosts']['read_at'] ?? null) ? Carbon::parse($at)->timezone(config('app.timezone'))->format('d/m/Y H:i') : null,
            'childHostsStale' => (bool) ($this->domain->meta['child_hosts']['stale'] ?? false),
            'dnssecKind' => $manageable ? $this->management()->dnssecKind($this->domain) : null,
            'dnssec' => $manageable ? $this->management()->dnssecRead($this->domain) : null,
            // The registry's check under way: its note while it may be (an hour), with its time; never another kind's (M1).
            'dnssecChecking' => $manageable && $this->management()->dnssecKind($this->domain) === DnssecRecord::DS
                && ($this->domain->meta['dnssec']['note_kind'] ?? null) === 'check' && is_string($at = $this->domain->meta['dnssec']['note_at'] ?? null)
                && Carbon::parse($at)->gt(now()->subHour()) ? Carbon::parse($at)->timezone(config('app.timezone'))->format('d/m/Y H:i') : null,
        ])->layoutData([
            'title' => $this->domain->fqdn,
            'sidebar' => true,
        ]);
    }
}
