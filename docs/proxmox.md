# Proxmox VE

Ξεκίνησε στις 2026-10-24 με μια φάση μόνο ανάγνωσης (PR 1, παρακάτω), που κάνει και το «πρώτα το migration»: κάθε VM
του cluster αντιστοιχίζεται σε υπηρεσία ή σημειώνεται εσωτερικό. Οι ενέργειες (create, power, κονσόλα) έρχονται μετά.

## PR 1: inventory, μόνο ανάγνωση (2026-10-24)
- **Ένας server ανά cluster** (τύπος «Proxmox VE»), με έως 3 διευθύνσεις κόμβων (vega, pegasus…). Οποιοσδήποτε κόμβος
  απαντά για όλο το cluster με το ίδιο token. Αν ο πρώτος δεν απαντά, η κλήση πάει στον επόμενο, που μένει πρώτος
  από εκεί και πέρα. Ένα token που απορρίπτεται δεν δοκιμάζεται αλλού. Ένα δεύτερο cluster ή ένας standalone κόμβος
  είναι άλλος server· τα VMID είναι μοναδικά μόνο μέσα στο cluster τους.
- Η σύνδεση είναι όπως των panels (`PanelConnection`): μόνο https, προς την IP του κόμβου αν δοθεί, self-signed
  μόνο μαζί με IP, χωρίς redirects και χωρίς pins.
- **Utilities → Proxmox Guests:** κάθε VM και container με VMID, κόμβο, κατάσταση, CPU/RAM/δίσκο, MACs (από το
  config) και IPs (από τον guest agent, ή τις στατικές ενός container).
  - Αντιστοίχιση σε υπηρεσία ή «Internal» με σημείωση· φίλτρο «Not mapped».
  - Πρόταση υπηρεσίας από dedicated IP, MAC (στοιχεία μηχανής του Offline VM) ή όνομα = domain.
  - Ένα guest που χάθηκε από το cluster μένει ως «gone», με την αντιστοίχισή του.
- **Utilities → Proxmox Nodes:** οι κόμβοι με γραφήματα CPU, μνήμης, IO wait και δικτύου (ώρα, μέρα, εβδομάδα),
  διαβασμένα ζωντανά, με cache ενός λεπτού.
- `proxmox:sync` κάθε 15 λεπτά, και κουμπί «Read the clusters now». Ένα cluster που δεν διαβάζεται δεν σημειώνει
  τίποτα ως gone. Τα δικαιώματα είναι αυτά των servers (`admin.servers.viewAny` / `update`).
- Πρόσβαση: αρκεί ένα token με **PVEAuditor** στο `/`, που μόνο διαβάζει:

```bash
pveum user add mycs@pve --comment "MyCS control plane"
pveum user token add mycs@pve api --privsep 1
pveum acl modify / --roles PVEAuditor --users mycs@pve --tokens 'mycs@pve!api'
```

  Στο MyCS: Setup → Servers → New, τύπος «Proxmox VE». Συμπληρώνονται:
  - η διεύθυνση και η IP κάθε κόμβου (`https://vega…:8006`, `https://pegasus…:8006`)·
  - «Self-signed», αν το πιστοποιητικό είναι του Proxmox· τότε η IP κάθε κόμβου είναι υποχρεωτική·
  - token ID `mycs@pve!api` και το secret του.

  Η πρόσβαση για τις ενέργειες (ρόλος MyCS στο pool, παρακάτω) προστίθεται όταν έρθουν.

## Τι υπάρχει σήμερα (2026-10-23, μόνο ανάγνωση)
- **WHMCS του myip:** module «Proxmox VE for WHMCS» (pvewhmcs, GPL, The-Network-Crew), μόνο ως αναφορά, όχι για
  αντιγραφή κώδικα.
  - 8 ενεργοί VPS: 7 active, 1 suspended· 6 μηνιαίοι, 2 τριμηνιαίοι. Μόνο οι 6 είναι συνδεδεμένοι με VM στο module
    (`mod_pvewhmcs_vms`: vmid, IP, mask, gateway, 2 με IPv6 prefix)· οι 2 φαίνονται χειροκίνητοι.
  - Προϊόντα: «Cloud 2CPU‑8GB‑60GB» (5), cloud‑1, Cloud‑2, CS‑2 (από 1). Στο `configoption1` το πλάνο του module, στο
    `configoption2` το IP pool.
  - Πλάνα (`mod_pvewhmcs_plans`): KVM, 2 cores, 2 ή 4 GB RAM, δίσκος σε `local-zfs`, virtio, bridge `vmbr0`, onboot.
  - Το CS‑2 κουβαλά παλιές επιλογές του Hetzner Cloud (Ubuntu 16/18/20, CentOS 7/8, Falkenstein): δεν ισχύουν πια.
  - IPs: ένα pool με 10 IPv4, από τις οποίες χρησιμοποιούνται οι 6.
  - Ο πελάτης κάνει start, reboot, soft stop, hard stop, κονσόλα (noVNC/SPICE) και βλέπει γραφήματα (RRD). Δεν υπάρχει
    re‑install ούτε ISO.
  - Servers: ενεργός ο Pegasus· ο Vega καταχωρημένος αλλά disabled.
- **Proxmox:** PVE 9.2, cluster «myip» με 2 κόμβους, vega (72 CPU, 252 GB) και pegasus (40 CPU, 220 GB).
  - 27 VM συνολικά, μαζί με τα δικά μας. Κανένα cloud‑init template. ISOs: Debian 13 netinst, Ubuntu 24.04,
    Windows 10 IoT, virtio‑win. Υπάρχει storage με `snippets` (για το cloud‑init).
  - Υπάρχουν οι χρήστες `whmcs@pam` και `vnc@pve`.

## Αποφάσεις (ο υπεύθυνος, 2026-10-23)
- **Εγκατάσταση και re‑install:** cloud‑init templates (π.χ. Debian 13, Ubuntu 24.04), με νέο κωδικό root ή SSH
  key· και ISO με installer μέσω κονσόλας, ως επιλογή.
- **Κονσόλα:** noVNC μέσα από το MyCS, με proxy προς τον Proxmox και εισιτήριο μίας χρήσης. Ο πελάτης δεν βλέπει
  ποτέ τον Proxmox.
- **Κόμβος:** ορίζεται ανά προϊόν, με προεπιλογή τον πιο ελεύθερο.
- **Πρόσβαση:** τον χρήστη και το token τα φτιάχνει ο υπεύθυνος (εντολές παρακάτω).

## Πρώτα το migration
Τα υπάρχοντα VM είναι «χύμα»: πριν το extension, το καθένα να μπει στο pool `mycs` και να ταιριάξει με την υπηρεσία
του (vmid, IP, MAC, IPv6 από το `mod_pvewhmcs_vms`· οι 2 χωρίς σύνδεση χειροκίνητα). Ως τότε οι 8 VPS είναι
χειροκίνητα προϊόντα στο MyCS.

## Πρόσβαση του MyCS (να γίνει και οδηγία μέσα στη σελίδα του server)
Τα ελάχιστα δικαιώματα, μόνο στο pool `mycs`: το MyCS βλέπει και αγγίζει μόνο τα δικά του VM.

```bash
pvesh create /pools --poolid mycs --comment "VMs managed by MyCS"
pveum role add MyCS -privs "VM.Allocate VM.Clone VM.Audit VM.PowerMgmt VM.Console VM.Config.CDROM VM.Config.CPU VM.Config.Cloudinit VM.Config.Disk VM.Config.HWType VM.Config.Memory VM.Config.Network VM.Config.Options VM.GuestAgent.Audit Datastore.AllocateSpace Datastore.AllocateTemplate Datastore.Audit Pool.Audit Sys.Audit SDN.Use"
pveum user add mycs@pve --comment "MyCS control plane"
pveum user token add mycs@pve api --privsep 1
for p in /pool/mycs /storage /nodes /sdn/zones; do pveum acl modify $p --roles MyCS --users mycs@pve --tokens 'mycs@pve!api'; done
```

Το secret του token τυπώνεται μία φορά και μπαίνει μόνο στη σύνδεση του server στο MyCS (κρυπτογραφημένο). Τα ονόματα
των δικαιωμάτων αλλάζουν ανά έκδοση (στο PVE 9 το `VM.Monitor` έγινε `VM.GuestAgent.*`).

## Σχέδιο ενεργειών (μετά το PR 1)
1. Extension: σύνδεση (token), create, suspend, unsuspend, terminate· κόμβος ανά προϊόν· τα πλάνα ως ρυθμίσεις
   προϊόντος· import από το `mod_pvewhmcs_vms`.
2. Πελάτης: κατάσταση, start, shutdown, stop, reboot· γραφήματα CPU, RAM, δικτύου, δίσκου (RRD)· IP και MAC.
3. Κονσόλα noVNC μέσα από το MyCS.
4. Re‑install από template (cloud‑init) ή από ISO.
5. IP pools στο MyCS, με import του pool του WHMCS.
