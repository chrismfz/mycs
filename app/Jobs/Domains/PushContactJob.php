<?php

namespace App\Jobs\Domains;

use App\Contracts\UpdatesContacts;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\ModuleAction;
use App\Services\Domains\DomainRegistrarFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * P1b-2: one registrar contact (a handle) set to its contact's address, phone and email. What the contact is now, read
 * when it runs (a later edit sends the latest). The same request again changes nothing more: retried a few times; still
 * failing, each of its domains has an open Pending Module Action, which staff retry or resolve.
 */
class PushContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public int $timeout = 120;

    /** @param  list<int>  $domainIds */
    public function __construct(public int $contactId, public string $handle, public array $domainIds) {}

    public function handle(DomainRegistrarFactory $factory): void
    {
        $contact = Contact::query()->find($this->contactId);
        $domains = Domain::query()->whereKey($this->domainIds)->get();
        $domain = $domains->first();
        if ($contact === null || $domain === null) {
            return;
        }
        // One update of a handle at a time (two edits close together: the later one's job sends the latest anyway).
        Cache::lock("registrar-contact:{$this->handle}", 300)->block(30, function () use ($factory, $contact, $domain, $domains) {
            try {
                $connection = $domain->effectiveRegistrarConnection() ?? throw new RuntimeException("{$domain->fqdn} has no registrar connection.");
                $registrar = $factory->for($connection);
                if (!$registrar instanceof UpdatesContacts) {
                    return; // a registrar that keeps no contacts of ours (none today)
                }
                $registrar->updateContact($this->handle, $contact->fresh() ?? $contact, $factory->credentialsFor($connection));
            } catch (Throwable $e) {
                if ($this->attempts() < $this->tries) {
                    throw $e; // retried
                }
                foreach ($domains as $failed) {
                    ModuleAction::domainFailed($failed, null, $connection->registrar ?? null, $e, ModuleAction::DOMAIN_CONTACT);
                }
                report($e);

                return;
            }
            foreach ($domains as $done) {
                ModuleAction::domainSucceeded($done, null, ModuleAction::DOMAIN_CONTACT);
            }
        });
    }
}
