<?php

namespace Tests\Feature;

use App\Admin\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 2026-11-18: a mail's text sees its data (a reset or invitation link, a new server's password, a guest's ticket
 * link): with «edit templates» an image in it would send them out. Templates are a full admin's text; others with the
 * permission read them.
 */
class PrivateTemplateEditTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $key): NotificationTemplate
    {
        return NotificationTemplate::query()->firstOrCreate(['key' => $key], ['name' => $key, 'subject' => 'S', 'body' => 'B',
            'enabled' => true, 'mail_enabled' => 'force', 'in_app_enabled' => 'never']);
    }

    public function test_only_a_full_admin_edits_creates_or_deletes_a_template(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $editor = Staff::factory()->withPermissions(['admin.notification_templates.viewAny', 'admin.notification_templates.create',
            'admin.notification_templates.update', 'admin.notification_templates.delete', 'admin.notification_templates.deleteAny'])->create();
        $admin = Staff::factory()->admin()->create();

        foreach (['password_reset', 'client_invite', 'new_server_created', 'ticket_link', 'new_invoice_created'] as $key) {
            $template = $this->template($key);
            $this->assertTrue($editor->can('view', $template), $key);
            $this->assertFalse($editor->can('update', $template), $key);
            $this->assertFalse($editor->can('delete', $template), $key);
            $this->assertTrue($admin->can('update', $template), $key);
            // Filament: a page the policy refuses is not found.
            $this->actingAs($editor)->get(NotificationTemplateResource::getUrl('edit', ['record' => $template]))->assertNotFound();
        }
        $this->assertFalse($editor->can('create', NotificationTemplate::class));
        $this->assertFalse($editor->can('deleteAny', NotificationTemplate::class));
    }
}
