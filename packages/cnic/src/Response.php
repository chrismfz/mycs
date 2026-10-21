<?php

namespace MyCS\Cnic;

/**
 * An answer of CentralNic Reseller's API: `code`, `description`, and its properties (`property[NAME][i] = value`, names
 * upper-cased, each a list). 2xx: done; 4xx: temporary, nothing done; 5xx: refused. An answer without a code (a proxy's
 * page, an empty body) is none of those: unknown().
 */
final class Response
{
    /** @param  array<string, list<string>>  $properties */
    private function __construct(public readonly ?int $code, public readonly string $description, private readonly array $properties) {}

    public static function parse(string $raw): self
    {
        $code = null;
        $description = '';
        $properties = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*([^=]*[^=\s])\s*=\s*(.*?)\s*$/', $line, $m) !== 1) {
                continue;
            }
            [$key, $value] = [$m[1], $m[2]];
            if (preg_match('/^property\[([^\]]*)\]/i', $key, $p) === 1) {
                $properties[strtoupper((string) preg_replace('/\s/', '', $p[1]))][] = $value;
            } elseif (strcasecmp($key, 'code') === 0 && ctype_digit($value)) {
                $code = (int) $value;
            } elseif (strcasecmp($key, 'description') === 0) {
                $description = $value;
            }
        }

        return new self($code, $description, $properties);
    }

    public function success(): bool
    {
        return $this->code !== null && $this->code >= 200 && $this->code < 300;
    }

    /** Try later: nothing was done (421 temporary error, 423 busy…). */
    public function temporary(): bool
    {
        return $this->code !== null && $this->code >= 400 && $this->code < 500;
    }

    /** Refused: nothing done, the same request would be refused again. */
    public function refused(): bool
    {
        return $this->code !== null && $this->code >= 500;
    }

    /** No code: not its answer (a proxy, an empty body): whether anything was done is not known. */
    public function unknown(): bool
    {
        return $this->code === null;
    }

    /** No such object in the account (a domain, a contact). */
    public function notFound(): bool
    {
        return $this->code === 545;
    }

    /** @return list<string> */
    public function list(string $name): array
    {
        return $this->properties[strtoupper($name)] ?? [];
    }

    public function first(string $name): ?string
    {
        $value = $this->list($name)[0] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function has(string $name): bool
    {
        return array_key_exists(strtoupper($name), $this->properties);
    }

    /** Its code and description, for a message (never its properties: they may carry an auth code or contact data). */
    public function summary(): string
    {
        return ($this->code ?? 'no code') . ' ' . mb_substr($this->description !== '' ? $this->description : 'no description', 0, 300);
    }
}
