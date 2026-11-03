<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\Services\Index as ServicesIndex;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Support\ServiceIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What tells a service apart (docs/ROADMAP.md «Τι ξεχωρίζει κάθε υπηρεσία», owner 2026-11-03): one helper for the
 * services list, its page, the invoice lines and its mails (Service::mailName, whose own tests keep its rules).
 */
class ServiceIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function service(array $attributes = [], array $properties = []): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => null]);
        foreach ($properties as $key => $value) {
            $service->properties()->create(['key' => $key, 'name' => $key, 'value' => $value]);
        }

        return $service->fresh();
    }

    public function test_hosting_by_its_domain_a_server_by_its_hostname_and_ip(): void
    {
        $this->assertSame(['shop.example.gr'], ServiceIdentifier::parts($this->service(['domain' => 'shop.example.gr'])));
        $this->assertSame('vps1.example.gr · 203.0.113.10', ServiceIdentifier::line($this->service(['domain' => 'vps1.example.gr', 'dedicated_ip' => '203.0.113.10'])));
        $this->assertSame('203.0.113.11', ServiceIdentifier::line($this->service(['dedicated_ip' => '203.0.113.11'])));
    }

    public function test_without_a_domain_its_panels_username_or_hostname_never_another_property(): void
    {
        $radio = $this->service([], ['centova_username' => 'radio-example', 'centova_password' => 'secret-pass']);
        $this->assertSame(['radio-example'], ServiceIdentifier::parts($radio));

        $pending = $this->service([], ['cpanel_pending_username' => 'notyet', 'password' => 'secret-pass']);
        $this->assertSame([], ServiceIdentifier::parts($pending), 'no pending username, never a password');

        $vm = $this->service(['dedicated_ip' => '203.0.113.12'], ['hostname' => 'vm7.example.gr']);
        $this->assertSame('vm7.example.gr · 203.0.113.12', ServiceIdentifier::line($vm));

        $hosting = $this->service(['domain' => 'site.example.gr'], ['cpanel_username' => 'siteuser']);
        $this->assertSame(['site.example.gr'], ServiceIdentifier::parts($hosting), 'a domain says it: not its username too');
    }

    public function test_a_domain_with_no_dot_is_a_key_masked(): void
    {
        $this->assertSame(['ZC-…7Q2F'], ServiceIdentifier::parts($this->service(['domain' => 'ZC-ABCDEFGH7Q2F'])));
    }

    public function test_a_license_by_its_label_and_ip_else_its_key_masked(): void
    {
        $named = $this->service(['domain' => 'NIXPAL-ABCDEFGH1234', 'label' => 'Office']);
        License::query()->create(['service_id' => $named->id, 'license_key' => 'NIXPAL-ABCDEFGH1234', 'status' => 'active', 'valid_ip' => '198.51.100.7,198.51.100.8']);
        $this->assertSame('Office · 198.51.100.7 +1', ServiceIdentifier::line($named->fresh()));

        $bare = $this->service(['domain' => 'NIXPAL-ABCDEFGH5678', 'dedicated_ip' => '198.51.100.9']);
        License::query()->create(['service_id' => $bare->id, 'license_key' => 'NIXPAL-ABCDEFGH5678', 'status' => 'active']);
        $this->assertSame(['NIXPAL-…5678'], ServiceIdentifier::parts($bare->fresh()), 'a license is told by its own IPs, not the service\'s');

        // The licensing module's service with no license row yet: its domain is its key, masked.
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server']);
        $pending = $this->service(['domain' => 'NIXPAL-ABCDEFGH9999']);
        $pending->product->forceFill(['server_id' => $server->id])->save();
        $this->assertSame(['NIXPAL-…9999'], ServiceIdentifier::parts($pending->fresh()));
    }

    public function test_with_nothing_its_own_label_and_never_its_name_twice(): void
    {
        $labelled = $this->service(['label' => 'Office site']);
        $this->assertSame(['Office site'], ServiceIdentifier::parts($labelled));
        $this->assertSame('Office site', ServiceIdentifier::named($labelled, 'Office site'));
        $this->assertSame('', ServiceIdentifier::besides($labelled, 'Office site'));
        $this->assertSame([], ServiceIdentifier::parts($this->service()));

        $long = $this->service(['domain' => str_repeat('a', 90) . '.example.gr']);
        $this->assertSame(83, mb_strlen(ServiceIdentifier::line($long, 80)), '80 and the ellipsis');
        $this->assertSame('Hosting — shop.example.gr', ServiceIdentifier::named($this->service(['domain' => 'shop.example.gr']), 'Hosting'));
    }

    public function test_an_invoice_line_says_what_its_text_does_not(): void
    {
        $service = $this->service(['domain' => 'vps1.example.gr', 'dedicated_ip' => '203.0.113.10']);
        $line = fn (string $text) => tap(new InvoiceItem(['description' => $text, 'reference_type' => Service::class, 'reference_id' => $service->id]),
            fn (InvoiceItem $item) => $item->setRelation('reference', $service));

        $this->assertSame('vps1.example.gr · 203.0.113.10', ServiceIdentifier::forLine($line('VPS S (01/11/2026 - 30/11/2026)')));
        $this->assertSame('203.0.113.10', ServiceIdentifier::forLine($line('VPS S - VPS1.example.gr (01/11/2026)')), 'any case');
        $this->assertSame('', ServiceIdentifier::forLine($line('VPS S - vps1.example.gr 203.0.113.10')));

        $upgrade = new ServiceUpgrade;
        $upgrade->setRelation('service', $service);
        $item = new InvoiceItem(['description' => 'Upgrade to VPS M', 'reference_type' => ServiceUpgrade::class]);
        $item->setRelation('reference', $upgrade);
        $this->assertSame('vps1.example.gr · 203.0.113.10', ServiceIdentifier::forLine($item), 'an upgrade\'s line, by its service');

        $gone = new InvoiceItem(['description' => 'Deleted', 'reference_type' => Service::class, 'reference_id' => 999999]);
        $this->assertSame('', ServiceIdentifier::forLine($gone));
        $this->assertSame('', ServiceIdentifier::forLine(new InvoiceItem(['description' => 'A fee'])));
    }

    public function test_the_services_list_and_the_service_page_say_it(): void
    {
        $a = $this->service(['domain' => 'shop.example.gr']);
        $b = $this->service(['domain' => 'blog.example.gr']);

        $this->livewireAs($this->user)->test(ServicesIndex::class)->assertSee('shop.example.gr')->assertSee('blog.example.gr');
        $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $a])
            ->assertSee($a->label . ' — shop.example.gr')->assertDontSee('blog.example.gr');
        $this->assertNotSame($a->label, $b->label);
    }

    public function test_the_invoice_page_and_the_invoices_list_say_it(): void
    {
        $service = $this->service(['domain' => 'shop.example.gr']);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting Plus (01/11/2026 - 30/11/2026)', 'price' => '10.00', 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $service->id]);

        $this->livewireAs($this->user)->test(InvoiceShow::class, ['invoice' => $invoice])->assertSee('shop.example.gr');
        $this->livewireAs($this->user)->test(InvoicesIndex::class)->assertSee('Hosting Plus (01/11/2026 - 30/11/2026) — shop.example.gr');
    }
}
