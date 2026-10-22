<?php

namespace App\Console\Commands\Staff;

use App\Admin\Auth\Login;
use App\Helpers\NotificationHelper;
use App\Models\Staff;
use App\Support\CallerIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use OwenIt\Auditing\Events\AuditCustom;

use function Laravel\Prompts\password;

/**
 * The way back in for a member of staff locked out of the admin (security audit, after 2FA became required): their
 * login's limit cleared, and if asked their 2FA (set up again at their next login) or their password. From the console
 * only, so whoever runs it already holds the server; it asks first, is audited, and staff are told every time.
 */
class Unlock extends Command
{
    protected $signature = 'app:staff:unlock {email : The member of staff\'s email}
        {--reset-2fa : Clear their 2FA: they set it up again at their next login}
        {--password : Set a new password (asked, never on the command line)}
        {--ip=* : Also clear the admin login\'s limit for this address (one per --ip)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Let a locked-out member of staff into the admin again (limits, 2FA, password)';

    public function handle(): int
    {
        Config::set('audit.console', true);
        $email = trim((string) $this->argument('email'));
        // The address exactly (the collation would match lookalikes: review of #338).
        $staff = Staff::query()->whereRaw('lower(email) = ?', [strtolower($email)])->get()->first(fn (Staff $s) => strtolower((string) $s->email) === strtolower($email));
        if ($staff === null) {
            $this->error('No member of staff with this email.');

            return self::FAILURE;
        }
        $ips = array_values(array_filter((array) $this->option('ip'), 'is_string'));
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $this->error("Not an IP address: {$ip}");

                return self::FAILURE;
            }
        }

        $changes = ['login limit cleared'];
        if ($this->option('reset-2fa')) {
            $changes[] = '2FA reset';
        }
        if ($this->option('password')) {
            $changes[] = 'new password';
        }
        if ($ips !== []) {
            $changes[] = 'address limit cleared for ' . implode(', ', $ips);
        }
        $who = trim($staff->first_name . ' ' . $staff->last_name) . " <{$staff->email}> (#{$staff->id})";
        if (!$this->option('force') && !$this->confirm("{$who}: " . implode(', ', $changes) . '. Go ahead?')) {
            return self::FAILURE;
        }

        $new = null;
        if ($this->option('password')) {
            $new = password('New password?', required: true, validate: fn (string $value) => mb_strlen($value) < 12 ? 'At least 12 characters.' : null);
            if (password('Again?', required: true) !== $new) {
                $this->error('The passwords differ: nothing changed.');

                return self::FAILURE;
            }
        }

        RateLimiter::clear('staff-login-account:' . $staff->id);
        foreach ($ips as $ip) {
            RateLimiter::clear('livewire-rate-limiter:' . sha1(Login::class . '|authenticate|' . CallerIp::bucket($ip)));
        }
        if ($this->option('reset-2fa')) {
            $staff->saveAppAuthenticationSecret(null);
            $staff->saveAppAuthenticationRecoveryCodes(null);
        }
        if ($new !== null) {
            $staff->password = $new;
        }
        if ($this->option('reset-2fa') || $new !== null) {
            // Whoever holds a «remember me» of theirs is not let in by it, nor the one to set the new 2FA up.
            $staff->forceFill(['remember_token' => null]);
            $staff->save();
        }

        // In the member's audit, what changed (never the secrets themselves): its columns are left out of the audit, so
        // a plain save leaves no row (review of #351).
        $staff->auditEvent = 'unlocked_from_console';
        $staff->isCustomEvent = true;
        $staff->auditCustomOld = [];
        $staff->auditCustomNew = ['changes' => $changes, 'host' => gethostname()];
        event(new AuditCustom($staff));
        Log::warning('Staff unlocked from the console', ['staff_id' => $staff->id, 'changes' => $changes]);
        NotificationHelper::sendSystemEmailNotification('A member of staff was unlocked from the console', e(
            "{$who}: " . implode(', ', $changes) . ', on ' . gethostname() . ' at ' . now()->toDateTimeString() . '. If nobody here did it, the server itself is in other hands.'
        ));
        $this->info('Done: ' . implode(', ', $changes) . '.');
        if (!$staff->active) {
            $this->warn('This member of staff is disabled: they still cannot log in until enabled in the admin.');
        }
        if ($this->option('reset-2fa') && $new === null) {
            $this->warn('Whoever knows their password sets the new 2FA up first: if it may be known to someone else, run again with --password.');
        }

        return self::SUCCESS;
    }
}
