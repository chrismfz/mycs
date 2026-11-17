<?php

namespace Tests\Feature;

use App\Admin\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 2026-11-18: the text of a mail that carries a login link or a password (password reset, invitation, welcome) is
 * a full admin's only. With «edit templates» alone an image in it would send the reset link or the server's password out.
 */
class PrivateTemplateEditTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $key): NotificationTemplate
    {
        return NotificationTemplate::query()->firstOrCreate(['key' => $key], ['name' => $key, 'subject' => 'S', 'body' => 'B',
            'enabled' => true, 'mail_enabled' => 'force', 'in_app_enabled' => 'never']);
    }

    public function test_only_a_full_admin_edits_or_deletes_a_private_template(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $editor = Staff::factory()->withPermissions(['admin.notification_templates.viewAny', 'admin.notification_templates.update',
            'admin.notification_templates.delete'])->create();
        $admin = Staff::factory()->admin()->create();
        $invoice = $this->template('new_invoice_created');

        foreach (['password_reset', 'client_invite', 'email_verification', 'new_server_created'] as $key) {
            $private = $this->template($key);
            $this->assertFalse($editor->can('update', $private), $key);
            $this->assertFalse($editor->can('delete', $private), $key);
            $this->assertTrue($admin->can('update', $private), $key);
            $this->actingAs($editor)->get(NotificationTemplateResource::getUrl('edit', ['record' => $private]))->assertNotFound(); // Filament: a page the policy refuses is not found
        }
        $this->assertTrue($editor->can('update', $invoice));
        $this->actingAs($editor)->get(NotificationTemplateResource::getUrl('edit', ['record' => $invoice]))->assertOk();
    }
}
