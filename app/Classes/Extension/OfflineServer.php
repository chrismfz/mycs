<?php

namespace App\Classes\Extension;

/**
 * A server module with no API (owner 2026-10-24: VMs outside the fleet, servers in the rack or elsewhere): MyCS keeps
 * what staff write about the machine, and every server action (create, suspend, unsuspend, terminate, change package)
 * becomes a task for staff in Pending Module Actions, as WHMCS's «Auto Release» makes a to-do. The service's status
 * follows as for any module; the machine is staff's to change by hand.
 */
interface OfflineServer
{
    /**
     * The machine's details kept on the service (its properties), shown and edited on its page. Each: name (the
     * property's key), label, type (text, textarea, mac, ip, number), and an optional help.
     *
     * @return list<array{name: string, label: string, type: string, help?: string}>
     */
    public function serviceFields(): array;

    /** What it is, in a sentence and as its section's title («machine», «certificate»). */
    public function noun(): string;

    /** Whether a paid renewal is a task too (a certificate is bought again at its provider; a machine just runs on). */
    public function renewsByHand(): bool;
}
