<?php

namespace MyCS\Vies;

/** Not an EU member state's VAT number: VIES cannot check it. */
final class ViesInvalidFormat extends \InvalidArgumentException {}
