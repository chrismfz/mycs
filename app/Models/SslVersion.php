<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use MyCS\SslTools\Certificate;
use MyCS\SslTools\PrivateKey;

/**
 * One key and CSR an SSL order has used, and the certificate issued for it (docs/ssl-vault.md). Its private key is
 * encrypted (APP_KEY), hidden from arrays and JSON, and read only through privateKey(), by SslVault's checked paths.
 *
 * @property int $id
 * @property int $ssl_order_id
 * @property int $number
 * @property string $source
 * @property string|null $key_type
 * @property string|null $key_description
 * @property string|null $private_key
 * @property string|null $spki_sha256
 * @property string|null $csr
 * @property string|null $common_name
 * @property list<string>|null $host_names
 * @property string|null $certificate
 * @property string|null $chain
 * @property string|null $serial
 * @property Carbon|null $expires_at
 * @property Carbon|null $ordered_at
 * @property Carbon $created_at
 */
class SslVersion extends Model
{
    public const SOURCE_GENERATED = 'generated';

    public const SOURCE_PASTED = 'pasted';

    public const SOURCE_SYNCED = 'synced';

    /** The files a version gives, as their download names end. */
    public const FILES = ['crt', 'ca-bundle', 'fullchain', 'csr', 'key'];

    protected $guarded = ['id'];

    protected $hidden = ['private_key'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'host_names' => 'array',
            'expires_at' => 'datetime',
            'ordered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SslOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SslOrder::class, 'ssl_order_id');
    }

    /** @return MorphTo<Model, $this> */
    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasKey(): bool
    {
        return $this->getRawOriginal('private_key') !== null;
    }

    /** The key, decrypted and read: for SslVault's downloads and checks only. */
    public function privateKey(): ?PrivateKey
    {
        return $this->hasKey() ? PrivateKey::parse((string) $this->private_key) : null;
    }

    /** @return list<Certificate> the chain's certificates, in order */
    public function chainCertificates(): array
    {
        return filled($this->chain) ? Certificate::parseAll((string) $this->chain) : [];
    }

    /** The file's content, or null when this version has none of it. Never the key: SslVault::keyFile() gives it. */
    public function publicFile(string $file): ?string
    {
        $text = fn (?string $pem) => filled($pem) ? rtrim((string) $pem) . "\n" : null;

        return match ($file) {
            'crt' => $text($this->certificate),
            'ca-bundle' => $text($this->chain),
            'fullchain' => filled($this->certificate) ? $text($this->certificate) . ($text($this->chain) ?? '') : null,
            'csr' => $text($this->csr),
            default => null,
        };
    }

    /** A download's name: «example.gr-3.crt», a wildcard's «*» as «star». */
    public function fileName(string $file): string
    {
        $name = preg_replace('/[^a-z0-9.-]/', '', str_replace('*', 'star', strtolower((string) ($this->common_name ?: 'certificate'))));

        return "{$name}-{$this->number}." . match ($file) {
            'ca-bundle' => 'ca-bundle.crt',
            'fullchain' => 'fullchain.pem',
            default => $file,
        };
    }
}
