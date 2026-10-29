<?php

namespace App\Console\Commands;

use App\Classes\Settings;
use App\Models\Currency;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\SettingsProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * First install of an instance (one per company, docs/decisions.md): migrations, base seed,
 * the company settings and the first admin. CLI only, no web installer (docs/install.md).
 *
 * Refuses to run when a user already exists (--force to add an admin / re-apply settings);
 * every step is idempotent. The password is never a flag (it would land in the shell
 * history and the process list): prompted, or MYCS_ADMIN_PASSWORD for --no-interaction.
 *
 *   php artisan mycs:install
 *   MYCS_ADMIN_PASSWORD=… php artisan mycs:install --company="nixpal" --url=https://my.nixpal.com \
 *       --first-name=… --last-name=… --email=… --no-interaction
 */
class Install extends Command
{
    protected $signature = 'mycs:install
        {--company= : Company name}
        {--url= : Public URL of the client area (https://…)}
        {--first-name= : First admin, first name}
        {--last-name= : First admin, last name}
        {--email= : First admin, email (login)}
        {--language=el : Client area default language}
        {--timezone=Europe/Athens : Timezone}
        {--currency=EUR : Default currency (EUR or USD)}
        {--eu-vat-rates : Add the EU member states\' standard VAT rates to Tax Rates (asked when interactive)}
        {--force : Run even if users already exist}';

    protected $description = 'First install: migrations, base data, company settings and the first admin.';

    /** Currencies the installer can create; others are added from the admin. */
    private const CURRENCIES = [
        'EUR' => ['name' => 'Euro', 'prefix' => '', 'suffix' => ' €', 'format' => '1.000,00'],
        'USD' => ['name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'format' => '1,000.00'],
    ];

    public function handle(): int
    {
        if (blank(config('app.key'))) {
            $this->error('APP_KEY is empty. Run `php artisan key:generate` first (it encrypts the stored credentials).');

            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot connect to the database (check DB_* in .env): ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->call('migrate', ['--force' => true]);

        if (Staff::query()->exists() && !$this->option('force')) {
            $this->error('Staff already exist: this instance looks installed.');
            $this->line('Re-run with --force to add another admin or re-apply the settings.');

            return self::FAILURE;
        }

        $input = $this->gatherInput();
        if ($input === null) {
            return self::FAILURE;
        }

        // The base seed creates USD when no currency exists: create the chosen one first.
        $currency = self::CURRENCIES[$input['currency']];
        Currency::query()->firstOrCreate(['code' => $input['currency']], $currency);

        $this->call('db:seed', ['--force' => true]); // admin role, setting defaults, email templates

        $this->saveSettings([
            'company_name' => $input['company'],
            'app_url' => $input['url'],
            'app_language' => $input['language'],
            'allowed_languages' => array_values(array_unique([$input['language'], 'en'])),
            'admin_language' => 'en',
            'timezone' => $input['timezone'],
            'default_currency' => $input['currency'],
        ]);

        // The EU standard VAT rates (docs/eu-vat.md), only where Tax Rates has none: asked, since not every company
        // sells across the EU (myip has only Greece's).
        if ($this->option('eu-vat-rates') || ($this->input->isInteractive()
            && $this->confirm('Add the EU member states\' standard VAT rates to Tax Rates?', false))) {
            // Not forced: on an instance that already has rates (a re-run with --force) the seed asks again, or refuses
            // without questions (adversarial ADV-525-2).
            $this->call('mycs:vat-rates:seed');
        }

        if (!file_exists(public_path('storage'))) {
            Artisan::call('storage:link');
        }

        // The first member of staff (docs/accounts.md, PR 1): the admin panel's own login, not a customer.
        $admin = Staff::query()->firstOrCreate(['email' => $input['email']], [
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'password' => $input['password'], // hashed by the model cast
            'role_id' => Role::query()->where('name', 'admin')->value('id'),
        ]);
        if (!$admin->wasRecentlyCreated) {
            $this->warn("{$admin->email} already existed: password and role were NOT changed.");
        }

        $this->newLine();
        $this->info('Installed.');
        $this->line("  Company: {$input['company']}");
        $this->line("  Admin:   {$admin->email}");
        $this->line("  Login:   {$input['url']}/" . AdminPanelProvider::PATH);
        $this->line('  Next:    cron (`php artisan schedule:run` every minute), queue worker, mail settings (docs/install.md).');

        return self::SUCCESS;
    }

    /** @return array<string, string>|null */
    private function gatherInput(): ?array
    {
        $input = [
            'company' => $this->value('company', 'Company name'),
            'url' => rtrim($this->value('url', 'Public URL (https://…)'), '/'),
            'first_name' => $this->value('first-name', 'Admin first name'),
            'last_name' => $this->value('last-name', 'Admin last name'),
            'email' => $this->value('email', 'Admin email (login)'),
            'language' => (string) $this->option('language'),
            'timezone' => (string) $this->option('timezone'),
            'currency' => strtoupper((string) $this->option('currency')),
            'password' => $this->password(),
        ];

        $validator = Validator::make($input, [
            'company' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'language' => ['required', 'in:' . implode(',', array_map('basename', glob(base_path('lang/*'), GLOB_ONLYDIR) ?: ['en']))],
            'timezone' => ['required', 'timezone'],
            'currency' => ['required', 'in:' . implode(',', array_keys(self::CURRENCIES))],
            // Same rule as client registration.
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        return $input;
    }

    private function value(string $option, string $question): string
    {
        $value = trim((string) $this->option($option));
        if ($value === '' && $this->input->isInteractive()) {
            $value = trim((string) $this->ask($question));
        }

        return $value;
    }

    private function password(): string
    {
        $fromEnv = (string) getenv('MYCS_ADMIN_PASSWORD');
        if ($fromEnv !== '' || !$this->input->isInteractive()) {
            return $fromEnv;
        }

        $password = (string) $this->secret('Admin password');
        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('The passwords do not match.');

            return '';
        }

        return $password;
    }

    /** @param array<string, mixed> $values */
    private function saveSettings(array $values): void
    {
        $types = collect(Settings::settings())->flatten(1)->pluck('database_type', 'name');
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key, 'settingable_type' => null],
                ['value' => $value, 'type' => $types[$key] ?? 'string'],
            );
        }
        SettingsProvider::flushCache();
    }
}
