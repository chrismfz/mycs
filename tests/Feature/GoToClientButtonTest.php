<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-11-16: the client field's «Go to Client» is a button, not gray text staff miss. */
class GoToClientButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoice_page_shows_a_button_to_its_client(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        $html = $this->get(InvoiceResource::getUrl('edit', ['record' => $invoice]))->assertOk()->getContent();

        $this->assertStringContainsString(__('Go to Client'), $html);
        $this->assertStringContainsString('href="' . ClientResource::getUrl('view', ['record' => $client]) . '"', $html);
        $this->assertMatchesRegularExpression('/<a[^>]+fi-btn[^>]+href="' . preg_quote(ClientResource::getUrl('view', ['record' => $client]), '/') . '"|href="' . preg_quote(ClientResource::getUrl('view', ['record' => $client]), '/') . '"[^>]+fi-btn/', $html, 'a button, not a text link');
    }
}
