<?php

namespace MyCS\Extensions\Servers\Redfish;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\OfflineMachineServer;
use App\Support\PanelConnection;
use App\Support\Redfish\ActionPanelTransport;
use App\Support\Redfish\PanelTransport;
use MyCS\Redfish\Machine;
use MyCS\Redfish\RedfishClient;
use MyCS\Redfish\RedfishException;
use Throwable;

/**
 * One physical machine with a BMC that speaks Redfish (Supermicro, iDRAC, iLO; owner 2026-11-11): **one server per
 * machine**, with «Max Accounts» 1. The service on it is the customer who rents the whole machine; none on it and the
 * machine is free; «Max Accounts» 0 and it is our own, never given to an order. MyCS reads its BMC (what it is,
 * on or off, health, watts, its parts: Utilities → Machines) and keeps the last read: no history, no alerts (LibreNMS's).
 * With «use the account above» on, or an actions' account set, staff may also power it, light its locate light, set its next boot and restart its BMC
 * (MachineActions). Every server action on its service is still a task for staff, as an offline machine's.
 */
#[ExtensionMeta(
    name: 'Redfish machine',
    description: 'One physical server read through its BMC (Redfish): model, serial, power state, health, watts. Read-only; every action a task for staff',
    version: 'builtin',
    author: 'MyCS',
)]
class Redfish extends OfflineMachineServer
{
    public function getConfig($values = []): array
    {
        return [
            ['name' => 'host', 'type' => 'text', 'label' => 'BMC address', 'placeholder' => 'https://10.0.0.20', 'validation' => 'url:https', 'required' => true,
                'description' => 'The machine\'s BMC (IPMI / iDRAC / iLO web address), on the management network. One server here per machine: set «Max Accounts» to 1, or to 0 for a machine of our own.'],
            ...PanelConnection::fields(),
            ['name' => 'username', 'type' => 'text', 'label' => 'BMC username', 'required' => true,
                'description' => 'A read-only account is enough for reading (Supermicro «User», iDRAC «Read Only», iLO with login only). For the actions below with this same account: Operator.'],
            ['name' => 'password', 'type' => 'password', 'label' => 'BMC password', 'required' => true, 'encrypted' => true],
            ['name' => 'actions_same_account', 'type' => 'checkbox', 'label' => 'Actions: use the account above', 'database_type' => 'boolean', 'default' => false, 'required' => false,
                'description' => 'Staff may power the machine, light its locate light, set its next boot and restart its BMC from its page (Utilities → Machines), with the account above: it then needs the Operator role. Off, and no other account below: no actions are offered.'],
            ['name' => 'action_username', 'type' => 'text', 'label' => 'Actions: another BMC username', 'required' => false,
                'description' => 'Optional, instead of the checkbox: a second account (Operator) used only when staff press a button, so that the account of the hourly read stays read-only.'],
            ['name' => 'action_password', 'type' => 'password', 'label' => 'Actions: its password', 'required' => false, 'encrypted' => true],
            ['name' => 'location', 'type' => 'text', 'label' => 'Location', 'required' => false, 'placeholder' => 'Rack 4, U12',
                'description' => 'Where it is: datacenter, rack, unit.'],
        ];
    }

    /**
     * What stays on the service, under an Offline Dedicated's keys: a service moved here keeps these two. Its other
     * `machine_*` values stay stored but are not shown (the BMC says the serial and the model; the rest is «Location»).
     */
    public function serviceFields(): array
    {
        return [
            ['name' => 'machine_os', 'label' => 'Operating system', 'type' => 'text'],
            ['name' => 'machine_notes', 'label' => 'Notes', 'type' => 'textarea'],
        ];
    }

    public function client(): RedfishClient
    {
        $base = rtrim(trim((string) $this->config('host')), '/');

        return new RedfishClient(new PanelTransport($base, trim((string) $this->config('ip')) ?: null, (bool) $this->config('self_signed')),
            trim((string) $this->config('username')), (string) $this->config('password'));
    }

    /**
     * The machine as its BMC says it now (three to five requests, seconds on a slow BMC); with its parts (fans, memory,
     * drives: tens of requests, within $budget seconds) for Utilities → Machines.
     */
    public function hardware(bool $parts = false, float $budget = 45.0): Machine
    {
        return $this->client()->machine($parts, $budget);
    }

    /**
     * The account the actions are sent with: their own where one is set, else the reads' when «use the account above»
     * is on (owner 2026-11-11: a second account is a choice, not a must), else none: no action is offered.
     *
     * @return array{0: string, 1: string}|null
     */
    private function actionAccount(): ?array
    {
        $own = [trim((string) $this->config('action_username')), (string) $this->config('action_password')];
        if ($own[0] !== '' && $own[1] !== '') {
            return $own;
        }
        $reads = [trim((string) $this->config('username')), (string) $this->config('password')];

        return $this->config('actions_same_account') && $reads[0] !== '' && $reads[1] !== '' ? $reads : null;
    }

    /** Whether staff may act on the machine. */
    public function canAct(): bool
    {
        return $this->actionAccount() !== null;
    }

    /** The client of one action: the actions' account and a transport that may change things. */
    public function actionClient(): RedfishClient
    {
        [$username, $password] = $this->actionAccount() ?? throw new RedfishException('No account is set for this machine\'s actions.');
        $base = rtrim(trim((string) $this->config('host')), '/');

        return new RedfishClient(new ActionPanelTransport($base, trim((string) $this->config('ip')) ?: null, (bool) $this->config('self_signed')), $username, $password);
    }

    public function testConfig(): bool|string
    {
        try {
            $this->hardware();
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return true;
    }
}
