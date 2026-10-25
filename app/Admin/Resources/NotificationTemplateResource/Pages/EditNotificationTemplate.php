<?php

namespace App\Admin\Resources\NotificationTemplateResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\Mail\TemplatePreview;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail as Mailer;
use Illuminate\Support\HtmlString;
use Throwable;

class EditNotificationTemplate extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // As a customer would get it, with the texts on the form now, saved or not (owner 2026-10-25).
            Action::make('preview')->label(__('Preview'))->icon('ri-eye-line')->color('gray')
                ->modalHeading(fn () => __('Preview') . ': ' . $this->template()->key)
                ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->modalContent(fn () => new HtmlString(collect(TemplatePreview::locales())->map(fn (string $locale) => $this->previewHtml($locale))->implode(''))),
            Action::make('sendTest')->label(__('Send test to me'))->icon('ri-mail-send-line')->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => __('The texts on the form now, with made-up data, to :email, in each language.', ['email' => Auth::guard('staff')->user()?->email]))
                ->action(function () {
                    $to = (string) Auth::guard('staff')->user()?->email;
                    try {
                        foreach (TemplatePreview::locales() as $locale) {
                            $mail = TemplatePreview::render($this->draft(), $locale);
                            Mailer::html($mail['html'], fn ($message) => $message->to($to)->subject('[TEST ' . strtoupper($locale) . '] ' . $mail['subject']));
                        }
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title(__('Not sent'))->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title(__('Sent to :email', ['email' => $to]))->send();
                }),
            DeleteAction::make(),
        ];
    }

    private function template(): NotificationTemplate
    {
        $record = $this->getRecord();
        assert($record instanceof NotificationTemplate);

        return $record;
    }

    /** The template with what the form holds now (not saved). */
    private function draft(): NotificationTemplate
    {
        $state = (array) $this->data;
        $draft = clone $this->template();
        foreach (['subject', 'body', 'in_app_title', 'in_app_body'] as $field) {
            if (array_key_exists($field, $state)) {
                $draft->{$field} = $state[$field];
            }
        }
        if (is_array($state['translations'] ?? null)) {
            $draft->translations = $state['translations'];
        }

        return $draft;
    }

    private function previewHtml(string $locale): string
    {
        try {
            $mail = TemplatePreview::render($this->draft(), $locale);
        } catch (Throwable $e) {
            return '<p class="text-sm font-medium mt-2">' . e(strtoupper($locale)) . '</p><p class="text-sm text-danger-600">⚠ ' . e(__('It does not render: :reason', ['reason' => $e->getMessage()])) . '</p>';
        }

        return '<p class="text-sm font-medium mt-2">' . e(strtoupper($locale)) . ' · ' . e($mail['subject']) . '</p>' . TemplatePreview::frame($mail['html']);
    }
}
