<?php

namespace App\Models\Traits;

use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Support\Facades\App;
use OwenIt\Auditing\Auditable as AuditableTrait;
use Request;
use Str;

trait Auditable
{
    use AuditableTrait;

    public function generateTags(): array
    {
        // A change made from the admin (its page, or its Livewire request coming from one) is tagged so.
        $path = trim((string) parse_url(Request::livewireUrl(), PHP_URL_PATH), '/');
        if (App::runningInConsole() || !Str::is([AdminPanelProvider::PATH, AdminPanelProvider::PATH . '/*'], $path)) {
            return [];
        }

        return [
            'admin',
        ];
    }
}
