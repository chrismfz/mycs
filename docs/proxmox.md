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
3. [ ] **Importer (γραμμή 9 του ROADMAP):**
   - Οι 8 VPS του myip γίνονται υπηρεσίες με server το cluster και `dedicated_ip`.
   - Οι 6 που έχουν εγγραφή στο `mod_pvewhmcs_vms` αντιστοιχίζονται αυτόματα με το vmid. Οι 2 χειροκίνητοι γίνονται
     από τη λίστα, με τη βοήθεια της πρότασης από IP/MAC.
   - Re-runnable. Δεν αλλάζει αντιστοίχιση που έκανε το staff. Το parity μετρά «VPS του WHMCS = αντιστοιχισμένα
     guests».
4. [ ] **Προϊόντα:** τα «Cloud 2CPU‑8GB‑60GB», cloud‑1, Cloud‑2 και CS‑2 με module Proxmox. Τα πλάνα
   (`mod_pvewhmcs_plans`) γίνονται ρυθμίσεις του προϊόντος όταν έρθει το create (Β4). Ως τότε αρκεί το module, για
   suspend και terminate. Οι παλιές επιλογές Hetzner του CS‑2 δεν περνούν.
5. [ ] **IP pool:** το pool του WHMCS (10 IPv4, οι 6 σε χρήση) εισάγεται όταν έρθουν τα IP pools (Β3).
6. [ ] **Μετά το cut-over:** ο υπεύθυνος σβήνει τον χρήστη `whmcs@pam` και το module του WHMCS.

### Πρόσβαση για το PR 2 (suspend, start, destroy)
Μόνο στο pool `mycs`, και μόνο ό,τι χρειάζεται (το `PVEAuditor` στο `/` μένει για την ανάγνωση):

```bash
pvesh create /pools --poolid mycs --comment "VMs managed by MyCS"
pveum role add MyCSLifecycle -privs "VM.Audit VM.PowerMgmt VM.Config.Options VM.Allocate"
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
2. [ ] **PR 3, client area:**
   - Ο πελάτης βλέπει κατάσταση, IP/MAC και γραφήματα (CPU, RAM, δίκτυο, δίσκος).
   - Κάνει start, shutdown, reboot και hard stop, αλλά όχι όσο είναι suspended.
3. [ ] **PR 4, IP pools στη MyCS:** IPv4 (και IPv6 prefix) με gateway και mask, δέσμευση και αποδέσμευση ανά
   υπηρεσία, import του pool του WHMCS. Χωρίς αυτά δεν γίνεται create.
4. [ ] **PR 5, create από πλάνο και αλλαγή πακέτου:**
   - Το πλάνο είναι ρυθμίσεις του προϊόντος: cores, RAM, δίσκος, storage, bridge/VLAN, κόμβος (ή ο πιο ελεύθερος),
     template.
   - Η ροή: full clone από cloud-init template, resize δίσκου, IP από το pool, κωδικός root ή SSH key, start, mail.
   - Κανένα διπλό VM: tag `mycs-svc-<id>`, που ψάχνεται πριν από κάθε νέα προσπάθεια.
   - Αλλαγή πακέτου: CPU και RAM αλλάζουν, ο δίσκος μόνο μεγαλώνει.
   - **Από τον υπεύθυνο:** cloud-init templates (Debian 13, Ubuntu 24.04).
5. [ ] **PR 6, reinstall και reset κωδικού:** reinstall από template (σβήνει τα δεδομένα, με επιβεβαίωση) και reset
   του root password μέσω cloud-init. Το ISO, αν χρειαστεί, έρχεται μαζί με την κονσόλα.
6. [ ] **PR 7, κονσόλα noVNC:**
   - Ο πελάτης δεν βλέπει ποτέ τον Proxmox: εισιτήριο μίας χρήσης (`vncproxy`).
   - Το websocket περνά από ένα μικρό relay δίπλα στη MyCS, πίσω από το nginx, με έλεγχο ότι ο πελάτης έχει δικαίωμα
     σε αυτό το VM.

Σειρά (ο υπεύθυνος, 2026-10-24): το Β1 τώρα, ώστε οι VPS να μπουν στον αυτόματο κύκλο από την πρώτη μέρα. Μετά
payments tracking και importer (μαζί με το Α). Τα Β2–Β6 έρχονται μετά το cut-over.
