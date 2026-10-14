<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles holding permission names nothing checks (R2 of #84): the upstream rename of email templates to notification
 * templates, and «audits» where the code checks «audit». Given the names the code checks, so they keep what they meant.
 */
return new class extends Migration
{
    private const RENAMES = [
        'admin.audits.viewAny' => 'admin.audit.viewAny',
        'admin.email_templates.create' => 'admin.notification_templates.create',
        'admin.email_templates.update' => 'admin.notification_templates.update',
        'admin.email_templates.viewAny' => 'admin.notification_templates.viewAny',
        'admin.email_templates.delete' => 'admin.notification_templates.delete',
        'admin.email_templates.deleteAny' => 'admin.notification_templates.deleteAny',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    /** @param  array<string, string>  $renames */
    private function rename(array $renames): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (!is_array($permissions)) {
                continue;
            }
            $renamed = array_values(array_unique(array_map(fn ($name) => $renames[$name] ?? $name, $permissions)));
            if ($renamed !== $permissions) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($renamed)]);
            }
        }
    }
};
