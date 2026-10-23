<?php

namespace Tests\Feature;

use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Models\License;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\Extensions\Others\Affiliates\Admin\Resources\AffiliateResource\Pages\EditAffiliate;
use MyCS\Extensions\Others\Affiliates\Admin\Resources\AffiliateResource\RelationManagers\AffiliatesRelationManager;
use MyCS\Extensions\Others\Affiliates\Models\Affiliate;
use Tests\TestCase;

/** Adversarial review of the staff hardening, group 2 (SA-7, SA-10, SA-11, SA-12). */
class AdvStaffGroup2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * The key is masked for staff who only view services, but the column's substring search answers «does a key
     * contain X»: the hidden middle comes back one character at a time (16 tries per hex digit).
     */
    public function test_a_masked_license_key_cannot_be_recovered_through_a_partial_search(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'currency_code' => 'USD']);
        $license = License::query()->create(['service_id' => $service->id, 'license_key' => 'ZCAgent-0123456789abcdef', 'status' => License::STATUS_ACTIVE]);

        $page = $this->livewireAs(Staff::factory()->withPermissions(['admin.services.viewAny'])->create())->test(ListLicenses::class);

        // What the viewer sees (prefix…last4) plus a guessed next digit: a hit confirms the guess.
        $page->searchTable('ZCAgent-0')->assertCanNotSeeTableRecords([$license]);
        $page->searchTable('ZCAgent-01234')->assertCanNotSeeTableRecords([$license]);
    }

    /**
     * Strict authorization refuses a relation manager whose related model has no policy with a LogicException, not a
     * deny: the Affiliates extension's «orders» (AffiliateOrder has none) turns the affiliate's edit page into a 500.
     */
    public function test_the_affiliate_edit_page_relation_manager_does_not_throw_under_strict_authorization(): void
    {
        $this->actingAs(Staff::factory()->admin()->create(), 'staff');

        $visible = AffiliatesRelationManager::canViewForRecord(new Affiliate, EditAffiliate::class);

        $this->assertTrue($visible);
    }
}
