<?php

namespace App\Jobs\Domains;

use App\Services\Domains\DomainOwnerChanges;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** A .gr change of holder, paid (or free), sent to the registry once (G8-5b). Never retried by the queue: charged there. */
class SendDomainOwnerChangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $changeId) {}

    public function handle(DomainOwnerChanges $changes): void
    {
        $changes->send($this->changeId);
    }
}
