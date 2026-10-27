<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Models\Contact;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A contact entered by its tax id only (a company whose details Ekdosi fills in from the registry, live test of
 * 2026-10-27) is named by it in lists, and can be a service's related contact: a blank option was refused as invalid.
 */
class ContactLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_contact_is_never_named_blank(): void
    {
        $client = User::factory()->create()->client();
        $byTaxId = $client->contacts()->create(['type' => 'business', 'tax_id' => '099206170']);
        $byVat = $client->contacts()->create(['type' => 'business', 'vat_number' => 'DE123456789']);
        $byEmail = $client->contacts()->create(['type' => 'individual', 'email' => 'x@example.test']);
        $named = $client->contacts()->create(['type' => 'business', 'company_name' => 'ROTOGRAN', 'tax_id' => '099206170']);

        $this->assertSame('', $byTaxId->displayName(), 'the account and the invoice keep their own name rules');
        $this->assertSame(__('invoices.tax_id_line', ['tax_id' => '099206170']), $byTaxId->label());
        $this->assertSame(__('invoices.vat_number_line', ['vat_number' => 'DE123456789']), $byVat->label());
        $this->assertSame('x@example.test', $byEmail->label());
        $this->assertSame('ROTOGRAN', $named->label());
    }

    public function test_a_service_takes_a_contact_known_by_its_tax_id_only(): void
    {
        $product = $this->createProduct();
        $client = User::factory()->create()->client();
        $contact = $client->contacts()->create(['type' => 'business', 'tax_id' => '099206170', 'document_type' => 'invoice']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(CreateService::class)
            ->fillForm(['product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'client_id' => $client->id,
                'status' => 'pending', 'quantity' => 1, 'currency_code' => 'USD', 'price' => 10, 'contact_id' => $contact->id])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($contact->id, Service::query()->latest('id')->first()->contact_id);
    }
}
