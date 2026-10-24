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
        $privateKey = $check->read('key', $key, fn (string $t) => PrivateKey::parse($t),
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
        $leaf = $chain[0] ?? null;
        if ($leaf !== null) {
            $check->add('certificate.read', CheckResult::INFO, ['name' => $leaf->commonName, 'names' => implode(', ', $leaf->names), 'issuer' => $leaf->issuer,
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
            $missing = array_values(array_diff(array_unique(array_merge([$request->commonName], $request->names)), $leaf->names ?: [$leaf->commonName]));
            $check->add('names.covered', $missing === [] ? CheckResult::OK : CheckResult::FAIL, ['missing' => implode(', ', $missing)]);
        }
        if ($leaf !== null) {
            $check->chain($leaf, array_slice($chain, 1), $caFile);
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

        $trusted = $this->trusted($leaf, array_slice($path, 1), $caFile);
        if ($trusted) {
            $this->add('chain.trusted', CheckResult::OK, ['path' => $names]);
        } elseif ($current->selfSigned()) {
            $this->add('chain.untrusted_root', CheckResult::FAIL, ['path' => $names, 'root' => $current->commonName ?: $current->subjectDn]);
        } else {
            $this->add('chain.incomplete', CheckResult::FAIL, ['path' => $names, 'missing' => $current->issuer]);
        }
        if ($extra !== []) {
            $this->add('chain.extra', CheckResult::WARN, ['names' => implode(', ', array_map(fn (Certificate $c) => $c->commonName ?: $c->subjectDn, $extra))]);
        }
    }

    /** @param list<Certificate> $intermediates */
    private function trusted(Certificate $leaf, array $intermediates, ?string $caFile): bool
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
            // Its purpose checked as of now: an expired one is told by dates() too.
            $ok = $untrusted !== null
                ? @openssl_x509_checkpurpose($leaf->pem, X509_PURPOSE_SSL_SERVER, [$caFile], $untrusted)
                : @openssl_x509_checkpurpose($leaf->pem, X509_PURPOSE_SSL_SERVER, [$caFile]);
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
