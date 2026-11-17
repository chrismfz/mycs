<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2026-11-17 (review of #894): Stripe's pay and billing-agreement windows put the customer's name and email in their
 * script. Blade's {{ }} is HTML escaping, not JavaScript: a name ending in a backslash (or with a line break) broke the
 * string and the whole script, and no card form came up. They are written as JavaScript (@js) now.
 */
class StripeViewsJsTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function views(): iterable
    {
        yield 'pay' => ['pay', []];
        yield 'billing agreement' => ['billing-agreement', ['type' => 'setup']];
    }

    /** @param  array<string, mixed>  $extra */
    #[DataProvider('views')]
    public function test_the_customer_name_and_email_are_javascript_strings(string $view, array $extra): void
    {
        $user = User::factory()->create(['first_name' => 'Ann "AB"', 'last_name' => "O'Neil \\"]);
        $name = $user->name;
        $this->assertStringEndsWith('\\', $name);

        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID]);
        $page = $this->livewireAs($user)->test(StripeViewsJsHost::class, ['view' => $view, 'extra' => $extra, 'invoiceId' => $invoice->id]);
        $html = implode("\n", array_map('strval', (array) ($page->effects['scripts'] ?? [])));
        $this->assertStringContainsString('js.stripe.com', $html, 'the script came with the render');

        $this->assertStringContainsString('name: ' . Js::from($name)->toHtml() . ',', $html);
        $this->assertStringContainsString('email: ' . Js::from($user->email)->toHtml() . ',', $html);
        $this->assertStringNotContainsString('\\",', $html, 'no backslash left to escape the closing quote');
    }
}

/** Renders a Stripe window as its gateway's pay() view is rendered: inside a Livewire component (its @script). */
class StripeViewsJsHost extends Component
{
    public string $view = 'pay';

    /** @var array<string, mixed> */
    public array $extra = [];

    public int $invoiceId = 0;

    public function render(): View
    {
        return view()->file(base_path('extensions/Gateways/Stripe/resources/views/' . $this->view . '.blade.php'), [
            'intent' => (object) ['client_secret' => 'pi_test_secret'], 'stripePublishableKey' => 'pk_test',
            'invoice' => Invoice::query()->findOrFail($this->invoiceId),
        ] + $this->extra);
    }
}
