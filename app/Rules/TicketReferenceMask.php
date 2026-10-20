<?php

namespace App\Rules;

use App\Support\Tickets\TicketReference;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A ticket number mask that can number tickets (docs/tickets.md §5): see TicketReference::validMask(). */
class TicketReferenceMask implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !TicketReference::validMask($value)) {
            $fail('Use %n, %A, %a, %y, %m, %d, letters, digits, - and _, with at least four of %n, %A, %a, and at most 64 characters once filled.');
        }
    }
}
