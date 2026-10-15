<?php

namespace App\Services\Ops;

use RuntimeException;

/** No such record, or more than one (Lookup): an answer, not an error to report. */
final class NotFound extends RuntimeException {}
