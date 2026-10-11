<?php

namespace Nixpal\DomainTools\Whois;

/**
 * Classic whois for TLDs without RDAP: the TLD's server from whois.iana.org ("whois:"),
 * then the domain there. Emails and phone numbers are masked; the text is returned raw
 * (the caller escapes it).
 */
final class Port43
{
    const IANA = 'whois.iana.org';

    /** @var SocketConnector */
    private $socket;

    public function __construct(SocketConnector $socket)
    {
        $this->socket = $socket;
    }

    /** The whois server of a TLD per IANA, or null (no server, or not a plain hostname). */
    public function serverFor($tld)
    {
        $answer = $this->socket->query(self::IANA, strtolower(trim((string) $tld, '.')));
        if ($answer === null || !preg_match('/^whois:\s*(\S+)\s*$/mi', $answer, $m)) {
            return null;
        }
        $host = strtolower($m[1]);

        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host) ? $host : null;
    }

    /** @return array{0:string,1:string|null} "found" with the masked text, or "unsupported", "error" */
    public function lookup($domain, $server = null)
    {
        $domain = strtolower((string) $domain);
        $server = $server ?: $this->serverFor(substr($domain, strpos($domain, '.') + 1));
        if ($server === null) {
            return ['unsupported', null];
        }
        $answer = $this->socket->query($server, $domain);
        if ($answer === null || trim($answer) === '') {
            return ['error', null];
        }

        return ['found', self::mask($answer)];
    }

    /** Drops comment lines, masks emails and phone/fax values, normalises line ends, trims to 20 kB. */
    public static function mask($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        // Registry disclaimers ("%" / "#" comment lines) and runs of blank lines.
        $text = preg_replace('/^[ \t]*[%#].*\n?/m', '', $text);
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
        // Phone/fax lines only: a free-text number pattern would also eat dates.
        $text = preg_replace('/^([^:\n]*\b(?:phone|fax|tel)\b[^:\n]*:[ \t]*)\S.*$/mi', '$1[phone]', $text);
        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            $text = function_exists('mb_convert_encoding') ? mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1') : $text;
        }

        return substr($text, 0, 20480);
    }
}
