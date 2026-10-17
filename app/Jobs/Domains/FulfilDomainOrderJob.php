<?php

namespace App\Jobs\Domains;

use App\Services\Domains\Ordering\DomainOrderFulfilment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A domain ordered, paid (or free), registered or its transfer started at its registrar (D8b-2), off the payment's
 * request (D-15). One attempt: a failure is a Pending Module Action; the cron's next run sends what is still due; the
 * service's guards (lock, sent once, a pending charge never asked again) make a second run harmless.
 */
class FulfilDomainOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $domainId) {}

    public function handle(DomainOrderFulfilment $fulfilment): void
    {
        $fulfilment->fulfil($this->domainId);
    }
}
