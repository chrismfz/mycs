<?php

namespace MyCS\GrEpp\Data;

/** A form of a name in its bundle (.gr / .ελ, homographs): «domain» registered, «dname» a reserved form; chargeable or not. */
final readonly class BundleName
{
    public function __construct(
        public string $name,
        public bool $chargeable,
        public string $recordType,
    ) {}
}
