<?php

namespace MyCS\EuVatRates;

/** TEDB gave no usable answer (an error, a fault, an answer without every state's standard rate): nothing to compare. */
final class TedbUnavailable extends \RuntimeException {}
