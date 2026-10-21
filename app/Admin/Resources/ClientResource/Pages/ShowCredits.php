<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Exceptions\DisplayException;
use App\Models\Credit;
use App\Models\Currency;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Account credit of a customer (docs/money-trail.md §2, decision 2): staff add or remove credit with a reason, like the
 * WHMCS «Credit Log». The balance is never edited or deleted directly: every change goes through the ledger and is
 * journaled.
 */
class ShowCredits extends ManageRelatedRecords
{
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'credits';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-coin-line';

    public static function getNavigationLabel(): string
    {
        return 'Credits';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('currency.code')
            ->columns([
                TextColumn::make('currency.code')->label(__('Currency')),
                TextColumn::make('formattedAmount')->label(__('Balance')),
            ])
            ->filters([])
            ->headerActions([
                $this->adjustAction('addCredit', __('Add credit'), add: true),
                $this->adjustAction('removeCredit', __('Remove credit'), add: false),
            ])
            ->recordActions([]);
    }

    private function adjustAction(string $name, string $label, bool $add): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($add ? 'success' : 'danger')
            ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('admin.credits.update'))
            ->schema([
                Select::make('currency_code')
                    ->label(__('Currency'))
                    ->options(fn () => Currency::query()->pluck('code', 'code'))
                    ->default(fn () => Credit::query()->where('client_id', $this->getOwnerRecord()->getKey())->value('currency_code') ?? config('settings.default_currency'))
                    ->live()
                    ->required(),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->required()
                    ->prefix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->prefix)
                    ->suffix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->suffix)
                    ->mask(RawJs::make(
                        <<<'JS'
                        $money($input, '.', '', 2)
                        JS
                    ))
                    ->rules([fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                        if (is_numeric($value) && (float) $value * 100 > CreditLedger::MAX_CENTS) {
                            $fail(__('The amount cannot be more than :max.', ['max' => number_format(CreditLedger::MAX_CENTS / 100, 2, '.', '')]));
                        } elseif (RecordPaymentService::toCents($value) <= 0) {
                            $fail(__('The amount must be positive.'));
                        }
                    }]),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->helperText(__('Kept with the credit history, for staff only.'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (array $data) use ($add, $label): void {
                $ledger = new CreditLedger;
                $clientId = (int) $this->getOwnerRecord()->getKey();
                $cents = RecordPaymentService::toCents($data['amount']);
                try {
                    $add
                        ? $ledger->add($clientId, $data['currency_code'], $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: $data['reason'])
                        : $ledger->remove($clientId, $data['currency_code'], $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: $data['reason']);
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Credit not changed'))->body($e->getMessage())->send();

                    throw new Halt;
                }
                Notification::make()->success()->title($label)->send();
            });
    }
}
