<?php

namespace App\Admin\Resources\CpanelAccountResource\Pages;

use App\Admin\Resources\CpanelAccountResource;
use App\Services\Cpanel\AccountSync;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListCpanelAccounts extends ListRecords
{
    protected static string $resource = CpanelAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The other way round: services billed for an account their server does not have (owner 2026-10-28).
            Action::make('withoutAccount')->label('Services without an account')->icon('ri-error-warning-line')->color('gray')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('admin.cpanel.services-without-account', ['services' => AccountSync::servicesWithoutAccount()])),
            // The cron's read, now: an account just made shows without waiting the 30 minutes.
            Action::make('refresh')->label('Read the servers now')->icon('ri-refresh-line')
                ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.update'))
                ->action(function () {
                    foreach (AccountSync::servers() as $server) {
                        try {
                            $result = AccountSync::sync($server);
                            Notification::make()->success()->title($server->name)
                                ->body("{$result['seen']} account(s), {$result['new']} new, {$result['gone']} gone")->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title($server->name)->body(Redact::text($e->getMessage()))->send();
                        }
                    }
                }),
        ];
    }
}
