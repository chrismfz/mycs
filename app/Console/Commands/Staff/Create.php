<?php

namespace App\Console\Commands\Staff;

use App\Models\Role;
use App\Models\Staff;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Config;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

/** A member of staff from the console: the way in when nobody can log in to the admin (docs/install.md). */
class Create extends Command implements PromptsForMissingInput
{
    protected $signature = 'app:staff:create {first_name} {last_name} {email} {password} {role}';

    protected $description = 'Create a member of staff (admin panel login)';

    public function handle(): int
    {
        Config::set('audit.console', true);
        $role = Role::query()->find($this->argument('role'));
        if ($role === null) {
            $this->error('No such role.');

            return self::FAILURE;
        }
        if (Staff::query()->where('email', $this->argument('email'))->exists()) {
            $this->error('A member of staff with this email already exists.');

            return self::FAILURE;
        }

        Staff::query()->create([
            'first_name' => $this->argument('first_name'),
            'last_name' => $this->argument('last_name'),
            'email' => $this->argument('email'),
            'password' => $this->argument('password'),
            'role_id' => $role->id,
        ]);
        $this->info('Created. Log in at ' . rtrim((string) config('app.url'), '/') . '/admin');

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'first_name' => 'First name?',
            'last_name' => 'Last name?',
            'email' => 'Email address?',
            'password' => fn () => password('Password?', required: true),
            'role' => fn () => select(label: 'Role?', options: Role::query()->pluck('name', 'id')->all()),
        ];
    }
}
