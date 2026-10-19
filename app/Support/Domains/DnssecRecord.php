<?php

namespace App\Support\Domains;

use InvalidArgumentException;

/**
 * One of a domain's DNSSEC records at its registrar (G8-4b): a DS (key tag, algorithm, digest type, digest: the .gr
 * registry's only form) or a DNSKEY (flags, algorithm, protocol, public key: Openprovider's, which makes the DS itself).
 */
final readonly class DnssecRecord
{
    public const DS = 'ds';

    public const DNSKEY = 'dnskey';

    private function __construct(
        public string $kind,
        public int $algorithm,
        public ?int $keyTag = null,
        public ?int $digestType = null,
        public ?string $digest = null,
        public ?int $flags = null,
        public ?int $protocol = null,
        public ?string $publicKey = null,
    ) {}

    public static function ds(int $keyTag, int $algorithm, int $digestType, string $digest): self
    {
        return new self(self::DS, $algorithm, keyTag: $keyTag, digestType: $digestType, digest: strtoupper((string) preg_replace('/\s+/', '', $digest)));
    }

    public static function dnskey(int $flags, int $algorithm, string $publicKey, int $protocol = 3): self
    {
        return new self(self::DNSKEY, $algorithm, flags: $flags, protocol: $protocol, publicKey: (string) preg_replace('/\s+/', '', $publicKey));
    }

    /** @param  array<string, mixed>  $data  as toArray() gives it (meta, a form) */
    public static function fromArray(array $data): self
    {
        $int = function (string $key) use ($data): int {
            $value = $data[$key] ?? null;
            if (!is_int($value) && !(is_string($value) && ctype_digit(trim($value)))) {
                throw new InvalidArgumentException("A DNSSEC {$key} is a number.");
            }

            return (int) $value;
        };

        return ($data['kind'] ?? null) === self::DNSKEY
            ? self::dnskey($int('flags'), $int('algorithm'), (string) ($data['public_key'] ?? ''), isset($data['protocol']) && $data['protocol'] !== '' ? $int('protocol') : 3)
            : self::ds($int('key_tag'), $int('algorithm'), $int('digest_type'), (string) ($data['digest'] ?? ''));
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return $this->kind === self::DNSKEY
            ? ['kind' => $this->kind, 'flags' => $this->flags, 'algorithm' => $this->algorithm, 'protocol' => $this->protocol, 'public_key' => $this->publicKey]
            : ['kind' => $this->kind, 'key_tag' => $this->keyTag, 'algorithm' => $this->algorithm, 'digest_type' => $this->digestType, 'digest' => $this->digest];
    }

    /** As a zone file writes it, for a page. */
    public function label(): string
    {
        return $this->kind === self::DNSKEY
            ? "DNSKEY {$this->flags} {$this->protocol} {$this->algorithm} {$this->publicKey}"
            : "DS {$this->keyTag} {$this->algorithm} {$this->digestType} {$this->digest}";
    }

    /**
     * The same records, in whatever order.
     *
     * @param  list<self>  $a
     * @param  list<self>  $b
     */
    public static function sameSet(array $a, array $b): bool
    {
        $key = fn (self $r): string => (string) json_encode($r->toArray());
        $a = array_map($key, $a);
        $b = array_map($key, $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    public function same(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
