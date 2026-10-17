<?php

namespace App\Jobs\Domains;

use App\Services\Domains\DomainRenewalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A paid domain renewal at its registrar (D5b-2), off the payment's request: the payment never waits for the registrar
 * (D-15). One attempt: a failure is a Pending Module Action and the cron's next run tries again; the service's guards
 * (lock, once per invoice) make a second run harmless.
 */
class RenewDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240; // under the queue's retry_after (300): never run twice at once (code review 12)

    public function __construct(public int $lineId) {}

    public function handle(DomainRenewalService $renewals): void
    {
        $renewals->renewLine($this->lineId);
    }
}
