<?php

use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Credentials that an extension declares `encrypted` but that were stored in plain text
 * (created through the create form, or before the extension marked them) are encrypted
 * in place (docs/history/audit-security.md M6).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('extensions')->get() as $extension) {
            $settings = Setting::query()
                ->whereIn('settingable_type', [Extension::class, Server::class, Gateway::class])
                ->where('settingable_id', $extension->id)
                ->get();

            try {
                $config = ExtensionHelper::getConfig($extension->type, $extension->extension, $settings->pluck('value', 'key')->toArray() ?: ['_' => null]);
            } catch (Throwable $e) {
                Log::warning("Extension #{$extension->id} ({$extension->extension}): config unavailable, credentials not checked: {$e->getMessage()}");

                continue;
            }

            $secret = collect($config)->where('encrypted', true)->pluck('name')->all();
            foreach ($settings as $setting) {
                if (in_array($setting->key, $secret, true) && !$setting->encrypted && $setting->value !== null) {
                    // The Saving event encrypts the value once the flag is set.
                    $setting->encrypted = true;
                    $setting->save();
                }
            }
        }
    }
};
