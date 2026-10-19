<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * A domain's DNSSEC records changed at its registrar since staff or the customer read them (G8-4b, review L1 of #223):
 * nothing was sent; read them again. Its own type, so a page can say so in its own words.
 */
final class DnssecChangedSinceRead extends RuntimeException {}
