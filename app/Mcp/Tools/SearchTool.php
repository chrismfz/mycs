<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Ops\Scrub;
use App\Support\ServiceIdentifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Find a record by what staff know of it (owner 2026-11-14: «from a ticket, the customer and the rest»): accounts by a
 * contact's name, company, email, phone, VAT number or tax id, a login's email, a domain or a service's domain, username
 * or IP; tickets by their subject or a message's text; domains and services by name. Each part only to staff whose role
 * may see it, as in the admin. Read only; the whole record comes from `client`, `ticket`, `domain`, `service`.
 */
#[Name('search')]
#[Description('Find accounts, services, domains and tickets by a piece of text (at least 3 characters): a name, company, email, phone, VAT number or tax id, a domain, a service\'s username or IP, a ticket\'s subject or message text. Returns short rows with ids; ask `client`, `service`, `domain` or `ticket` for the whole record. `in` narrows it to one kind. Each kind only if your role may see it. Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchTool extends McpTool
{
    public const KINDS = ['clients', 'services', 'domains', 'tickets'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The text to look for (at least 3 characters; matched anywhere, case aside).')->required(),
            'in' => $schema->string()->description('Only one kind: clients, services, domains or tickets (default: all you may see).'),
            'limit' => $schema->integer()->description('Rows per kind (default 10, at most 50).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.clients.viewAny';
    }

    protected function run(Request $request): array
    {
        $query = trim((string) $request->get('query'));
        if (mb_strlen($query) < 3) {
            throw new \InvalidArgumentException('Give at least 3 characters to search for.');
        }
        $in = trim((string) $request->get('in'));
        if ($in !== '' && !in_array($in, self::KINDS, true)) {
            throw new \InvalidArgumentException('`in` is one of: ' . implode(', ', self::KINDS) . '.');
        }
        $staff = $request->user();
        $may = fn (string $permission) => $staff instanceof Staff && $staff->hasPermission($permission);
        $limit = self::limit($request, 10, 50);
        $wanted = fn (string $kind) => $in === '' || $in === $kind;

        $result = ['query' => $query];
        if ($wanted('clients')) {
            $result['clients'] = self::clients($query, $limit);
        }
        if ($wanted('services') && $may('admin.services.viewAny')) {
            $result['services'] = self::services($query, $limit);
        }
        if ($wanted('domains') && $may('admin.domains.viewAny')) {
            $result['domains'] = self::domains($query, $limit);
        }
        if ($wanted('tickets') && $may('admin.tickets.viewAny')) {
            $result['tickets'] = self::tickets($query, $limit);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function clients(string $text, int $limit): array
    {
        $ids = Contact::query()->whereNull('archived_at')->whereNotNull('client_id')
            ->where(fn (Builder $q) => self::anyContains($q, ['first_name', 'last_name', 'company_name', 'email', 'phone', 'vat_number', 'tax_id'], $text))
            ->limit($limit * 5)->pluck('client_id')
            ->merge(self::anyContains(User::query(), ['email'], $text)->limit($limit)->get()->flatMap(fn (User $user) => $user->clients()->pluck('clients.id')))
            ->merge(self::anyContains(Domain::query(), ['fqdn'], $text)->limit($limit)->pluck('client_id'))
            ->merge(self::anyContains(Service::query(), ['domain', 'username', 'dedicated_ip'], $text)->limit($limit)->pluck('client_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->take($limit)->values()->all();

        return Client::query()->whereIn('id', $ids)->with(['primaryContact', 'group'])->withCount(['services', 'domains'])->orderByDesc('id')->get()
            ->map(fn (Client $client) => [
                'id' => $client->id, 'name' => $client->name, 'company' => $client->primaryContact?->company_name,
                'email' => $client->primaryContact?->email, 'vat_number' => $client->primaryContact?->vat_number,
                'group' => $client->group?->name, 'services' => $client->services_count, 'domains' => $client->domains_count,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private static function services(string $text, int $limit): array
    {
        return Service::query()->with(['product', 'license', 'client.primaryContact'])
            ->where(fn (Builder $q) => self::anyContains($q, ['domain', 'username', 'dedicated_ip', 'label'], $text))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Service $service) => [
                'id' => $service->id, 'status' => $service->status,
                'service' => ServiceIdentifier::named($service, trim((string) ($service->product->name ?? ''))),
                'account' => '#' . $service->client_id . ' ' . $service->client?->name, 'expires' => $service->expires_at?->toDateString(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private static function domains(string $text, int $limit): array
    {
        return self::anyContains(Domain::query()->with('client.primaryContact'), ['fqdn'], $text)->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Domain $domain) => [
                'id' => $domain->id, 'domain' => $domain->fqdn, 'status' => $domain->status->value,
                'expires' => $domain->expires_at?->toDateString(), 'account' => $domain->client_id ? '#' . $domain->client_id . ' ' . $domain->client->name : null,
            ])->all();
    }

    /** By subject, then by a message's text (newest first); the matching message's opening, so staff see why. @return list<array<string, mixed>> */
    private static function tickets(string $text, int $limit): array
    {
        $bySubject = self::anyContains(Ticket::query(), ['subject'], $text)->orderByDesc('id')->limit($limit)->pluck('id');
        $byText = self::anyContains(TicketMessage::query(), ['message'], $text)->orderByDesc('id')->limit($limit * 3)->get(['id', 'ticket_id', 'message']);
        $ids = $bySubject->merge($byText->pluck('ticket_id'))->map(fn ($id) => (int) $id)->unique()->take($limit)->values();
        $hits = $byText->groupBy('ticket_id');

        return Ticket::query()->with(['department', 'client.primaryContact'])->whereIn('id', $ids)->orderByDesc('id')->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id, 'reference' => $ticket->reference, 'subject' => Scrub::text($ticket->subject), 'status' => $ticket->status,
                'department' => $ticket->department?->name, 'account' => $ticket->client_id ? '#' . $ticket->client_id . ' ' . $ticket->client?->name : null,
                'updated_at' => $ticket->updated_at?->toDateTimeString(),
                'matched_text' => ($hit = $hits->get($ticket->id)?->first()) ? Scrub::text(mb_substr((string) $hit->message, 0, 300)) : null,
            ])->all();
    }

    /**
     * Any of $columns holds $text, case aside, its % and _ taken literally on MariaDB and SQLite alike (as
     * TicketResource::whereContains). The columns are ours, never the caller's.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns
     * @return TBuilder
     */
    private static function anyContains(Builder $query, array $columns, string $text): Builder
    {
        $escape = fn (string $value) => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
        // Also as typed: SQLite's LOWER folds ASCII only, so a Greek «Θεσσαλονίκη» would not find itself lowered.
        $likes = array_values(array_unique([$escape(mb_strtolower(trim($text))), $escape(trim($text))]));

        return $query->where(function (Builder $q) use ($columns, $likes) {
            foreach ($columns as $column) {
                foreach ($likes as $like) {
                    $q->orWhereRaw('LOWER(' . $column . ") LIKE ? ESCAPE '!'", [$like])->orWhereRaw($column . " LIKE ? ESCAPE '!'", [$like]);
                }
            }
        });
    }
}
