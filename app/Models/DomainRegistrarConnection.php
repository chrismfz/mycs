<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An account at a registrar (docs/domains.md D-21): Openprovider, «manual» (no API, D-10), later CNIC or the .gr
 * registry. Several may exist, two at the same registrar too. Its credentials are Settings of it, the secret ones
 * encrypted: so they are scrubbed from every log (Redact), redacted in the audit and never sent back to a form, as an
 * extension's. A connection TLDs or domains use is not deleted.
 *
 * @property int $id
 * @property string $registrar
 * @property string $label
 * @property bool $is_active
 * @property string $mode off | sandbox | production
 */
class DomainRegistrarConnection extends Model implements Auditable
{
    use Traits\Auditable;

    public const MODES = ['off', 'sandbox', 'production'];

    public const MANUAL = 'manual';

    protected $fillable = ['registrar', 'label', 'is_active', 'mode'];

    /** Its settings are the credentials, its TLDs and domains every account's: never in a JSON or a template. */
    protected $hidden = ['settings', 'tlds', 'domains'];

    public const TEMPLATE_HIDDEN = ['settings', 'tlds', 'domains'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $connection) {
            // Its log is history too (D-17, D-18).
            if ($connection->tlds()->exists() || $connection->domains()->exists() || DomainRegistrarLog::query()->where('registrar_connection_id', $connection->id)->exists()) {
                throw new DisplayException(__('domains.connection_in_use'));
            }
        });
        // Its credentials go with it.
        static::deleted(fn (self $connection) => $connection->settings()->get()->each->delete()); // with their audit
    }

    /** @return MorphMany<Setting, $this> */
    public function settings(): MorphMany
    {
        return $this->morphMany(Setting::class, 'settingable');
    }

    /** @return HasMany<DomainTld, $this> */
    public function tlds(): HasMany
    {
        return $this->hasMany(DomainTld::class, 'registrar_connection_id');
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'registrar_connection_id');
    }

    /** It may be called: active, not off, and not the manual one. */
    public function isUsable(): bool
    {
        return $this->is_active && $this->mode !== 'off' && $this->registrar !== self::MANUAL;
    }

    /** @return array<string, mixed> its credentials, decrypted, for its driver only */
    public function credentials(): array
    {
        return $this->settings()->get()->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value])->all();
    }

    /**
     * Store credentials: the keys in `$secret` encrypted. A secret left empty keeps the stored one (a form never shows it).
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $secret
     */
    public function storeCredentials(array $values, array $secret): void
    {
        foreach ($values as $key => $value) {
            $isSecret = in_array($key, $secret, true);
            if ($isSecret && ($value === null || $value === '')) {
                continue;
            }
            $this->settings()->updateOrCreate(
                ['key' => $key, 'settingable_id' => $this->id, 'settingable_type' => $this->getMorphClass()],
                ['type' => 'string', 'value' => $value === null ? null : (string) $value, 'encrypted' => $isSecret],
            );
        }
    }
}
