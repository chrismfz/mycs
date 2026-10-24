<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

/**
 * The client's 2FA QR (Client\Security): robthree's provider over endroid/qr-code, whose major versions differ. It must
 * still give a PNG an authenticator app can scan.
 */
class TwoFactorQrImageTest extends TestCase
{
    public function test_the_qr_is_a_png_data_uri(): void
    {
        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider, 'MyCS');

        $uri = $tfa->getQRCodeImageAsDataUri('client@example.test', $tfa->createSecret());

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $png = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        $this->assertIsString($png);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $this->assertSame([200, 200], array_slice((array) getimagesizefromstring($png), 0, 2));
    }
}
