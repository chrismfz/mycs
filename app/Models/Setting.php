<?php

namespace App\Models;

use App\Events\Setting\Retrieved;
use App\Events\Setting\Saved;
use App\Events\Setting\Saving;
use App\Redactors\RightRedactor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Crypt;
use OwenIt\Auditing\Contracts\Auditable;
use Throwable;

class Setting extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'encrypted',
        'settingable_id',
        'settingable_type',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    protected $dispatchesEvents = [
        'retrieved' => Retrieved::class,
        'saving' => Saving::class,
        'saved' => Saved::class,
    ];

    /**
     * A stored value as the app uses it: decrypted, then cast by its type. Also used on the raw rows the settings
     * cache keeps (SettingsProvider), so the cache never holds a secret in clear.
     */
    public static function decodeValue(mixed $value, ?string $type, bool $encrypted): mixed
    {
        if ($encrypted && $value) {
            try {
                $value = Crypt::decryptString($value);
            } catch (Throwable $th) {
                report($th);
                $value = null;
            }
        }

        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'array' => json_decode($value, true),
            default => $value,
        };
    }

    public function getAttributeModifiers(): array
    {
        if (!$this->encrypted) {
            return [];
        }

        return [
            'value' => RightRedactor::class,
        ];
    }
}
