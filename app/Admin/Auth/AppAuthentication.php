<?php

namespace App\Admin\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication as FilamentAppAuthentication;
use SensitiveParameter;

/**
 * The staff's authenticator app setup, with its QR code drawn. pragmarx/google2fa-qrcode 4 already answers a
 * `data:image/svg+xml;base64,…` URI; Filament's fallback for a PHP without imagick (as here) encodes it again, and the
 * page shows a broken image with only its alt text (owner's report, 2026-10-23).
 */
class AppAuthentication extends FilamentAppAuthentication
{
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        $uri = parent::generateQrCodeDataUri($secret);
        $prefix = 'data:image/svg+xml;base64,';
        // Encoded twice: the inner one is the image.
        if (str_starts_with($uri, $prefix)) {
            $inner = base64_decode(substr($uri, strlen($prefix)), true);
            if (is_string($inner) && str_starts_with($inner, 'data:image/')) {
                return $inner;
            }
        }

        return $uri;
    }
}
