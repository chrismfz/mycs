<?php

namespace App\Admin\Resources\DomainResource\RelationManagers;

use App\Admin\Resources\DomainRegistrarLogResource\RegistrarLogTable;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\DomainManagementService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Every call to the registrar for this domain (docs/domains.md D-17, D4b): what was asked and answered, by whom, or why
 * it failed. Secrets were masked before they were written; an EPP code is never here. Read-only.
 */
class RegistrarLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrarLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Registrar log');
    }

    /** Before → after, from what the log kept: its nameservers, its lock or privacy. */
    public static function change(DomainRegistrarLog $record): ?string
    {
        $request = (array) $record->request;
        if (!array_key_exists('before', $request)) {
            return null;
        }
        $show = fn (mixed $value) => is_bool($value) ? ($value ? __('On') : __('Off')) : (is_array($value) ? (implode(', ', array_map('strval', $value)) ?: '—') : (string) $value);
        $after = $request['nameservers'] ?? $request['transfer_lock'] ?? $request['whois_privacy'] ?? null;

        return $show($request['before']) . ' → ' . $show($after);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['staff', 'user'])) // who asked, per row (review R13)
            // What changed, before → after (owner 2026-10-23: «which were they before?»), where the log has it.
            ->columns([
                ...RegistrarLogTable::columns(withDomain: false),
                TextColumn::make('change')->label(__('Change'))->placeholder('—')->wrap()
                    ->state(fn (DomainRegistrarLog $record) => self::change($record)),
            ])
            ->recordActions([
                // Its nameservers back as they were before this change: asked of the registrar, logged as any change.
                Action::make('restoreNameservers')->label(__('Restore these'))->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->visible(fn (DomainRegistrarLog $record) => $record->action === 'nameservers' && $record->status === DomainRegistrarLog::STATUS_OK
                        && count((array) ($record->request['before'] ?? [])) >= 2
                        && (bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.manage'))
                    ->requiresConfirmation()
                    ->modalDescription(fn (DomainRegistrarLog $record) => __('Sets the nameservers at the registrar back to: :hosts', ['hosts' => implode(', ', (array) $record->request['before'])]))
                    ->action(function (DomainRegistrarLog $record) {
                        abort_unless((bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.manage'), 403);
                        $domain = $this->getOwnerRecord();
                        abort_unless($domain instanceof Domain, 404);
                        try {
                            app(DomainManagementService::class)->setNameservers($domain, array_values(array_map('strval', (array) $record->request['before'])), Auth::guard('staff')->user());
                            Notification::make()->success()->title(__('Nameservers restored.'))->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('Not restored'))->body(e($e->getMessage()))->send();
                        }
                    }),
                RegistrarLogTable::viewAction(),
            ]);
    }
}
