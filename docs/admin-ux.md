# Admin «σαν WHMCS»: μενού και dashboard (2026-10-11)

Το staff δουλεύει ~15 χρόνια σε WHMCS. Κρατάμε το Filament, αλλά δίνουμε τα σημεία που ψάχνει το χέρι:
μενού πάνω με την ίδια δομή και dashboard με τους ίδιους μετρητές. Το admin μένει στα αγγλικά (`Admin Language`).

## Μενού
**Topbar Menu (vaslv/filament-topbar-menu): όχι.** Είναι MIT και Filament 5, αλλά:
- τα links ζουν σε δικό του πίνακα και συντηρούνται με το χέρι, όχι από τις σελίδες του admin (διπλή καταχώριση)·
- δεν ελέγχει `canAccess`/policies των σελίδων· τα roles θέλουν `hasAnyRole()` (spatie), που δεν έχουμε, άρα
  ό,τι έχει περιορισμό ρόλου κρύβεται από όλους·
- μικρό project (10 stars, ένας maintainer).

**Δικό μας (`App\Admin\TopMenu`):** ~150 γραμμές, μέσω του ίδιου επίσημου hook (`PanelsRenderHook::TOPBAR_LOGO_AFTER`)
και των ίδιων components με το top navigation του Filament. Το μενού ορίζεται σε κώδικα και δείχνει στις υπάρχουσες
σελίδες, άρα μια σελίδα είναι και πάνω και στο sidebar. Κάθε item φαίνεται μόνο αν το επιτρέπει το `canAccess()`
(`canCreate()` για «Add New …») της σελίδας: ίδια permissions με το sidebar. Μόνο σε desktop· σε κινητό μένει το sidebar.

| WHMCS | MyCS |
|---|---|
| Clients | View/Search Clients, Add New Client, Products/Services, Cancellation Requests |
| Orders | List All Orders, Add New Order, Promotions |
| Billing | Invoices, Create Invoice, Transactions List, Adjustment Notes |
| Support | Support Tickets, Open New Ticket |
| Reports | — (δεν υπάρχουν ακόμα· τα χρησιμοποιούμε συχνά, ιδέες από WHMCS και Ekdosi· ROADMAP Φάση 3) |
| Utilities | Email Message Log, Activity Log, Cron Stats, Failed Jobs |
| Setup | Settings, Product Groups, Products/Services, Configurable Options, Custom Client Fields, Currencies, Tax Rules, Payment Gateways, Servers, Email Templates, Staff Roles, Extensions, API, OAuth |
| Addons | — (extensions: όταν αποκτήσουν σελίδες, μπαίνουν εδώ) |

Νέα σελίδα (domains, reports): μία γραμμή στο `TopMenu::MENU`.

## Πυκνότητα (2026-10-19)
Το Filament είναι «αέρινο» και το πλήρωμα συνηθισμένο στο WHMCS. Μετρήσεις στο ίδιο παράθυρο (2147px): γραμμή πίνακα
WHMCS 29px, Filament 57px· πλάτος πίνακα WHMCS 1906px, Filament ~1030px (όριο 1280px). Τα γράμματα είναι ίδια (14px):
το βάρος είναι το padding (τρία επίπεδα μέσα σε κάθε κελί) και το όριο πλάτους.

**Όχι πληρωμένο «compact theme», όχι custom Blade για όλα.** Το Filament βάζει τα στυλ του σε `@layer components` και
δίνει σταθερές κλάσεις `fi-*`, άρα ένα αρχείο CSS εκτός layer τα αλλάζει χωρίς `!important`:
- `resources/css/filament/admin/density.css`: σελίδα, sections (το «compact» του Filament παντού), πίνακες από `sm`
  και πάνω (γραμμή ~35px· στο κινητό μένει το stacked layout του Filament).
- Panel: `maxContentWidth(Width::Full)`, `sidebarCollapsibleOnDesktop()` (το μενού πάνω έχει τις ίδιες σελίδες), 50
  γραμμές ανά σελίδα (25/50/100/200)· όποιος πίνακας ορίζει δικές του επιλογές (widgets, logs) κρατά το default του.
- Custom Blade μόνο όπου θέλουμε διάταξη WHMCS που δεν βγαίνει από πίνακα ή φόρμα: Summary πελάτη, dashboard.

Σε αναβάθμιση του Filament: μια λίστα, μια φόρμα και ένα Summary, σε desktop και κινητό.

## Λίστα πελατών και tabs του πελάτη (2026-10-19)
Οι στήλες και οι όροι του WHMCS:
- **Clients:** ID, Name (με την πίστωση από κάτω), Company, Email, Services «ενεργές (όλες)», Date Created· φίλτρο
  «Active services» ναι/όχι· το κλικ στη γραμμή ανοίγει το Summary.
- **Products/Services:** Product/Service (με το domain από κάτω), Amount (με φόρο, όπως στο Summary), Billing Cycle
  (`ClientSummary::cycle`), Signup Date, Next Due Date, Status· αναζήτηση σε προϊόν και domain, φίλτρο Status.
- **Invoices:** Invoice #, Invoice Date, Due Date, Total, Remaining, Status, τα νεότερα πρώτα. Τα σύνολα φορτώνουν
  lines, notes και transactions μαζί με τις γραμμές (όχι query ανά γραμμή). «Date Paid» δεν μπαίνει ακόμα: δεν είναι
  πεδίο του τιμολογίου, βγαίνει από τις συναλλαγές.

## Απευθείας links (2026-10-19)
Τα sites των εταιρειών (WordPress) πουλούν με links προς το κατάστημα· στο cut-over τα links τους **αλλάζουν** στα URLs
του MyCS (το «Order link» του admin). Δεν κρατάμε legacy URLs του WHMCS (`cart.php`, `store/…`, `clientarea.php`, …):
μπήκαν με το #216 και βγήκαν μετά, με απόφαση του owner (2026-10-19).
- **Ορατότητα, όπως στο WHMCS:** «hidden» = δεν εμφανίζεται στις λίστες, αγοράζεται με το link του· «retired»
  (`products.retired`, από το `retired` του WHMCS) = δεν αγοράζεται: 404 στη σελίδα και στο checkout, άρνηση στο
  `PlaceOrderService` (και για staff), όχι στόχος αναβάθμισης. Όχι «stock 0»: κάθε ακύρωση ή τερματισμός δίνει πίσω stock
  (ADV-1 του #216). Κρυφό με stock 0 = απλώς εξαντλημένο. `Product::listed()` / `Product::reachable()`. Μια κατηγορία
  χωρίς ορατά προϊόντα **και** χωρίς ορατές υποκατηγορίες (κρυφή ομάδα του WHMCS) ανοίγει με το link της και δείχνει
  ό,τι αγοράζεται ακόμα· μόνο με retired δίνει 404.
- **Admin:** «Order link» (με αντιγραφή) στην επεξεργασία κατηγορίας και προϊόντος· `?plan=` με το id ενός πλάνου
  προεπιλέγει τον κύκλο του.

## Μενού επισκέπτη και αρχική (2026-10-19)
Η βιτρίνα κάθε εταιρείας είναι το δικό της site (WordPress)· το MyCS για τον επισκέπτη είναι κατάστημα και πόρτα στον
λογαριασμό, όχι δεύτερο site. Μετρημένα (WHMCS, 2026-10-19): myip 22 ομάδες, 5 ορατές (η SSL & Security με 56
προϊόντα)· nixpal 7, 2 ορατές. Γι' αυτό δεν «χύνονται» όλες οι κατηγορίες στη σελίδα.
- **Κατηγορία «Show in menu»** (`categories.show_in_menu`): στο μενού και στην αρχική μόνο αυτές (`Category::inMenu()`).
  Ο importer το γεμίζει από το `hidden` της ομάδας του WHMCS. Οι άλλες ανοίγουν με το link τους.
- **Μενού** (`Navigation::getLinks()`): Προϊόντα ▾ (κατηγορίες του μενού) · Domains ▾ (αναζήτηση, τιμές, whois) ·
  Επικοινωνία (νέο ticket· χωρίς login όταν γίνει το contact). Η αρχική είναι το λογότυπο. Γνωσιακή βάση και κατάσταση
  δικτύου όχι (είναι στα sites των εταιρειών).
- **Link «← κεντρικό site»** στο μενού από το Appearance (`theme_main_site_url`, `theme_main_site_label`), όχι
  ξεχωριστή μπάρα: το μενού είναι fixed.
- **Αρχική:** το `home_page_text`, αναζήτηση domain, οι κατηγορίες του μενού ως λίστα με «από €X · κύκλος (€Y/μήνα)»
  (η μικρότερη από τις τιμές που δείχνει η σελίδα της κατηγορίας, `Category::startingPrice()`), «Ήδη πελάτης;».
  Με login πηγαίνει στο Dashboard, όπως το clientarea του WHMCS.
- Επόμενα: η SSL & Security με υποκατηγορίες ή φίλτρα· contact χωρίς login με δικό μας captcha (ALTCHA).

## Dashboard: τι έχει το WHMCS και τι έχουμε
Τα widgets του upstream: `Overview` (έσοδα/tickets/υπηρεσίες με σύγκριση περιόδου), `Revenue` (γράφημα),
`ActiveUsers` (online τελευταίας ώρας), `Support` (5 πρόσφατα ανοιχτά tickets), όλα με permission `admin.widgets.*`.

| WHMCS | Σήμερα | Πηγή δεδομένων | Σχέδιο |
|---|---|---|---|
| **Pending Orders** (μετρητής) | μόνο badge στις υπηρεσίες | `services.status = pending` (ανά `order_id`) | νέο |
| **Tickets Waiting** | badge στο μενού, widget στη λίστα tickets | `tickets.status = open` (απάντησε ο πελάτης) | νέο, στη σειρά μετρητών |
| **Pending Cancellations** | — | `service_cancellations` `end_of_period` με υπηρεσία ακόμα active/suspended | νέο |
| **Pending Module Actions** | badge στα Failed Jobs | `failed_jobs` με `Jobs\Server\*`, υπηρεσίες κολλημένες σε pending | νέο |
| Support (awaiting reply, assigned to you, πρόσφατα) | `Support` (5 πρόσφατα) | `tickets.assigned_to` | επέκταση: «Assigned to me» |
| Client Activity (active clients, online, πρόσφατα logins) | `ActiveUsers` | πελάτες με ενεργή υπηρεσία, `user_sessions` | επέκταση |
| Billing (Today / This Month / This Year / All Time) | `Overview` με φίλτρο περιόδου | `invoice_transactions` succeeded, όχι credit, μείον refunds | νέο: 4 ποσά μαζί |
| Income Forecast (ανά κύκλο, ετήσια εκτίμηση) | — | ενεργές υπηρεσίες: τιμή × κύκλος χρέωσης | νέο |
| Upcoming Renewals (υπηρεσίες) | — | `services.expires_at` στις επόμενες N ημέρες | νέο |
| Upcoming Domain Renewals | — | `domains.expires_at` | με τη Φάση 2 (domains) |
| Automation Overview | σελίδα Cron Stats | `cron_stats` | μικρό widget με link |
| Shortcuts (Add client/order, open ticket) | — | — | καλύπτεται από το μενού («Add New …») |
| To-Do, Staff Noticeboard, Network Status | — | — | όχι τώρα |
| Payments tracking (τράπεζα) | — | δικός μας κώδικας στο WHMCS: IMAP της τράπεζας, αριθμός προτιμολογίου στην αιτιολογία → mark paid | ROADMAP Φάση 3 (πάνω στον allocator) |

Κάθε μετρητής είναι link στη φιλτραρισμένη λίστα (όπως στο WHMCS) και έχει το δικό του permission (`admin.widgets.*`).
Τα ποσά από `invoice_transactions` αφαιρούν τα `refunded_amount`.

## Σειρά
1. Μενού (αυτό το PR).
2. Σειρά μετρητών (Pending Orders, Tickets Waiting, Pending Cancellations, Pending Module Actions) + Billing.
3. Income Forecast + Upcoming Renewals· «Assigned to me» στο Support.
4. Domains (Φάση 2): Upcoming Domain Renewals, μενού «Domains».
