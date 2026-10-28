<?php

namespace App\Services\Mailcow;

use App\Helpers\ExtensionHelper;
use App\Models\MailcowDomain;
use App\Models\MailcowMailbox;
use App\Models\Server;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MyCS\Extensions\Servers\Mailcow\Mailcow;
use Nixpal\Mailcow\MailcowClient;
use RuntimeException;
use Throwable;

/**
 * A mailcow server's domains and mailboxes read into mailcow_domains/mailcow_mailboxes (owner 2026-10-28: «what goes on
 * in each server», as for Proxmox): new domains added, mapped at once when exactly one live service on this server is
 * that domain; known ones refreshed (their mapping kept); the ones no longer listed marked gone (kept, with their
 * mapping). Mailboxes mirror the server. Reads only.
 */
final class DomainSync
{
    /** @return array{seen: int, new: int, gone: int, mailboxes: int} */
    public static function sync(Server $server): array
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Mailcow) {
            throw new \InvalidArgumentException("{$server->name} is not a mailcow server.");
        }
        $lock = Cache::lock("mailcow.sync.{$server->id}", 600);
        if (!$lock->get()) {
            throw new RuntimeException("{$server->name} is being read already: try again in a minute.");
        }
        try {
            $result = self::read($server, $extension);
            // Its domains' DNS, after the write (lookups, never in the transaction): a failure costs only the statuses.
            rescue(fn () => MailSetup::refreshStatuses($server->id));

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** @return array{seen: int, new: int, gone: int, mailboxes: int} */
    private static function read(Server $server, Mailcow $extension): array
    {
        $client = $extension->client(60);
        // Both read before anything is written: a failed read marks nothing gone. Mailboxes first: a domain made between
        // the two reads shows next time with its mailboxes, none of them dropped (review of #492).
        $mailboxes = $client->listMailboxes();
        $domains = $client->listDomains();
        if ($domains === [] && MailcowDomain::query()->where('server_id', $server->id)->whereNull('gone_at')->exists()) {
            throw new RuntimeException("{$server->name} listed no domain: check its API key. Nothing marked gone.");
        }

        $dkims = self::dkims($client, $domains);
        $now = now();
        $new = 0;
        $byDomain = [];
        foreach ($mailboxes as $mailbox) {
            $byDomain[strtolower((string) ($mailbox['domain'] ?? substr(strrchr((string) $mailbox['username'], '@') ?: '', 1)))][] = $mailbox;
        }

        return DB::transaction(function () use ($server, $domains, $byDomain, $dkims, $now, &$new) {
            $seen = [];
            $boxes = 0;
            foreach ($domains as $data) {
                $name = strtolower(trim((string) $data['domain_name']));
                $row = MailcowDomain::query()->firstOrNew(['server_id' => $server->id, 'domain' => $name]);
                $new += $row->exists ? 0 : 1;
                $row->fill([
                    'active' => (int) ($data['active_int'] ?? $data['active'] ?? 1) === 1,
                    'mailboxes' => self::int($data['mboxes_in_domain'] ?? 0), 'mailbox_limit' => self::int($data['max_num_mboxes_for_domain'] ?? 0),
                    'aliases' => self::int($data['aliases_in_domain'] ?? 0), 'alias_limit' => self::int($data['max_num_aliases_for_domain'] ?? 0),
                    'quota_bytes' => self::int($data['max_quota_for_domain'] ?? 0), 'used_bytes' => self::int($data['bytes_total'] ?? 0),
                    'messages' => self::int($data['msgs_total'] ?? 0), 'admins' => self::admins($data['domain_admins'] ?? null),
                    'last_seen_at' => $now, 'gone_at' => null,
                ]);
                if ($row->service_id === null && !$row->internal) {
                    $row->service_id = $row->suggestedServiceId();
                } elseif ($row->service_id !== null && !in_array($row->service?->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                    // Its service no longer live and another live one now the domain's (gone and back, even within one read):
                    // the new customer's, as a VMID reused; otherwise kept, to show whose it was (review of #492).
                    $row->service_id = $row->suggestedServiceId() ?? $row->service_id;
                }
                if (array_key_exists($name, $dkims)) { // one that could not be read keeps what was read before
                    $row->fill($dkims[$name]);
                }
                $row->save();
                $seen[] = $name;
                $boxes += self::mailboxes($row, $byDomain[$name] ?? []);
            }
            $goneQuery = MailcowDomain::query()->where('server_id', $server->id)->whereNull('gone_at')->whereNotIn('domain', $seen ?: ['']);
            // A domain gone keeps its mapping, not its people's addresses and names (review of #492).
            MailcowMailbox::query()->whereIn('mailcow_domain_id', (clone $goneQuery)->select('id'))->delete();
            $gone = $goneQuery->update(['gone_at' => $now]);

            return ['seen' => count($seen), 'new' => $new, 'gone' => $gone, 'mailboxes' => $boxes];
        });
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function mailboxes(MailcowDomain $domain, array $rows): int
    {
        $seen = [];
        foreach ($rows as $data) {
            $address = strtolower(trim((string) $data['username']));
            $logins = array_map(fn ($key) => self::int($data[$key] ?? 0), ['last_imap_login', 'last_smtp_login', 'last_pop3_login', 'last_sso_login']);
            MailcowMailbox::query()->updateOrCreate(['mailcow_domain_id' => $domain->id, 'address' => $address], [
                'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 255) ?: null,
                // 2: no login, mail still received (mailcow): active for its mail.
                'active' => (int) ($data['active_int'] ?? $data['active'] ?? 1) !== 0,
                'quota_bytes' => self::int($data['quota'] ?? 0), 'used_bytes' => self::int($data['quota_used'] ?? 0),
                'messages' => self::int($data['messages'] ?? 0),
                'last_login_at' => max($logins) > 0 ? Carbon::createFromTimestamp(max($logins)) : null,
            ]);
            $seen[] = $address;
        }
        MailcowMailbox::query()->where('mailcow_domain_id', $domain->id)->whereNotIn('address', $seen ?: [''])->delete();

        return count($seen);
    }

    /**
     * Each domain's DKIM record as mailcow has it (selector, record; none yet: nulls), read before anything is written; a
     * domain whose record cannot be read is left out.
     *
     * @param  list<array<string, mixed>>  $domains
     * @return array<string, array{dkim_selector: ?string, dkim_txt: ?string}>
     */
    private static function dkims(MailcowClient $client, array $domains): array
    {
        $read = [];
        foreach ($domains as $data) {
            $name = strtolower(trim((string) $data['domain_name']));
            try {
                $dkim = $client->getDkim($name);
            } catch (Throwable) {
                continue;
            }
            $read[$name] = ['dkim_selector' => filled($dkim['dkim_selector'] ?? null) ? mb_substr((string) $dkim['dkim_selector'], 0, 63) : null,
                'dkim_txt' => filled($dkim['dkim_txt'] ?? null) ? (string) $dkim['dkim_txt'] : null];
        }

        return $read;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** @return list<string> */
    private static function admins(mixed $value): array
    {
        $list = is_array($value) ? $value : (is_string($value) && $value !== '' ? preg_split('/[\s,]+/', $value) : []);

        return array_values(array_filter(array_map(fn ($a) => is_scalar($a) ? trim((string) $a) : '', $list)));
    }

    /** @return Collection<int, Server> */
    public static function servers()
    {
        return Server::query()->where('extension', 'Mailcow')->where('enabled', true)->with('settings')->get();
    }
}
