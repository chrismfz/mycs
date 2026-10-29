<?php

namespace App\Admin\Resources\IpPoolResource\Pages;

use App\Admin\Resources\IpPoolResource;
use App\Models\IpPool;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateIpPool extends CreateRecord
{
    protected static string $resource = IpPoolResource::class;

    /** Under the pools' write lock (IpPool::writing). */
    protected function handleRecordCreation(array $data): Model
    {
        return IpPool::writing(fn () => parent::handleRecordCreation($data));
    }

    /** An IPv4 pool with its network's hosts at once (the toggle), the gateway left out. */
    protected function afterCreate(): void
    {
        /** @var IpPool $pool */
        $pool = $this->getRecord();
        if ($pool->family !== 4 || blank($pool->network) || !($this->data['add_hosts'] ?? false)) {
            return;
        }
        try {
            $result = $pool->add($pool->network);
        } catch (InvalidArgumentException $e) {
            Notification::make()->warning()->title('Pool saved, no addresses added')->body(e($e->getMessage()))->send();

            return;
        }
        Notification::make()->success()->title("{$result['added']} address(es) added")
            ->body($result['skipped'] !== [] ? count($result['skipped']) . ' already in a pool: ' . implode(', ', array_slice($result['skipped'], 0, 10)) : null)->send();
    }
}
