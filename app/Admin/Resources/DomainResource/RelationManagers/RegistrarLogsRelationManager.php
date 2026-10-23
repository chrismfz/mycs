<?php

namespace App\Admin\Resources\DomainResource\RelationManagers;

use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\DomainManagementService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
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
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime(),
                TextColumn::make('action')->label(__('Action')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->color(fn (string $state) => match ($state) {
                        DomainRegistrarLog::STATUS_FAILED => 'danger',
                        DomainRegistrarLog::STATUS_OK => 'success',
                        default => 'gray',
                    }),
                // Staff, or the customer in the client area (D7-2), else the system.
                TextColumn::make('by')->label(__('By'))->placeholder(__('System'))
                    ->state(fn ($record) => match (true) {
                        $record->staff_id !== null => $record->staff->name,
                        $record->user_id !== null => $record->user->name . ' (' . __('customer') . ')',
                        default => null,
                    }),
                // What changed, before → after (owner 2026-10-23: «which were they before?»), where the log has it.
                TextColumn::make('change')->label(__('Change'))->placeholder('—')->wrap()
                    ->state(fn (DomainRegistrarLog $record) => self::change($record)),
                TextColumn::make('error')->label(__('Error'))->limit(80)->placeholder('—'),
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
                            Notification::make()->danger()->title(__('Not restored'))->body($e->getMessage())->send();
                        }
                    }),
                ViewAction::make()->schema(fn (Schema $schema) => $schema->components([
                    TextEntry::make('request')->label(__('Request'))
                        ->state(fn (DomainRegistrarLog $record) => json_encode($record->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                        ->fontFamily('mono')->columnSpanFull(),
                    TextEntry::make('response')->label(__('Response'))
                        ->state(fn (DomainRegistrarLog $record) => json_encode($record->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                        ->fontFamily('mono')->columnSpanFull(),
                    TextEntry::make('transactions')->label(__('Registry transactions'))->placeholder('—')
                        ->state(fn (DomainRegistrarLog $record) => $record->transactions === null ? null : collect($record->transactions)
                            ->map(fn (array $t) => ($t['command'] ?? '?') . ' ' . ($t['code'] ?? (($t['http'] ?? 0) === 0 ? __('no answer') : 'HTTP ' . $t['http']))
                                . '  clTRID ' . ($t['clTRID'] ?? '—') . '  svTRID ' . ($t['svTRID'] ?? '—'))
                            ->all())
                        ->listWithLineBreaks()->fontFamily('mono')->columnSpanFull(),
                    TextEntry::make('error')->label(__('Error'))->placeholder('—')->columnSpanFull(),
                ])),
            ]);
    }
}
