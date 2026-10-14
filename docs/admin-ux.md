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
