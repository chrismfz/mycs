# MyCS: Roadmap

> Τι μένει να γίνει και με ποια σειρά. Ενημερώνεται σε κάθε PR: ό,τι κλείνει φεύγει από τα ανοιχτά και μπαίνει μία
> γραμμή στο «Έγινε». Οι λεπτομέρειες κάθε θέματος είναι στο doc του· εδώ μόνο ο χάρτης.
>
> **Καθάρισμα 2026-11-11 (main στο #784):** κάθε ανοιχτό σημείο ελέγχθηκε απέναντι στον κώδικα. Η προηγούμενη μορφή,
> με όλο το ιστορικό και τις περιγραφές «όπως χτίστηκε», είναι στο
> [`history/roadmap-2026-10-20-to-11-11.md`](history/roadmap-2026-10-20-to-11-11.md)· όσα docs παραπέμπουν σε γραμμές
> του `ROADMAP.md` εννοούν εκείνο το αρχείο. Οι αριθμοί 7, 8, 9, 9β, 10 των βημάτων προς το cut-over κρατήθηκαν.
> Παλαιότερα: `history/roadmap-2026-10-11-to-20.md` (ως το #232).

## Πού είμαστε (2026-11-11, main στο #784)
- Το MyCS τρέχει στο devbox (`https://mycs.myip.gr`)· **καμία εταιρεία δεν δουλεύει ακόμα σε αυτό**. Pilot η nixpal,
  μετά το myip.gr.
- **Ο κώδικας για το cut-over της nixpal υπάρχει.** Ο importer φέρνει και τις δύο εταιρείες χωρίς άρνηση (πρόβα
  2026-11-11: nixpal 358 ενεργές υπηρεσίες, myip 710· `service_unbilled` 0 και στις δύο). Επτά sweeps για bugs και η
  «ελευθερία του staff» έκλεισαν (#708–#784).
- **Τι λείπει για να μπει η nixpal:** δοκιμές και στήσιμο, όχι κώδικας. Η ενότητα «Πριν τη nixpal» παρακάτω.

## Πριν τη nixpal
- [ ] **8. Παραγωγή στον orion** ως `mycs.nixpal.com`, που στο cut-over παίρνει το `clients.nixpal.com` (το WHMCS →
      `old-clients`· `importer.md` → «Host και gateways»): deploy, SMTP ίδιο με το WHMCS, JetBackup στον rosso και **μία
      δοκιμή επαναφοράς**. Τίποτα δεν στήνεται εκεί πριν το πει ο ιδιοκτήτης (`open-questions.md`).
- [ ] **9β. Πρόβα του cron σε εισαγόμενα δεδομένα, ξανά.** Το `bin/simulate`, το `mycs:forecast-parity` και το
      `mycs:parity` έτρεξαν τελευταία φορά πριν από τα sweeps 5–7, που άλλαξαν αναστολή, τερματισμό, addons και όσα
      διορθώνει το staff. Τρέχουν ξανά στο τελικό main, και στις δύο εταιρείες· κάθε διαφορά μηδέν ή εξηγημένη
      (`importer.md` → «Simulation», «Πρόβλεψη»). Μαζί ο έλεγχος του E4: τιμολόγια με mark ακόμη ανοιχτά
      (`ekdosi-bridge.md` → «Cut-over»).
- [ ] **10. Runbook** (`cutover-runbook.md`): 2–3 δοκιμαστικές μεταφορές από την αρχή ως το τέλος. Το δίχτυ των πρώτων
      ημερών ως βήμα με όνομα ρύθμισης: αναστολές και τερματισμοί κλειστοί την πρώτη εβδομάδα (την πρώτη νύχτα το cron
      θα τερμάτιζε κάθε εισαγόμενη υπηρεσία πέρα από τις Termination Days, `termination.md`), Daily Summary Email ανοιχτό.
- [ ] **7. Παλιές συνδρομές PayPal, την ημέρα του migration:** 5 υπηρεσίες, 3 πελάτες. Μήνυμα στους 3 να αποθηκεύσουν
      το PayPal στο portal, ακύρωση της παλιάς συνδρομής από το staff, `paypal_legacy_receivers`. Χωρίς νέο κώδικα.
- [ ] **Εισερχόμενο mail των tickets:** το polling (`FetchTicketMail`) ανοίγει ανά τμήμα μετά από δοκιμή με πραγματικό
      email (`tickets.md` §6γ). Ο mail server πρέπει να γράφει ο ίδιος το `Authentication-Results` και να σβήνει ό,τι
      έρχεται απ' έξω, και για τους δικούς του authenticated χρήστες (`app:mail-auth-check`, #771).
- [ ] **Το repo γίνεται private** (απόφαση 2026-10-22· ακόμα public). Ως τότε: έγγραφα με σήμανση εμπιστευτικού δεν
      μπαίνουν στο repo, μόνο παράφραση.
- [ ] **Οδηγός για το staff: τι αλλάζει από το WHMCS** (πρόταση 2026-11-11, δεν έχει γραφτεί): μία-δύο σελίδες με τους
      κανόνες που δεν είχε το WHMCS (paid με το χέρι, γραμμές υπηρεσίας που δεν προστίθενται ούτε αφαιρούνται σε
      τιμολόγιο με πληρωμή, hold μετά από unsuspend, ό,τι εκδόθηκε στο Ekdosi είναι οριστικό, addons με τη μέρα της
      γονικής). Πηγές: `staff-freedom.md`, `termination.md`, `addons.md`, `ekdosi-bridge.md`.
- [ ] Του ιδιοκτήτη, όχι κώδικας: το .in της nixpal σε grace· τα 4 domains της nixpal στο CNIC (στο cut-over ανοίγει
      ο διακόπτης «μεταφορά αντί για ανανέωση», #303, #304)· `cronjob_overdue_reminders` = `1,3,7`,
      `cronjob_delete_email_logs` = 365 και `mail_must_verify` off στο cut-over (`settings-map.md`).

## Πριν το myip (μετά τη nixpal)
- [ ] **Χάρτης 301:** στο MyCS έγινε (`cutover-runbook.md` §1β)· μένει ο κανόνας του myip.gr τη μέρα
      (`/clients/knowledgebase/...` ~2.500/μήνα, `/clients/store/...` ~640/μήνα, `/clients/announcements`).
- [ ] Freeze, import, DNS/rewrites, προώθηση του IPN του PayPal (`routes/legacy.php`), dump του WHMCS για αρχείο· οι
      ρυθμίσεις του myip από το `settings-map.md`· **G8-2** καθάρισμα των `533_`/`c60_`· η αρίθμηση συνεχίζει από τον
      τελευταίο αριθμό του WHMCS (ξαναδιαβάζεται τη μέρα).
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
- [ ] Του ιδιοκτήτη: cdnet.gr auto renew off στο WHMCS· 2 domains με `serverHold`· ανάκληση του API credential του
      WHMCS μετά το cut-over· να κοιταχτούν στο WHMCS η υπηρεσία #619 και τα απλήρωτα τιμολόγια domain #32054, #32556.

## Ανοιχτά για απόφαση του ιδιοκτήτη
- **«Add addon» σε pending γονική:** δεν χτίστηκε· οι επιλογές με το κόστος τους στο `staff-freedom.md`.
- **Domains, αντίστροφη έκθεση:** γραμμή που εφαρμόστηκε στον registrar, σε τιμολόγιο που μετά έγινε unpaid ή
  refunded. Δεν έχει ελεγχθεί.
- **Γνωστή διαδρομή πίστωσης:** διαγραφή πληρωμής → αφαίρεση της γραμμής υπηρεσίας → downgrade πιστώνει μέρες στην
  τιμή ανανέωσης (fallback του `ChangeCycle::unused`).
- Όσα έμειναν «όπως είναι, μόνο καταγραφή» στα sweeps: `sweeps/2026-11-09-sixth.md`, `sweeps/2026-11-10-seventh.md`.
- Τα 8 ερωτήματα του sweep των domains (`domains.md`, «Sweep 2026-10-22»).

## Μετά το cut-over, ανά θέμα

### Portal του πελάτη
- [ ] **Αρχική σελίδα, πιο πυκνή** (απόφαση 2026-11-03, κατεύθυνση A): έγιναν τα πλήθη και ο πίνακας υπηρεσιών (PR 2).
      Μένουν: PR 3, κουμπιά σύνδεσης στον πίνακα (lazy ή cache)· PR 4, τιμολόγια με το τι θα γίνει στο καθένα, αιτήματα,
      extensions, αναζήτηση domain.
- [ ] **Τι ξεχωρίζει κάθε υπηρεσία** (`App\Support\ServiceIdentifier`): έγινε σε λίστα, σελίδα και γραμμές τιμολογίου.
      Μένει το PDF του τιμολογίου.
- [ ] GDPR: εξαγωγή και διαγραφή/ανωνυμοποίηση. Δεν υπάρχει· ως τότε με το χέρι.

### Χρέωση
- [ ] Promotions: test ανανέωσης για Price Override και δοκιμαστική περίοδο (#274).
- [ ] Πληρωμές: fallback του Stripe· SEPA (υπάρχει κώδικας, όχι δοκιμή).
- [ ] Reports: σελίδα «Reports» με κατηγορίες όπως του WHMCS, όταν χρειαστεί.
- [ ] Αργότερα ή χειροκίνητα ως τότε: quotes (3 τον χρόνο), downloads (4), unblockip.

### Provisioning
- [ ] **Dedicated servers με BMC: ένα extension Redfish** (ιδέα 2026-11-04): ανάγνωση, power, suspend από θύρα ή BMC,
      console. Το σχέδιο και οι κανόνες ασφάλειας στο αρχείο του ιστορικού, ενότητα «Provisioning».
- [ ] DirectAdmin: αλλαγή κωδικού στον server (`CMD_API_USER_PASSWD`) μετά από live δοκιμή· ως τότε «Save a password
      set elsewhere».
- [ ] ngm: live δοκιμή σε ngm-debian με άδεια του ιδιοκτήτη· ό,τι κάνει το module του WHMCS και δεν πέρασε ακόμα.
- [ ] Hosting Accounts: inodes και CloudLinux στον έλεγχο πακέτου (σύγκριση με τους άλλους του ίδιου plan). Γνωστό
      όριο: με login reseller που δεν βλέπει τα πακέτα του root, οι λογαριασμοί μένουν ανέλεγκτοι χωρίς αναφορά.
- [ ] SSL vault: εγκατάσταση στο cPanel. cPanel: WP Toolkit, Imunify360, faults του lve-stats: όχι τώρα (2026-10-28).

### Domains
- [ ] Αλλαγή registrar από dropdown στη σελίδα του domain (σε «χειροκίνητα» ή σε άλλη σύνδεση· μόνο καταγραφή, μετά
      sync).
- [ ] Αλλαγή κατόχου .gr: **G8-5c** (από τον πελάτη), **G8-5d** (trade στο Openprovider).
- [ ] Μικρά του P1b (#248): πεδίο διεύθυνσης που αδειάζει ίσως μένει παλιό στο Openprovider· η `int` διεύθυνση του .gr
      δεν ενημερώνεται.
- [ ] Ανανέωση από το portal και ως `orders` (`domains.md` D7-2)· additional fields ανά TLD.

### Licensing και admin
- [ ] **Ποιοι χτυπάνε το licensing χωρίς να πρέπει** (2026-10-20): οθόνη με άδειες terminated/suspended/expired που
      ακόμα ελέγχουν, IPs χωρίς ποτέ άδεια, και ενέργειες. Τα δεδομένα υπάρχουν (`LicenseActivity`)· λείπουν η άθροιση
      ανά IP και η οθόνη.
- [ ] `mycs:login-link`.
- [ ] Έλεγχος σε πραγματικό browser (`audit-browser.md`, δεν έχει γραφτεί).
- [ ] WG-24 ως WG-34, μικρά ή απόφαση (`sweeps/whmcs-gaps.md`).

### Ποιότητα
- [ ] phpstan baseline: 266 εγγραφές του upstream· μειώνεται όπου περνάμε.
- [ ] Sweep 5 (2026-11-08, #759–#761): δεν έχει δικό του αρχείο στο `sweeps/`.

## Έγινε (σύνοψη· λεπτομέρειες στα PRs και στα docs)
- **2026-11-09 → 11:**
  - **Ελευθερία του staff** (`staff-freedom.md`, #779–#782): διορθώσεις σε τιμολόγιο με πληρωμή, «paid με το χέρι»,
    επεξεργασία πληρωμής, αναίρεση refund, merge/split, διαγραφή υπηρεσίας με τα addons της· τα ίδια από το Admin API
    με `Idempotency-Key`.
  - **Ό,τι εκδόθηκε είναι οριστικό** (#778): τιμολόγιο που είναι του Ekdosi δεν γίνεται merge, split ή μεταφορά.
  - **Τερματισμός και αναστολή** (#772, #773, `termination.md`): τουλάχιστον 7 ημέρες σε αναστολή, hold μετά από
    unsuspend του staff, ο τερματισμός μένει αυτόματος.
  - Έλεγχος `service_unbilled` στο `mycs:money-check` (#783)· `Authentication-Results` του εισερχόμενου mail (#771).
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
