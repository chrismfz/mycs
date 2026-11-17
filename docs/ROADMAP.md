# MyCS: Roadmap

> Τι μένει να γίνει και με ποια σειρά. Ενημερώνεται σε κάθε PR: ό,τι κλείνει φεύγει από τα ανοιχτά και μπαίνει μία
> γραμμή στο «Έγινε». Οι λεπτομέρειες κάθε θέματος είναι στο doc του· εδώ μόνο ο χάρτης.
>
> **Καθάρισμα 2026-11-17 (main στο #907), μετά το cut-over της nixpal:** τα ανοιχτά της nixpal έκλεισαν ή έγιναν
> ερωτήματα προς τον ιδιοκτήτη· το προηγούμενο κείμενο στο
> [`history/roadmap-before-nixpal-2026-11-17.md`](history/roadmap-before-nixpal-2026-11-17.md).
>
> **Καθάρισμα 2026-11-11 (main στο #784):** κάθε ανοιχτό σημείο ελέγχθηκε απέναντι στον κώδικα. Η προηγούμενη μορφή,
> με όλο το ιστορικό και τις περιγραφές «όπως χτίστηκε», είναι στο
> [`history/roadmap-2026-10-20-to-11-11.md`](history/roadmap-2026-10-20-to-11-11.md)· όσα docs παραπέμπουν σε γραμμές
> του `ROADMAP.md` εννοούν εκείνο το αρχείο. Οι αριθμοί 7, 8, 9, 9β, 10 των βημάτων προς το cut-over κρατήθηκαν.
> Παλαιότερα: `history/roadmap-2026-10-11-to-20.md` (ως το #232).

## Πού είμαστε (2026-11-17, main στο #907)
- **Η nixpal δουλεύει μόνο στο MyCS από 2026-11-13** (`clients.nixpal.com`, orion, λογαριασμός cPanel `mycsnixpal`)· το
  WHMCS της τερματίστηκε. Τρέχει 1–3 μήνες ως η πραγματική δοκιμή πριν το myip. Πώς έγινε: `cutover-nixpal-day.md`.
- **Επόμενο: το myip.gr**, ακόμα σε WHMCS. Το `mycs.myip.gr` είναι το devbox (δοκιμές) και αργότερα το instance του myip.
- **Τα sweeps τελείωσαν** (το ενδέκατο, 2026-11-12, #811). Audit ασφαλείας tickets + live chat 2026-11-17 (#898–#907).
- **Live chat δικό μας** (βήματα 0–4, `livechat.md`)· για τους επισκέπτες του myip.gr μετά το cut-over του, ως τότε
  LHC/Tawk.
- Τα ανοιχτά της nixpal πριν το cut-over (βήματα 7, 8, 9β, 10) έκλεισαν· ό,τι έγραφαν:
  [`history/roadmap-before-nixpal-2026-11-17.md`](history/roadmap-before-nixpal-2026-11-17.md).

## Η nixpal, μετά το cut-over (να επιβεβαιωθούν από τον ιδιοκτήτη)
- [ ] Δοκιμή επαναφοράς του JetBackup (rosso) του `mycsnixpal`.
- [ ] Οι παλιές συνδρομές PayPal (5 υπηρεσίες, 3 πελάτες): ακυρώθηκαν όλες στο PayPal; (`first-days.md` λέει ναι).
- [ ] Τα ευρήματα του `mycs:money-check` στα εισαγόμενα: κλειστά ή γνωστά.
- [ ] Ο καθαρισμός μετά το cut-over (`cutover-runbook.md` §4: API key του WHMCS, scratch βάσεις, αρχεία στο `/root`).
- [ ] Το .in σε grace και τα 4 domains στο CNIC (διακόπτης «μεταφορά αντί για ανανέωση», #303, #304).
- [ ] Πού φυλάσσεται το τελικό dump του WHMCS της.
- [ ] Τα tickets: «Require DMARC» κλειστό και στα δύο τμήματα, `authserv_id` κενό (σωστό για τον orion)· να
      ξανακοιταχτεί με περισσότερο mail.

## Πριν το cut-over του myip
- [ ] **Runbook** (`cutover-runbook.md`): ό,τι έμαθε η nixpal («Πώς έγινε» στο `cutover-nixpal-day.md`: `mycs:install`
      πριν το import, η IP του MyCS στο API του WHMCS, ρυθμίσεις που ξαναμπαίνουν μετά από κάθε import) μπαίνει στα
      βήματα· 2–3 πρόβες του myip. Αναστολές και τερματισμοί την πρώτη εβδομάδα: απόφαση του ιδιοκτήτη (η nixpal
      ξανάνοιξε τις αναστολές την ίδια μέρα).
- [ ] **Χάρτης 301:** στο MyCS έγινε (`cutover-runbook.md` §1β)· μένει ο κανόνας του myip.gr τη μέρα
      (`/clients/knowledgebase/...` ~2.500/μήνα, `/clients/store/...` ~640/μήνα, `/clients/announcements`).
- [ ] Freeze, import, DNS/rewrites, προώθηση του IPN του PayPal (`routes/legacy.php`), dump του WHMCS για αρχείο· οι
      ρυθμίσεις του myip από το `settings-map.md`· **G8-2** καθάρισμα των `533_`/`c60_`· η αρίθμηση συνεχίζει από τον
      τελευταίο αριθμό του WHMCS (ξαναδιαβάζεται τη μέρα).
- [ ] Παλιές συνδρομές PayPal του myip, αν υπάρχουν (στη nixpal: ROADMAP 7, στο ιστορικό).
- [ ] **Εισερχόμενο mail των tickets** ανά τμήμα του myip, μετά από δοκιμή με πραγματικό email (`tickets.md` §6γ)· ο
      mailcow γράφει ο ίδιος το `Authentication-Results` (`app:mail-auth-check`, #771).
- [ ] PayPal: «Transaction Search» και «Reconcile from» (`payments-tracking.md`, «Συμφωνία gateway»), όπως στη nixpal.
      Πρώτη πραγματική ανάγνωση: έλεγχος ότι τα ids ταιριάζουν.
- [ ] cPanel: root token στους servers του myip πριν τους resellers (το create υπάρχει, #114).
- [ ] **Κονσόλα Proxmox σε cPanel:** ο κώδικας του relay υπάρχει (`packages/proxmox`)· μένουν το LXC στη vega, το token
      μόνο για κονσόλα και η δοκιμή στον devbox (`proxmox.md` → «Οδηγός εγκατάστασης του relay»). Το create και το
      reinstall χωρίς templates ξανασυζητιούνται.
- [ ] Τράπεζες: τα στοιχεία του mailbox στο gateway Bank transfer και το auto-match (`payments-tracking.md`, WG-17).
- [ ] Ο κατάλογος .gr στο MyCS: τιμές και ΦΠΑ πριν πουληθεί από εδώ.
- [ ] SSL: live δοκιμή με πραγματική παραγγελία (PositiveSSL 6,53 €), με έγκριση του ιδιοκτήτη. Οι 12 του TheSSLStore
      γίνονται νέες παραγγελίες στο Openprovider στη λήξη τους.
- [ ] Addons: τα 10 Dedicated IP χωρίς ΦΠΑ έρχονται έτσι, σε retired προϊόν· το «Suspend Parent Product» δεν
      μεταφέρεται (`addons.md`). Τα #160/#169/#170 ευθυγραμμίζονται με το χέρι πριν το dump (runbook).
- [ ] **Live chat στα sites του myip.gr** μετά το cut-over του, και απόσυρση του LHC/Tawk (`livechat.md`, βήμα 5).
- [ ] **Οδηγός για το staff: τι αλλάζει από το WHMCS** (δεν έχει γραφτεί): μία-δύο σελίδες με τους κανόνες που δεν
      είχε το WHMCS (paid με το χέρι, γραμμές υπηρεσίας που δεν προστίθενται ούτε αφαιρούνται σε τιμολόγιο με πληρωμή,
      hold μετά από unsuspend, ό,τι εκδόθηκε στο Ekdosi είναι οριστικό, addons με τη μέρα της γονικής). Πηγές:
      `staff-freedom.md`, `termination.md`, `addons.md`, `ekdosi-bridge.md`, `first-days.md`.
- [ ] **Το repo γίνεται private** (απόφαση 2026-10-22· ακόμα public). Ως τότε: έγγραφα με σήμανση εμπιστευτικού δεν
      μπαίνουν στο repo, μόνο παράφραση.
- [ ] Του ιδιοκτήτη: cdnet.gr auto renew off στο WHMCS· 2 domains με `serverHold`· ανάκληση του API credential του
      WHMCS μετά το cut-over· να κοιταχτούν στο WHMCS η υπηρεσία #619 και τα απλήρωτα τιμολόγια domain #32054, #32556.
- [ ] Από τον έλεγχο των εισαγόμενων δεδομένων: το προϊόν #431 χωρίς ΦΠΑ με μία ενεργή υπηρεσία· 4 απλήρωτα
      τιμολόγια με γραμμή υπηρεσίας χωρίς ΦΠΑ· οι υπηρεσίες #337 και #2614 χωρίς πληρωμένη γραμμή.
- [ ] Από το ενδέκατο sweep (`sweeps/2026-11-12-ninth-tenth.md`): **S11-TAX-EKDOSI-1** (πελάτες `taxexempt`: 30 στη
      nixpal, 12 στο myip· το δίχτυ είναι το Ekdosi, καμία λογική ΦΠΑ στο MyCS), **S11-IMPORT-1** (ανανέωση domain με
      σβησμένο τιμολόγιο WHMCS χρεώνεται ξανά), **S11-CLIENT-PORTAL-1** («Auto renew off» βγάζει το κοινό τιμολόγιο από
      την αυτόματη χρέωση).
- [ ] DirectAdmin: αντίστοιχο του `cpanel:sync --domains`, αν οι υπηρεσίες DirectAdmin είναι πολλές (REV-808-3).

## Ανοιχτά για απόφαση του ιδιοκτήτη
- **Αναστολή μόνο όταν υπάρχει ανοιχτό χρέος:** η ρύθμιση υπάρχει, κλειστή (ADV-486-2)· η σύγκριση με το Blesta
  επιβεβαιώνει την ιδέα (`sweeps/2026-11-12-ninth-tenth.md`).
- **Ekdosi:** έλεγχος στη δική του πλευρά αν μια περίοδος που εμφανίζεται τώρα σε γραμμή παλιού τιμολογίου διαβάζεται
  ως «άλλαξε».
- **«Add addon» σε pending γονική:** δεν χτίστηκε· οι επιλογές με το κόστος τους στο `staff-freedom.md`.
- **Domains, αντίστροφη έκθεση:** γραμμή που εφαρμόστηκε στον registrar, σε τιμολόγιο που μετά έγινε unpaid ή
  refunded. Δεν έχει ελεγχθεί.
- **Γνωστή διαδρομή πίστωσης:** διαγραφή πληρωμής → αφαίρεση της γραμμής υπηρεσίας → downgrade πιστώνει μέρες στην
  τιμή ανανέωσης (fallback του `ChangeCycle::unused`).
- Όσα έμειναν «όπως είναι, μόνο καταγραφή» στα sweeps: `sweeps/2026-11-09-sixth.md`, `sweeps/2026-11-10-seventh.md`,
  `sweeps/2026-11-11-eighth.md` (και τα ανοιχτά του όγδοου, στο τέλος του αρχείου),
  `sweeps/2026-11-12-ninth-tenth.md` (ένατο και δέκατο: τι διορθώθηκε, τι αναιρέθηκε, τι μένει γνωστό, πόσο συχνά συμβαίνουν).
- Τα 8 ερωτήματα του sweep των domains (`domains.md`, «Sweep 2026-10-22»).
- **Audit tickets + live chat (2026-11-17), όσα έμειναν:**
  - «Block sender» πάνω σε From που δεν επιβεβαιώθηκε: προειδοποίηση στο παράθυρο του block, ή ως έχει.
  - Άλλα `->block(` χωρίς catch (IpPool, ModuleAction, GatewayBalances): σελίδα σφάλματος αντί για άρνηση, όταν ένα
    lock κρατιέται πάνω από την αναμονή. Μικρό· όταν φανεί.
  - Διαγραφή συνομιλίας επισκέπτη (GDPR, `livechat.md` §6α) και CSP με `script-src` για το popup `/chat`: το καθένα
    δικό του βήμα.
  - Προτείνεται να μείνουν: DKIM χωρίς υποχρεωτικά υπογεγραμμένα Subject/Message-ID, webhooks ειδοποιήσεων προς
    εσωτερικές διευθύνσεις (μόνο staff), κλειδί API με `tickets.update` που μετακινεί ticket σε άλλο λογαριασμό.

## Ανοιχτά ανά θέμα (όχι πριν το myip)

### Portal του πελάτη
- [ ] **Αρχική σελίδα, πιο πυκνή** (απόφαση 2026-11-03, κατεύθυνση A): έγιναν τα πλήθη και ο πίνακας υπηρεσιών (PR 2).
      Μένουν: PR 3, κουμπιά σύνδεσης στον πίνακα (lazy ή cache)· PR 4, τιμολόγια με το τι θα γίνει στο καθένα, αιτήματα,
      extensions, αναζήτηση domain.
- [x] **Τι ξεχωρίζει κάθε υπηρεσία** (`App\Support\ServiceIdentifier`): σε λίστα, σελίδα, γραμμές τιμολογίου και στο
      κείμενο κάθε νέας γραμμής, άρα και PDF και mails (#895). Τα παλιά τιμολόγια μένουν όπως ήταν.
- [ ] GDPR: εξαγωγή και διαγραφή/ανωνυμοποίηση. Δεν υπάρχει· ως τότε με το χέρι.
- [x] Διακόπτης ανά τιμολόγιο «θα πληρώσω αλλιώς» για τον πελάτη (OK ιδιοκτήτη 2026-11-03· #623).
- [ ] Η κονσόλα του πελάτη δεμένη με το session της σύνδεσής του (S10-ACCESS-1· μαζί με τη δουλειά του relay).

### Χρέωση
- [x] Promotions: test ανανέωσης για Price Override (#274· 2026-11-12, `PriceOverrideProrataTest`: pro rata πρώτη περίοδος
  με την τιμή του override αναλογικά, ποτέ κάτω από το μηδέν, ανανέωση στην τιμή του override).
- [ ] Promotions: test ανανέωσης για δοκιμαστική περίοδο (#274).
- [ ] Πληρωμές: fallback του Stripe· SEPA (υπάρχει κώδικας, όχι δοκιμή).
- [ ] Reports: σελίδα «Reports» με κατηγορίες όπως του WHMCS, όταν χρειαστεί.
- [ ] Αργότερα ή χειροκίνητα ως τότε: quotes (3 τον χρόνο), downloads (4), unblockip.

### Provisioning
- [ ] **Dedicated servers με BMC: ένα extension Redfish** (ιδέα 2026-11-04): ανάγνωση, power, suspend από θύρα ή BMC,
      console. Το σχέδιο και οι κανόνες ασφάλειας στο αρχείο του ιστορικού, ενότητα «Provisioning».
  - [x] Βήμα 1, ανάγνωση (2026-11-11): module «Redfish», ένα μηχάνημα = ένας server, «Hardware» με μοντέλο, serial,
        power state, health και watt (`docs/provisioning.md`, «Μηχανήματα με BMC»). Η σύνοψη διαβάζεται από
        πραγματικό Supermicro στον devbox (από 2026-11-11, κάθε ώρα· power, health, watt, θερμοκρασία εισόδου).
  - [x] Utilities → Machines (2026-11-11): `redfish:sync` κάθε ώρα, λίστα και σελίδα ανά μηχάνημα με ανεμιστήρες,
        θερμοκρασίες, τροφοδοτικά, CPU, RAM, δίσκους. Μόνο ένδειξη, χωρίς εργασία ή mail. Μένουν event log, RAID,
        δίκτυο και η δοκιμή των εξαρτημάτων σε πραγματικά BMC.
  - [x] Βήμα 2, ενέργειες για το staff (2026-11-11): power, locate light, boot μία φορά, restart BMC, με δεύτερο
        λογαριασμό BMC, επιβεβαίωση και audit. Για τον πελάτη και για το suspend: όχι ακόμα (θέλουν απόφαση).
- [ ] DirectAdmin: αλλαγή κωδικού στον server (`CMD_API_USER_PASSWD`) μετά από live δοκιμή· ως τότε «Save a password
      set elsewhere».
- [ ] ngm: live δοκιμή σε ngm-debian με άδεια του ιδιοκτήτη· ό,τι κάνει το module του WHMCS και δεν πέρασε ακόμα.
- [ ] Hosting Accounts: inodes και CloudLinux στον έλεγχο πακέτου (σύγκριση με τους άλλους του ίδιου plan). Γνωστό
      όριο: με login reseller που δεν βλέπει τα πακέτα του root, οι λογαριασμοί μένουν ανέλεγκτοι χωρίς αναφορά.
- [ ] SSL vault: εγκατάσταση στο cPanel. cPanel: WP Toolkit, Imunify360, faults του lve-stats: όχι τώρα (2026-10-28).

### Domains
- [ ] Ενέργεια στο admin για διόρθωση της λήξης ενός domain (δεν υπάρχει καμία· S10-DOMAINS-1).
- [x] Αλλαγή registrar και status από dropdown στην κάρτα «Domain» της σελίδας του domain (#848, D-26 στο
      `domains.md`).
- [ ] Αλλαγή κατόχου .gr: **G8-5c** (από τον πελάτη), **G8-5d** (trade στο Openprovider).
- [ ] Μικρά του P1b (#248): πεδίο διεύθυνσης που αδειάζει ίσως μένει παλιό στο Openprovider· η `int` διεύθυνση του .gr
      δεν ενημερώνεται.
- [ ] Ανανέωση από το portal και ως `orders` (`domains.md` D7-2)· additional fields ανά TLD.

### Licensing και admin
- [ ] **Ποιοι χτυπάνε το licensing χωρίς να πρέπει** (2026-10-20): οθόνη με άδειες terminated/suspended/expired που
      ακόμα ελέγχουν, IPs χωρίς ποτέ άδεια, και ενέργειες. Μερικώς: «Went quiet after expiry» και `bypass_ips` (#862),
      Live/Dormant (#871)· μένουν η άθροιση ανά IP και οι IPs χωρίς ποτέ άδεια.
- [ ] `mycs:login-link`.
- [ ] Έλεγχος σε πραγματικό browser (`audit-browser.md`, δεν έχει γραφτεί).
- [ ] WG-24–26, 28–31, 33: μικρά ή απόφαση (`sweeps/whmcs-gaps.md`· τα 27 και 32 έγιναν, το 34 δεν γίνεται).

### Ποιότητα
- [ ] phpstan baseline: 266 εγγραφές του upstream· μειώνεται όπου περνάμε.
- [ ] Sweep 5 (2026-11-08, #759–#761): δεν έχει δικό του αρχείο στο `sweeps/`.

## Έγινε (σύνοψη· λεπτομέρειες στα PRs και στα docs)
- **2026-11-17: audit ασφαλείας tickets + live chat** (μετά το XSS του Live Helper Chat, πριν ανοίξει το chat σε
  επισκέπτες): XSS, injection, inclusion, εκτελέσιμο upload ή πρόσβαση σε ξένο λογαριασμό δεν βρέθηκαν, και σε ζωντανή
  δοκιμή στο devbox. Διορθώθηκαν κατάχρηση και μικρά κενά σε δύο γύρους, κάθε PR με δύο ανεξάρτητα reviews: #898–#900
  (όρια chat επισκέπτη και ουράς, όριο νέων tickets από mail χωρίς επιβεβαίωση, mail που ρίχνει την ανάγνωση,
  retention καραντίνας, toast, `viewticket.php`) και #902–#904 (Admin API και MCP, ονόματα αρχείων, References, IP
  επισκέπτη σε 30 ημέρες). `tickets.md` §6π, `livechat.md` §3 Ε.
- **2026-11-17: MCP και Proxmox, ό,τι έμεινε από το audit** (#907): κάθε εργαλείο του MCP που αλλάζει κάτι ζητά και το
  δικαίωμα να το βλέπει (module actions, servers, tickets, και η λίστα των προτάσεων στο admin)· το lock του VM λέει
  ξανά το δικό μας μήνυμα.
- **2026-11-13: cut-over της nixpal** (WHMCS πάγωσε 00:18, MyCS ανοιχτό 00:59:47 στο `clients.nixpal.com`, orion·
  `cutover-nixpal-day.md`). Πριν: πρόβες με API, logins και CC hash, μέθοδοι 66/66. Μετά: captcha (#828), όνομα
  εταιρείας και nameservers (#829), ένα deploy script και για τον orion (#834), polling των tickets κάθε 3 λεπτά (#813).
- **2026-11-12 → 17:**
  - **Bulk license της ZoneCloud** (`zonecloud-bulk-license.md`, PR 1–7: #819–#825, #832, #850, #851).
  - **Licensing:** `/licensing/v1/check` (#863), υπογεγραμμένο token σε σκιά (#864), στήλη API (#865, #870),
    Live/Dormant (#871), «Went quiet after expiry» (#862).
  - **MCP:** search, client, catalog, revenue (#857), ό,τι διαβάζουν τα modules (#858), `add_ticket_note` (#860, #861).
  - **Live chat:** μεταφορά και σημειώσεις, κλείσιμο αδρανών (3β, #884, #885)· επισκέπτες, popup, εργαλεία του operator,
    launcher, κουμπί (βήμα 4, #887–#893).
  - **Admin και portal:** tickets (#815, #833, #837, #845, #846), πελάτες (#840, #873–#875), domains (#831, #848, #849),
    γραμμή τιμολογίου με την υπηρεσία της (#895), mails τιμολογίων (#897), Cardlink (#894).
- **2026-11-09 → 11:**
  - **Ελευθερία του staff** (`staff-freedom.md`, #779–#782): διορθώσεις σε τιμολόγιο με πληρωμή, «paid με το χέρι»,
    επεξεργασία πληρωμής, αναίρεση refund, merge/split, διαγραφή υπηρεσίας με τα addons της· τα ίδια από το Admin API
    με `Idempotency-Key`.
  - **Ό,τι εκδόθηκε είναι οριστικό** (#778): τιμολόγιο που είναι του Ekdosi δεν γίνεται merge, split ή μεταφορά.
  - **Τερματισμός και αναστολή** (#772, #773, `termination.md`): τουλάχιστον 7 ημέρες σε αναστολή, hold μετά από
    unsuspend του staff, ο τερματισμός μένει αυτόματος.
  - Έλεγχος `service_unbilled` στο `mycs:money-check` (#783)· `Authentication-Results` του εισερχόμενου mail (#771).
  - **Καρτέλα «Invoices»** σε υπηρεσία (στη θέση της παλιάς) και domain (ιδιοκτήτης 2026-11-11): μία γραμμή ανά γραμμή τιμολογίου, με ποσό
    γραμμής, περίοδο, κατάσταση, πότε και πώς πληρώθηκε· στην υπηρεσία και των addons και των αλλαγών πακέτου της.
- **Sweeps για bugs, όγδοο ως ενδέκατο (2026-11-11 → 12):** το ενδέκατο (#811) ήταν το τελευταίο
  (`sweeps/2026-11-11-eighth.md`, `sweeps/2026-11-12-ninth-tenth.md`).
- **Sweeps για bugs (2026-11-07 → 10):** επτά γύροι, κάθε high και medium διορθωμένο ή κλεισμένο με απόφαση:
  πρώτο (#708–#721), δεύτερο ανά ροή (#723–#735), τρίτο ανά οπτική (#737–#746), τέταρτο (#752–#757), πέμπτο
  (#759–#761), έκτο (#764–#770), έβδομο (#771–#778). Αρχεία στο `sweeps/`. Από αυτά: ο κανόνας KISS (#751), τα addons
  με τον κύκλο και τη μέρα της γονικής (#757, #765), ειδοποίηση του staff για αλλαγή πλάνου σε απλήρωτο pro rata
  (#748), εργασία για stray λογαριασμούς (#749), ποιος κρατά ένα όνομα domain (D-25, #750).
- **Importer και ασφάλεια του migration (9, 9β, 9γ):** πλήρες import με parity, addons, ρυθμίσεις modules, αρνήσεις
  πριν το dump (`importer.md`)· `mycs:forecast-parity`, `bin/simulate`, `mycs:replay-compare` (replay της nixpal
  2026-11-01)· σύγκριση λογικής με Blesta και FOSSBilling (#551–#559)· «Το cron ανά μέρα» και Daily Summary Email·
  `mycs:rollback-report`. Κενά από το sweep του WHMCS: WG-1 ως WG-23 (`sweeps/whmcs-gaps.md`).
- **Pre-launch audit** (`prelaunch-audit.md`, 2026-10-29): και οι 10 ροές (#517–#536).
- **Portal και admin (2026-11-03 → 06):** ροή πληρωμής του πελάτη, και τα 7 PRs (`pay-flow-ux.md`)· dashboard του
  admin (Needs attention, Servers, Recent activity, γράφημα, #621–#631)· έσοδα για το ταμείο, 4 σελίδες
  (`income-planning.md`, #500–#504)· Hosting Accounts με έλεγχο πακέτου, προσωρινό extra και έγκριση (#622, #645)·
  σελίδα domain συμπαγής με τιμή ανανέωσης με το χέρι.
- **2026-10-23 → 29:** μαζικές ενέργειες domains στο portal, μεταφορά υπηρεσίας/domain σε άλλο πελάτη, knowledgebase
  στα URLs του WHMCS, WHOIS στο admin· Proxmox (inventory, κύκλος ζωής, client area, IP pools, κονσόλα noVNC)· offline
  modules (VM, dedicated, SSL)· mailcow (live δοκιμή, Mail Domains, DNS)· cPanel (Hosting Accounts, resellers,
  CloudLinux, «Differs», IP από τον server)· resend welcome και κωδικοί· SSL vault· γλώσσα ανά πελάτη, ενιαίο «Total»,
  «Issued At»· 2FA πελάτη με recovery codes· Ekdosi bridge E1–E5 με live δοκιμή· Mattermost/Slack· squash των
  migrations (#510)· seeders.
- **2026-10-20 → 23:**
  - **Tickets (πλήρες port):** #239–#273.
  - **Χρέωση:** ομάδες πελατών (#272), promotions (#274), προσφορές (#285), ληξιπρόθεσμα (#286), τραπεζική μεταφορά
    (#287), πίστωση στο downgrade (#326).
  - **Reports:** πρόβλεψη εσόδων, έσοδα έτους, σύνολα ανά έτος, υπόλοιπο registrars (#275–#283).
  - **Domains:** μεταφορές, emails, **CNIC** (#297–#304), sweep χρημάτων και λήξεων (#305–#312).
  - **Provisioning:** cPanel resellers και χρήση, **mailcow** (#324), **Centova Cast**, **SSL μέσω Openprovider**
    (#343–#350).
  - **Ασφάλεια και λειτουργία:** Automation Status, `/health`, σκλήρυνση (headers/CSP, cookies, throttles, gateways),
    2FA staff (#344).
- **Ως 2026-10-20:** αρίθμηση σε τρεις τρόπους (#238)· καθάρισμα από telemetry/updater/marketplace, CI· χρήματα σε
  decimal, αλλαγή πακέτου/κύκλου, sweep χρέωσης A–H (#122–#150), EU VAT/VIES, Cardlink· λογαριασμοί με πολλούς users
  και επαφές· παραγγελίες· licensing L1–L3· domains με Openprovider και **.gr EPP**· portal P1–P4d· templates el/en·
  `bin/test`.

## Δεν γίνονται (απογραφή: δεν χρησιμοποιούνται)
Late fees, billable items, affiliates (το extension του upstream μένει αχρησιμοποίητο), escalation rules, network
issues, fraud modules, φόρος δεύτερου επιπέδου, premium domains, δωρεάν domain με hosting, «Group Similar Line Items»,
server status, TheSSLStore (SSL μέσω Openprovider). Επίσης: custom fields προϊόντος (Σ1-c), Δ4 `completed`, G2 (ανοιχτά
τιμολόγια όταν αλλάζει το καθεστώς ΦΠΑ), η ένδειξη SSL δίπλα σε κάθε domain του portal, οι ανανεώσεις on demand από τον
πελάτη, τα minor units του Stripe για νομίσματα χωρίς δεκαδικά (PAY-16), unique `transaction_id` σε όλους τους πελάτες
(το idempotency μένει ανά πελάτη, `payments.md` Τ2), K4 του Ekdosi (η ΑΛΠ μένει χειροκίνητη επιλογή), **G8-6** διαγραφή
στο Μητρώο, live chat τρίτου (WG-34: δικό μας), έξοδα (ανήκουν στο Ekdosi), λογιστική κλεισμένου έτους στο MyCS (οι
αναφορές είναι ενημερωτικές, 2026-11-10).
