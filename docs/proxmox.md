# Proxmox VE

Ξεκίνησε στις 2026-10-24 με μια φάση μόνο ανάγνωσης (PR 1, παρακάτω), που κάνει και το «πρώτα το migration»: κάθε VM
του cluster αντιστοιχίζεται σε υπηρεσία ή σημειώνεται εσωτερικό. Οι ενέργειες (create, power, κονσόλα) έρχονται μετά.

## PR 1: inventory, μόνο ανάγνωση (2026-10-24)
- **Ένας server ανά cluster** (τύπος «Proxmox VE»), με έως 3 διευθύνσεις κόμβων (vega, pegasus…). Οποιοσδήποτε κόμβος
  απαντά για όλο το cluster με το ίδιο token. Αν ο πρώτος δεν απαντά (ή απαντά 502/503/504), η κλήση πάει στον επόμενο, που μένει πρώτος
  από εκεί και πέρα. Ένα token που απορρίπτεται δεν δοκιμάζεται αλλού. Ένα δεύτερο cluster ή ένας standalone κόμβος
  είναι άλλος server· τα VMID είναι μοναδικά μόνο μέσα στο cluster τους.
- Η σύνδεση είναι όπως των panels (`PanelConnection`): μόνο https, προς την IP του κόμβου αν δοθεί, self-signed
  μόνο μαζί με IP, χωρίς redirects και χωρίς pins.
- **Utilities → Proxmox Guests:** κάθε VM και container με VMID, κόμβο, κατάσταση, CPU/RAM/δίσκο, MACs (από το
  config) και IPs (από τον guest agent, ή τις στατικές ενός container).
  - Αντιστοίχιση σε υπηρεσία ή «Internal» με σημείωση· φίλτρο «Not mapped».
  - Πρόταση υπηρεσίας από dedicated IP, MAC (στοιχεία μηχανής του Offline VM) ή όνομα = domain.
  - Ένα guest που χάθηκε από το cluster μένει ως «gone», με την αντιστοίχισή του. Αν το VMID του το πάρει άλλο
    μηχάνημα (άλλες MACs, ή άλλο όνομα όταν δεν υπάρχουν MACs), η αντιστοίχιση σβήνει και το guest ξαναγίνεται «Not mapped».
  - Μια λίστα χωρίς κανένα guest, εκεί που υπήρχαν, θεωρείται token χωρίς δικαιώματα: δεν σημειώνεται τίποτα ως gone.
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

## Αποφάσεις για τον κύκλο ζωής (ο υπεύθυνος, 2026-10-24)
- **Suspend = stop:** πρώτα κανονικό shutdown και, αν δεν σβήσει μέσα στο όριο, hard stop. Το `onboot` γίνεται 0,
  ώστε ένα reboot του κόμβου να μην το ξαναξεκινά.
- **Unsuspend:** start, και το `onboot` επανέρχεται.
- **Terminate = destroy, μαζί με τους δίσκους.** Γίνεται μόνο μετά τις μέρες του Terminate της εταιρείας (στο myip,
  μήνες απλήρωτο). Αν κάποιος το μετανιώσει, υπάρχουν τα backups του Proxmox. Η υπηρεσία μένει Terminated.
- **Failsafe** (ο υπεύθυνος, 2026-10-24): η ρύθμιση «Terminate» του server έχει προεπιλογή **«Stop and leave to staff»**.
  - Το VM σταματά και δεν ξεκινά σε reboot του κόμβου.
  - Το destroy γίνεται εργασία για το staff στα Pending Module Actions, με mail και την εντολή `qm destroy … --purge` (`pct` για container).
  - Όταν φανεί ότι όλα πάνε σωστά, το staff γυρίζει τη ρύθμιση σε «Destroy» από το UI (Setup → Servers).
- **Όριο ασφάλειας:** η MyCS ενεργεί μόνο σε guest που είναι **και** στο pool `mycs` **και** αντιστοιχισμένο σε αυτή
  την υπηρεσία (Proxmox Guests). Ό,τι είναι Internal ή εκτός pool δεν το αγγίζει ποτέ.

## Roadmap

### Α. Τα υπάρχοντα VM στη MyCS (μαζί με τον importer, πριν από το cut-over του myip)
1. [x] **Inventory (PR 1, #446):** όλα τα guests του cluster στη λίστα (28 στις 2026-10-24, 27 χωρίς το template).
2. [x] **Pool στη λίστα (PR 2, #447):** η στήλη «In MyCS pool» διαβάζεται από το `/cluster/resources`. Για όσα είναι
   αντιστοιχισμένα αλλά εκτός pool, η σελίδα δίνει έτοιμη την εντολή `pvesh set /pools --poolid mycs --vms …`, που την τρέχει
   ο υπεύθυνος. Το token της MyCS δεν παίρνει δικαίωμα να αλλάζει pools.
3. [x] **Importer (γραμμή 9 του ROADMAP, `WhmcsProxmoxImport`, 2026-10-30): η αντιστοίχιση γίνεται με το χέρι.**
   - Η υπηρεσία έρχεται κανονικά με τις χρεώσεις, τις IP, το hostname και τα στοιχεία της.
   - Σε κάθε VPS σε λειτουργία κρατιέται το vmid και η IP του από το `mod_pvewhmcs_vms` (properties `whmcs_vmid`,
     `whmcs_vm_ip`), για το staff. Μια νέα εκτέλεση του importer σβήνει όσα δεν ισχύουν πια.
   - **Ποτέ αυτόματα (απόφαση υπευθύνου, 2026-10-30).** Τρεις γύροι review του #548 έβρισκαν κάθε φορά έναν νέο τρόπο να
     πάρει μια υπηρεσία το VM άλλου πελάτη: vmid που ξαναδόθηκε, IP που ξαναδόθηκε από το pool, IPv6 γραμμένο αλλιώς,
     όνομα που δεν είναι μοναδικό. Μια λάθος αντιστοίχιση σημαίνει ότι η αναστολή ή ο τερματισμός πέφτει στο λάθος VM.
   - Το staff αντιστοιχίζει από τα «Proxmox Guests» → «Service». Η πρόταση εκεί έρχεται με αυτή τη σειρά:
     1. το vmid του WHMCS, αν ανήκει σε μία μόνο υπηρεσία σε λειτουργία·
     2. η IP·
     3. το MAC (`machine_mac` στα στοιχεία της υπηρεσίας)·
     4. το όνομα.
   - Στη myip: 5 VPS με vmid, και 2 χωρίς, που δεν τα ήξερε ποτέ το module.
   - Οι IP τους έρχονται με το `app:import-whmcs-ip-pools` (5).
   - **Στο cluster (μόνο ανάγνωση, 2026-10-30): 5 VM πελατών εκτός του module του WHMCS.** Εκεί το WHMCS δεν δεχόταν
     μικρά VMID για άλλη υπηρεσία.
     - Τα 4 τα δένει το staff από τα «Proxmox Guests». Η πρόταση πιάνει το όνομα. Ένα από αυτά είναι σταματημένο και
       θέλει έλεγχο πριν δεθεί.
     - Το 5ο είναι φίλου, δωρεάν, χωρίς υπηρεσία στο WHMCS. Στη MyCS γίνεται δωρεάν υπηρεσία και δένεται (υπεύθυνος,
       2026-10-30).
     - Ένα VPS του WHMCS, δικό μας, δεν έχει πια VM· έγινε Terminated στο WHMCS (υπεύθυνος, 2026-10-30).
     - Τα υπόλοιπα 19 guests είναι δικά μας, και ένα είναι template. Κάθε δικό μας γίνεται «Internal», ή δωρεάν υπηρεσία στον
       λογαριασμό της εταιρείας, αν θέλουμε να το βλέπουμε σαν πελάτη (υπεύθυνος, 2026-10-30).
     - Η λίστα με τα ονόματα μένει εκτός repo (δημόσιο).
4. [ ] **Προϊόντα:** τα «Cloud 2CPU‑8GB‑60GB», cloud‑1, Cloud‑2 και CS‑2 με module Proxmox. Τα πλάνα
   (`mod_pvewhmcs_plans`) γίνονται ρυθμίσεις του προϊόντος όταν έρθει το create (Β4). Ως τότε αρκεί το module, για
   suspend και terminate. Οι παλιές επιλογές Hetzner του CS‑2 δεν περνούν.
5. [ ] **IP pool:** το pool του WHMCS (10 IPv4, οι 6 σε χρήση) με `app:import-whmcs-ip-pools`, μετά τις υπηρεσίες
   (Β3, PR 4).
6. [ ] **Μετά το cut-over:** ο υπεύθυνος σβήνει τον χρήστη `whmcs@pam` και το module του WHMCS.

### Πρόσβαση για το PR 2 (suspend, start, destroy)
Μόνο στο pool `mycs`, και μόνο ό,τι χρειάζεται (το `PVEAuditor` στο `/` μένει για την ανάγνωση):

```bash
pvesh create /pools --poolid mycs --comment "VMs managed by MyCS"
pveum role add MyCSLifecycle -privs "VM.Audit VM.PowerMgmt VM.Config.Options VM.Allocate VM.Console"
pveum acl modify /pool/mycs --roles MyCSLifecycle --users mycs@pve --tokens 'mycs@pve!api'
```

- `VM.Config.Options` χρειάζεται για το `onboot`, και `VM.Allocate` για το destroy.
- Τα VM των πελατών μπαίνουν στο pool με `pvesh set /pools --poolid mycs --vms 101,102,…`. Το κουμπί «Pool command»
  στο Proxmox Guests δίνει έτοιμη τη λίστα των αντιστοιχισμένων που λείπουν.
- Ό,τι μείνει εκτός pool δεν το αγγίζει η MyCS: το suspend ή το terminate του πηγαίνει στο staff (Pending Module
  Actions), με την εντολή που λείπει.
- Το create (Β4) θα χρειαστεί τον πλήρη ρόλο της ενότητας «Πρόσβαση του MyCS».

### Β. Full provisioning (PRs)
1. [x] **PR 2, κύκλος ζωής** (`GuestLifecycle`, #447):
   - Suspend, unsuspend και terminate όπως στις αποφάσεις, πάνω στο όριο ασφάλειας.
   - Start, shutdown, reboot και stop από τη σελίδα της υπηρεσίας στο admin.
   - Οι εργασίες του Proxmox (UPID) περιμένονται μέσα στο job. Μια αποτυχία πάει στα Pending Module Actions, όπως
     στα άλλα modules. Κάθε βήμα επαναλαμβάνεται με ασφάλεια: το retry βρίσκει ό,τι έγινε ήδη και συνεχίζει.
   - Το VM ελέγχεται πριν από κάθε ενέργεια:
     - είναι ένα μόνο ανά υπηρεσία·
     - είναι στο pool·
     - είναι το ίδιο μηχάνημα: οι MACs του ταιριάζουν, και για destroy πρέπει να υπάρχουν γνωστές MACs·
     - αν είναι ήδη destroyed ή gone, το VMID του δεν ξαναψάχνεται ποτέ.
   - Lock ανά υπηρεσία, ώστε ένα suspend και ένα unsuspend να μην τρέχουν μαζί.
   - Η κατάσταση του VM διαβάζεται από το `status/current`, όχι από τη λίστα του cluster, που είναι cache λίγων
     δευτερολέπτων. Ένα suspend σε VM που δεν είναι ούτε running ούτε stopped αρνείται.
   - Templates: η MyCS δεν ενεργεί ποτέ σε αυτά, και δεν αντιστοιχίζονται σε υπηρεσία.
   - Ένα token που δεν βλέπει κανένα guest δεν θεωρείται «όλα gone»: αν δεν το είχαμε, κάθε destroy θα περνούσε
     σιωπηλά ως επιτυχία.
   - Destroy με `purge=1`, χωρίς `destroy-unreferenced-disks`: αυτό σβήνει κάθε `vm-<vmid>-disk-*` σε κάθε storage, και σε
     shared storage μπορεί να είναι δίσκοι άλλου cluster.
   - Όλες οι αναμονές μιας ενέργειας χωρούν σε 80 δευτερόλεπτα, κάτω από το timeout του job (120 δευτερόλεπτα).
   - Τα κουμπιά Start και Reboot δεν εμφανίζονται όσο η υπηρεσία είναι suspended.
   - Η πρόσβαση της MyCS γίνεται ο ρόλος MyCS στο pool (εντολές παραπάνω).
   - Αγγίζει τον κύκλο ζωής, άρα θέλει **και adversarial review**.
2. [x] **PR 3, client area:**
   - Ο πελάτης βλέπει κατάσταση, CPU/RAM/δίσκο, uptime, IP/MAC και γραφήματα (CPU, RAM, δίκτυο, δίσκος· ώρα, μέρα,
     εβδομάδα, από το `rrddata`), ζωγραφισμένα στον server χωρίς βιβλιοθήκη γραφημάτων.
   - Κάνει start, shutdown, reboot και hard stop (με επιβεβαίωση, μία ενέργεια ανά 30'' ανά VM, στο audit), αλλά όχι
     όσο είναι suspended. Όλα περνούν από τους ίδιους ελέγχους του `GuestLifecycle::guest()` (ένα VM, στο pool, το ίδιο
     μηχάνημα): ένα VM που δεν αγγίζει η MyCS ούτε φαίνεται. Η ανάγνωση κρατιέται 60''.
   - Ο πελάτης μπορεί να ξεκινήσει VM που σταμάτησε το staff όσο η υπηρεσία είναι active (υπεύθυνος 2026-10-28): για
     abuse το staff κάνει suspend την υπηρεσία, και τότε δεν υπάρχουν κουμπιά.
   - **Create (υπεύθυνος 2026-10-28):** χωρίς cloud-init templates (εγκατάσταση από .iso), και το πρώτο create γίνεται
     χειροκίνητα, γιατί η MAC μπαίνει στο MikroTik (gw1-snc) κατά των αλλαγών IP. Το Β4 ξανασυζητιέται.
3. [x] **PR 4, IP pools στη MyCS (Utilities → IP Pools):**
   - Pool = δίκτυο, gateway, μάσκα (prefix), DNS και cluster. IPv4: διευθύνσεις (ένα δίκτυο, ένα εύρος ή μία μία).
     Ποτέ το gateway, η διεύθυνση δικτύου ή το broadcast. Η μάσκα είναι πάντα του δικτύου.
   - IPv6: ένα prefix ανά υπηρεσία (π.χ. /64), όχι μεγαλύτερο από το δίκτυο, ποτέ αυτό του gateway. Δύο prefixes που
     επικαλύπτονται δεν μπαίνουν, ούτε σε διαφορετικά pools. Το μήκος κλειδώνει μόλις μπουν prefixes.
   - Το gateway δεν γίνεται ποτέ διεύθυνση του pool που δόθηκε ή μπορεί να δοθεί (πρώτα reserve). Το gateway, η
     διεύθυνση δικτύου και το broadcast **κάθε** pool δεν μπαίνουν σε κανένα pool και δεν δίνονται ποτέ. Μένουν
     reserved ακόμη κι αν τα ζητήσει κανείς πίσω στο pool.
   - Δύο pools δεν καλύπτουν το ίδιο δίκτυο. Το gateway ενός IPv6 pool μπορεί να είναι link-local (`fe80::1`).
   - Κάθε διεύθυνση είναι free, reserved (δική μας, router), μιας υπηρεσίας, ή «held» από υπηρεσία που έληξε
     (terminated/cancelled). Η held δεν δίνεται ξανά μέχρι να την αποδεσμεύσει το staff: η αποδέσμευση **δεν** γίνεται
     αυτόματα με το terminate. Με το delete μιας υπηρεσίας οι διευθύνσεις της αποδεσμεύονται, και περιμένουν τη σειρά
     τους όπως κάθε άλλη αποδεσμευμένη.
   - Δίνεται και αποδεσμεύεται από το pool ή από τη σελίδα της υπηρεσίας («IPs»). Μία διεύθυνση δεν δίνεται ποτέ σε δύο
     (conditional update). Πρώτα δίνεται όποια δεν δόθηκε ποτέ, μετά όποια αποδεσμεύτηκε παλαιότερα. Το `dedicated_ip`
     ακολουθεί την πρώτη IPv4, αν είναι κενό, και περνά στην επόμενη όταν εκείνη αποδεσμευτεί. Όλα στο audit της υπηρεσίας.
   - Το staff που μόνο διαβάζει βλέπει το pool και τις διευθύνσεις του (σελίδα View).
   - Η λίστα δείχνει σε ποιο VM φαίνεται κάθε διεύθυνση (από το `proxmox:sync`). Σημαδεύει όποια είναι σε χρήση αλλά
     free εδώ, ή είναι σε VM άλλης υπηρεσίας.
   - Ο πελάτης βλέπει στο panel του VPS διεύθυνση, μάσκα, gateway και DNS, για την εγκατάσταση από ISO.
   - Τίποτα δεν αλλάζει στο cluster ή στο MikroTik: το staff βάζει την IP στο VM και στο router όπως πριν.
   - Import: `php artisan app:import-whmcs-ip-pools <βάση του WHMCS>`, μετά τις υπηρεσίες (ίδια ids), re-runnable. Τα
     `v6prefix` του WHMCS δεν είναι πραγματικά prefixes (2026-10-28) και δεν περνούν.
     - Ένα pool που ήρθε μία φορά δεν ξαναγγίζεται: ό,τι άλλαξε, έσβησε ή αποδέσμευσε το staff μένει. Ούτε ένα pool
       που έσβησε το staff ξαναέρχεται (`whmcs_ip_pool_imports`).
     - Μια παράξενη γραμμή (άλλο υποδίκτυο, IPv6, σκουπίδια) αναφέρεται στο τέλος και το import συνεχίζει.
     - Μάσκα /32 σημαίνει routed διευθύνσεις, χωρίς δίκτυο. Μάσκα πλατύτερη από /16 δεν γίνεται πιστευτή: εκείνο το pool
       το στήνει το staff με το χέρι.
4. [ ] **PR 5, create από πλάνο και αλλαγή πακέτου:**
   - Το πλάνο είναι ρυθμίσεις του προϊόντος: cores, RAM, δίσκος, storage, bridge/VLAN, κόμβος (ή ο πιο ελεύθερος),
     template.
   - Η ροή: full clone από cloud-init template, resize δίσκου, IP από το pool, κωδικός root ή SSH key, start, mail.
   - Κανένα διπλό VM: tag `mycs-svc-<id>`, που ψάχνεται πριν από κάθε νέα προσπάθεια.
   - Αλλαγή πακέτου: CPU και RAM αλλάζουν, ο δίσκος μόνο μεγαλώνει.
   - **Από τον υπεύθυνο:** cloud-init templates (Debian 13, Ubuntu 24.04).
5. [ ] **PR 6, reinstall και reset κωδικού:** reinstall από template (σβήνει τα δεδομένα, με επιβεβαίωση) και reset
   του root password μέσω cloud-init. Το ISO, αν χρειαστεί, έρχεται μαζί με την κονσόλα.
   Ως τότε, το «Change password on server» δεν προσφέρεται στο Proxmox (`provisioning.md`, «Κωδικός υπηρεσίας και
   welcome email»): ο κωδικός αλλάζει μέσα στο VM και αποθηκεύεται με το «Save a password set elsewhere».
6. [x] **PR 7, κονσόλα noVNC (υπεύθυνος 2026-10-28: PHP relay, και τις αλλαγές στον server τις κάνει το deploy):**
   - Στο panel του VPS υπάρχει «Κονσόλα», όσο η υπηρεσία είναι active και το VM τρέχει. Ανοίγει σε δική της καρτέλα,
     με noVNC (`@novnc/novnc` 1.7.0, MPL-2.0). Την ανοίγει όποιος κάνει και power (`can:update`).
   - Κάθε σύνδεση ζητά εισιτήριο (POST με CSRF, 10 το λεπτό, στο audit ως `vm_console`):
     - η MyCS ελέγχει το VM όπως για το power και φτιάχνει `vncproxy` στον κόμβο·
     - το κρατά 30'' στο cache με τυχαίο token, και ο browser το παίρνει μία φορά.
   - **Relay:** `php artisan proxmox:console-relay`, ένα PHP process στο `127.0.0.1:8091`, πίσω από το
     `location = /console/ws` του nginx.
     - Δέχεται μόνο websocket από τη MyCS (Origin), με εισιτήριο που δεν έχει χρησιμοποιηθεί.
     - Ανοίγει το `vncwebsocket` του κόμβου με το token, όπως κάθε σύνδεση της MyCS: στην IP του κόμβου, και
       self-signed μόνο με IP.
     - Περνά τα frames και από τις δύο μεριές. Το token δεν φτάνει ποτέ στον browser.
     - Κλείνει μετά από 30' χωρίς κίνηση ή 4 ώρες συνολικά. Κλείνει επίσης μόλις η υπηρεσία πάψει να είναι active ή ο
       χρήστης χάσει την πρόσβαση: ελέγχεται ξανά κάθε 30''.
     - Έως 64 κονσόλες μαζί, έως 2 ανά VPS.
     - Ένας πελάτης δεν το ρίχνει για τους άλλους:
       - πριν ανοίξει η κονσόλα δεν κρατιέται τίποτα πέρα από το handshake·
       - μια πλευρά που δεν διαβάζει σταματά την ανάγνωση της άλλης (2 MB), χωρίς να χάνεται τίποτα: ό,τι έμεινε
         περνά μόλις αδειάσει. Κλείνει μετά από 60'' χωρίς ούτε ένα byte να φύγει. Όλες μαζί
         κρατούν έως 96 MB: πάνω από αυτό σταματούν μόνο όσες κρατούν πάνω από το μερίδιό τους·
       - μια κονσόλα που κλείνει έχει 5'' για τα τελευταία της bytes, και μετρά στο όριο των 2 ανά VPS·
       - ένας browser που σωπαίνει 30'' δέχεται ping· αν δεν απαντήσει σε 60'', η κονσόλα κλείνει (ένα laptop που
         κοιμήθηκε δεν κρατά τη θέση)·
       - αν ο έλεγχος των 30'' αποτύχει (η βάση κάτω), η κονσόλα μένει· κλείνει μετά από 3 αποτυχίες στη σειρά·
       - ένα log που δεν γράφεται δεν σταματά το relay, ούτε ένα flush του cache·
       - frame του browser έως 64 KB, του κόμβου έως 4 MB·
       - το TCP και το TLS του κόμβου γίνονται χωρίς να σταματά ο βρόχος·
       - ό,τι πετάξει μια κονσόλα κλείνει μόνο αυτήν.
   - **Deploy:** το `deploy/mycs-console.service.example` στο `/etc/systemd/system/mycs-console.service` (`enable --now`)
     και το location του `deploy/nginx.conf.example`. Στο τέλος κάθε deploy, το `update.sh` τρέχει
     `proxmox:console-relay --restart`: το relay τελειώνει και το systemd το ξεκινά με τον νέο κώδικα (512 MB μνήμη).
   - **Στο cluster:** έγινε στις 2026-10-28. Ο υπεύθυνος έφτιαξε το pool `mycs`, τον ρόλο `MyCSLifecycle` (μαζί με
     `VM.Console`) και το ACL του στο pool. Ως τότε υπήρχε μόνο το `PVEAuditor` στο `/`.
   - **Σε server με nginx (devbox, 2026-10-28):** εγκαταστάθηκαν το unit `mycs-console` και το `location = /console/ws`.
     Ελέγχθηκαν το 403 χωρίς εισιτήριο ή από άλλο site και το restart στο deploy.

#### Η κονσόλα σε cPanel (orion): ανοιχτό, για την ώρα της myip
Ο υπεύθυνος αποφάσισε στις 2026-10-28: η κονσόλα μένει όπως είναι, και η λύση δοκιμάζεται όταν έρθει η ώρα της myip.
Το ζητούμενο είναι η εγκατάσταση να μετακινείται εύκολα, χωρίς εξωτερικές εξαρτήσεις.

**Το πρόβλημα:** στον orion (έλεγχος 2026-10-28, μόνο ανάγνωση) δεν υπάρχει ούτε το ένα ούτε το άλλο από όσα θέλει το
relay.
- Τα sites τα σερβίρει ο LiteSpeed Enterprise 6.3.7, μπροστά από τον Apache 2.4.
- Υπάρχουν CloudLinux και CageFS· ο λογαριασμός cPanel δεν κρατά process που τρέχει μόνιμα.
- Τρέχει Redis, και έχουμε root.
- Η MyCS όμως τρέχει μέσα στον λογαριασμό cPanel: δεν έχει δικό της systemd ούτε δικό της proxy.

Χωρίς τίποτα έξω από τη MyCS δεν γίνεται: ο Proxmox ανοίγει το VNC μόνο σε όποιον έχει το token, και ο browser δεν
στέλνει, ούτε πρέπει να έχει ποτέ, το token. Κάτι πρέπει να κρατά ανοιχτή τη σύνδεση με τον Proxmox όσο κρατά η
κονσόλα. Οι τρεις επιλογές:

1. **Μέσα στη MyCS, σκέτη PHP.** Χωρίς daemon, proxy ή `.htaccess`.
   - Ένα μακρύ HTTPS request φέρνει την οθόνη, και μικρά POSTs στέλνουν πληκτρολόγιο και ποντίκι. Το noVNC δέχεται
     δικό του transport στη θέση του WebSocket.
   - Κάθε ανοιχτή κονσόλα κρατά έναν PHP worker (ένα entry process του CloudLinux) και περνά από τα όρια χρόνου
     εκτέλεσης.
   - **Δοκιμή:** αν ο LiteSpeed αφήνει ένα streaming response να περάσει χωρίς buffering.
2. **Το relay μαζί με το cluster, όχι με τη MyCS (πρόταση).** Το ίδιο relay μπαίνει μία φορά σε ένα μικρό LXC του
   cluster (π.χ. `console.myip.gr`), με δικό του nginx και systemd.
   - Η MyCS κρατά μόνο μία ρύθμιση, «Console URL», και μετακινείται αυτούσια.
   - Αλλάζει ο κώδικας: το εισιτήριο υπογράφεται (HMAC, 30''), αντί να γράφεται στο Redis της MyCS. Ο έλεγχος των
     30'' γίνεται με υπογεγραμμένη κλήση προς τη MyCS. Το token του Proxmox το κρατά το relay.
   - Τα όρια, το backpressure και το TLS του #506 μένουν ως έχουν.
3. **SPICE με το remote-viewer.** Η MyCS δίνει ένα `.vv` με εισιτήριο μίας χρήσης, και το πρόγραμμα του πελάτη συνδέεται
   απευθείας στο spiceproxy του κόμβου (θύρα 3128).
   - Η MyCS δεν θέλει τίποτα.
   - Θέλει όμως πρόγραμμα στον υπολογιστή του πελάτη, και εκθέτει τη θύρα του κόμβου, αντίθετα με το «ο πελάτης δεν
     βλέπει ποτέ τον Proxmox».

**Τι μένει από το #506 σε κάθε περίπτωση:** τα εισιτήρια, ο έλεγχος δικαιωμάτων, η σελίδα με το noVNC, το
`WebSocket` του package και το relay (για την 2). Αλλάζει μόνο ο τρόπος που φτάνουν τα frames.

**Ως τότε:** στον orion δεν μπαίνει unit ούτε proxy. Το κουμπί «Κονσόλα» φαίνεται, αλλά η σύνδεση δεν ανοίγει (το
`/console/ws` δεν οδηγεί πουθενά). Πριν την παραγωγή χρειάζεται ρύθμιση «console on/off» που κρύβει το κουμπί: να
μπει μαζί με την επιλογή.

Σειρά (ο υπεύθυνος, 2026-10-24): το Β1 τώρα, ώστε οι VPS να μπουν στον αυτόματο κύκλο από την πρώτη μέρα. Μετά
payments tracking και importer (μαζί με το Α). Τα Β2–Β6 έρχονται μετά το cut-over.
