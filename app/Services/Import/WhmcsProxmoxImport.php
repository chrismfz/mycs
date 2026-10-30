<?php

namespace App\Services\Import;

use App\Models\Service;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * The VPS of WHMCS's Proxmox module (pvewhmcs, docs/proxmox.md A3): each live one's VMID and address from
 * mod_pvewhmcs_vms kept on its service, for staff, who map the guests by hand from Proxmox Guests (owner 2026-10-30:
 * never automatically; three review rounds kept finding a way an automatic match could give a service another
 * customer's VM). The VMID is that page's first suggestion, never a mapping. The service, its billing, IPs and
 * hostname come with the import as any other.
 */
final class WhmcsProxmoxImport
{
    /** The service's VMID in WHMCS's module: for staff, and Proxmox Guests' suggestion (ProxmoxGuest::suggestedServiceId). */
    public const WHMCS_VMID = 'whmcs_vmid';

    /** Its address in WHMCS's module, for staff. */
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
        $live = DB::table('services')->whereIn('status', self::LIVE)->pluck('id')->flip()->all();
        $kept = [];
        foreach ($vms as $vm) {
            $this->counts['vms']++;
            $id = (int) $vm['id']; // the module's row is its service's (tblhosting.id)
            $vmid = (int) ($vm['vmid'] ?? 0);
            if ($vmid < 1 || !isset($live[$id])) {
                continue; // an ended one's VMID Proxmox may have given another machine since
            }
            DB::table('properties')->updateOrInsert(['model_type' => Service::class, 'model_id' => $id, 'key' => self::WHMCS_VMID],
                ['name' => 'Proxmox VMID (from WHMCS)', 'value' => (string) $vmid, 'created_at' => now(), 'updated_at' => now()]);
            $ip = trim((string) ($vm['ipaddress'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                DB::table('properties')->updateOrInsert(['model_type' => Service::class, 'model_id' => $id, 'key' => self::WHMCS_VM_IP],
                    ['name' => 'Proxmox VM address (from WHMCS)', 'value' => $ip, 'created_at' => now(), 'updated_at' => now()]);
            }
            $kept[$id] = true;
            $this->counts['kept']++;
        }
        // An earlier run's, for a service no longer kept: never a stale hint (R3-548-1).
        DB::table('properties')->where('model_type', Service::class)->whereIn('key', [self::WHMCS_VMID, self::WHMCS_VM_IP])
            ->whereNotIn('model_id', array_keys($kept) ?: [0])->delete();
        $loose = DB::table('services')->whereIn('product_id', array_map('intval', $products))->whereIn('status', self::LIVE)->pluck('id')
            ->reject(fn ($id) => isset($kept[(int) $id]))->values()->all();
        $this->counts['unmapped'] = count($loose);
        if ($vms !== [] || $loose !== []) {
            $this->notes[] = "Proxmox: every VPS is mapped by staff from Proxmox Guests once the cluster is read ({$this->counts['kept']} with WHMCS's VMID as the suggestion"
                . ($loose !== [] ? '; ' . count($loose) . ' with none: service #' . implode(', #', $loose) : '') . ').';
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }
}
