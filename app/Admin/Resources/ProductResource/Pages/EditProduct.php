<?php

namespace App\Admin\Resources\ProductResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ProductResource;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Server;
use App\Support\ProductSettings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('duplicate')
                ->label('Duplicate')
                ->requiresConfirmation()
                ->action(function (Product $record) {
                    $new_record = $record->replicate();
                    $new_record->name = 'Copy of ' . $record->name;
                    $new_record->slug = Str::slug($new_record->name);
                    $new_record->save();

                    // Duplicate settings
                    $record->settings->each(function ($setting) use ($new_record) {
                        $new_setting = $setting->replicate();
                        $new_setting->settingable_id = $new_record->id;
                        $new_setting->save();
                    });

                    $record->upgrades->each(function ($upgrade) use ($new_record) {
                        // Duplicate the upgrade relationship (its a belongsToMany, so we need to use the pivot table)
                        $new_record->upgrades()->attach($upgrade->id);
                    });

                    // Duplicate plans and their prices
                    $record->plans->each(function ($plan) use ($new_record) {
                        $new_plan = $plan->replicate();
                        $new_plan->priceable_id = $new_record->id;
                        $new_plan->save();

                        // Duplicate plan prices
                        $plan->prices->each(function ($price) use ($new_plan) {
                            $new_price = $price->replicate();
                            $new_price->plan_id = $new_plan->id;
                            $new_price->save();
                        });
                    });

                    Notification::make()
                        ->title('Product duplicated successfully!')
                        ->success()
                        ->send();

                    return $this->redirect(static::getResource()::getUrl('edit', [
                        'record' => $new_record,
                    ]), true);
                }),
            DeleteAction::make()
                ->before(function (Product $record, DeleteAction $action) {
                    if ($record->inUse()) {
                        Notification::make()
                            ->title('Whoops!')
                            ->body(__('This product is used by services or upgrades: hide it instead of deleting it.'))
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                })->after(function (Product $record) {
                    $record->settings()->delete();
                }),
            AuditAction::make(),
        ];
    }

    /** The save in one transaction: a refused plan rolls back the plans removed before it too (R5b of #73). */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * A plan removed from the form is deleted on save: one a service or an upgrade started using meanwhile is refused
     * (Plan's guard, audit sweep BIL-6) with a message, not an error page; the whole save is rolled back.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Product not saved'))->body($e->getMessage())->send();
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // An encrypted setting (a PSK) never goes to the browser: empty, kept as stored when left empty.
        $product = $this->getRecord();
        $server = Server::query()->find($product->getAttribute('server_id'));
        $options = $server !== null ? rescue(fn () => ExtensionHelper::getProductConfigOnce($server, []), [], false) : [];
        $data['settings'] = ProductSettings::forForm($product, $options);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            return $record;
        }

        $product_config = ExtensionHelper::getProductConfig(Server::findOrFail($data['server_id']), $data['settings']);

        ProductSettings::save($record, $product_config, $data['settings']);

        return $record;
    }
}
