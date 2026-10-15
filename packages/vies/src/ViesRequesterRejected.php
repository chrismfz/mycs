<?php

namespace MyCS\Vies;

/** VIES rejected the requester's own VAT number (INVALID_REQUESTER_INFO): the check is asked again without it. */
final class ViesRequesterRejected extends \RuntimeException {}
