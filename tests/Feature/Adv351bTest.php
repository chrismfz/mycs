<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use OwenIt\Auditing\Events\AuditCustom;
use Tests\TestCase;

/** Adversarial re-review of #351 (abcb104a: the unlock's custom audit event). */
class Adv351bTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The unlock's audit row holds `changes` as a list. The member's «Audits» action in the admin (EditStaff) renders
     * every modified value with {{ $value['new'] }}, i.e. e(array): a TypeError, so after a break-glass the member's
     * whole audit history no longer opens in the admin, the very place the row was added to be seen.
     */
    public function test_the_members_audit_history_still_renders_after_an_unlock(): void
    {
        Mail::fake();
        $staff = Staff::factory()->admin()->create(['email' => 'admin@example.test', 'password' => Hash::make('Old-Pass-123')]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--reset-2fa' => true, '--force' => true])->assertSuccessful();

        $html = view('admin.actions.audits', ['record' => $staff->fresh(), 'children' => []])->render();

        $this->assertStringContainsString('unlocked_from_console', $html);
        $this->assertStringContainsString('2FA reset', $html);
    }

    public function test_an_audit_with_a_list_value_still_renders(): void
    {
        $staff = Staff::factory()->admin()->create();
        $staff->auditEvent = 'probe';
        $staff->isCustomEvent = true;
        $staff->auditCustomOld = ['tags' => ['a']];
        $staff->auditCustomNew = ['tags' => ['a', 'b <c>']];
        event(new AuditCustom($staff));
        Config::set('audit.console', true);

        $html = view('admin.actions.audits', ['record' => $staff->fresh(), 'children' => []])->render();

        $this->assertStringContainsString('[&quot;a&quot;,&quot;b &lt;c&gt;&quot;]', $html, 'printed as JSON, escaped');
    }
}
