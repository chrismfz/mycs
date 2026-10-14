<?php

namespace App\Console\Commands\User;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Config;

use function Laravel\Prompts\password;

class Create extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:user:create {first_name} {last_name} {email} {password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a customer login (staff: app:staff:create)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Config::set('audit.console', true);
        // A customer: the login and the account it owns (docs/accounts.md, PR 2a), as registration does.
        DB::transaction(fn () => Client::openFor(User::create([
            'first_name' => $this->argument('first_name'),
            'last_name' => $this->argument('last_name'),
            'email' => $this->argument('email'),
            'password' => $this->argument('password'),
        ])));
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'first_name' => 'What is the user\'s first name?',
            'last_name' => 'What is the user\'s last name?',
            'email' => 'What is the user\'s email address?',
            'password' => fn () => password('What is the user\'s password?', required: true),
        ];
    }
}
