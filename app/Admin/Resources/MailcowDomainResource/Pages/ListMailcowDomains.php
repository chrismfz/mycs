<?php

namespace App\Admin\Resources\MailcowDomainResource\Pages;

use App\Admin\Resources\MailcowDomainResource;
use App\Services\Mailcow\DomainSync;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListMailcowDomains extends ListRecords
{
    protected static string $resource = MailcowDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The cron's read, now: a domain just made shows without waiting the 15 minutes.
            Action::make('refresh')->label('Read the servers now')->icon('ri-refresh-line')
                ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.update'))
                ->action(function () {
                    foreach (DomainSync::servers() as $server) {
                        try {
                            $result = DomainSync::sync($server, dns: false); // the DNS statuses with the cron (review of #494)
                            Notification::make()->success()->title($server->name)
                                ->body("{$result['seen']} domain(s), {$result['new']} new, {$result['gone']} gone, {$result['mailboxes']} mailbox(es)")->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title($server->name)->body(Redact::text($e->getMessage()))->send();
                        }
                    }
                }),
        ];
    }
}
