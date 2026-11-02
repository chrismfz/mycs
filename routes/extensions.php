<?php

use App\Http\Middleware\EnsureExtensionBoots;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Each extension's own routes (the gateways' webhooks, returns and callbacks in routes.php, an «other» extension's pages
// in routes/web.php), loaded with the app's own so the route cache holds them all. Added from the extensions' boot(),
// `artisan optimize` cached those of the extensions there were at the deploy: a gateway created after it had no webhook
// until the next deploy, a deleted one kept its own (2026-11-02, Stripe on the test site). Each answers only while its
// extension boots (ExtensionHelper::bootable), as when it was boot() that added it: a gateway without a record, a
// disabled «other» extension, still 404.
foreach ([...glob(base_path('extensions/*/*/routes.php')) ?: [], ...glob(base_path('extensions/*/*/routes/web.php')) ?: []] as $file) {
    $dir = str_ends_with($file, '/routes/web.php') ? dirname($file, 2) : dirname($file);
    $type = Str::lower(Str::singular(basename(dirname($dir)))); // Gateways → gateway, Others → other, as Extension::$type
    Route::middleware(EnsureExtensionBoots::class . ':' . $type . ',' . basename($dir))->group($file);
}
