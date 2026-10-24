<?php

namespace App\Admin\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use MyCS\SslTools\CheckResult;
use MyCS\SslTools\Pem;
use MyCS\SslTools\SslCheck;

/**
 * Utilities → SSL Checker (docs/ssl-vault.md, PR 2): staff paste any private key, CSR and certificate (with its chain)
 * and see whether they belong together, the chain's trust and the dates. Nothing pasted is kept: the key is cleared
 * from the page as soon as it is checked. Its own permission; 30 checks a minute each.
 */
class SslChecker extends Page
{
    public const PERMISSION = 'admin.services.ssl_checker';

    public const LIMIT = 30;

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shield-check-line';

    protected static ?string $navigationLabel = 'SSL Checker';

    protected static ?string $title = 'SSL Checker';

    protected static string $routePath = 'ssl-checker';

    protected string $view = 'admin.pages.ssl-checker';

    public string $key = '';

    public string $csr = '';

    public string $certificate = '';

    /** @var list<array{code: string, status: string, values: array<string, string>}> */
    #[Locked]
    public array $results = [];

    #[Locked]
    public ?string $error = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission(self::PERMISSION);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function check(): void
    {
        abort_unless(static::canAccess(), 403);
        $key = $this->key;
        $csr = $this->csr;
        $certificate = $this->certificate;
        $this->key = ''; // never kept in the page's state past this request
        // A combined PEM (certificate, chain and key in one file) pasted in another box: checked as pasted, its key
        // taken as the key, and the box cleared, so no key stays in the page (ADV-3 of #438).
        foreach (['csr', 'certificate'] as $field) {
            if (str_contains($this->{$field}, 'PRIVATE KEY')) {
                $key = trim($key) === '' ? $this->{$field} : $key;
                $this->{$field} = '';
            }
        }
        $this->results = [];
        $this->error = null;
        foreach ([$key, $csr, $certificate] as $text) {
            if (strlen($text) > Pem::MAX_LENGTH) {
                $this->error = 'One of the texts is too long.';

                return;
            }
        }
        $limit = 'admin-ssl-checker:' . Auth::guard('staff')->id();
        if (RateLimiter::tooManyAttempts($limit, self::LIMIT)) {
            $this->error = __('services.ssl.too_many');

            return;
        }
        RateLimiter::hit($limit, 60);
        $this->results = array_map(fn (CheckResult $r) => $r->toArray(), SslCheck::run($key, $csr, $certificate));
        if ($this->results === []) {
            $this->error = 'Paste a key, a CSR or a certificate.';
        }
    }
}
