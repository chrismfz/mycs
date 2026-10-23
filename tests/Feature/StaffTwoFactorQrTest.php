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

        // A PNG with imagick (CI), an SVG without (the devbox): either way the image itself, encoded once.
        $this->assertMatchesRegularExpression('#^data:image/(png|svg\+xml);base64,#', $uri);
        $image = (string) base64_decode(substr($uri, strpos($uri, ',') + 1), true);
        $this->assertTrue(str_starts_with($image, "\x89PNG") || str_contains($image, '<svg'), 'the image itself, not another data URI');
    }

    public function test_the_admin_panel_uses_it(): void
    {
        $providers = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

        $this->assertInstanceOf(AppAuthentication::class, array_values($providers)[0]);
    }
}
