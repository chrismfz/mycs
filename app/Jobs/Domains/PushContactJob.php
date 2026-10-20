<?php

namespace App\Jobs\Domains;

use App\Contracts\UpdatesContacts;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\ModuleAction;
use App\Services\Domains\ContactPush;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarRefused;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * P1b-2: one registrar contact (a handle, at one connection) set to its contact's address, phone and email. Checked
 * again when it runs (adversarial A3–A5, A7 of #248): only the domains that still have this contact in a role under this
 * handle, of its account, not ended, through this same connection, switched on; never a handle another contact has
 * meanwhile. The contact as it is now (a later edit sends the latest). A refusal (the registrar's, or our own check) is
 * a Pending Module Action at once; anything else is tried 3 times, then one (failed(): a timeout or a lock too).
 */
class PushContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public int $timeout = 120;

    /** @param  list<int>  $domainIds */
    public function __construct(public int $contactId, public int $connectionId, public string $handle, public array $domainIds, public bool $email = true) {}

    public function handle(DomainRegistrarFactory $factory): void
    {
        $lock = Cache::lock("registrar-contact:{$this->connectionId}:{$this->handle}", 300);
        if (!$lock->get()) {
            $this->release(30); // another push of this handle under way: the latest details are sent after it

            return;
        }
        try {
            $contact = Contact::query()->find($this->contactId);
            $connection = DomainRegistrarConnection::query()->find($this->connectionId);
            $domains = $this->stillOurs($contact, $connection);
            if ($contact === null || !$connection instanceof DomainRegistrarConnection || $domains->isEmpty()) {
                return;
            }
            if (ContactPush::sharedWithOthers($this->handle, $contact->id)) {
                $this->record($domains, new \RuntimeException("The registrar contact {$this->handle} is also another contact's now: not sent."));

                return;
            }
            try {
                $registrar = $factory->for($connection);
                if (!$registrar instanceof UpdatesContacts) {
                    return; // a registrar that keeps no contacts of ours
                }
                $registrar->updateContact($this->handle, $contact, $factory->credentialsFor($connection), $this->email);
            } catch (RegistrarRefused|InvalidArgumentException $e) {
                $this->record($domains, $e); // the same request would be refused again: staff now

                return;
            } catch (RegistrarNotAsked $e) {
                if ($e->getPrevious() instanceof InvalidArgumentException || $this->attempts() >= $this->tries) {
                    $this->record($domains, $e);

                    return;
                }
                throw $e; // not sent (the registry busy, a connection lost): tried again
            } catch (Throwable $e) {
                if ($this->attempts() >= $this->tries) {
                    $this->record($domains, $e);

                    return;
                }
                throw $e;
            }
            foreach ($domains as $done) {
                ModuleAction::domainSucceeded($done, null, ModuleAction::DOMAIN_CONTACT, $this->handle);
            }
        } finally {
            $lock->release();
        }
    }

    /** Out of tries some other way (a timeout, a worker killed): staff are told all the same. */
    public function failed(Throwable $e): void
    {
        $this->record(Domain::query()->whereKey($this->domainIds)->get(), $e);
    }

    /** @return Collection<int, Domain> the domains this push is still for */
    private function stillOurs(?Contact $contact, ?DomainRegistrarConnection $connection): Collection
    {
        if ($contact === null || $connection === null || !$connection->isUsable()) {
            return collect();
        }

        return Domain::query()->whereKey($this->domainIds)->where('client_id', $contact->client_id)->get()
            ->filter(fn (Domain $domain) => !$domain->status->isTerminal() && $domain->effectiveRegistrarConnection()?->id === $connection->id
                && collect(Domain::ROLES)->contains(fn (string $role) => (int) $domain->{"{$role}_contact_id"} === $contact->id
                    && trim((string) $domain->{"{$role}_handle"}) === $this->handle))
            ->values();
    }

    /** @param  iterable<Domain>  $domains */
    private function record(iterable $domains, Throwable $e): void
    {
        $registrar = DomainRegistrarConnection::query()->whereKey($this->connectionId)->value('registrar');
        foreach ($domains as $domain) {
            ModuleAction::domainFailed($domain, null, is_string($registrar) ? $registrar : null, $e, ModuleAction::DOMAIN_CONTACT, $this->handle);
        }
        report($e);
    }
}
