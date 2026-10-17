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
        $element = $this->document->createElementNS(Response::NS[$prefix], "{$prefix}:{$name}");
        if ($text !== null) {
            $element->appendChild($this->document->createTextNode($text));
        }
        $parent->appendChild($element);

        return $element;
    }

    /** The registry's extension element for this command (<extension><prefix:name>), made once per prefix and name. */
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
        $this->command->appendChild($this->document->createElementNS(Response::NS['epp'], 'clTRID'))
            ->appendChild($this->document->createTextNode($clTRID));

        return (string) $this->document->saveXML();
    }
}
