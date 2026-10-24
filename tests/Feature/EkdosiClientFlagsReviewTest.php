<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Http\Requests\Api\Admin\Clients\UpdateClientRequest;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EkdosiClientFlagsReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_page_keeps_what_staff_toggled(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.clients.create', 'admin.clients.viewAny', 'admin.clients.update'])->create();

        $this->livewireAs($staff)->test(CreateClient::class)
            ->assertSee('Fiscal invoicing (Ekdosi)')
            ->fillForm(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'password' => 'secret-pass-1',
                'ignore_invoicing' => true, 'immediate_invoicing' => true])
            ->call('create')->assertHasNoFormErrors();

        $client = User::query()->where('email', 'ada@example.test')->sole()->client();
        $this->assertSame([true, true], [$client->ignore_invoicing, $client->immediate_invoicing]);
    }

    public function test_the_admin_api_does_not_take_them(): void
    {
        $this->assertArrayNotHasKey('ignore_invoicing', (new UpdateClientRequest)->rules());
    }
}
