<?php

use App\Http\Middleware\EnsureExtensionBoots;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Each extension's own routes (the gateways' webhooks, returns and callbacks), loaded with the app's own so the route
// cache holds them all. Added from the extensions' boot(), `artisan optimize` cached those of the extensions there were
// at the deploy: a gateway created after it had no webhook until the next deploy, a deleted one kept its own
// (2026-11-02, Stripe on the test site). Each answers only while its extension boots (ExtensionHelper::bootable), as
// when it was boot() that added it: a gateway without a record still 404s.
foreach (glob(base_path('extensions/*/*/routes.php')) ?: [] as $file) {
    $type = Str::lower(Str::singular(basename(dirname($file, 2)))); // Gateways → gateway, as Extension::$type
    Route::middleware(EnsureExtensionBoots::class . ':' . $type . ',' . basename(dirname($file)))->group($file);
}
