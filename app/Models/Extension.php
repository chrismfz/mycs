<?php

namespace App\Models;

use App\Exceptions\ServerHasAccounts;
use App\Helpers\ExtensionHelper;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $path
 * @property-read mixed $namespace
 * @property-read mixed $meta
 */
class Extension extends Model implements Auditable
{
    use HasFactory, SoftDeletes, Traits\Auditable;

    protected $fillable = [
        'name',
        'enabled',
        // Name of extension class (e.g. 'Stripe' or 'Paypal')
        'extension',
        'type',
        // Servers: WHMCS's «max accounts» (null = no limit)
        'max_accounts',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        // A server with accounts on it is not deleted: the admin's delete is a soft delete, so the services' key never
        // refuses it, and its accounts would be left with no server (review F1b). Move or end them first.
        static::deleting(function (Extension $extension) {
            if ($extension->type === 'server' && ($accounts = Server::liveAccountsOn($extension->id)) > 0) {
                throw new ServerHasAccounts("Server «{$extension->name}» still has {$accounts} account(s): move them to another server or end them first.");
            }
        });
    }

    /**
     * Get the extension's settings.
     *
     * @return MorphMany<Setting, $this>
     */
    public function settings(): MorphMany
    {
        return $this->morphMany(Setting::class, 'settingable');
    }

    /** @return Attribute<mixed, mixed> */
    public function path(): Attribute
    {
        return Attribute::make(
            get: fn () => ucfirst($this->type) . 's/' . ucfirst($this->extension)
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function namespace(): Attribute
    {
        return Attribute::make(
            get: fn () => 'MyCS\\Extensions\\' . ucfirst($this->type) . 's\\' . ucfirst($this->extension)
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function meta(): Attribute
    {
        return Attribute::make(
            get: fn () => ExtensionHelper::getMeta($this->namespace . '\\' . ucfirst($this->extension))
        );
    }
}
