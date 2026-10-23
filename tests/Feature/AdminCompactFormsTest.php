<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin's forms, as WHMCS (owner 2026-10-23): the label beside the field, dates picked as d/m/Y. */
class AdminCompactFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pages_form_has_its_labels_beside_the_fields_and_pickers_write_d_m_y(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $client = User::factory()->create()->clients()->firstOrFail();

        $html = (string) $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertOk()->getContent();
        $this->assertStringContainsString('fi-fo-field-has-inline-label', $html);

        $picker = DatePicker::make('due');
        $this->assertFalse($picker->isNative(), 'ours, not the browser\'s in its own locale');
        $this->assertSame('d/m/Y', $picker->getDisplayFormat());
        $this->assertSame('d/m/Y H:i', DateTimePicker::make('at')->getDisplayFormat());
    }
}
