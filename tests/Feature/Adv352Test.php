<?php

namespace Tests\Feature;

use App\Admin\Resources\Audits\Pages\ViewAudit;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of #352. A service's password is audited redacted (FullRedactor, "********") but cast `encrypted`:
 * the audit's getModified() casts the stored value through the model, so it decrypts "********" and throws. Both audit
 * views then fail for every password change (the redaction holds: nothing leaks, the page just does not open).
 */
class Adv352Test extends TestCase
{
    use RefreshDatabase;

    private function passwordAudit(): array
    {
        $owner = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $owner->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'password' => 'S3cret!pw']);
        Config::set('audit.console', true);
        $service->fresh()->update(['password' => 'N3w!pw']);
        $audit = Audit::query()->where('auditable_type', $service->getMorphClass())->where('event', 'updated')->latest('id')->firstOrFail();

        return [$service->fresh(), $audit];
    }

    public function test_the_audit_page_of_a_password_change_opens_and_shows_no_password(): void
    {
        [, $audit] = $this->passwordAudit();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->admin()->create());

        Livewire::test(ViewAudit::class, ['record' => $audit->getKey()])
            ->assertOk()
            ->assertSee('password')
            ->assertDontSee('N3w')
            ->assertDontSee('S3cret');
    }

    public function test_the_services_audit_list_opens_after_a_password_change(): void
    {
        [$service] = $this->passwordAudit();

        $html = view('admin.actions.audits', ['record' => $service, 'children' => []])->render();

        $this->assertStringContainsString('password', $html);
        $this->assertStringNotContainsString('N3w', $html);
        $this->assertStringNotContainsString('S3cret', $html);
    }
}
