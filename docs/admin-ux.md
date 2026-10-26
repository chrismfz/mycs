# Admin «σαν WHMCS»: μενού και dashboard (2026-10-11)

Το staff δουλεύει ~15 χρόνια σε WHMCS. Κρατάμε το Filament, αλλά δίνουμε τα σημεία που ψάχνει το χέρι:
μενού πάνω με την ίδια δομή και dashboard με τους ίδιους μετρητές. Το admin μένει στα αγγλικά (`Admin Language`).

## Διεύθυνση: `/sysadmin` (2026-10-21)
Όπως στα άλλα δύο συστήματα της εταιρείας. Μία σταθερά, `AdminPanelProvider::PATH`· από αυτήν διαβάζουν το panel, το
guard του staff (`UseStaffGuard`), το login/logout, τα tags του audit και το export των εσόδων. Το παλιό `/admin/…`
πάει με 301 στο ίδιο σημείο του `/sysadmin/…`, με το query του (bookmarks, links σε mails που έφυγαν). Ένα signed
link που φτιάχτηκε πριν την αλλαγή δεν περνά το redirect (η υπογραφή είναι πάνω στο URL)· βγαίνει ξανά από τη σελίδα.

## Φόρμες συμπαγείς (2026-10-23)
Όπως στο WHMCS: η ετικέτα **δίπλα** στο πεδίο σε κάθε φόρμα και στοιχεία σελίδας (`BasePage::inlineLabels()`, μόνο οι
φόρμες των σελίδων· τα modals και τα φίλτρα κρατούν την ετικέτα πάνω), σε στήλη 8–11rem· λιγότερο κενό ανάμεσα στα πεδία·
το βοηθητικό κείμενο μικρό, κάτω από το πεδίο· ο άδειος πίνακας μία γραμμή, χωρίς εικονίδιο (`density.css`). Οι
ημερομηνίες διαλέγονται με τον picker του Filament σε **d/m/Y** (ο του browser έγραφε 11/22/2026 στο locale του),
η εβδομάδα από Δευτέρα.

**Η σελίδα της υπηρεσίας** (όπως το mockup, 2026-10-23): το usage και τα hosted domains σε **μία γραμμή** πάνω από τη
φόρμα (`summary-line`, «+N» για τα υπόλοιπα)· «Billing» και «Account on the server» δίπλα δίπλα, τα βοηθητικά κείμενα
πίσω από ⓘ· οι εντολές του module (Create, Suspend, Unsuspend, Change package, Terminate) ένα κουμπί η καθεμία μέσα στο
Account, που ανοίγει την ίδια ενέργεια της σελίδας σε εκείνη την εντολή (`changeStatus`, ίδιοι έλεγχοι). Πάνω δεξιά:
«Invoice next period», **«Log in to panel»** (το `getLoginUrl` του module, όπως ο πελάτης· δικό του δικαίωμα
`admin.services.sso`, μόνο active/suspended, https χωρίς credentials στο URL, `panel_login` στο audit) και «More ▾» για τα
υπόλοιπα. Το «Log in to panel» ανοίγει σε **νέα καρτέλα** (owner 2026-10-26): link σε signed route
(`filament.admin.sso.service`, `StaffLink`), όχι Livewire action· το ίδιο στο client area, όπου κάθε κουμπί `sso` ενός
server module (`getActions`) και τα shortcuts του είναι φόρμα με CSRF και `target="_blank"` στο `services.panel-login`.
Γενικό: κάθε module με `sso` κουμπί ή `getLoginUrl` το παίρνει χωρίς αλλαγή. Η μιας χρήσης διεύθυνση του panel δεν
γράφεται πουθενά (ούτε στο audit), και το redirect έχει `no-store` και `Referrer-Policy: no-referrer`.

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

**Όλες οι σελίδες στο πάνω μενού (owner 2026-10-21: «αρκεί να μην ξεχάσουμε κάποια σελίδα ή endpoint απ' έξω»):**
- Το πάνω μενού είναι ο πλήρης χάρτης του admin· το sidebar θα κρατήσει ό,τι θέλει δουλειά σήμερα.
- Το `TopMenuCoverageTest` αποτυγχάνει αν κάποιο resource ή page του `App\` δεν έχει γραμμή στο `MENU` ή λόγο στο
  `NOT_IN_MENU` (Dashboard, clusters). Ελέγχει επίσης ότι κάθε γραμμή ανοίγει (200) για staff με όλα τα δικαιώματα.
- Ό,τι φορτώνει το panel χωρίς γραμμή (η σελίδα ενός extension) μπαίνει μόνο του στο «Addons», όπως στο WHMCS.
- Τα Error/HTTP Logs φαίνονται μόνο όταν είναι ανοιχτό το debug.
- Προστέθηκαν όσα έλειπαν: Manage Users, Gateway Log, Predefined Replies, Reply Categories, MCP Proposals/Calls/
  Authorizations, Staff, Error/HTTP Logs. Το «View/Search Clients» οδηγούσε στους Users (τα logins)· τώρα οδηγεί στους
  Clients.
- **Ένα όνομα ανά σελίδα**, του WHMCS, στο μενού, στο sidebar και στον τίτλο:
  - Product Groups, Configurable Options, Custom Client Fields, Tax Rules, Payment Gateways, Email Templates, Staff Roles,
    API Credentials·
  - Promotions, Cancellation Requests, Products/Services (οι υπηρεσίες των πελατών), Transactions, Email Message Log,
    Activity Log, Gateway Log.
  Ο κατάλογος λέγεται «Products», για να μη μοιράζεται όνομα με τις υπηρεσίες των πελατών.
- Τα Reports θα είναι δική τους ομάδα, ανάμεσα στο Support και στο Utilities (σειρά του WHMCS).


**Το sidebar για τη δουλειά της μέρας (2026-10-21):**
- Σε desktop, όπου υπάρχει το πάνω μενού, το sidebar (`AdminSidebar`, `filament/admin/sidebar-work`) δεν είναι δεύτερο
  αντίγραφο του μενού.
- **Dashboard.**
- **Για χειρισμό:** tickets που περιμένουν απάντηση, παραγγελίες σε αναμονή, απλήρωτα τιμολόγια, υπηρεσίες σε αναμονή,
  ενέργειες modules, καραντίνα mail, domains προς έλεγχο, προτάσεις MCP, αποτυχημένα jobs, κατάσταση αυτοματισμών.
  - Ο μετρητής είναι το badge της ίδιας της σελίδας.
  - Ένα στοιχείο με μηδέν δεν φεύγει, φαίνεται αχνό.
  - Το καθένα φαίνεται μόνο σε όποιον μπορεί να ανοίξει τη σελίδα του.
- **Δημιουργία:** νέος πελάτης, νέα παραγγελία, νέο τιμολόγιο, νέο ticket.
- **Πρόσφατα:** οι 6 τελευταίοι πελάτες, υπηρεσίες, τιμολόγια, tickets, παραγγελίες και domains που άνοιξε ο ίδιος ο
  χρήστης του staff (trait `RemembersRecent`, στο session, ξεχωριστά για τον καθένα).
- Σε κινητό, όπου δεν υπάρχει το πάνω μενού, οι ομάδες του sidebar μένουν κάτω από αυτά (CSS, από `lg` και πάνω
  κρύβονται). Μένουν και όταν το sidebar είναι μαζεμένο στα εικονίδια.

**Ημερομηνίες:** παντού `d/m/Y` και `d/m/Y H:i`, στη ζώνη ώρας των Settings, μέσα από τα defaults του Filament
(`bootUsing`). Οι λίστες έγραφαν «Oct 20, 2026», οι παραγγελίες «2026-10-18 21:01:40».

**Dashboard:**
- τα έσοδα με νόμισμα·
- «Καμία αλλαγή» σε γκρι, αντί για «Increased by 0.00%» σε πράσινο ή κόκκινο·
- «Νέο σε αυτή την περίοδο» όταν η προηγούμενη περίοδος ήταν μηδέν·
- οι νέες παραγγελίες σε δικό τους άξονα στο γράφημα·
- τα «Domains/Services to check» μόνο όταν υπάρχει κάτι να ελεγχθεί· οι ανανεώσεις, άδειες, σε μία γραμμή.

Στη λίστα των παραγγελιών, η στήλη δείχνει την ημερομηνία της παραγγελίας· η τελευταία αλλαγή φαίνεται σε tooltip.

**Διάταξη του dashboard (owner 2026-10-21, «baby steps», σε σύγκριση με το WHMCS):**
- 5 κάρτες, με τη σειρά του WHMCS: Pending Orders, Revenue, Tickets, Services, Pending Module Actions. Οι δύο ουρές
  φαίνονται πάντα, γκρι όταν είναι μηδέν.
- Το γράφημα των εσόδων έχει περίπου το μισό ύψος (160px).
- Οι 4 λίστες (ανανεώσεις domains και υπηρεσιών, domains και υπηρεσίες προς έλεγχο) μπαίνουν δύο δίπλα δίπλα, σε δύο
  σειρές. Η πρώτη λίστα κάθε ζεύγους ξεκινά πάντα νέα σειρά, ό,τι κι αν αφήνουν τα widgets από πάνω (για παράδειγμα,
  χωρίς δικαίωμα στα reports). Όταν λείπει μια λίστα «προς έλεγχο», η άλλη μένει μόνη στη σειρά της.
- Οι κάρτες γεμίζουν τη σειρά: πέντε, ή όσες βλέπει ο χρήστης του staff.
- **Δεύτερο πέρασμα (owner 2026-10-21: «το registrar credit χάλια… tiny», «κενός χώρος… να μπει εκεί το upcoming
  renewals»):**
  - Το υπόλοιπο στους registrars είναι μια λεπτή γραμμή κάτω από τις κάρτες, π.χ. «Openprovider 131,87 € · GR 201,00 €».
    Πότε διαβάστηκε φαίνεται με hover, και ένα πρόβλημα με κόκκινο και ⚠.
  - Η σειρά: κάρτες, registrars, Revenue | Support, Income | Upcoming domain renewals (στον χώρο δίπλα στο Income), μετά
    οι υπόλοιπες λίστες ανά δύο, και τελευταίο το Active Users σε όλο το πλάτος.
  - Τα widgets πλήρους πλάτους είναι μόνο στην αρχή και στο τέλος, οπότε η ροή δεν αφήνει κενά, όποια δικαιώματα κι αν
    έχει ο χρήστης του staff.

**Ληξιπρόθεσμα τιμολόγια (owner 2026-10-21: «βλέπω μόνο unpaid… όχι ποιοι καθυστερούν»):**
- Ληξιπρόθεσμο είναι το απλήρωτο τιμολόγιο με ημερομηνία λήξης πριν από σήμερα (`Invoice::scopeOverdue`,
  `daysOverdue()`). Όπως και στις υπενθυμίσεις, δεν μετρούν:
  - τα «add funds», δηλαδή αγορές υπολοίπου που έμειναν στο gateway, όπου δεν οφείλεται τίποτα·
  - όσα έχουν πληρωμή σε εξέλιξη (π.χ. SEPA)·
  - όσα δεν έχουν πελάτη.
  (Adversarial review του #286.)
- Η λίστα έχει στήλη «Due Date» (ταξινομείται), κόκκινη για τα ληξιπρόθεσμα και με «N days late».
- Το φίλτρο «Overdue» στέκεται δίπλα στο Status.
- Στο sidebar, κάτω από τα «Απλήρωτα», τα «Ληξιπρόθεσμα» έχουν δικό τους κόκκινο μετρητή και ανοίγουν τη λίστα
  φιλτραρισμένη.

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

## Σελίδες του καταστήματος (2026-10-20)
Ο owner: «χύμα και άσχημες· οι κατηγορίες αριστερά και το h1 της κατηγορίας μπερδεύουν».
- **Κατηγορία:**
  - breadcrumb, και το όνομα με την περιγραφή της πάνω από τα προϊόντα (όχι πάνω από το μενού)·
  - οι κατηγορίες σε μενού με τίτλο, η ανοιχτή σημειωμένη (`aria-current`)· στο κινητό, μια σειρά που κυλά.
- **Κάρτα προϊόντος:**
  - η τιμή μαζί με τον κύκλο της («/ έτος») και τους άλλους κύκλους («Και για 2 έτη, 3 έτη»)·
  - το κόστος ενεργοποίησης και η περιγραφή·
  - ένα κουμπί «Παραγγελία» και ένα link «Λεπτομέρειες» (χωρίς αυτό με το «Direct Checkout»).
  Το κουμπί με το καλάθι, που δεν έλεγε τι κάνει, φεύγει.
- **Προϊόν:** breadcrumb, τιμή με κύκλο, ένα κουμπί που δουλεύει. Το εικονίδιο του καλαθιού δεν οδηγούσε πουθενά.
- **Checkout:** «Περίοδος χρέωσης»· τα πακέτα με τον κύκλο τους στη γλώσσα του πελάτη (`Plan::label()`), αντί για τα
  «Annually» του WHMCS. Όπου το staff έδωσε δικό του όνομα, αυτό μένει.
- **Καλάθι:**
  - ο κύκλος κάθε γραμμής·
  - «Κουπόνι» και «το καθένα» στα ελληνικά·
  - το άδειο καλάθι οδηγεί πίσω στο κατάστημα.
- **Δεδομένα, όχι κώδικας:**
  - το «€504,00 EUR» βγαίνει από το νόμισμα (πρόθεμα «€» και επίθεμα « EUR», όπως στο WHMCS) και διορθώνεται στο
    Configuration → Currencies·
  - η σειρά των προϊόντων είναι το `sort` τους.

### Δεύτερο πέρασμα (2026-10-20)
- **Αρχική:** «από €192 / έτος», όχι «· Annually» (το όνομα του πακέτου από το WHMCS).
- **Ελληνικά:** τα «Free», «Not available in your currency» και τα μηνύματα του καλαθιού («χωρά έως 15»,
  «προσθέτετε πολύ γρήγορα»).
- **Καλάθι:** το «Αφαίρεση» ως διακριτικό link, όχι ως το πιο έντονο κουμπί της σελίδας· το κουπόνι πίσω από το «Έχετε
  κουπόνι;» (ανοιχτό όταν απορρίφθηκε)· πιο φαρδύ πεδίο για τον κωδικό EPP.
- **Κάρτες:** από μια μακριά λίστα χαρακτηριστικών φαίνονται τα 6 πρώτα και ένα «+N ακόμη» που ανοίγει τα υπόλοιπα.
- **Δεδομένα:** το «Welcome!» της αρχικής είναι το Appearance → Home Page Text, και η γλώσσα των επισκεπτών είναι ρύθμιση.

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
