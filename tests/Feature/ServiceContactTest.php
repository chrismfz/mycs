<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A service's related contact (docs/accounts.md 3b, decided 2026-10-14): a contact of its own account, information only
 * (the invoice stays the account's).
 */
class ServiceContactTest extends TestCase
{
    use RefreshDatabase;

    private function service(?Client $client = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => ($client ?? User::factory()->create()->client())->id,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addMonth()]);
    }

    private function contact(Client $client, string $name = 'Domain Owner'): Contact
    {
        return $client->contacts()->create(['type' => 'individual', 'first_name' => $name, 'last_name' => 'Test', 'email' => 'owner@example.test']);
    }

    public function test_a_contact_of_another_account_is_refused(): void
    {
        $service = $this->service();
        $foreign = $this->contact(User::factory()->create()->client());

        $this->expectException(DisplayException::class);
        $service->update(['contact_id' => $foreign->id]);
    }

    public function test_moving_the_service_to_another_account_leaves_the_contact_behind(): void
    {
        $service = $this->service();
        $service->update(['contact_id' => $this->contact($service->client)->id]);

        $service->update(['client_id' => User::factory()->create()->client()->id]);

        $this->assertNull($service->fresh()->contact_id);
    }

    public function test_staff_set_it_on_the_service_form(): void
    {
        $service = $this->service();
        $contact = $this->contact($service->client);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Svc' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['contact_id' => $contact->id])->call('save')->assertHasNoFormErrors();

        $this->assertSame($contact->id, (int) $service->fresh()->contact_id);
    }

    public function test_the_api_sets_only_a_contact_of_the_account(): void
    {
        $service = $this->service();
        $own = $this->contact($service->client);
        $foreign = $this->contact(User::factory()->create()->client());
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.services.update']]);

        $this->withToken($token)->putJson(route('api.v1.admin.services.update', $service), ['contact_id' => $foreign->id])
            ->assertUnprocessable();
        $this->withToken($token)->putJson(route('api.v1.admin.services.update', $service), ['contact_id' => $own->id])
            ->assertSuccessful()->assertJsonPath('data.attributes.contact_id', $own->id);
    }

    public function test_the_customer_sets_it_on_the_service_page(): void
    {
        $service = $this->service();
        $contact = $this->contact($service->client);
        $owner = $service->client->users()->first();

        $this->livewireAs($owner)->test(ServiceShow::class, ['service' => $service])->set('contactId', $contact->id);

        $this->assertSame($contact->id, (int) $service->fresh()->contact_id);
    }

    public function test_the_customer_cannot_pick_another_accounts_contact(): void
    {
        $service = $this->service();
        $foreign = $this->contact(User::factory()->create()->client());

        $this->livewireAs($service->client->users()->first())->test(ServiceShow::class, ['service' => $service])->set('contactId', $foreign->id);

        $this->assertNull($service->fresh()->contact_id);
    }

    public function test_a_login_without_the_contacts_permission_cannot_set_it(): void
    {
        $service = $this->service();
        $contact = $this->contact($service->client);
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $service->client_id, 'user_id' => $member->id, 'permissions' => ['manageproducts'], 'granted_at' => now()]);

        $this->livewireAs($member)->test(ServiceShow::class, ['service' => $service])
            ->set('contactId', $contact->id)->assertForbidden();

        $this->assertNull($service->fresh()->contact_id);
    }
}
