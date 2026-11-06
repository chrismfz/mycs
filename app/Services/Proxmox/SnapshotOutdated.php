<?php

namespace App\Services\Proxmox;

use RuntimeException;

/**
 * A rollback refused because the VM's settings changed since its snapshot (ADV-SNAP-1): Proxmox puts the snapshot's
 * config back with its disks, so a VM downgraded since would get its old memory and cores back. The customer is told to
 * take a new snapshot instead.
 */
final class SnapshotOutdated extends RuntimeException {}
