<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UnsuspendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // A queued action must not undo a newer payment or staff hold.
        if ($this->service->fresh()?->status !== Service::STATUS_ACTIVE) {
            return;
        }
        try {
            ExtensionHelper::unsuspendServer($this->service);
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }
    }
}
