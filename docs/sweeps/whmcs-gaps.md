# Sweep κενών: WHMCS (myip, nixpal) ↔ MyCS και importer (2026-11-04)

> Μόνο έρευνα, χωρίς αλλαγές κώδικα. Αφορμή: τα product addons ξέφυγαν για εβδομάδες, παρά τις πρόβες του import.
> 8 agents, ένας ανά περιοχή, και ένας skeptic ανά περιοχή που προσπάθησε να διαψεύσει κάθε εύρημα. Οι περιοχές:
> χρέωση, κατάλογος/παραγγελίες, υπηρεσίες/provisioning, domains, πελάτες/ασφάλεια, support/email, δικά μας
> hooks/addons/gateways/cron/`tblconfiguration`, και κάλυψη του importer πίνακα-πίνακα.
>
> Πηγές: ο κώδικας του MyCS στο `main` @ `1fee1643` (μετά το #634), και τα WHMCS των δύο εταιρειών (myip στον rigel,
> nixpal στον orion). Στα WHMCS έγιναν μόνο SELECT με αθροίσματα και ανάγνωση αρχείων. Δεν έτρεξε import ούτε tests.
> Τα WG-1, WG-2 και WG-3 τα επιβεβαίωσε ξανά ο συντονιστής, στον κώδικα και με SELECT.
>
> **Εκτός:** τα product addons (`addons.md`, βήμα 2 στο #634) και το upgrade/downgrade με την αναλογία ως τη λήξη, που
> έχουν δικά τους tasks.

## Το συμπέρασμα

Ο πυρήνας στέκει. Τα κενά είναι στις **άκρες του importer**, εκεί όπου το WHMCS κρατά κάτι αλλού απ' ό,τι νομίζαμε:
- η έκπτωση ομάδας είναι γραμμή του τιμολογίου, όχι μέρος της τιμής·
- ο κατάλογος έρχεται από δύο διαφορετικούς κώδικες, και στο cut-over τρέχει ο παλιός·
- πολλές ρυθμίσεις του `tblconfiguration` δεν διαβάζονται·
- τα docs έλεγαν «καλύπτεται» για πράγματα που καλύπτονται μόνο από την εντολή του καταλόγου.

Ένα μοτίβο που ξανάρθε: ευρήματα του audit της 2026-10-13 (`history/audit-sweep-2026-10-13/DATA-schema-importer.md`,
π.χ. τα config options των υπηρεσιών) **δεν πέρασαν στο ROADMAP** και χάθηκαν. Γι' αυτό εδώ κάθε κενό παίρνει ID (WG-…)
και γραμμή στο ROADMAP.

## Π0: πριν το cut-over της nixpal

| ID | Κενό | WHMCS | Τι να γίνει |
|---|---|---|---|
| **WG-1** ✔ | **Διορθώθηκε (fix/import-group-discount):** ο importer γράφει την τιμή μείον την έκπτωση, και το forecast parity συγκρίνει με το ίδιο ποσό (`importer.md`, «Έκπτωση ομάδας»). **Η έκπτωση ομάδας χάνεται.** Το WHMCS κρατά πλήρη τιμή στο `tblhosting.amount` και προσθέτει γραμμή `GroupDiscount` σε **κάθε** ανανέωση. Ο importer γράφει την πλήρη τιμή με `group_percent` = της ομάδας, δηλαδή «η έκπτωση έχει ήδη μπει», οπότε το reconcile δεν την αφαιρεί ποτέ. Το σχόλιο στο `ImportFromWhmcs.php` (το `group_percent`, review του #628) υποθέτει ότι το WHMCS δεν εφαρμόζει την έκπτωση στην ανανέωση, και τα δεδομένα δείχνουν το αντίθετο. | nixpal: ομάδα 10%, 1 πελάτης με 24 μηνιαίες υπηρεσίες· στο τρίμηνο 72 γραμμές Hosting 139,20 και 72 `GroupDiscount` −13,92. myip: καμία ομάδα με έκπτωση. | Στον importer, `group_percent = 0` όπου το WHMCS βγάζει `GroupDiscount` (ή η τιμή του ισούται με τον κατάλογο), ώστε το reconcile να γράψει την τιμή × 0,9. Test parity: σύνολο ανανέωσης MyCS = Hosting + `GroupDiscount` του WHMCS. **Χρήματα: adversarial review.** |
| **WG-2** ✔ | **Διορθώθηκε (feat/import-catalog-full):** το πλήρες import καλεί το `CatalogImport` μετά τους servers, με τα ids του WHMCS, και το parity μετρά products, listed και package (`importer.md`, «Κατάλογος»). **Το πλήρες import δεν είναι το import του καταλόγου.** Το `app:import-from-whmcs` ξεκινά με `migrate:fresh` και φτιάχνει κατηγορίες, προϊόντα και config options με τον **παλιό** κώδικα (`importCategories`/`importProducts`/`importConfigOptions`), όχι με το `CatalogImport`. Χάνονται: το `package` του cPanel και τα `package`/`php` του ngm· οι κρυφές ομάδες (`show_in_menu`)· το `retired`· οι δωρεάν τιμές (0.00) των config options και των κύκλων· η σειρά (`sort`)· το `configoptionsupgrade`. Επιπλέον, οι συνδέσεις `config_option_products` γράφονται με τα ids του WHMCS, ενώ τα options παίρνουν νέα ids. | myip: 29 προϊόντα cPanel (658 ζωντανές), 1 ngm, 17 κρυφές ομάδες με 36 ορατά προϊόντα (24 ζωντανές), 230 options με 495 τιμές 0.00, 100 προϊόντα με σειρά. nixpal: **6 προϊόντα cPanel (12 ζωντανές)**, 1 retired. | Ο πλήρης importer να καλεί το `CatalogImport` (κατηγορίες, προϊόντα, options, settings), όπως ήδη κάνει για servers και TLDs. Test πλήρους import, και στο `mycs:parity`: package ανά προϊόν cPanel, listed προϊόντα ανά κατηγορία. |
| **WG-3** ✔ | **Διορθώθηκε (feat/import-funds-order-grace):** ο importer τα φέρνει· μέγιστο 0 ή ελάχιστο πάνω από το μέγιστο κρατά του MyCS, με σημείωση. **Τα όρια του Add Funds δεν έρχονται.** Ο importer φέρνει μόνο το `AddFundsEnabled`· μένουν τα defaults 5 / 100 / 300 (ελάχιστο, μέγιστη κατάθεση, μέγιστο υπόλοιπο). | myip: 1 / 5.000 / 10.000· 13 από τις 23 καταθέσεις των 12μ πάνω από 100, 2 πελάτες ήδη με υπόλοιπο πάνω από 300. nixpal: 2 / 1.000 / 1.000, μία κατάθεση 750. | `AddFundsMinimum/Maximum/MaximumBalance` → `credits_minimum_deposit/credits_maximum_deposit/credits_maximum_credit` στο `importAutomationSettings`, με test. |
| WG-4 ✔ | **Διορθώθηκε (feat/import-funds-order-grace):** ρύθμιση `order_days_grace` (default 7), από τον importer, για παραγγελίες υπηρεσιών και domains. **Προθεσμία του πρώτου τιμολογίου:** σταθερή στις 7 ημέρες (`PlaceOrderService`). Είναι στο `settings-map.md` ως «Ν», όχι στο ROADMAP. | myip `OrderDaysGrace` 8 (474/489 τιμολόγια παραγγελιών), nixpal 0 (67/67 λήγουν την ίδια μέρα). | Ρύθμιση `order_days_grace` (default 7), από τον importer. |
| WG-5 ✔ | **Οι ρόλοι staff εκτός Full Administrator** έρχονται χωρίς δικαιώματα, και το runbook δεν το λέει. | nixpal: ένας ενεργός admin σε ρόλο Sales Operator. myip: ένας Support Operator. | Βήμα στο runbook, ή αντιστοίχιση των `tbladminperms`, με προειδοποίηση για ό,τι δεν αντιστοιχεί. **Έγινε** (2026-11-04): `App\Services\Whmcs\RolePermissions`, 96 από τα 162 αντιστοιχούν· τα υπόλοιπα 66 (λειτουργίες που το MyCS δεν έχει, ή που θα έδιναν περισσότερα) τα λέει ο importer ανά ρόλο. |
| WG-6 ✔ | **Τα promotions κλείνουν στο import.** Το σχόλιο λέει ότι το MyCS δεν έχει όριο κύκλων, όμως από το #274 έχει κύκλους, νέους/υπάρχοντες πελάτες και `requires_existing`. | nixpal: 4, τα 2 Price Override με 212 και 90 χρήσεις. myip: 23, λίγη χρήση. | Ο importer να γράφει ό,τι εκφράζεται πια, και να κλείνει μόνο το υπόλοιπο (`recurfor`, lifetime). |
| WG-7 ✔ | **Οι όροι χρήσης:** η ρύθμιση `tos` υπάρχει, αλλά ο importer δεν τη γράφει, οπότε το checkout δεν ζητά αποδοχή. | Και οι δύο: `EnableTOSAccept` on, με URL. | `TermsOfService` → `tos`. |
| WG-8 | **Κατάσταση πελάτη:** ο πίνακας `clients` δεν έχει status. Τα Closed έρχονται ως κανονικοί, με login. | nixpal: 2 Closed, 200 Inactive. myip: 354 Inactive, 0 Closed. | Ελάχιστο: Closed → χωρίς login/παραγγελίες. Το Inactive μπορεί να υπολογίζεται. |
| WG-9 | **Συναίνεση marketing:** δεν εισάγεται. Το ROADMAP έλεγε «δεν χρησιμοποιήθηκε ποτέ», που δεν ισχύει. | myip: `emailoptout` σε 345, 830 γραμμές `tblmarketing_consent`, 26 campaigns. nixpal: 76 opt-out. | Πριν το πρώτο news mail: opt-out του `announcement_news` για όσους έχουν `emailoptout` ή `opt_in = 0`, με parity. |
| WG-10 | **Επιβεβαίωση email:** το `EnableEmailVerification` της nixpal δεν αντιστοιχεί στο `mail_must_verify`, που κλειδώνει τον λογαριασμό. | nixpal on, αλλά μόνο 8/233 πελάτες επιβεβαιωμένοι. | Στο settings-map: το `mail_must_verify` μένει off στο cut-over. |

## Π1: πριν το cut-over της myip

| ID | Κενό | WHMCS (myip) | Τι να γίνει |
|---|---|---|---|
| WG-11 | **Οι επιλογές (config options) των υπηρεσιών δεν εισάγονται.** Κανένα αρχείο δεν διαβάζει το `tblhostingconfigoptions`. Το `importer.md` τα έβαζε στο parity, που δεν τα ελέγχει. Βρέθηκε στο audit της 13/10 και χάθηκε. | 705 γραμμές, αλλά πραγματική επιλογή σε ~7–10 ζωντανές: Dedicated IP (3, με τιμή), Extra Addon domain (1, qty 2), OS/Location σε VPS. nixpal: 0. | `tblhostingconfigoptions` → `service_configs` μέσω `whmcs_ref` (μετά το WG-2). Checkbox/quantity μόνο με qty > 0. Στο parity. |
| WG-12 | **Τιμές domains:** ο importer γράφει `recurring_amount` σε **κάθε** domain, οπότε όλα γίνονται «τιμή με το χέρι» και δεν ακολουθούν τον κατάλογο. Το ποσό περιέχει και το ID protection. | 1.814/1.858 ίσα με την τιμή του TLD· ~44 πραγματικές εξαιρέσεις. | `null` όταν ισούται με την τιμή renew του TLD για τα έτη και το νόμισμα. |
| WG-13 | **Conversion παραγγελίας (GA4/Ads `purchase`):** το hook `myip_branding.php` το στέλνει με Consent Mode. Το MyCS έχει μόνο στατικό `theme_custom_head`. | 467–503 παραγγελίες στους 12μ, Smart Bidding του Ads. nixpal: UA, νεκρό από το 2023. | Ρύθμιση GA4 ID και event στη σελίδα ολοκλήρωσης: καθαρό ποσό, μία φορά, consent. |
| WG-14 | **Ημέρες τιμολόγησης ανά κύκλο** (ήδη στο ROADMAP, Χρέωση). | 14 / Monthly 10 / Quarterly 15 / Semi 20 / Annual 20. | Ρύθμιση ανά κύκλο με fallback. |
| WG-15 | **Επιλογές τύπου quantity με τιμή δεν χρεώνονται** (CART-9, `orders.md`). | 134 options σε ~20 ορατά προϊόντα. | Να κλείσει το CART-9, ή να έρθουν κρυφές ως τότε. |
| WG-16 | **Ρυθμίσεις module των προϊόντων** πέρα από το `package` (ROADMAP 9 (ζ)): reseller/ACL/όρια cPanel, **theme** (`configoption9`, 3 προϊόντα, 11 ζωντανές), mailcow, Centova, πλάνα Proxmox. | Βλ. ROADMAP 9 (ζ). | Μαζί με το WG-2. |
| WG-17 | **Ρυθμίσεις του myip που λείπουν από το runbook:** «όχι suspend Σάββατο, Κυριακή, Δευτέρα ως τις 10» (crontab: `skip --AutoSuspensions`)· auto-match του Bank transfer (`PaymentMatcher`, κλειστό από προεπιλογή, ενώ το `PaymentsTrackingMatch` τρέχει)· `InvoicePayTo` → `bill_to_text`· `EnablePDFInvoices` off (το MyCS επισυνάπτει πάντα το PDF, χωρίς ρύθμιση). | Βλ. αριστερά. | Λίστα ανά εταιρεία στο runbook. Ρύθμιση για το συνημμένο PDF. |
| WG-18 | **Collections:** τα γράφει το `AfterModuleTerminate` του `auto-cancellation.php` (ζωντανό κομμάτι του hook) και ο importer τα κάνει `unpaid`. | 15, σύνολο 813,99, 7 πελάτες· nixpal 0. | Απόφαση: `unpaid` με σημείωση και χωρίς αυτόματη χρέωση, ή ακύρωση πριν το import. |
| WG-19 | **Ανανέωση πολλών domains μαζί** (ήδη στο ROADMAP, Domains). | 473 παραγγελίες με ανανεώσεις domains στους 12μ. | Bulk «Ανανέωση επιλεγμένων» με ένα τιμολόγιο. |
| WG-20 | **Endpoint αναζήτησης domain του site** (`/api/domain-check.php` πάνω στο `init.php` του WHMCS)· γραμμένο μόνο στο «τι σπάει» του `importer.md`. | ~200 κλήσεις τον Οκτώβριο (live αναζήτηση /domains). | Δημόσιο JSON endpoint (τιμές TLD, διαθεσιμότητα), throttle, CORS. |
| WG-21 | **Συνημμένα tickets:** δεν έχει γίνει ακόμα πρόβα με αντίγραφο του φακέλου (`tickets.md`). | 3.966 tickets και 2.352 απαντήσεις με συνημμένα. | Πρόβα και έλεγχος `files_missing/refused`. |
| WG-22 | **Πού είναι η DNS ζώνη ενός domain:** το hook `_searchDomainZcloud.php` τη δείχνει στο global search του admin (ZoneCloud controller). Το hook έχει το token του controller **σε απλό κείμενο**. | Χρήση από staff· nixpal όχι. | Provider στο global search ή ενότητα στη σελίδα domain, με το token κρυπτογραφημένο. **Στο cut-over να ανακληθεί το token του hook.** |
| WG-23 | **Μεταγραφή ελληνικών στοιχείων επαφής** προς CNIC/Openprovider (ήδη στο ROADMAP, Domains). | 388/461 domains σε πελάτες με ελληνικά στοιχεία. | Όπως στο ROADMAP. |

## Π2: μικρά, ή απόφαση του υπευθύνου

- **WG-24** Σύνδεση ticket → υπηρεσία: το `tbltickets.service` (`S<id>`) δεν εισάγεται, αν και υπάρχει `tickets.service_id`. Για τα `D<id>` (domains) δεν υπάρχει στήλη. myip 1.968 tickets (254 στους 12μ), nixpal 206.
- **WG-25** Auto-close: το MyCS κλείνει μόνο τα Answered, ενώ το WHMCS κλείνει και Open/Customer-Reply (myip ~30 τον χρόνο χωρίς απάντηση staff). Να γραφτεί ως διαφορά στο `settings-map.md` ή να γίνει ρύθμιση.
- **WG-26** Συνημμένα rar/7z/bmp: απορρίπτονται στα νέα tickets και στο import. Απόφαση: να μπουν με magic check, ή αποδεκτή απώλεια.
- **WG-27** Γραμμές Hosting σε υπηρεσίες που σβήστηκαν παίρνουν αναφορά σε ανύπαρκτη υπηρεσία (`serviceLineReference`), αντίθετα με όσα λέει το `importer.md`. myip 3.808 γραμμές, nixpal 1.275, καμία σε απλήρωτο. Θέλει test και διόρθωση του κώδικα ή του doc.
- **WG-28** `tblhosting.termination_date` χάνεται (myip 159). Αν χρειάζεται, ως σημείωση· αλλιώς να γραφτεί ως παράλειψη.
- **WG-29** `billingcid` και `overrideautoclose`: από 1 πελάτη στο myip. Με το χέρι στο cut-over.
- **WG-30** Αρχεία πελατών (`tblclientsfiles`: myip 18, nixpal 1), downloads (4), `tblannouncementsEN` (7), to-do list (640 ανοιχτά, όλα του συστήματος): όλα γραμμένα στο `importer.md`, θέλουν τελική απόφαση. Για το to-do: να επιβεβαιωθεί ότι το D-20 βγάζει τις χειροκίνητες ανανεώσεις και μεταφορές στα Pending Module Actions.
- **WG-31** `AddFundsRequireOrder` (nixpal on): δεν υπάρχει ρύθμιση. Απόφαση.
- **WG-32** Τιμή ID protection (myip 5,50 τον χρόνο, 6 ενεργά): στο MyCS ανοίγει δωρεάν. Απόφαση: δωρεάν ή τιμή ανά TLD.
- **WG-33** Custom fields προϊόντος στη φόρμα παραγγελίας: μόνο 1 σε ορατό προϊόν (myip «IP»). Να επιβεβαιωθεί ότι είναι αποδεκτό.
- **WG-34** Live chat: ως το `livechat.md`, το snippet του LHC στο `theme_custom_footer` (runbook). unblockip: στην ανακοίνωση του cut-over, «με ticket».

## Γραμμένα ήδη ως συνειδητές παραλείψεις (δεν είναι κενά)
Ιστορικό activity log (μένει στο old-clients), προμήθειες gateway (`tblaccounts.fees`), αξιολογήσεις tickets, DNS
management και email forwarding του registrar, σημειώσεις τιμολογίου (γίνονται σημείωση λογαριασμού), «Send Message»
από το admin (ticket αντί για email), `defaultgateway` του πελάτη.

## Τι δεν ελέγχθηκε
- Δεν έτρεξε import ή parity. Τα κενά του importer βγήκαν από ανάγνωση κώδικα και από τις τοπικές βάσεις πρόβας.
  Τα WG-2 και WG-11 να επιβεβαιωθούν σε scratch import της myip.
- Οι κρυπτογραφημένες ρυθμίσεις των gateways (statement descriptor, test mode, γλώσσα/νόμισμα Eurobank).
- Τα hooks των server modules (thesslstorefullv2, pvewhmcs).
- Header, footer, CSS και signature των emails.
- `RequiredPWStrength`, `InvalidLoginBanLength`, `CaptchaForms` (captcha στο checkout) απέναντι στο throttling και στο
  altcha του MyCS.
- Account statement του πελάτη.
- Τα ionCube αρχεία (`ispapi_transliteration.php`, `mywhois.php`, `mydomainsearch.php`): μόνο ο σκοπός τους.

## Διορθώσεις στα docs (σε αυτό το PR)
- `ROADMAP.md` 9: το (στ) έχει γίνει ως βήμα 2 (#634) και μένουν τα βήματα 3–4· το (ζ) ισχύει μόνο για την εντολή του
  καταλόγου, όχι για το πλήρες import (WG-2)· η συναίνεση marketing χρησιμοποιείται (WG-9).
- `ROADMAP.md`, Provisioning: το `PaymentsTrackingMatch` έχει πια αντίστοιχο (`PaymentMatcher`), μένει η ενεργοποίηση (WG-17).
- `importer.md`: το parity δεν ελέγχει ακόμα config options (WG-11)· τα Collections χρησιμοποιούνται στο myip (WG-18)·
  το `AfterModuleTerminate` του `auto-cancellation.php` ζει.
