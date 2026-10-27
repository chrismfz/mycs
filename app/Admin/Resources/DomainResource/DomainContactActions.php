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
                // Only the domain's own account's contact (review: bad data after an account move).
                ->visible(fn (Domain $record) => self::can('admin.clients.update') && $record->{"{$role}Contact"} !== null
                    && (int) $record->{"{$role}Contact"}->client_id === (int) $record->client_id)
                ->modalDescription(__('The contact of the account, wherever it is used. Its address, phone and email are sent to the registrars of the domains that use it; its name and company stay as the registrars hold them.'))
                ->fillForm(fn (Domain $record) => $record->{"{$role}Contact"}->attributesToArray())
                ->schema(ContactForm::fields())->modalWidth('3xl')
                ->action(function (Domain $record, array $data) use ($role) {
                    $contact = $record->{"{$role}Contact"};
                    $contact?->fill($data)->save(); // fillable fields only, as on the account
                    $record->unsetRelation("{$role}Contact");
                    Notification::make()->success()->title(__('Saved.'))->send();
                }),

            Action::make("linkContact_{$role}")->label(__('Link here'))->icon('heroicon-o-link')->link()->size('xs')->color('warning')
                ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::service()->isManageable($record)
                    && self::service()->isUnlinked($record, $role))
                ->modalHeading(fn (Domain $record) => __('Link :handle as the :role', ['handle' => self::service()->handleFor($record, $role), 'role' => __('domains.role.' . $role)]))
                ->schema(fn (Domain $record) => [
                    Select::make('contact')->label(__('Contact'))->required()->searchable()
                        // A new account contact needs the account permission too (review).
                        ->options((self::can('admin.clients.update') ? ['new' => __('A new contact of the account, from the registrar\'s record')] : [])
                            + Contact::query()->where('client_id', $record->client_id)->whereNull('archived_at')->get()
                                ->mapWithKeys(fn (Contact $c) => [$c->id => $c->label()])->all())
                        // The contact this account already linked to this handle on another domain, if any.
                        ->default(fn () => self::alreadyLinked($record, (string) self::service()->handleFor($record, $role))),
                ])
                ->modalDescription(__('Nothing is sent to the registrar: WHOIS stays as it is. You say who this handle is here; every role with this handle at the registrar is linked with it.'))
                ->action(function (Domain $record, array $data) use ($role) {
                    try {
                        if ($data['contact'] === 'new' && !self::can('admin.clients.update')) {
                            throw new \InvalidArgumentException(__('Not allowed.'));
                        }
                        self::service()->linkContact($record, $role, $data['contact'] === 'new' ? null : (int) $data['contact'], self::staff());
                        Notification::make()->success()->title(__('Linked.'))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('Not linked.'))->body(e($e->getMessage()))->send();
                    }
                    $record->refresh();
                }),
        ];
    }

    /**
     * Its related contact (docs/ekdosi-bridge.md): who Ekdosi invoices its lines to, as a service's. Not a role at the
     * registrar: nothing is sent there.
     */
    public static function related(): Action
    {
        return Action::make('relatedContact')->label(__('Change'))->icon('heroicon-o-pencil-square')->link()->size('xs')
            ->visible(fn () => self::can('admin.domains.update'))
            ->fillForm(fn (Domain $record) => ['contact_id' => $record->contact_id])
            ->schema(fn (Domain $record) => [
                Select::make('contact_id')->label(__('Related contact'))->searchable()->nullable()
                    ->placeholder(__('The account'))
                    ->options(Contact::query()->where('client_id', $record->client_id)->whereNull('archived_at')->get()
                        ->mapWithKeys(fn (Contact $c) => [$c->id => $c->label() . ($c->email && $c->label() !== $c->email ? " <{$c->email}>" : '')])->all()),
            ])
            ->modalDescription(__('Who this domain is for, among the account\'s contacts: Ekdosi issues the document for its lines to this contact. Nothing is sent to the registrar.'))
            ->action(function (Domain $record, array $data) {
                abort_unless(self::can('admin.domains.update'), 403);
                $id = filled($data['contact_id'] ?? null) ? (int) $data['contact_id'] : null;
                // Only a contact of the domain's own account, not archived (as the picker).
                abort_if($id !== null && !Contact::query()->whereKey($id)->where('client_id', $record->client_id)->whereNull('archived_at')->exists(), 422);
                $record->forceFill(['contact_id' => $id])->save();
                $record->unsetRelation('contact');
                Notification::make()->success()->title(__('Saved.'))->send();
            });
    }

    /** A contact of the domain's account already linked to this handle on one of its domains. */
    private static function alreadyLinked(Domain $record, string $handle): ?int
    {
        if ($handle === '') {
            return null;
        }
        foreach (Domain::ROLES as $role) {
            $id = Domain::query()->where('client_id', $record->client_id)->where("{$role}_handle", $handle)->whereNotNull("{$role}_contact_id")->value("{$role}_contact_id");
            if ($id !== null && Contact::query()->whereKey($id)->where('client_id', $record->client_id)->whereNull('archived_at')->exists()) {
                return (int) $id;
            }
        }

        return null;
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
        // Read once per request however often the modal renders (each read is logged): kept on the request, never longer.
        $key = "domain-contact-card:{$record->id}:{$role}";
        if (!request()->attributes->has($key)) {
            request()->attributes->set($key, self::readCard($record, $role));
        }

        return request()->attributes->get($key);
    }

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
