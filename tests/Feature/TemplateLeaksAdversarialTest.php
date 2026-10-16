<?php

namespace Tests\Feature;

use App\Events\Invoice\GeneratePdf;
use App\Helpers\TemplateRenderer;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Adversarial review of PR #158 (staff-edited templates, docs/history/audit-security.md H1). Each test is a path a
 * staff-edited template, mailed to a customer, can still take after the fix.
 */
class TemplateLeaksAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1]);
    }

    /** A1: every Auditable model has a typed `audits()` MorphMany: the audit trail holds old/new values of hidden fields. */
    public function test_a1_audits_do_not_leak_staff_notes_or_staff_identity(): void
    {
        $service = $this->service(['notes' => 'Staff only: difficult customer']);
        $staff = Staff::factory()->create(['email' => 'staff-member@example.test']);
        $service->audits()->create(['event' => 'updated', 'user_type' => $staff->getMorphClass(), 'user_id' => $staff->id,
            'old_values' => ['notes' => null], 'new_values' => ['notes' => 'Staff only: difficult customer'],
            'ip_address' => '203.0.113.77', 'url' => 'https://example.test/admin', 'user_agent' => 'x']);

        $out = TemplateRenderer::render('{% for a in service.audits %}[{{ a.new_values.notes }}|{{ a.ip_address }}|{{ a.user.email }}]{% endfor %}',
            ['service' => $service->fresh()], false);

        $this->assertStringNotContainsString('difficult customer', $out, 'service notes through service.audits');
        $this->assertStringNotContainsString('203.0.113.77', $out, 'staff IP through audits');
        $this->assertStringNotContainsString('staff-member@example.test', $out, 'staff email through audits.user');
    }

    /** A2: User is Auditable without strict mode or exclusions: its audits keep the password hash and 2FA secret. */
    public function test_a2_user_audits_do_not_leak_password_hash(): void
    {
        $user = User::factory()->create();
        $hash = $user->getRawOriginal('password');
        $user->audits()->create(['event' => 'updated', 'old_values' => ['password' => 'old-hash'], 'new_values' => ['password' => $hash, 'tfa_secret' => 'TFA-SECRET-VALUE'],
            'url' => 'x', 'ip_address' => '127.0.0.1', 'user_agent' => 'x']);

        $out = TemplateRenderer::render('{% for a in user.audits %}{% for v in a.new_values %}[{{ v }}]{% endfor %}{% endfor %}', ['user' => $user->fresh()], false);

        $this->assertStringNotContainsString($hash, $out);
        $this->assertStringNotContainsString('TFA-SECRET-VALUE', $out);
    }

    /** A3: a login shared by two accounts (an accountant): client A's mail walks client.users.clients into client B. */
    public function test_a3_shared_login_does_not_lead_to_another_customers_records(): void
    {
        $mine = $this->service(['domain' => 'mine.example.test']);
        $other = $this->service(['domain' => 'someone-else.example.test']);
        $accountant = User::factory()->create();
        foreach ([$mine->client_id, $other->client_id] as $clientId) {
            DB::table('client_user')->insert(['client_id' => $clientId, 'user_id' => $accountant->id, 'owner' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        $out = TemplateRenderer::render('{% for u in service.client.users %}{% for c in u.clients %}{% for s in c.services %}{{ s.domain }} {% endfor %}{{ c.email }} {% endfor %}{% endfor %}',
            ['service' => $mine->fresh()], false);

        // Before the fix the path printed both accounts' services; now `client.users` is not readable at all.
        $this->assertStringNotContainsString('someone-else', $out, 'another customer\'s service through a shared login');
        $this->assertStringNotContainsString(Client::find($other->client_id)->email, $out, 'another customer\'s email');
    }

    /** A4: the reason staff wrote for a credit adjustment (money_event_notes, staff-only) through invoice.moneyEvents. */
    public function test_a4_money_event_staff_note_does_not_reach_a_template(): void
    {
        $service = $this->service();
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id]);
        $eventId = DB::table('money_events')->insertGetId(['kind' => 'credit_in', 'amount' => 5, 'currency_code' => 'USD', 'client_id' => $service->client_id,
            'invoice_id' => $invoice->id, 'source' => 'staff', 'reason' => 'Internal: customer threatened chargeback', 'occurred_at' => now()]);
        DB::table('money_event_notes')->insert(['money_event_id' => $eventId, 'note' => 'Internal: goodwill, do not repeat', 'created_at' => now(), 'updated_at' => now()]);

        $out = TemplateRenderer::render('{% for e in invoice.moneyEvents %}[{{ e.reason }}|{{ e.note.note }}]{% endfor %}', ['invoice' => $invoice->fresh()], false);

        $this->assertStringNotContainsString('do not repeat', $out);
        $this->assertStringNotContainsString('threatened chargeback', $out);
    }

    /** A5: an accessor is still code: `{{ invoice.pdf }}` fires GeneratePdf to every extension and renders the PDF view. */
    public function test_a5_invoice_pdf_accessor_does_not_run_from_a_template(): void
    {
        $service = $this->service();
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id]);
        Event::fake([GeneratePdf::class]);

        TemplateRenderer::render('{% if invoice.pdf %}x{% endif %}', ['invoice' => $invoice->fresh()], false);

        Event::assertNotDispatched(GeneratePdf::class);
    }

    /** Holds: the paths the seeded templates use still render. */
    public function test_seeded_paths_still_render(): void
    {
        $service = $this->service(['domain' => 'shop.example.test', 'username' => 'shopuser']);
        $out = TemplateRenderer::render('{{ service.product.name }}|{{ service.username }}|{% for item in items %}{{ item.product.name }}/{{ item.description }}/{{ item.quantity }}/{{ item.formattedPrice }}{% endfor %}|{{ user.first_name }}',
            ['service' => $service->fresh(), 'items' => collect([$service->fresh()]), 'user' => User::factory()->create(['first_name' => 'Maria'])], false);

        $this->assertStringContainsString('shopuser|', $out);
        $this->assertStringContainsString($service->product->name . '|', $out);
        $this->assertStringEndsWith('|Maria', $out);
        $this->assertDoesNotMatchRegularExpression('#//1/\|#', $out, 'description and price render');
    }
}
