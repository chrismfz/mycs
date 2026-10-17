<?php

namespace MyCS\GrEpp;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * A registrar account at the registry: its endpoint, its user (clID) and password, the language of the registry's
 * messages (el: shown to staff and customers as they are). The password is never shown: not by var_dump, print_r or
 * an exception (it is checked here without being repeated).
 */
final class Config
{
    public const PRODUCTION = 'https://regepp.ics.forth.gr:700/epp/proxy';

    public const UAT = 'https://uat-regepp.ics.forth.gr:700/epp/proxy';

    public function __construct(
        public readonly string $clientId,
        #[SensitiveParameter] private readonly string $password,
        public readonly string $endpoint = self::PRODUCTION,
        public readonly string $lang = 'el',
        /** Seconds a session may sit idle before a fresh login (the registry's own limit is not documented). */
        public readonly int $maxIdle = 300,
    ) {
        if (!str_starts_with($endpoint, 'https://')) {
            throw new InvalidArgumentException('The EPP endpoint must be https.');
        }
        if (trim($clientId) === '' || mb_strlen($clientId) > 16) {
            throw new InvalidArgumentException('The EPP user (clID) must be 1 to 16 characters.');
        }
        // epp-1.0.xsd pwType: a token of 6 to 16 characters.
        $length = mb_strlen($password);
        if ($length < 6 || $length > 16 || $password !== trim($password)) {
            throw new InvalidArgumentException('The EPP password must be 6 to 16 characters, without leading or trailing spaces.');
        }
        if (!in_array($lang, ['el', 'en'], true)) {
            throw new InvalidArgumentException('The EPP language must be el or en.');
        }
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'password' => '********', 'endpoint' => $this->endpoint, 'lang' => $this->lang, 'maxIdle' => $this->maxIdle];
    }

    /** @return array<string, mixed> never the password in a serialised copy (a queued job, a cache) */
    public function __serialize(): array
    {
        throw new \LogicException('An EPP config is not serialised: it holds the password.');
    }
}
