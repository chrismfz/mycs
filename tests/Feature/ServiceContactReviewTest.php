<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
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
 * Code review of the related contact (PR #116): the tests that failed on c874fa67 (the `contacts` permission checked on
 * the session's account, the name shown without it, the Filament select scoped to the record's account, an archived
 * contact through the API) and those that held.
 */
class ServiceContactReviewTest extends TestCase
{
    use RefreshDatabase;

    private function service(Client $client): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()]);
    }

    private function contact(Client $client, string $name = 'Secret'): Contact
    {
        return $client->contacts()->create(['type' => 'individual', 'first_name' => $name, 'last_name' => 'Person', 'email' => 'o@example.test']);
    }

    private function token(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.services.update']]);

        return $token;
    }

    // Owner of A (current account, all permissions) with a grant on B without `contacts`.
    public function test_contacts_permission_is_checked_on_the_services_account(): void
    {
        $user = User::factory()->create();           // owns A
        $b = User::factory()->create()->client();
        ClientUser::query()->create(['client_id' => $b->id, 'user_id' => $user->id, 'permissions' => ['products', 'manageproducts'], 'granted_at' => now()]);
        $service = $this->service($b);
        $contact = $this->contact($b, 'Hiddenbname');

        $this->livewireAs($user)->test(ServiceShow::class, ['service' => $service])
            ->set('contactId', $contact->id);

        $this->assertNull($service->fresh()->contact_id);
    }

    public function test_a_login_without_contacts_does_not_see_the_contacts_name(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $service = $this->service($client);
        $service->update(['contact_id' => $this->contact($client, 'Leakedname')->id]);
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'permissions' => ['products'], 'granted_at' => now()]);

        $this->livewireAs($member)->test(ServiceShow::class, ['service' => $service])->assertDontSee('Leakedname');
    }

    public function test_api_refuses_an_archived_contact(): void
    {
        $service = $this->service(User::factory()->create()->client());
        $c = $this->contact($service->client);
        $c->forceFill(['archived_at' => now()])->save();
        $this->withToken($this->token())->putJson(route('api.v1.admin.services.update', $service), ['contact_id' => $c->id])
            ->assertUnprocessable();
    }

    public function test_api_moves_account_with_a_contact_of_the_new_one(): void
    {
        $service = $this->service(User::factory()->create()->client());
        $old = $this->contact($service->client);
        $service->update(['contact_id' => $old->id]);
        $b = User::factory()->create()->client();
        $new = $this->contact($b);
        $t = $this->token();
        $this->withToken($t)->putJson(route('api.v1.admin.services.update', $service), ['client_id' => $b->id, 'contact_id' => $old->id])
            ->assertUnprocessable();
        $this->withToken($t)->putJson(route('api.v1.admin.services.update', $service), ['client_id' => $b->id, 'contact_id' => $new->id])
            ->assertSuccessful();
        $this->assertSame($new->id, (int) $service->fresh()->contact_id);
    }

    public function test_api_non_numeric_contact_id(): void
    {
        $service = $this->service(User::factory()->create()->client());
        $this->withToken($this->token())->putJson(route('api.v1.admin.services.update', $service), ['contact_id' => 'abc'])
            ->assertUnprocessable();
    }

    public function test_filament_create_page_offers_the_chosen_clients_contacts(): void
    {
        $client = User::factory()->create()->client();
        $c = $this->contact($client, 'Createname');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Svc' . uniqid(), 'permissions' => ['*']])->id]);
        $lw = $this->livewireAs($staff)->test(CreateService::class)->fillForm(['client_id' => $client->id]);
        $lw->fillForm(['contact_id' => $c->id])->call('create')->assertHasNoFormErrors(['contact_id']);
    }

    public function test_filament_edit_offers_the_new_clients_contacts_after_changing_client(): void
    {
        $service = $this->service(User::factory()->create()->client());
        $b = User::factory()->create()->client();
        $new = $this->contact($b);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Svc' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['client_id' => $b->id, 'contact_id' => $new->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($new->id, (int) $service->fresh()->contact_id);
    }
}
