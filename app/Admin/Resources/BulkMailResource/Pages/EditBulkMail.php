<?php

namespace App\Admin\Resources\BulkMailResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\BulkMailResource;
use App\Exceptions\DisplayException;
use App\Models\BulkMail;
use App\Models\BulkMailRecipient;
use App\Models\Client;
use App\Services\Mail\BulkMailer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/** A mass mail's page: edited while a draft; previewed, tested and sent from here (BulkMailer). */
class EditBulkMail extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = BulkMailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label(__('Recipients'))->icon('ri-group-line')->color('gray')
                ->modalHeading(__('Who it is for'))->modalSubmitAction(false)
                ->modalDescription(fn (BulkMail $record) => $record->isDraft() ? $this->who($record) : $this->progress($record)),
            Action::make('test')->label(__('Send a test'))->icon('ri-mail-check-line')->color('gray')
                ->modalDescription(__('As the first customer it goes to would get it.'))
                // To the staff member only: shown, never taken from the form (reviews of #294).
                ->schema([TextInput::make('email')->label(__('To'))->default(fn () => auth('staff')->user()?->email)->disabled()->dehydrated(false)])
                ->action(function (BulkMail $record) {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $staff = auth('staff')->user();
                    app(BulkMailer::class)->sendTest($record->refresh(), $staff);
                    Notification::make()->title(__('A test is on its way to :email.', ['email' => $staff->email]))->success()->send();
                })->visible(fn (BulkMail $record) => $record->isDraft()),
            Action::make('send')->label(__('Send'))->icon('ri-send-plane-line')->color('danger')->requiresConfirmation()
                ->modalDescription(fn (BulkMail $record) => __('It goes to :count customers now, a batch a minute. It cannot be stopped or sent again.', ['count' => app(BulkMailer::class)->recipients($record)->count()]))
                ->visible(fn (BulkMail $record) => $record->isDraft() && (auth('staff')->user()?->hasPermission('admin.bulk_mails.send') ?? false))
                ->action(function (BulkMail $record) {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    try {
                        $count = app(BulkMailer::class)->start($record->refresh(), auth('staff')->user());
                    } catch (DisplayException $e) {
                        Notification::make()->title(e($e->getMessage()))->danger()->send();

                        return;
                    }
                    Notification::make()->title(__('Sending to :count customers.', ['count' => $count]))->success()->send();
                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                }),
            DeleteAction::make()->visible(fn (BulkMail $record) => $record->isDraft()),
        ];
    }

    /**
     * Only a draft is saved, checked on its row under a lock (reviews of #294): the form is disabled once it is sent, and
     * a save sent anyway (a stale tab, a crafted request, a second Send) changes nothing of what is being sent.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $locked = BulkMail::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (!$locked->isDraft()) {
                Notification::make()->title(__('This mass mail has been sent already.'))->danger()->send();
                $this->halt();
            }
            $record->update($data);

            return $record;
        });
    }

    /** Hidden once it is sent: what was sent stays as it was. */
    protected function getFormActions(): array
    {
        return $this->getRecord() instanceof BulkMail && $this->getRecord()->isDraft() ? parent::getFormActions() : [];
    }

    private function who(BulkMail $record): HtmlString
    {
        $recipients = app(BulkMailer::class)->recipients($record);
        $count = (clone $recipients)->count();
        $first = (clone $recipients)->orderBy('id')->limit(25)->get()->map(fn (Client $client) => e($client->name . ' <' . $client->owner()?->email . '>'));

        return new HtmlString(e(__(':count customers.', ['count' => $count])) . ($first->isEmpty() ? '' : '<br><br>' . $first->implode('<br>') . ($count > 25 ? '<br>…' : '')));
    }

    private function progress(BulkMail $record): string
    {
        $counts = $record->recipients()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return __(':sent sent, :skipped skipped (news turned off, no owner), :failed failed, :pending to send, of :count.', [
            'sent' => (int) ($counts[BulkMailRecipient::STATUS_SENT] ?? 0),
            'skipped' => (int) ($counts[BulkMailRecipient::STATUS_SKIPPED] ?? 0),
            'failed' => (int) ($counts[BulkMailRecipient::STATUS_FAILED] ?? 0) + (int) ($counts[BulkMailRecipient::STATUS_SENDING] ?? 0),
            'pending' => (int) ($counts[BulkMailRecipient::STATUS_PENDING] ?? 0),
            'count' => $record->recipients_count,
        ]);
    }
}
