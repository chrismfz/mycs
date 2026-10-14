<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A product's server-extension settings, saved from the product form. Through the model, so an option marked
 * «encrypted» (a licensing PSK) is stored encrypted (Setting's saving event); such a value never goes back to the
 * browser, and left empty on save the stored one is kept (as the settings page, audit sweep AUTH-12).
 */
class ProductSettings
{
    /**
     * @param  list<array<string, mixed>>  $options  the extension's getProductConfig()
     * @param  array<string, mixed>  $values  the form's «settings»
     */
    public static function save(Model $product, array $options, array $values): void
    {
        foreach ($options as $option) {
            $name = $option['name'];
            $value = $values[$name] ?? null;
            $encrypted = (bool) ($option['encrypted'] ?? false);
            if ($encrypted && ($value === null || $value === '')) {
                continue; // kept as stored
            }
            $setting = Setting::query()->firstOrNew(['key' => $name, 'settingable_id' => $product->getKey(), 'settingable_type' => $product->getMorphClass()]);
            $setting->forceFill([
                'type' => $option['database_type'] ?? 'string',
                'encrypted' => $encrypted,
                'value' => is_array($value) ? json_encode($value) : $value,
            ])->save();
        }
    }

    /**
     * The settings to fill the form with: an encrypted one empty.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    public static function forForm(Model $product, array $options): array
    {
        $secret = collect($options)->filter(fn ($option) => $option['encrypted'] ?? false)->pluck('name')->all();
        $values = [];
        foreach (self::stored($product)->get() as $setting) {
            $values[$setting->key] = in_array($setting->key, $secret, true) || $setting->encrypted ? null : $setting->value;
        }

        return $values;
    }

    /** @return Builder<Setting> the product's stored settings */
    public static function stored(Model $product)
    {
        return Setting::query()->where('settingable_id', $product->getKey())->where('settingable_type', $product->getMorphClass());
    }
}
