<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Models\DomainRegistrarConnection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a registrar key → DomainRegistrar adapter. Config-driven via
 * config/domains.php → registrars, mirroring ProviderTransportRegistry:
 * a new registrar is one config line + one class, no core edit.
 *
 * ALWAYS returns an adapter — the Null ('manual') one for empty/'manual'/unknown
 * keys — so callers never null-check, and an unknown key fails LOUDLY at
 * call-time (the Null adapter throws on API operations) instead of silently
 * pretending a registrar exists. docs/domains/ekdosi-design.md §4.2.
 */
class DomainRegistrarRegistry
{
    /** @var array<string, DomainRegistrar> resolved-instance cache */
    private array $cache = [];

    public function for(string $key): DomainRegistrar
    {
        $key = trim($key);
        if ($key === '' || $key === 'manual') {
            return $this->null();
        }
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $class = $this->map()[$key] ?? null;
        if ($class === null || !class_exists($class) || !is_subclass_of($class, DomainRegistrar::class)) {
            Log::warning('Unknown domain registrar requested — using the manual (Null) adapter.', ['key' => $key]);

            return $this->null();
        }

        return $this->cache[$key] = new $class;
    }

    /** @return list<string> configured registrar keys ('manual' is always implicit) */
    public function keys(): array
    {
        return array_keys($this->map());
    }

    /**
     * Human label for a registrar key (config → domains.registrar_labels).
     * Falls back to the raw key so a stale/removed key still renders — never a
     * Null fallback + a per-row log warning (the PaymentGatewayRegistry lesson).
     */
    public function label(string $key): string
    {
        $labels = config('domains.registrar_labels', []);

        return is_array($labels) && is_string($labels[$key] ?? null) ? $labels[$key] : $key;
    }

    /**
     * Display label for a connection (null = the manual pseudo-registrar):
     * its custom label, else its registrar's human label. The one helper the
     * list column, the View header and any future surface share.
     */
    public function connectionLabel(?DomainRegistrarConnection $connection): string
    {
        if ($connection === null) {
            return $this->label('manual');
        }

        return $connection->label !== ''
            ? $connection->label
            : $this->label((string) $connection->registrar);
    }

    /**
     * The connection-form options: every labeled registrar ('manual' included)
     * PLUS every key wired into domains.registrars — so «one config line + one
     * class» really is enough to make an adapter selectable (a label is polish,
     * not a second mandatory line; an unlabeled key renders as itself). A
     * labeled-but-unwired key is also selectable — until its adapter lands, any
     * API action fails loudly via the Null adapter.
     *
     * @return array<string, string> key => label
     */
    public function selectOptions(): array
    {
        $labels = config('domains.registrar_labels', []);
        $options = is_array($labels) ? array_filter($labels, 'is_string') : [];

        foreach ($this->keys() as $key) {
            $options[$key] ??= $key;
        }

        return $options;
    }

    private function null(): DomainRegistrar
    {
        return $this->cache['manual'] ??= new NullDomainRegistrar;
    }

    /** @return array<string, class-string> */
    private function map(): array
    {
        $map = config('domains.registrars', []);

        return is_array($map) ? $map : [];
    }
}
