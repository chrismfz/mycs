<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\SslOrder;
use App\Support\Redact;
use Illuminate\Console\Command;
use MyCS\Extensions\Servers\OpenproviderSsl\OpenproviderSsl;
use Throwable;

/**
 * Every certificate ordered at Openprovider read again (extension OpenproviderSsl): its status, dates, certificate (a
 * reissue's too) and what to publish to validate; an order request whose answer was lost looked for and adopted. Reads
 * only: nothing is ordered here. A failure is kept on the order and told.
 */
class SyncSslOrders extends Command
{
    protected $signature = 'ssl:sync {--service= : one service (its id)}';

    protected $description = 'Read every Openprovider SSL order again: status, dates, certificate, validation';

    public function handle(): int
    {
        $failed = false;
        $orders = SslOrder::query()->where(fn ($q) => $q->whereNotNull('order_id')->orWhereNotNull('requested_at'))
            ->whereHas('service', fn ($q) => $q->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
                ->when($this->option('service'), fn ($q, $id) => $q->whereKey((int) $id)))
            ->with('service.server.settings', 'service.product.server.settings')->get();
        foreach ($orders as $order) {
            $server = $order->service->provisioningServer();
            try {
                $extension = $server !== null ? ExtensionHelper::getExtension('server', $server->extension, $server->settings) : null;
                if (!$extension instanceof OpenproviderSsl) {
                    continue;
                }
                $order->order_id === null ? $extension->recover($order) : $extension->sync($order);
            } catch (Throwable $e) {
                report($e);
                $reason = Redact::text($e->getMessage());
                $order->forceFill(['sync_error' => mb_substr($reason, 0, 1000)])->save();
                $this->error("service #{$order->service_id}: {$reason}");
                $failed = true;
            }
        }
        $this->line(count($orders) . ' order(s)');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
