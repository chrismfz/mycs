<?php

namespace App\Admin\Resources\DomainResource;

use App\Admin\Schemas\ContactForm;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Staff;
use App\Services\Domains\DomainManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * A domain role's contact, from the domain's page (docs/domains.md D4c): what the registrar holds for its handle
 * (linked or not), the contact here edited as on the account, and a handle the registrar reports linked to a contact
 * here (an existing one, or a new one made from the registrar's record: an end customer under an agency's account).
 */
final class DomainContactActions
{
    /** @return list<Action> */
    public static function for(string $role): array
    {
        return [
            Action::make("registrarContact_{$role}")->label(__('At the registrar'))->icon('heroicon-o-magnifying-glass')->link()->size('xs')
                ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::service()->isManageable($record)
                    && self::service()->handleFor($record, $role) !== null)
                ->modalHeading(fn (Domain $record) => __('domains.role.' . $role) . ' · ' . self::service()->handleFor($record, $role))
                ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->modalContent(fn (Domain $record) => self::registrarCard($record, $role)),

            Action::make("editContact_{$role}")->label(__('Edit contact'))->icon('heroicon-o-pencil-square')->link()->size('xs')
                ->visible(fn (Domain $record) => self::can('admin.clients.update') && $record->{"{$role}Contact"} !== null)
                ->modalDescription(__('The contact of the account, wherever it is used. The registrar keeps its own copy until the contact is sent to it again.'))
                ->fillForm(fn (Domain $record) => $record->{"{$role}Contact"}->attributesToArray())
                ->schema(ContactForm::fields())
                ->action(function (Domain $record, array $data) use ($role) {
                    $contact = $record->{"{$role}Contact"};
                    $contact?->fill($data)->save(); // audited, as on the account
                    $record->unsetRelation("{$role}Contact");
                    Notification::make()->success()->title(__('Saved.'))->send();
                }),

            Action::make("linkContact_{$role}")->label(__('Link here'))->icon('heroicon-o-link')->link()->size('xs')->color('warning')
                ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::service()->isManageable($record)
                    && self::service()->isUnlinked($record, $role))
                ->modalHeading(fn (Domain $record) => __('Link :handle as the :role', ['handle' => self::service()->handleFor($record, $role), 'role' => __('domains.role.' . $role)]))
                ->modalDescription(__('Nothing is sent to the registrar: WHOIS stays as it is. You say who this handle is here.'))
                ->schema(fn (Domain $record) => [
                    Select::make('contact')->label(__('Contact'))->required()
                        ->options(['new' => __('A new contact of the account, from the registrar\'s record')]
                            + Contact::query()->where('client_id', $record->client_id)->get()->mapWithKeys(fn (Contact $c) => [$c->id => $c->displayName()])->all()),
                ])
                ->action(function (Domain $record, array $data) use ($role) {
                    try {
                        self::service()->linkContact($record, $role, $data['contact'] === 'new' ? null : (int) $data['contact'], self::staff());
                        Notification::make()->success()->title(__('Linked.'))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('Not linked.'))->body(e($e->getMessage()))->send();
                    }
                    $record->refresh();
                }),
        ];
    }

    private static function service(): DomainManagementService
    {
        return app(DomainManagementService::class);
    }

    private static function can(string $permission): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission($permission);
    }

    private static function staff(): ?Staff
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }

    /** What the registrar holds for the role's handle (escaped: it is the registrar's text). */
    private static function registrarCard(Domain $record, string $role): HtmlString
    {
        // Read once per request however often the modal renders (each read is logged).
        return self::$cards["{$record->id}:{$role}"] ??= self::readCard($record, $role);
    }

    /** @var array<string, HtmlString> */
    private static array $cards = [];

    private static function readCard(Domain $record, string $role): HtmlString
    {
        try {
            $contact = self::service()->registrarContact($record, $role, self::staff());
            if ($contact === null) {
                return new HtmlString(e(__('Not found at the registrar')));
            }
            $lines = array_filter([$contact->name, $contact->org, $contact->email, $contact->phone,
                trim(implode(' ', array_filter([$contact->address1, $contact->address2]))),
                trim(implode(' ', array_filter([$contact->postcode, $contact->city, $contact->country])))]);

            return new HtmlString(implode('<br>', array_map('e', $lines)));
        } catch (Throwable $e) {
            return new HtmlString(e($e->getMessage()));
        }
    }
}
