<?php

namespace App\Support\Tickets;

use App\Models\Ticket;
use App\Models\TicketQuarantine;

/** What the router did with one mail. */
final class InboundOutcome
{
    public const THREADED = 'threaded';

    public const OPENED = 'opened';

    public const QUARANTINED = 'quarantined';

    public const DUPLICATE = 'duplicate';

    public const BLOCKED = 'blocked';

    public function __construct(
        public readonly string $kind,
        public readonly ?Ticket $ticket = null,
        public readonly ?TicketQuarantine $quarantine = null,
    ) {}
}
