<?php

namespace MyCS\SslTools;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * Checks a key, a CSR and a certificate (with its chain) against each other: each read, the key matching the CSR and
 * the certificate, the certificate covering the CSR's names, its chain built by signature and trusted, its dates. Any
 * of the three may be missing. The result holds codes and plain values only: never the key.
 */
final class SslCheck
{
    /** A certificate this close to its end is a warning. */
    public const SOON_DAYS = 30;

    /** @var list<CheckResult> */
    private array $results = [];

    private function __construct() {}

    /**
     * @param  string|null  $certificates  the certificate first, then any chain (a CA bundle), in any order
     * @param  string|null  $caFile  the trusted roots (a PEM bundle); the system's by default
     * @return list<CheckResult>
     */
    public static function run(#[SensitiveParameter] ?string $key, ?string $csr, ?string $certificates, ?string $caFile = null, ?DateTimeImmutable $now = null): array
    {
        $check = new self;
        $privateKey = $check->read('key', $key, fn (#[SensitiveParameter] string $t) => PrivateKey::parse($t),
            fn (PrivateKey $k) => ['type' => $k->description, 'fingerprint' => $k->spkiSha256]);
        $request = $check->read('csr', $csr, fn (string $t) => Csr::parse($t),
            fn (Csr $c) => ['name' => $c->commonName, 'names' => implode(', ', $c->names), 'type' => $c->keyDescription, 'fingerprint' => $c->spkiSha256]);
        $chain = [];
        if (self::given($certificates)) {
            try {
                $chain = Certificate::parseAll((string) $certificates);
                if ($chain === []) {
                    throw SslToolsException::because('No certificate was found.');
                }
            } catch (SslToolsException $e) {
                $chain = [];
                $check->add('certificate.invalid', CheckResult::FAIL, ['reason' => $e->getMessage()]);
            }
        }
        // The same certificate pasted twice is one (review 5 of #438).
        $chain = array_values(array_reduce($chain, fn (array $seen, Certificate $c) => $seen + [hash('sha256', $c->pem) => $c], []));
        $leaf = self::leaf($chain, $privateKey->spkiSha256 ?? $request?->spkiSha256);
        if ($leaf !== null) {
            $check->add('certificate.read', CheckResult::INFO, ['name' => $leaf->commonName, 'names' => implode(', ', $leaf->names ?: [$leaf->commonName]), 'issuer' => $leaf->issuer,
                'serial' => $leaf->serial, 'from' => $leaf->notBefore->format('Y-m-d'), 'to' => $leaf->notAfter->format('Y-m-d'), 'type' => $leaf->keyDescription,
                'fingerprint' => $leaf->spkiSha256]);
        }

        if ($privateKey !== null && $request !== null) {
            $check->add('match.key_csr', $privateKey->matches($request) ? CheckResult::OK : CheckResult::FAIL);
        }
        if ($privateKey !== null && $leaf !== null) {
            $check->add('match.key_certificate', $privateKey->matches($leaf) ? CheckResult::OK : CheckResult::FAIL);
        }
        if ($request !== null && $leaf !== null) {
            $check->add('match.csr_certificate', $leaf->matches($request) ? CheckResult::OK : CheckResult::FAIL);
            $covers = $leaf->names ?: [$leaf->commonName];
            // A CSR may carry no common name, only its names (ADV-4 of #438).
            $wanted = array_filter(array_unique(array_merge([$request->commonName], $request->names)), fn (string $n) => $n !== '');
            $missing = array_values(array_filter($wanted, fn (string $n) => !self::covered($n, $covers)));
            $check->add('names.covered', $missing === [] ? CheckResult::OK : CheckResult::FAIL, ['missing' => implode(', ', $missing)]);
        }
        if ($leaf !== null) {
            $check->chain($leaf, array_values(array_filter($chain, fn (Certificate $c) => $c !== $leaf)), $caFile);
            $check->dates($leaf, $now ?? new DateTimeImmutable);
        }

        return $check->results;
    }

    /**
     * @template T of object
     *
     * @param  callable(string): T  $parse
     * @param  callable(T): array<string, string>  $describe
     * @return T|null
     */
    private function read(string $what, #[SensitiveParameter] ?string $text, callable $parse, callable $describe): ?object
    {
        if (!self::given($text)) {
            return null;
        }
        try {
            $value = $parse((string) $text);
        } catch (SslToolsException $e) {
            $this->add("{$what}.invalid", CheckResult::FAIL, ['reason' => $e->getMessage()]);

            return null;
        }
        $this->add("{$what}.read", CheckResult::INFO, $describe($value));

        return $value;
    }

    /**
     * The path from the certificate up, each one signed by the next (whatever order they were pasted in); trusted when
     * the system's roots (or the given ones) complete it; a pasted certificate on no path is extra.
     *
     * @param  list<Certificate>  $pool
     */
    private function chain(Certificate $leaf, array $pool, ?string $caFile): void
    {
        $path = [$leaf];
        $current = $leaf;
        while (!$current->selfSigned() && count($path) <= count($pool)) {
            $next = null;
            foreach ($pool as $candidate) {
                if (!in_array($candidate, $path, true) && $current->issuedBy($candidate)) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $path[] = $current = $next;
        }
        $extra = array_values(array_filter($pool, fn (Certificate $c) => !in_array($c, $path, true)));
        $names = implode(' → ', array_map(fn (Certificate $c) => $c->commonName ?: $c->subjectDn, $path));

        // A CA certificate pasted alone is checked as one, not as a web server's (review 3 of #438).
        $intermediates = array_slice($path, 1);
        $purpose = $leaf->isCa ? X509_PURPOSE_ANY : X509_PURPOSE_SSL_SERVER;
        $now = new DateTimeImmutable; // OpenSSL checks as of now, whatever date the dates() line is told for
        if ($this->trusted($leaf, $intermediates, $caFile, $purpose)) {
            $this->add('chain.trusted', CheckResult::OK, ['path' => $names]);
        } elseif (array_filter($path, fn (Certificate $c) => $c->notBefore > $now || $c->notAfter <= $now) !== []) {
            // Out of its dates, OpenSSL verifies no chain: never told «incomplete» or «not trusted» for it (review 2).
            $this->add('chain.dates', CheckResult::WARN, ['path' => $names]);
        } elseif (!$leaf->isCa && $this->trusted($leaf, $intermediates, $caFile, X509_PURPOSE_ANY)) {
            $this->add('chain.wrong_purpose', CheckResult::FAIL, ['path' => $names]);
        } elseif ($current->selfSigned()) {
            $this->add('chain.untrusted_root', CheckResult::FAIL, ['path' => $names, 'root' => $current->commonName ?: $current->subjectDn]);
        } else {
            $this->add('chain.incomplete', CheckResult::FAIL, ['path' => $names, 'missing' => $current->issuer]);
        }
        if ($extra !== []) {
            $this->add('chain.extra', CheckResult::WARN, ['names' => implode(', ', array_map(fn (Certificate $c) => $c->commonName ?: $c->subjectDn, $extra))]);
        }
    }

    /**
     * The certificate the others are checked for: the one of the key or CSR given, else the first that is no CA and
     * signs none of the others (a bundle pasted intermediate first: review 4 of #438), else the first.
     *
     * @param  list<Certificate>  $chain
     */
    private static function leaf(array $chain, ?string $spki): ?Certificate
    {
        foreach ($chain as $certificate) {
            if ($spki !== null && hash_equals($certificate->spkiSha256, $spki)) {
                return $certificate;
            }
        }
        foreach ($chain as $certificate) {
            if (!$certificate->isCa && array_filter($chain, fn (Certificate $other) => $other !== $certificate && $other->issuedBy($certificate)) === []) {
                return $certificate;
            }
        }

        return $chain[0] ?? null;
    }

    /**
     * A CSR's name covered by the certificate's names: listed, or under a wildcard of one label (*.example.gr covers
     * shop.example.gr, never example.gr nor a.b.example.gr).
     *
     * @param  list<string>  $covers
     */
    private static function covered(string $name, array $covers): bool
    {
        $name = rtrim(strtolower($name), '.');
        foreach ($covers as $cover) {
            $cover = rtrim(strtolower($cover), '.');
            if ($cover === $name || (str_starts_with($cover, '*.') && !str_starts_with($name, '*.')
                && substr($name, strpos($name, '.') ?: 0) === substr($cover, 1) && str_contains($name, '.'))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Certificate> $intermediates */
    private function trusted(Certificate $leaf, array $intermediates, ?string $caFile, int $purpose): bool
    {
        $caFile ??= openssl_get_cert_locations()['default_cert_file'] ?? null;
        if (!is_string($caFile) || !is_readable($caFile)) {
            return false;
        }
        $untrusted = null;
        if ($intermediates !== []) {
            $untrusted = tempnam(sys_get_temp_dir(), 'chain');
            if ($untrusted === false) {
                return false;
            }
            file_put_contents($untrusted, implode('', array_map(fn (Certificate $c) => $c->pem, $intermediates)));
        }
        try {
            $ok = $untrusted !== null
                ? @openssl_x509_checkpurpose($leaf->pem, $purpose, [$caFile], $untrusted)
                : @openssl_x509_checkpurpose($leaf->pem, $purpose, [$caFile]);
        } finally {
            if ($untrusted !== null) {
                @unlink($untrusted);
            }
            SslToolsException::clearOpensslErrors();
        }

        return $ok === true;
    }

    private function dates(Certificate $leaf, DateTimeImmutable $now): void
    {
        $days = (int) floor(($leaf->notAfter->getTimestamp() - $now->getTimestamp()) / 86400);
        $values = ['to' => $leaf->notAfter->format('Y-m-d'), 'days' => (string) $days];
        match (true) {
            $leaf->notBefore > $now => $this->add('dates.not_yet', CheckResult::FAIL, ['from' => $leaf->notBefore->format('Y-m-d')]),
            $leaf->notAfter <= $now => $this->add('dates.expired', CheckResult::FAIL, $values),
            $days < self::SOON_DAYS => $this->add('dates.soon', CheckResult::WARN, $values),
            default => $this->add('dates.valid', CheckResult::OK, $values),
        };
    }

    private static function given(?string $text): bool
    {
        return $text !== null && trim($text) !== '';
    }

    /** @param array<string, string> $values */
    private function add(string $code, string $status, array $values = []): void
    {
        $this->results[] = new CheckResult($code, $status, $values);
    }
}
