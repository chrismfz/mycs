<?php

namespace App\Http\Controllers;

use App\Exceptions\ServerRefused;
use App\Models\Service;
use App\Models\SslVersion;
use App\Services\Ssl\SslVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * An SSL service's files from the client area (docs/ssl-vault.md): its certificate, CA bundle, full chain and CSR to
 * whoever sees the service; its private key and .pfx only to a login that manages it (staff logged in as the client
 * also need the key permission), a few an hour, each one audited.
 */
class SslFileController extends Controller
{
    private const KEY_DOWNLOADS_PER_HOUR = 20;

    public function download(Service $service, SslVersion $version, string $file): Response
    {
        $this->ofService($service, $version);
        if ($file === 'key') {
            $this->keyAllowed($service);
            abort_unless($version->hasKey(), 404);

            return $this->file(app(SslVault::class)->keyPem($version, 'portal'), $version->fileName('key'), 'application/x-pem-file');
        }
        $content = $version->publicFile($file);
        abort_if($content === null, 404);

        return $this->file($content, $version->fileName($file), 'application/x-pem-file');
    }

    public function pfx(Request $request, Service $service, SslVersion $version): Response|RedirectResponse
    {
        $this->ofService($service, $version);
        $this->keyAllowed($service);
        abort_if(blank($version->certificate) || !$version->hasKey(), 404);
        $request->validate(['password' => ['required', 'string', 'min:8', 'max:128', 'confirmed']]);
        try {
            $pfx = app(SslVault::class)->pfx($version, (string) $request->input('password'), 'portal');
        } catch (ServerRefused $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        return $this->file($pfx, preg_replace('/\.key$/', '.pfx', $version->fileName('key')), 'application/x-pkcs12');
    }

    /** The version is this service's (its order's), never another's by its id. */
    private function ofService(Service $service, SslVersion $version): void
    {
        abort_unless($version->order?->service_id === $service->id, 404);
    }

    private function keyAllowed(Service $service): void
    {
        abort_unless(SslVault::customerMayReadKey($service), 403);
        $key = 'ssl-key-download:' . $service->id;
        abort_if(RateLimiter::tooManyAttempts($key, self::KEY_DOWNLOADS_PER_HOUR), 429);
        RateLimiter::hit($key, 3600);
    }

    private function file(string $content, string $name, string $type): Response
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
