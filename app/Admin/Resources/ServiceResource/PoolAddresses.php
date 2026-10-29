<?php

namespace App\Admin\Resources\ServiceResource;

use App\Exceptions\DisplayException;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Service;
use App\Services\Ip\IpAllocator;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

/**
 * A service's addresses from the IP pools (docs/proxmox.md, B3), given and released from its page: the next free one of a
 * pool, or one chosen. Its dedicated IP follows (IpAllocator); the form shows it at once.
 */
final class PoolAddresses
{
    public static function assign(): Action
    {
        return Action::make('assignIp')->label('Give an IP')->icon('ri-git-merge-line')->color('gray')
            ->visible(fn (Service $record) => self::canManage() && !in_array($record->status, IpAddress::ENDED, true) && IpPool::query()->exists())
            ->schema([
                Select::make('pool')->label('Pool')->required()->live()
                    ->options(fn () => IpPool::query()->withCount(['addresses as free_count' => fn ($q) => IpAddress::whereGivable($q)])->orderBy('name')->get()
                        ->mapWithKeys(fn (IpPool $pool) => [$pool->id => "{$pool->name} ({$pool->free_count} free)"])),
                Select::make('address')->label('Address')->placeholder('The next free one')->searchable()
                    ->options(fn (Get $get) => $get('pool') ? IpAddress::query()->where('ip_pool_id', $get('pool'))->tap(fn ($q) => IpAddress::whereGivable($q))
                        ->orderBy('id')->limit(500)->pluck('address', 'id') : []),
            ])
            ->action(function (Service $record, array $data, $livewire) {
                try {
                    $ip = IpAllocator::assign($record, IpPool::query()->findOrFail($data['pool']),
                        filled($data['address'] ?? null) ? IpAddress::query()->find($data['address']) : null);
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title('Not given')->body(e($e->getMessage()))->send();

                    return;
                }
                self::refresh($record, $livewire);
                Notification::make()->success()->title("{$ip->address} given")->body('Nothing changes on the VM or the router: set it there.')->send();
            });
    }

    public static function release(): Action
    {
        return Action::make('releaseIp')->label('Release an IP')->icon('ri-link-unlink')->color('gray')
            ->visible(fn (Service $record) => self::canManage() && $record->ipAddresses()->exists())
            ->schema([
                Select::make('address')->label('Address')->required()
                    ->options(fn (Service $record) => $record->ipAddresses()->orderBy('id')->pluck('address', 'id')),
            ])
            ->modalDescription('Back to its pool. Nothing changes on the VM: take it off there too.')
            ->action(function (Service $record, array $data, $livewire) {
                $ip = $record->ipAddresses()->find($data['address']);
                try {
                    $ip ?? throw new DisplayException('No longer this service\'s.');
                    IpAllocator::release($ip, $record->id);
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title('Not released')->body(e($e->getMessage()))->send();

                    return;
                }
                self::refresh($record, $livewire);
                Notification::make()->success()->title("{$ip->address} released")->send();
            });
    }

    /** One a line, with its pool's mask and gateway, for the form. */
    public static function summary(Service $service): ?string
    {
        $lines = $service->ipAddresses()->with('pool')->orderBy('id')->get()->map(fn (IpAddress $ip) => $ip->address
            . (str_contains($ip->address, '/') ? '' : '/' . $ip->pool->prefix) . ($ip->pool->gateway ? ' · gw ' . $ip->pool->gateway : '') . ' (' . $ip->pool->name . ')');

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }

    private static function canManage(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.update');
    }

    /** The form's dedicated IP as it now is: a save would otherwise put back the one it was opened with. */
    private static function refresh(Service $record, $livewire): void
    {
        $record->refresh();
        if (method_exists($livewire, 'refreshFormData')) {
            $livewire->refreshFormData(['dedicated_ip']);
        }
    }
}
