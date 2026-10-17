<?php

namespace MyCS\GrEpp;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * A registrar account at the registry: its endpoint, its user (clID) and password, the language of the registry's
 * messages (el: shown to staff and customers as they are). The password is never shown: not by var_dump, print_r or
 * an exception (it is checked here without being repeated), never serialised; kept as a SensitiveParameterValue, so
 * var_export(), an (array) cast and VarDumper do not see it either.
 */
final class Config
{
    public const PRODUCTION = 'https://regepp.ics.forth.gr:700/epp/proxy';

    public const UAT = 'https://uat-regepp.ics.forth.gr:700/epp/proxy';

    /** The guide v4.3 (changes, item 8): the only Content-Type allowed, if one is sent. Its sample client sends text/xml. */
    public const CONTENT_TYPE = 'application/epp+xml;charset=UTF-8';

    /** Not a string property (adversarial ADV-3 of #184): var_export, an (array) cast and VarDumper see no password. */
    private readonly \SensitiveParameterValue $password;

    public function __construct(
        public readonly string $clientId,
        #[SensitiveParameter] string $password,
        public readonly string $endpoint = self::PRODUCTION,
        public readonly string $lang = 'el',
        /** Seconds a session may sit idle before a fresh login (the registry ends one idle for 15 minutes). */
        public readonly int $maxIdle = 300,
        public readonly string $contentType = self::CONTENT_TYPE,
    ) {
        if (!str_starts_with($endpoint, 'https://')) {
            throw new InvalidArgumentException('The EPP endpoint must be https.');
        }
        // eppcom-1.0.xsd clIDType: a token of 3 to 16 characters.
        if (mb_strlen($clientId) < 3 || mb_strlen($clientId) > 16 || $clientId !== trim($clientId)) {
            throw new InvalidArgumentException('The EPP user (clID) must be 3 to 16 characters.');
        }
        self::checkPassword($password);
        if ($maxIdle < 1 || $maxIdle > 900) {
            throw new InvalidArgumentException('maxIdle must be 1 to 900 seconds: the registry ends a session idle for 15 minutes.');
        }
        $this->password = new \SensitiveParameterValue($password);
        if (!in_array($lang, ['el', 'en'], true)) {
            throw new InvalidArgumentException('The EPP language must be el or en.');
        }
    }

    /** epp-1.0.xsd pwType: a token of 6 to 16 characters (never repeated in the message). */
    public static function checkPassword(#[SensitiveParameter] string $password): void
    {
        $length = mb_strlen($password);
        // Printable characters only: a control character makes the frame not XML (adversarial ADV-6).
        if ($length < 6 || $length > 16 || $password !== trim($password) || preg_match('/\s{2,}|[\x00-\x1F\x7F]/', $password) === 1
            || !mb_check_encoding($password, 'UTF-8')) {
            throw new InvalidArgumentException('An EPP password must be 6 to 16 characters, without leading, trailing or repeated spaces.');
        }
    }

    public function password(): string
    {
        return (string) $this->password->getValue();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'password' => '********', 'endpoint' => $this->endpoint, 'lang' => $this->lang, 'maxIdle' => $this->maxIdle,
            'contentType' => $this->contentType];
    }

    /** @return array<string, mixed> never the password in a serialised copy (a queued job, a cache) */
    public function __serialize(): array
    {
        throw new \LogicException('An EPP config is not serialised: it holds the password.');
    }
}
