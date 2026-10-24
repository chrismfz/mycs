<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * The registrar does not sell this TLD (Openprovider: code 307, «Your domain request contains an invalid extension!»,
 * which it answers with an HTTP 500, e.g. for .gov.gr). An answer, not a failure: nothing to retry.
 */
final class TldNotOffered extends RuntimeException {}
