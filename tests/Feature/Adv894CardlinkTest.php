<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\Cardlink\Cardlink;
use MyCS\Extensions\Gateways\Cardlink\CardlinkOrder;
use Nixpal\CardlinkEurobank\Tests\Fixtures;
use Tests\TestCase;

/**
 * Adversarial review of #894 (the Cardlink pay form sent by Alpine's x-init on the form, not a Livewire @script).
 * ADV-894-1 fails on the PR head; the others confirm what the PR relies on.
 */
class Adv894CardlinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'Cardlink'], ['name' => 'Cardlink', 'type' => 'gateway', 'enabled' => true]);
        $config = ['merchant_id' => Fixtures::MID, 'shared_secret' => Fixtures::SECRET, 'test_mode' => '1'];
        foreach ($config as $key => $value) {
            $this->gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Cardlink($config))->boot();
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 25]);

        return $invoice->fresh();
    }

    private function payPage(Invoice $invoice): string
    {
        return $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment')
            ->html();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function form(DOMXPath $xpath): DOMElement
    {
        $forms = $xpath->query('//form[@id="cardlink-form"]');
        $this->assertSame(1, $forms->length, 'exactly one Cardlink form on the page');
        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);

        return $form;
    }

    /**
     * ADV-894-1 (low): x-init now submits the form at once, and the «continue» button stays live under the «you are
     * being sent to the bank» text until the vPOS answers. A customer who clicks it meanwhile (the text invites it) sends
     * a second POST with the same orderid and digest: the browser drops the first navigation, the vPOS gets both, and
     * the page the customer lands on is the answer to the duplicate order. Before #894 the button was the only submit.
     */
    public function test_adv_894_1_the_continue_button_is_not_a_second_submit_while_the_auto_submit_runs(): void
    {
        $form = $this->form($this->xpath($this->payPage($this->invoice())));
        $markup = $form->ownerDocument->saveHTML($form);

        $guarded = (bool) preg_match('/(?:x-on:submit|@submit)[.=]/', $markup)                 // a submit guard on the form
            || (bool) preg_match('/(?::|x-bind:)disabled=/', $markup)                           // the button bound disabled
            || (bool) preg_match('/x-init="[^"]*(disabled|remove|hidden)/', $markup);            // or x-init takes it away

        $this->assertTrue($guarded, 'the continue button is a live second submit of the same orderid while x-init sends the form');
    }

    /** Confirms: the form is the only thing x-init runs on, with its own scope, once, and no @script is left for it. */
    public function test_one_form_one_auto_submit_no_script_effect(): void
    {
        $html = $this->payPage($this->invoice());
        $form = $this->form($this->xpath($html));

        $this->assertTrue($form->hasAttribute('x-data'));
        $this->assertSame('$el.submit()', $form->getAttribute('x-init'));
        $this->assertSame(1, substr_count($html, '$el.submit()'));
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
    }

    /**
     * Confirms: x-teleport clones only the template's first element child; the form has to sit inside it, or it is never
     * on the page (and x-init never runs).
     */
    public function test_the_form_is_inside_what_the_modal_teleports(): void
    {
        $xpath = $this->xpath($this->payPage($this->invoice()));
        $templates = $xpath->query('//template[@x-teleport]');
        $this->assertSame(1, $templates->length);
        $template = $templates->item(0);
        $elements = $xpath->query('./*', $template);
        $this->assertSame(1, $elements->length, 'the teleported template has one root element');
        $this->assertSame(1, $xpath->query('.//form[@id="cardlink-form"]', $elements->item(0))->length);
    }

    /**
     * Confirms: no field can shadow form.submit (an input named «submit» would turn $el.submit() into a TypeError), and
     * the customer's own values (address, email) are escaped, never markup or Alpine attributes.
     */
    public function test_no_field_shadows_submit_and_customer_values_are_escaped(): void
    {
        $client = $this->user->client();
        $payload = '"><script>alert(1)</script><input name="submit" x-init="alert(2)';
        $contact = $client->primaryContact()->first();
        if ($contact !== null) {
            $contact->update(['address1' => $payload, 'city' => '{{ 7*7 }}', 'postcode' => "'\"<b>"]);
        }
        $html = $this->payPage($this->invoice());
        $form = $this->form($xpath = $this->xpath($html));

        $names = [];
        foreach ($xpath->query('.//input|.//button|.//select|.//textarea', $form) as $field) {
            $this->assertInstanceOf(DOMElement::class, $field);
            $names[] = $field->getAttribute('name');
            $this->assertNotContains($field->getAttribute('name'), ['submit', 'action', 'method', 'id', 'x-init']);
        }
        $this->assertContains('digest', $names);
        $this->assertSame(0, $xpath->query('.//script', $form)->length);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('x-init="alert(2)', $html);
        $this->assertSame(1, $xpath->query('descendant-or-self::*[@x-init]', $form)->length, 'no x-init in the form but its own');
        $this->assertNotNull($contact, 'the account has a primary contact to carry the hostile values');
        $address = $xpath->query('.//input[@name="billAddress"]', $form);
        $this->assertSame(1, $address->length);
        $input = $address->item(0);
        $this->assertInstanceOf(DOMElement::class, $input);
        $this->assertStringStartsWith('"><script>', $input->getAttribute('value'));
    }

    /** Confirms: each «Pay» keeps one order and the page carries only the latest one's form. */
    public function test_a_second_pay_shows_only_the_latest_order(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment');
        $first = CardlinkOrder::query()->sole()->order_id;
        $html = $page->call('processPayment')->html();

        $this->assertSame(2, CardlinkOrder::query()->count());
        $latest = CardlinkOrder::query()->where('order_id', '!=', $first)->sole()->order_id;
        $form = $this->form($xpath = $this->xpath($html));
        $orderIds = array_map(fn ($input) => $input->getAttribute('value'), iterator_to_array($xpath->query('.//input[@name="orderid"]', $form)));
        $this->assertSame([$latest], $orderIds);
    }

    /**
     * Confirms the PR's claim: Stripe's and PayPal's @script touch the page only inside the SDK script's onload, which
     * fires (a task, after the network) after Livewire's morph of that render.
     */
    public function test_stripe_and_paypal_scripts_touch_the_dom_only_after_the_sdk_loads(): void
    {
        foreach (['Stripe/resources/views/pay.blade.php', 'Stripe/resources/views/billing-agreement.blade.php', 'PayPal/resources/views/pay.blade.php'] as $view) {
            $source = (string) file_get_contents(base_path('extensions/Gateways/' . $view));
            $this->assertMatchesRegularExpression('/@script(.*)@endscript/s', $source);
            preg_match('/@script(.*)@endscript/s', $source, $script);
            $before = strstr($script[1], '.onload', true);
            $this->assertNotFalse($before, $view . ' waits for onload');
            $this->assertDoesNotMatchRegularExpression('/getElementById|querySelector|\.mount\(|\.render\(/', $before, $view);
        }
    }
}
