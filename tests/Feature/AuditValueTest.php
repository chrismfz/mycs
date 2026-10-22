<?php

namespace Tests\Feature;

use App\Admin\Resources\Audits\Pages\ViewAudit;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Role;
use App\Models\Staff;
use App\Support\AuditValue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** An audited value is printed as text in both audit views: a list or an enum never makes them fail (review of #351). */
class AuditValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_values_are_printed_as_text(): void
    {
        $this->assertSame('', AuditValue::text(null));
        $this->assertSame('', AuditValue::text(false));
        $this->assertSame('1', AuditValue::text(true));
        $this->assertSame('0', AuditValue::text(0));
        $this->assertSame('1.5', AuditValue::text(1.5));
        $this->assertSame('plain', AuditValue::text('plain'));
        $this->assertSame(DomainStatus::cases()[0]->value, AuditValue::text(DomainStatus::cases()[0]), 'an enum by its value, no quotes');
        $this->assertSame('["a","b <c>"]', AuditValue::text(['a', 'b <c>']));
        $this->assertSame('{"k":"ä"}', AuditValue::text(['k' => 'ä']));
    }

    public function test_the_audits_page_of_a_roles_permission_change_opens(): void
    {
        Config::set('audit.console', true);
        $role = Role::query()->create(['name' => 'Support', 'permissions' => ['admin.tickets.viewAny']]);
        $role->update(['permissions' => ['admin.tickets.viewAny', '<b>admin.tickets.update</b>']]);
        $audit = Audit::query()->where('auditable_type', $role->getMorphClass())->where('event', 'updated')->sole();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->admin()->create());

        Livewire::test(ViewAudit::class, ['record' => $audit->getKey()])
            ->assertOk()
            ->assertSee('admin.tickets.update', false)
            ->assertDontSee('<b>admin.tickets.update</b>', false);
    }

    public function test_the_records_audit_list_prints_an_enum_by_its_value(): void
    {
        Config::set('audit.console', true);
        [$from, $to] = [DomainStatus::cases()[0], DomainStatus::cases()[1]];
        $domain = Domain::factory()->create(['status' => $from]);
        $domain->update(['status' => $to]);

        $html = view('admin.actions.audits', ['record' => $domain->fresh(), 'children' => []])->render();

        $this->assertStringContainsString($to->value, $html);
        $this->assertStringNotContainsString('&quot;' . $to->value . '&quot;', $html, 'no quotes around an enum');
    }
}
