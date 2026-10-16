<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff-edited templates go to customers (docs/history/audit-security.md H1): a record printed whole is its JSON (staff
 * notes included) and a model's settings are a module's credentials, decrypted when read. Neither reaches a template;
 * fields still read one by one.
 */
class TemplateLeaksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_service_its_server_and_their_settings_never_print_whole(): void
    {
        $server = Server::query()->create(['name' => 'cPanel One', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $server->settings()->create(['key' => 'api_token', 'value' => 'Module-Secret-T0ken', 'encrypted' => true, 'type' => 'string']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 5,
            'quantity' => 1, 'notes' => 'Staff only: difficult customer', 'domain' => 'shop.example.test']);
        $data = ['service' => $service->fresh()];

        foreach (['{{ service }}', '{{ service.server }}', '{{ service.server.settings }}', '{{ service.product.server.settings }}',
            '{% for s in service.server.settings %}{{ s.value }}{{ s }}{% endfor %}', '{{ service.product }}', '{{ service.configs }}'] as $template) {
            $out = TemplateRenderer::render($template, $data, false);
            foreach (['Module-Secret-T0ken', 'difficult customer', 'api_token'] as $secret) {
                $this->assertStringNotContainsString($secret, $out, $template);
            }
        }

        $this->assertSame('shop.example.test|cPanel One', TemplateRenderer::render('{{ service.domain }}|{{ service.server.name }}', $data, false), 'fields still read');
        $this->assertSame('yes', TemplateRenderer::render('{% if service.server %}yes{% endif %}', $data, false), 'and test');
    }
}
