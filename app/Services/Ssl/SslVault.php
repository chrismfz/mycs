<?php

namespace App\Services\Ssl;

use App\Exceptions\ServerRefused;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\SslVersion;
use App\Models\Staff;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MyCS\SslTools\Certificate;
use MyCS\SslTools\Csr;
use MyCS\SslTools\KeyType;
use MyCS\SslTools\Pkcs12;
use MyCS\SslTools\PrivateKey;
use MyCS\SslTools\SslToolsException;
use OwenIt\Auditing\Events\AuditCustom;
use SensitiveParameter;

/**
 * The SSL vault (docs/ssl-vault.md, owner 2026-10-24): keys and CSRs made here, the CSR each order and reissue used,
 * and the certificate issued for each, matched by public key. Every key leaves only through keyPem() or pfx(), each
 * time audited on the service (who, which version, its fingerprint; never the key).
 */
class SslVault
{
    /** Staff's permission to see or download a private key (in the admin, or while logged in as the client). */
    public const KEY_PERMISSION = 'admin.services.ssl_keys';

    /**
     * The names a key's CSR asks for, from the domain typed: «example.gr» is example.gr and its www (and «www.example.gr»
     * the same two, the www first); «*.example.gr» is the wildcard and example.gr. The names OpenproviderSsl::decodeCsr accepts, no others.
     *
     * @return list<string>
     */
    public static function namesFor(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $wildcard = str_starts_with($domain, '*.');
        $bare = $wildcard ? substr($domain, 2) : $domain;
        $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($bare, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $bare;
        $bare = is_string($ascii) ? strtolower($ascii) : '';
        // «www.example.gr» stays the common name (a reissue keeps the order's), with example.gr beside it.
        $names = match (true) {
            $wildcard => ["*.{$bare}", $bare],
            str_starts_with($bare, 'www.') => [$bare, substr($bare, 4)],
            default => [$bare, "www.{$bare}"],
        };
        foreach ($names as $name) {
            if (!Csr::validName($name)) {
                throw new ServerRefused(__('services.ssl.vault.domain_invalid'));
            }
        }

        return $names;
    }

    /**
     * A new key (or the key of an earlier version of this order, kept) and its CSR for the names, kept as a version not
     * ordered yet. Its CSR then goes through the order or the reissue as a pasted one would.
     *
     * @param  list<string>  $names
     */
    public function generate(SslOrder $order, KeyType $type, array $names, ?SslVersion $keepKeyOf = null): SslVersion
    {
        if ($keepKeyOf !== null && ($keepKeyOf->ssl_order_id !== $order->id || !$keepKeyOf->hasKey())) {
            throw new ServerRefused(__('services.ssl.vault.no_key_to_keep'));
        }
        try {
            $key = $keepKeyOf?->privateKey() ?? PrivateKey::generate($type);
            $csr = Csr::create($key, $names);
        } catch (SslToolsException) {
            throw new ServerRefused(__('services.ssl.vault.not_generated'));
        }
        $version = $this->add($order, [
            'source' => SslVersion::SOURCE_GENERATED,
            'key_type' => $key->type?->value,
            'key_description' => $key->description,
            'private_key' => $key->pem(),
            'spki_sha256' => $key->spkiSha256,
            'csr' => $csr->pem,
            'common_name' => $csr->commonName,
            'host_names' => $csr->names,
        ], creator: Actor::current());
        $this->audit($order, 'ssl_key_generated', $version, ['kept_from' => $keepKeyOf?->number]);

        return $version;
    }

    /**
     * After Openprovider is read (OpenproviderSsl::sync): the order's CSR has its version (one made here, marked
     * ordered; one pasted, added without a key), and its certificate is filed under the version of its key. Never
     * fails the sync: the caller reports what this throws.
     */
    public function record(SslOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = SslOrder::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($order === null) {
                return;
            }
            if (filled($order->csr) && ($order->order_id !== null)) {
                $this->markOrdered($order);
            }
            if (filled($order->certificate)) {
                $this->fileCertificate($order);
            }
        });
    }

    /** The version whose certificate is the order's, else the last one ordered. */
    public function current(SslOrder $order): ?SslVersion
    {
        $versions = $order->versions()->orderByDesc('number')->get();
        $certificate = self::norm($order->certificate);

        return ($certificate !== '' ? $versions->first(fn (SslVersion $v) => self::norm($v->certificate) === $certificate) : null)
            ?? $versions->first(fn (SslVersion $v) => $v->ordered_at !== null);
    }

    /**
     * A key kept elsewhere (an admin note, the customer's server) put on its version: only a version without one, and
     * only the key whose public key is the version's.
     */
    public function attachKey(SslVersion $version, #[SensitiveParameter] string $pem): void
    {
        try {
            $key = PrivateKey::parse($pem);
        } catch (SslToolsException $e) {
            throw new ServerRefused($e->getMessage());
        }
        if ($version->hasKey() || $version->spki_sha256 === null || !hash_equals($version->spki_sha256, $key->spkiSha256)) {
            throw new ServerRefused(__('services.ssl.vault.key_mismatch'));
        }
        $attached = SslVersion::query()->whereKey($version->id)->whereNull('private_key')->update([
            'private_key' => encrypt($key->pem(), false), 'key_type' => $key->type?->value, 'key_description' => $key->description, 'updated_at' => now(),
        ]);
        if ($attached !== 1) {
            throw new ServerRefused(__('services.ssl.vault.key_mismatch'));
        }
        $this->audit($version->order, 'ssl_key_attached', $version);
    }

    /** The version's key as PEM, for its download or its display: audited. */
    public function keyPem(SslVersion $version, string $via): string
    {
        $key = $this->readKey($version);
        $this->audit($version->order, 'ssl_key_downloaded', $version, ['file' => 'key', 'via' => $via]);

        return $key->pem();
    }

    /** The version's certificate, key and chain as a .pfx with the password given: audited. */
    public function pfx(SslVersion $version, #[SensitiveParameter] string $password, string $via): string
    {
        $key = $this->readKey($version);
        try {
            $pfx = Pkcs12::export(Certificate::parse((string) $version->certificate), $key, $version->chainCertificates(), $password);
        } catch (SslToolsException $e) {
            throw new ServerRefused($e->getMessage());
        }
        $this->audit($version->order, 'ssl_key_downloaded', $version, ['file' => 'pfx', 'via' => $via]);

        return $pfx;
    }

    /**
     * The client area's login may have a key of this service: one that manages it (`manageproducts`); staff logged in
     * as the client only with KEY_PERMISSION too (owner 2026-10-24).
     */
    public static function customerMayReadKey(Service $service): bool
    {
        if (!Gate::allows('update', $service)) {
            return false;
        }
        if (Actor::impersonating()) {
            $staff = Auth::guard('staff')->user();

            return $staff instanceof Staff && $staff->hasPermission(self::KEY_PERMISSION);
        }

        return true;
    }

    private function readKey(SslVersion $version): PrivateKey
    {
        try {
            $key = $version->privateKey();
        } catch (SslToolsException) {
            $key = null;
        }

        return $key ?? throw new ServerRefused(__('services.ssl.vault.no_key'));
    }

    /** The CSR the order was made (or reissued) with: its version marked ordered, or added (a pasted CSR has no key here). */
    private function markOrdered(SslOrder $order): void
    {
        $csr = self::norm($order->csr);
        $version = $order->versions()->orderByDesc('number')->get()->first(fn (SslVersion $v) => self::norm($v->csr) === $csr);
        if ($version !== null) {
            if ($version->ordered_at === null) {
                $version->forceFill(['ordered_at' => now()])->save();
            }

            return;
        }
        try {
            $parsed = Csr::parse((string) $order->csr);
        } catch (SslToolsException) {
            $parsed = null;
        }
        $this->add($order, [
            'source' => SslVersion::SOURCE_PASTED,
            'key_description' => $parsed?->keyDescription,
            'spki_sha256' => $parsed?->spkiSha256,
            'csr' => trim((string) $order->csr),
            'common_name' => $order->common_name ?? $parsed?->commonName,
            'host_names' => $parsed?->names ?: $order->host_names,
            'ordered_at' => now(),
        ]);
    }

    /**
     * The order's certificate under the newest version of its key. A version of that key with another certificate
     * already (a renewal's, a reissue with the same CSR) keeps it: the new one gets a version of its own, with the same
     * key and CSR, so every certificate issued stays readable.
     */
    private function fileCertificate(SslOrder $order): void
    {
        try {
            $certificate = Certificate::parse((string) $order->certificate);
        } catch (SslToolsException) {
            return;
        }
        $norm = self::norm($certificate->pem);
        $versions = $order->versions()->orderByDesc('number')->get();
        if ($versions->contains(fn (SslVersion $v) => self::norm($v->certificate) === $norm)) {
            $filed = $versions->first(fn (SslVersion $v) => self::norm($v->certificate) === $norm);
            if (self::norm($filed->chain) !== self::norm($order->intermediate)) {
                $filed->forceFill(['chain' => $order->intermediate])->save();
            }

            return;
        }
        $fields = [
            'certificate' => $certificate->pem,
            'chain' => filled($order->intermediate) ? trim((string) $order->intermediate) : null,
            'serial' => mb_substr($certificate->serial, 0, 64),
            'expires_at' => $certificate->notAfter,
        ];
        $ofKey = $versions->filter(fn (SslVersion $v) => $v->spki_sha256 !== null && hash_equals($v->spki_sha256, $certificate->spkiSha256));
        $mine = $ofKey->first(fn (SslVersion $v) => $v->ordered_at !== null) ?? $ofKey->first(); // never a draft never ordered first
        if ($mine !== null && blank($mine->certificate)) {
            $mine->forceFill($fields + ['ordered_at' => $mine->ordered_at ?? now()])->save();

            return;
        }
        $csr = $mine?->csr;
        if ($csr === null) {
            try {
                $csr = $certificate->matches(Csr::parse((string) $order->csr)) ? trim((string) $order->csr) : null;
            } catch (SslToolsException) {
                $csr = null;
            }
        }
        $this->add($order, $fields + [
            'source' => SslVersion::SOURCE_SYNCED,
            'key_type' => $mine?->key_type,
            'key_description' => $mine->key_description ?? $certificate->keyDescription,
            'private_key' => $mine?->hasKey() ? $mine->private_key : null,
            'spki_sha256' => $certificate->spkiSha256,
            'csr' => $csr,
            'common_name' => $certificate->commonName ?: $order->common_name,
            'host_names' => $certificate->names ?: $order->host_names,
            'ordered_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function add(SslOrder $order, array $fields, ?Model $creator = null): SslVersion
    {
        return DB::transaction(function () use ($order, $fields, $creator) {
            SslOrder::query()->whereKey($order->id)->lockForUpdate()->first();
            $version = new SslVersion($fields + ['ssl_order_id' => $order->id, 'number' => (int) $order->versions()->max('number') + 1]);
            if ($creator instanceof Staff || $creator instanceof User) {
                $version->creator()->associate($creator);
            }
            $version->save();

            return $version;
        });
    }

    /**
     * An audit on the service: what was done to which version (its number and key fingerprint), by whom (Actor); never
     * the key.
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(SslOrder $order, string $event, SslVersion $version, array $extra = []): void
    {
        $service = $order->service;
        if ($service === null) {
            return;
        }
        $service->auditEvent = $event;
        $service->isCustomEvent = true;
        $service->auditCustomOld = [];
        $service->auditCustomNew = array_filter(['version' => $version->number, 'spki_sha256' => $version->spki_sha256] + $extra, fn ($v) => $v !== null);
        event(new AuditCustom($service));
        $service->isCustomEvent = false;
        $service->auditCustomNew = [];
    }

    private static function norm(?string $pem): string
    {
        return (string) preg_replace('/\s+/', '', (string) $pem);
    }
}
