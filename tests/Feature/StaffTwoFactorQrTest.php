<?php

namespace Tests\Feature;

use App\Admin\Auth\AppAuthentication;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The staff's 2FA setup shows its QR code (it was a broken image: the SVG encoded twice without imagick). */
class StaffTwoFactorQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_qr_code_is_an_svg_image_once_encoded(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->withoutTwoFactor()->create(), 'staff');

        $uri = AppAuthentication::make()->generateQrCodeDataUri('JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $svg = (string) base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertStringContainsString('<svg', $svg, 'the image itself, not another data URI');
    }

    public function test_the_admin_panel_uses_it(): void
    {
        $providers = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

        $this->assertInstanceOf(AppAuthentication::class, array_values($providers)[0]);
    }
}
