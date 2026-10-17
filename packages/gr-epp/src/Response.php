<?php

namespace MyCS\GrEpp;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * One answer of the registry: a greeting (to hello) or a response (to a command): its first result's code and message,
 * the transaction ids, the registry's comment (extcommon), and the document for the commands' parsers (G2).
 *
 * Read safely: no network, no entities (LIBXML_NONET, no DTD loading); a body that is not EPP is OutcomeUnknown.
 */
final class Response
{
    public const NS = [
        'epp' => 'urn:ietf:params:xml:ns:epp-1.0',
        'domain' => 'urn:ietf:params:xml:ns:domain-1.0',
        'contact' => 'urn:ietf:params:xml:ns:contact-1.0',
        'host' => 'urn:ietf:params:xml:ns:host-1.0',
        'secDNS' => 'urn:ietf:params:xml:ns:secDNS-1.1',
        'extdomain' => 'urn:ics-forth:params:xml:ns:extdomain-1.3',
        'extcommon' => 'urn:ics-forth:params:xml:ns:extcommon-1.0',
        'extcontact' => 'urn:ics-forth:params:xml:ns:extcontact-1.0',
        'exthost' => 'urn:ics-forth:params:xml:ns:exthost-1.0',
        'extSecDNS' => 'urn:ics-forth:params:xml:ns:extSecDNS-1.0',
        'account' => 'urn:ics-forth:params:xml:ns:account-1.1',
        'dacor' => 'urn:ics-forth:params:xml:ns:dacor-1.0',
        'grrls' => 'urn:ics-forth:params:xml:ns:ext-gr-rls-1.0',
    ];

    private function __construct(
        public readonly DOMDocument $document,
        public readonly bool $greeting,
        public readonly ?int $code,
        public readonly ?string $message,
        public readonly ?string $lang,
        public readonly ?string $clTRID,
        public readonly ?string $svTRID,
    ) {}

    /** @throws OutcomeUnknown not an EPP greeting or response */
    public static function parse(string $xml): self
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = trim($xml) !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->doctype !== null) {
            throw new OutcomeUnknown('The registry answered with something that is not EPP XML.');
        }
        $xpath = self::xpathOf($document);
        if ($xpath->query('/epp:epp/epp:greeting')->length === 1) {
            return new self($document, true, null, null, null, null, null);
        }
        $result = $xpath->query('/epp:epp/epp:response/epp:result')->item(0);
        if (!$result instanceof DOMElement || !ctype_digit($result->getAttribute('code'))) {
            throw new OutcomeUnknown('The registry answered without an EPP result.');
        }
        $msg = $xpath->query('epp:msg', $result)->item(0);

        return new self(
            $document,
            false,
            (int) $result->getAttribute('code'),
            $msg !== null ? trim($msg->textContent) : null,
            $msg instanceof DOMElement && $msg->hasAttribute('lang') ? $msg->getAttribute('lang') : null,
            self::text($xpath, '/epp:epp/epp:response/epp:trID/epp:clTRID'),
            self::text($xpath, '/epp:epp/epp:response/epp:trID/epp:svTRID'),
        );
    }

    public function xpath(): DOMXPath
    {
        return self::xpathOf($this->document);
    }

    /** 1xxx: done (1000), done with actions pending (1001, a create), ending the session (1500). */
    public function isSuccess(): bool
    {
        return $this->code !== null && $this->code >= 1000 && $this->code < 2000;
    }

    /** The registry's comment on it (extcommon, extcontact, exthost: «Καλώς ήλθατε στο Μητρώο.»), if any. */
    public function comment(): ?string
    {
        return self::text($this->xpath(), '//extcommon:comment | //extcontact:comment | //exthost:comment');
    }

    private static function xpathOf(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        foreach (self::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    private static function text(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }
}
