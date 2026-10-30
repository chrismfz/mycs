<?php

namespace App\Services\Import;

use App\Models\Service;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * The VPS of WHMCS's Proxmox module (pvewhmcs, docs/proxmox.md A3): each one's VMID from mod_pvewhmcs_vms kept on its
 * service (WHMCS_VMID), for the first read of the cluster here to map its guest (GuestSync::mapFromWhmcs): the import
 * needs no network, and proxmox_guests starts empty after it. The live ones with no row (made by hand in WHMCS) are
 * listed: staff map them from Proxmox Guests (its suggestion from IP, MAC or name). Their IPs come with the IP pools'
 * import (app:import-whmcs-ip-pools).
 */
final class WhmcsProxmoxImport
{
    /** The service's VMID in WHMCS's module, until the cluster's first read maps its guest (then it goes). */
    public const WHMCS_VMID = 'whmcs_vmid';

    /** A VMID the cluster's read used already (mapped or not): an importer's later run never brings it back (R2-548-1). */
    public const WHMCS_VMID_USED = 'whmcs_vmid_used';

    /** Its address in WHMCS's module: what the first read checks the guest by, beside its name (code review 1 of #548). */
    public const WHMCS_VM_IP = 'whmcs_vm_ip';

    private const LIVE = [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING];

    /** @var array<string, int> */
    private array $counts = ['vms' => 0, 'kept' => 0, 'unmapped' => 0];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        try {
            $vms = $this->whmcs->query('SELECT * FROM mod_pvewhmcs_vms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $products = $this->whmcs->query("SELECT id FROM tblproducts WHERE servertype = 'pvewhmcs'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            if (preg_match('/doesn\'t exist|no such table|42S02/i', $e->getMessage() . ' ' . $e->getCode()) === 1) {
                return ['counts' => $this->counts, 'notes' => $this->notes]; // no Proxmox module in this WHMCS (nixpal)
            }
            throw $e;
        }
        $services = DB::table('services')->pluck('status', 'id')->all();
        $used = DB::table('properties')->where('model_type', Service::class)->where('key', self::WHMCS_VMID_USED)->pluck('model_id')->flip()->all();
        // Only a service of the module's products (code review 3 of #548: a VM WHMCS never suspended is not made one MyCS
        // suspends); the product ids are WHMCS's.
        $ofModule = DB::table('services')->whereIn('product_id', array_map('intval', $products))->pluck('id')->flip()->all();
        $ips = [];
        // A live service's VMID only (ADV-548-1/2: an ended one's VMID Proxmox may have given another machine since), and
        // only one claimed by a single live service (ADV-548-3: two claims are staff's to settle).
        $claims = [];
        foreach ($vms as $vm) {
            $this->counts['vms']++;
            $id = (int) $vm['id']; // the module's row is its service's (tblhosting.id)
            $vmid = (int) ($vm['vmid'] ?? 0);
            if ($vmid > 0 && in_array($services[$id] ?? null, self::LIVE, true) && isset($ofModule[$id]) && !isset($used[$id])) {
                $claims[$vmid][] = $id;
                $ips[$id] = trim((string) ($vm['ipaddress'] ?? ''));
            }
        }
        $kept = [];
        foreach ($claims as $vmid => $ids) {
            if (count($ids) > 1) {
                $this->notes[] = "VMID {$vmid} is claimed by " . count($ids) . ' live services in WHMCS\'s module (service #' . implode(', #', $ids)
                    . '): none mapped from it, staff map the right one.';

                continue;
            }
            $id = $ids[0];
            DB::table('properties')->updateOrInsert(['model_type' => Service::class, 'model_id' => $id, 'key' => self::WHMCS_VMID],
                ['name' => 'Proxmox VMID (from WHMCS)', 'value' => (string) $vmid, 'created_at' => now(), 'updated_at' => now()]);
            if (filter_var($ips[$id], FILTER_VALIDATE_IP) !== false) {
                DB::table('properties')->updateOrInsert(['model_type' => Service::class, 'model_id' => $id, 'key' => self::WHMCS_VM_IP],
                    ['name' => 'Proxmox VM address (from WHMCS)', 'value' => $ips[$id], 'created_at' => now(), 'updated_at' => now()]);
            }
            $kept[$id] = true;
            $this->counts['kept']++;
        }
        // The live VPS WHMCS's module never knew (made by hand): for staff to map.
        $loose = DB::table('services')->whereIn('product_id', array_map('intval', $products))->whereIn('status', self::LIVE)->pluck('id')
            ->reject(fn ($id) => isset($kept[(int) $id]))->values()->all();
        $this->counts['unmapped'] = count($loose);
        if ($loose !== []) {
            $this->notes[] = count($loose) . ' live VPS with no VM in WHMCS\'s module (service #' . implode(', #', $loose)
                . '): map them from Proxmox Guests once the cluster is read (its suggestion from IP, MAC or name).';
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }
}
