<?php

namespace App\Jobs\Server;

use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpgradeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesModuleAction, SerializesModels;

    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // An ended service has no account to change (sweep §2): a retry after a terminate must not reopen one.
        if ($this->service->fresh()?->isEnded() ?? true) {
            return;
        }
        // $data is the data that will be used to send the email, data is coming from the extension itself
        try {
            ExtensionHelper::upgradeServer($this->service);
        } catch (NothingToDoOnServer) {
            // No module, or a module without this action: nothing to do (not retried).
        }
    }

    protected function moduleAction(): string
    {
        return 'upgrade';
    }
}
