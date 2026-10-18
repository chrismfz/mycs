<?php

namespace MyCS\GrEpp\Command;

use DOMDocument;
use DOMElement;
use MyCS\GrEpp\Frame;
use MyCS\GrEpp\Response;

/**
 * An EPP <command> being built, as DOM (escaped by construction): <command><verb><prefix:verb xmlns:prefix=…>…, the
 * registry's extensions inline in <extension> (never declared at login, grepp.md §3), then <clTRID>.
 */
final class Builder
{
    private ?DOMElement $extension = null;

    private function __construct(
        public readonly DOMDocument $document,
        private readonly DOMElement $command,
        public readonly DOMElement $object,
    ) {}

    /** @param  string  $prefix  a key of Response::NS (domain, contact, host, account, …) */
    public static function start(string $verb, string $prefix, ?string $objectVerb = null): self
    {
        [$document, $epp] = Frame::epp();
        $command = $epp->appendChild($document->createElementNS(Response::NS['epp'], 'command'));
        $verbElement = $command->appendChild($document->createElementNS(Response::NS['epp'], $verb));
        $object = $verbElement->appendChild($document->createElementNS(Response::NS[$prefix], "{$prefix}:" . ($objectVerb ?? $verb)));

        return new self($document, $command, $object);
    }

    /** An element of $prefix's namespace under $parent, with text if given. */
    public function add(DOMElement $parent, string $prefix, string $name, ?string $text = null): DOMElement
    {
        // Every value, whatever its rule: never a NUL or control character (libxml cuts a text at a NUL, silently:
        // «victim.gr\0.mine.gr» would go as victim.gr; adversarial ADV-8 of #186), never invalid UTF-8.
        if ($text !== null && (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1 || !mb_check_encoding($text, 'UTF-8'))) {
            throw new \InvalidArgumentException("The {$name} has a control character or is not UTF-8.");
        }
        $element = $this->document->createElementNS(Response::NS[$prefix], "{$prefix}:{$name}");
        if ($text !== null) {
            $element->appendChild($this->document->createTextNode($text));
        }
        $parent->appendChild($element);

        return $element;
    }

    /** A new element of the registry's extension in this command (<extension> itself made once). */
    public function extension(string $prefix, string $name): DOMElement
    {
        if ($this->extension === null) {
            $this->extension = $this->document->createElementNS(Response::NS['epp'], 'extension');
            $this->command->appendChild($this->extension);
        }

        return $this->add($this->extension, $prefix, $name);
    }

    public function finish(string $clTRID): string
    {
        // epp-1.0.xsd trIDStringType: a token of 3 to 64.
        if (strlen($clTRID) < 3 || strlen($clTRID) > 64 || preg_match('/\s/', $clTRID) === 1) {
            throw new \InvalidArgumentException('A clTRID is 3 to 64 characters without spaces.');
        }
        $this->command->appendChild($this->document->createElementNS(Response::NS['epp'], 'clTRID'))
            ->appendChild($this->document->createTextNode($clTRID));

        return (string) $this->document->saveXML();
    }
}
