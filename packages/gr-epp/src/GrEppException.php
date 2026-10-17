<?php

namespace MyCS\GrEpp;

use RuntimeException;

/** Anything the registry client throws. Its message never holds the password. */
abstract class GrEppException extends RuntimeException {}
