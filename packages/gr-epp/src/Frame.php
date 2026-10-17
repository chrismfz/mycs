<?php

namespace MyCS\GrEpp;

use DOMDocument;
use DOMElement;

/**
 * The session's frames, built as DOM (escaped by construction): hello, login (the password in CDATA, as the registry's
 * client; no <svcExtension>: the registry takes its extensions inline in each command, grepp.md §3), logout.
 */
final class Frame
{
    /** The objects the registry serves (its greeting); never a svcExtension. */
    public const OBJECTS = [
        'urn:ietf:params:xml:ns:contact-1.0',
        'urn:ietf:params:xml:ns:domain-1.0',
        'urn:ietf:params:xml:ns:host-1.0',
    ];

    public static function hello(): string
    {
        [$document, $epp] = self::epp();
        $epp->appendChild($document->createElementNS(Response::NS['epp'], 'hello'));

        return (string) $document->saveXML();
    }

    public static function login(Config $config, string $clTRID, #[\SensitiveParameter] ?string $newPassword = null): string
    {
        [$document, $epp] = self::epp();
        $command = $epp->appendChild($document->createElementNS(Response::NS['epp'], 'command'));
        $login = $command->appendChild($document->createElementNS(Response::NS['epp'], 'login'));
        $login->appendChild(self::element($document, 'clID', $config->clientId));
        $login->appendChild(self::secret($document, 'pw', $config->password()));
        if ($newPassword !== null) {
            Config::checkPassword($newPassword);
            $login->appendChild(self::secret($document, 'newPW', $newPassword));
        }
        $options = $login->appendChild($document->createElementNS(Response::NS['epp'], 'options'));
        $options->appendChild(self::element($document, 'version', '1.0'));
        $options->appendChild(self::element($document, 'lang', $config->lang));
        $svcs = $login->appendChild($document->createElementNS(Response::NS['epp'], 'svcs'));
        foreach (self::OBJECTS as $uri) {
            $svcs->appendChild(self::element($document, 'objURI', $uri));
        }
        $command->appendChild(self::element($document, 'clTRID', $clTRID));

        return (string) $document->saveXML();
    }

    public static function logout(string $clTRID): string
    {
        [$document, $epp] = self::epp();
        $command = $epp->appendChild($document->createElementNS(Response::NS['epp'], 'command'));
        $command->appendChild($document->createElementNS(Response::NS['epp'], 'logout'));
        $command->appendChild(self::element($document, 'clTRID', $clTRID));

        return (string) $document->saveXML();
    }

    /** @return array{0: DOMDocument, 1: DOMElement} */
    public static function epp(): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $epp = $document->appendChild($document->createElementNS(Response::NS['epp'], 'epp'));

        return [$document, $epp];
    }

    private static function element(DOMDocument $document, string $name, string $text): DOMElement
    {
        $element = $document->createElementNS(Response::NS['epp'], $name);
        $element->appendChild($document->createTextNode($text));

        return $element;
    }

    /** A password in CDATA, as the registry's client sends it; one holding «]]>» as escaped text (the same value). */
    private static function secret(DOMDocument $document, string $name, #[\SensitiveParameter] string $value): DOMElement
    {
        $element = $document->createElementNS(Response::NS['epp'], $name);
        $element->appendChild(str_contains($value, ']]>') ? $document->createTextNode($value) : $document->createCDATASection($value));

        return $element;
    }
}
