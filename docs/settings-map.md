# Ρυθμίσεις WHMCS → MyCS (ανά instance, 2026-10-11)

> Αρχή: **ένας κώδικας, διαφορετικές ρυθμίσεις ανά instance.** Ό,τι στο WHMCS είναι επιλογή (αρίθμηση, proforma,
> αυτόματη πίστωση, ομαδοποίηση γραμμών, …) γίνεται ρύθμιση και στο MyCS, όχι κώδικας για τη μία εταιρεία.
> Πηγές: screenshots General Settings του WHMCS (Ordering, Domains, Invoices) και `whmcs-usage.md`. Τα κλειδιά του
> upstream είναι από το `app/Classes/Settings.php` στο `5b69d45`.
> **P** = υπάρχει στο upstream · **P~** = υπάρχει μερικώς · **Ν** = νέα ρύθμιση.

## Τιμολόγια
| WHMCS | myip | nixpal | MyCS |
|---|---|---|---|
| Αριθμός τιμολογίου | = invoice ID· «Invoice Starting #» ελάχιστο **32.477** σήμερα | Sequential paid numbering **`{YEAR}-{NUMBER}`**, επόμενο **6819**· τα IDs των proforma φτάνουν το 7.214 | **P:** `invoice_number`, `invoice_number_format` (`{number}{year}{month}{day}`), `invoice_number_padding`, `invoice_proforma` (αριθμός μόνο στην πληρωμή, ξεχωριστός από το id), `invoice_number_is_id` (myip: αριθμός = id, ο μετρητής δεν χρησιμοποιείται). Ο μετρητής κρατάει τον **τελευταίο** αριθμό (myip: 32.476, nixpal: 6818). Τα IDs του WHMCS κρατιούνται και στα δύο instances |
| Enable Proforma Invoicing | off | **on** | **P:** `invoice_proforma` |
| Continuous Invoice Generation | off | off | **Ν** (default off = συμπεριφορά upstream: δεν βγάζει νέο τιμολόγιο αν υπάρχει unpaid) |
| Enable PDF Invoices (συνημμένο στο email) | off | on | **Ν** |
| PDF font/μέγεθος | Freesans, A4 | Helvetica, A4 | P~ (dompdf)· Freesans/DejaVu για τα ελληνικά |
| Store Client Data Snapshot | off | off | **P:** `invoice_snapshot` |
| **Enable Mass Payment** | on | on | **Ν:** `mass_pay_enabled` (PR F2, `payments.md`): ομάδα πληρωμής χωρίς container τιμολόγιο |
| Clients Choose Gateway | on | on | P (επιλογή gateway στην πληρωμή) |
| Group Similar Line Items | off | **on** | **Ν** |
| Cancellation Request → ακύρωση απλήρωτων τιμολογίων | on | on | **M** (#61): σταθερή συμπεριφορά (on και στις δύο)· τιμολόγιο με χρήματα gateway ή με γραμμές άλλων υπηρεσιών μένει ανοιχτό για το staff (MT-2) |
| Auto-cancel subscriptions σε upgrade/ακύρωση | on | on | P~ (PayPal/Stripe subscriptions) → έλεγχος |
| Late fees | 0 (off) | 0 (off) | — δεν το χρειαζόμαστε |
| Accepted card types, Issue number | — | Visa/MasterCard | — (τα διαχειρίζεται το Stripe) |

## Παραγγελίες
| WHMCS (myip) | Τιμή | MyCS |
|---|---|---|
| Order Days Grace | 8 | **Ν** (προθεσμία πληρωμής του πρώτου τιμολογίου, όχι ακύρωση: βλ. Αυτοματισμοί) |
| Default Order Form | Standard Cart | P (theme) |
| On-Demand Renewals (πρόωρη ανανέωση από πελάτη, ημέρες ανά κύκλο 31/92/183/366/731/1096) | off | **Ν** (#1167). Μαζί με το «Ανανέωση τώρα» του admin |
| Enable TOS Acceptance + URL | on, `https://myip.gr/tos/` | **P:** `tos` |
| Auto Redirect on Checkout | → payment gateway | P~ |
| Allow Notes on Checkout | on | **P:** πάντα (σημειώσεις στο cart, στην παραγγελία, στη λίστα παραγγελιών και στο email «New Order») |
| New Order Notification (email στους admins) | on | **P:** `mail_staff_new_order`, default on· στο System Email Address, μόνο για παραγγελίες πελάτη από το checkout (όχι του staff)· με είδη, τιμολόγιο, σημειώσεις, IP και links |
| Monthly Pricing Breakdown | off | — |
| **Block Existing Domains** | on | **Ν**: και για το DirectAdmin #1186 (διπλό domain) |
| No Invoice Email on Order | off | P (templates) |
| Skip Fraud Check for Existing / Only Auto Provision for Existing | on / off | — (fraud δεν χρησιμοποιείται) |
| Random usernames | off | P~ ανά server extension |
| Signup Anniversary Prorata | off | — |
| Cross-selling | off | — |

## Domains (myip)
| WHMCS | Τιμή | MyCS |
|---|---|---|
| Register / transfer / χρήση δικού domain | on / on / on | Ν (domains από το Ekdosi) |
| Enable Renewal Orders (πρόωρη ανανέωση domain) | on | Ν |
| **Auto Renew on Payment** | on | Ν: πολιτική on-paid (default)· on-issue ανά πελάτη· prepaid (`history/platform-evaluation.md`) |
| Auto Renew Requires Product (δωρεάν domain) | on | Ν |
| Default Auto Renewal Setting | on | Ν (το Ekdosi έχει `auto_renew` ανά domain) |
| To-Do entries για αποτυχίες | on | Ν (λίστα εκκρεμοτήτων admin / ειδοποίηση) |
| IDN | on | Ν (`lib/myopenprovider` idn_encoder, `lib/domain-tools`) |
| Grace & Redemption Fees | on, **στο υπάρχον τιμολόγιο** | Ν |
| Default nameservers | ns1–ns3.myip.gr | Ν (ανά instance) |
| Use client details για contacts | on | Ν |

## Support (myip)
> Απόφαση: το support ακολουθεί τα χνάρια του **WHMCS + Ekdosi**: σύστημα tickets του Ekdosi, με τις ρυθμίσεις του
> WHMCS. **Δεχόμαστε «αδέσποτα»** (guest tickets από email, ~μισά tickets του myip). **E** = υπάρχει στο Ekdosi ·
> **E?** = να επιβεβαιωθεί στον κώδικα του Ekdosi.

| WHMCS | Τιμή | MyCS |
|---|---|---|
| Support Module | Built-in | E (tickets του Ekdosi, όχι του upstream) |
| Ticket Mask Format | `%n%n%n%n%n%n` (6 ψηφία) | E? (το Ekdosi κάνει threading με `[TK-…]`) → **Ν:** μορφή ρυθμιζόμενη· τα παλιά νούμερα από τον importer |
| Reply list order | Νεότερο πρώτα | Ν (ρύθμιση UI) |
| Ticket Reply Email Limit | 10 ανά 15' | E? → Ν (rate limit εξερχόμενων ανά ticket, κατά των loops) |
| Show client-only departments σε guests | off | Ν |
| Client tickets require login | on | E (portal guard) |
| Knowledgebase suggestions | on | Ν (KB: 97 άρθρα· `knowledge_search` του MCP του Ekdosi ως αφετηρία) |
| Attachment thumbnails | on | E? |
| **Support Ticket Rating** | on | E (αξιολόγηση) |
| **Auto add CC recipients** από εισερχόμενα | on | E (`TicketWatcher` CC/Bcc) |
| Prevent email reopening κλειστών | off | E? → Ν (ρύθμιση) |
| Update last reply timestamp | σε κάθε απάντηση | E? |
| Disable reply email logging | off | — |
| **Allowed attachment types** | `.jpg .gif .jpeg .png .zip .rar .pdf .7zip .7z .doc .docx .txt .bmp` | E (allowlist + caps) → ρύθμιση ανά instance |
| Service status require login | on | — (network issues δεν χρησιμοποιούνται) |
| Include product downloads | on | Ν (4 downloads· nice to have) |
| **Allow insecure imports** (reply σε υπάρχον από άγνωστο αποστολέα) | **off** | E? → Ν: **νέο** ticket από άγνωστο = ναι (αδέσποτα), **reply** σε υπάρχον μόνο από πελάτη ή CC |
| **Reopen existing tickets if importing fails** | on, με σημείωση στο ticket | E? → Ν |
| Τμήματα με email piping (3) | POP | E (IMAP ανά τμήμα, κρυπτογραφημένη ρύθμιση) |
| Spam filters (835) | — | E (`TicketBlockedSender`) + import των φίλτρων |
| Predefined replies (9 σε 4 κατηγορίες) | — | E (`CannedReply` / κατηγορίες) |
| Merge, σημειώσεις staff | 72 / 227 | E |
| Auto-close | 48 ώρες | E? / P (`cronjob_close_ticket`) |

## Αυτοματισμοί (Automation Settings)
Η καρτέλα **Automation** των ρυθμίσεων (key `cronjob`) ακολουθεί τις ενότητες και τη διατύπωση της σελίδας Automation
Settings του WHMCS. Κάθε εταιρεία έχει τις δικές της τιμές στη βάση του instance της· τα defaults του κώδικα είναι της
nixpal (pilot). Τιμές από το `tblconfiguration` των δύο WHMCS, 2026-10-13 (read-only).

| WHMCS (`tblconfiguration`) | myip | nixpal | MyCS |
|---|---|---|---|
| Time of Day (`DailyCronExecutionHour`) | 10 | 09 | **P:** `cronjob_time` (default 09:00· όχι 03:xx, αλλαγή ώρας, BIL-16) |
| Enable Suspension (`AutoSuspension`) | on | on | **P:** `cronjob_suspend_enabled` (default on) |
| Suspend Days (`AutoSuspensionDays`), από την ημερομηνία λήξης | 15 | 8 | **P:** `cronjob_order_suspend` (default 8) |
| — μόνο με ληξιπρόθεσμο τιμολόγιο | (όχι στο WHMCS) | | **P:** `cronjob_suspend_requires_invoice`, default off· on: τιμολόγιο ληξιπρόθεσμο **και** εκδομένο πριν από Suspend Days, ειδοποίηση staff για «ληγμένη χωρίς τιμολόγιο» (BIL-5) |
| Send Suspension Email | on | on | **Ν** (διακόπτης) |
| Enable Unsuspension (`AutoUnsuspend`) | on | on | P, πάντα on (η ανανέωση με την πληρωμή, `RenewServiceService`)· διακόπτης **Ν**. Δ3a: όχι όσο το cron θα την ανέστειλε ξανά· μένει σε αναστολή, το cron τιμολογεί την επόμενη περίοδο, ειδοποίηση staff· το staff κάνει unsuspend με το χέρι |
| Enable Termination (`AutoTermination`) | on | on | **P:** `cronjob_terminate_enabled` (default on) |
| Termination Days (`AutoTerminationDays`), από την ημερομηνία λήξης | 120 | 60 | **P:** `cronjob_order_terminate` (default 60) |
| — μέρες και ώρα για suspend/terminate | (cron hacks: όχι Σ/Κ, Δευτέρα από 10:00) | | **P:** `cronjob_dunning_days` (default όλες) και `cronjob_dunning_from` (default 00:00): μόνο αυτές τις μέρες και από αυτή την ώρα· το ωριαίο `app:cron-job --dunning-only` τα κάνει όταν ένα ημερήσιο cron των τελευταίων 24 ωρών έφτασε ως εκεί (`cron_dunning_ready_at`· ένα σφάλμα σε άλλο βήμα δεν τα σταματά), κάνοντας πρώτα ό,τι θα έκανε το ημερήσιο πριν από αυτά (πίστωση, χρέωση κάρτας, ακυρώσεις τέλους περιόδου· μία φορά τη μέρα όπου γίνουν)· η ώρα μετράει ολόκληρη (10:30 → 10:00)· ειδοποιήσεις και σφάλματα του ωριαίου μία φορά τη μέρα· οι επαναλήψεις χρέωσης κάρτας (AutoCharge) ακολουθούν ακόμη τις Suspend Days χωρίς το παράθυρο· ό,τι πέφτει εκτός περιμένει την επόμενη επιτρεπτή μέρα, το mail «θα ανασταλεί στις …» λέει αυτή τη μέρα (owner 2026-10-23) |
| — ελάχιστες ημέρες σε αναστολή | (όχι στο WHMCS) | | **P:** `cronjob_terminate_min_suspended_days`, default 1· 0 = ακριβώς WHMCS (BIL-4) |
| — προστασία «πληρωμένο, όχι εφαρμοσμένο» | (όχι στο WHMCS) | | **P:** `cronjob_paid_not_applied_days`, default 30 (1–365): η υπηρεσία δεν χρεώνεται ξανά, δεν αναστέλλεται, δεν τερματίζεται όσο το cron ξαναδοκιμάζει (BIL-9) |
| — ακύρωση απλήρωτων «Add Funds» | (hook του myip: 30) | | **P:** `cronjob_add_funds_cancel`, default 0 (ποτέ)· το myip 30 στο cut-over |
| Email στο staff με ό,τι έκλεισε (WHMCS: email «Cron Job Activity» στους admins, όχι ρύθμιση) | — | — | **P:** `cronjob_suspension_digest`, default on· μετά το cron, ένα email στο System Email Address με κάθε υπηρεσία που έγινε suspend/terminate για μη πληρωμή (πελάτης, απλήρωτα τιμολόγια, link για Unsuspend και «Do Not Suspend Until»)· μόνο τις μέρες που έκλεισε κάτι |
| — ανοιχτά τιμολόγια στον τερματισμό/ακύρωση (`CancelInvoiceOnCancellation`) | on | on | **P:** `cronjob_end_keeps_overdue`, default off = ακυρώνονται όλα (όπως σήμερα)· on: τα ληξιπρόθεσμα πάνω από `cronjob_end_keeps_overdue_days` (default 0) μένουν απλήρωτα, χρέος (Δ3a, απόφαση 4 της 2026-10-15) |
| Invoice Generation (`CreateInvoiceDaysBefore`) | 14· ανά κύκλο: μήνας 10, τρίμηνο 15, εξάμηνο/έτος 20· domains 15 | 7 | P~ `cronjob_invoice` (default 7, μία τιμή) → **Ν: ανά κύκλο** |
| Domain Invoice Generation (`CreateDomainInvoiceDaysBefore`) | 15 | κενό (→ 7) | ✓ `domain_invoice_days` (D5b-1· **default 15** και για τις δύο, ιδιοκτήτης 2026-10-17· κενό = `cronjob_invoice`) |
| Domain Renewal Notices (`DomainRenewalNotices`) | 60,30,15,10,5 | 30,7,-3 | ✓ `domain_reminder_days` (D7-1· **default `30,7,1,-3`** και για τις δύο, ICANN ERRP· αρνητικές = μετά τη λήξη· κενό = καμία· `domains.md` §2b) |
| Order Days Grace (`OrderDaysGrace`) | 8 | 0 | **Ν** (προθεσμία πληρωμής του πρώτου τιμολογίου)· το `cronjob_order_cancel` (όχι στο WHMCS· **default 0 = ποτέ**, ιδιοκτήτης 2026-10-19: παραγγελία ακυρώνει μόνο το staff· N ημέρες = ακυρώνει όσες μένουν απλήρωτες χωρίς καμία πληρωμή, BIL-12) |
| Payment Reminder Emails, Invoice Unpaid Reminder | on, 1 | on, 6 | **P:** `cronjob_payment_reminder` (default 6, 0 = off)· με αποθηκευμένο τρόπο πληρωμής ή συνδρομή φεύγει το «payment due» (WHMCS «Credit Card Payment Due») |
| First/Second/Third Overdue Reminder | 1/3/5 | 1/2/— | **P:** `cronjob_overdue_reminders` (λίστα ημερών, default «1,2», κενό = off): ένα template, η τελευταία λέει πότε αναστέλλεται· μία φορά η καθεμία (`invoice_reminders`) |
| Add Late Fee Days (`AddLateFeeDays`) | 5 (Percentage, ποσό κενό) | 0 | **Ν** (δεν χρησιμοποιείται στην πράξη) |
| Enable Auto Cancellation (unpaid invoices) | off | off | — |
| Cancellation Requests (`AutoCancellationRequests`) | on | on | **M** (#61), πάντα on |
| Credit card capture (Process Days Before, Attempt Only Once, Retry) | 0 / off / 0 | 0 / off / 0 | — (vaults στα gateways) |
| Close Inactive Tickets (`CloseInactiveTickets`, ώρες) | 48 | 120 | P~ `cronjob_close_ticket` σε **ημέρες** (default 5, 0 = ποτέ) → **Ν: ώρες** |
| Client Status Update (`AutoClientStatusChange`) | 2 | 2 | **Ν** |
| Domain Sync (`DomainSyncEnabled`) | on | on | Ν (domains, EPP) |
| Update Usage Statistics | on | on | — |
| Email log retention | — | — | **P:** `cronjob_delete_email_logs` (90, 0 = για πάντα) |

Στο cut-over ο importer γράφει τις τιμές του WHMCS της εταιρείας στις αντίστοιχες ρυθμίσεις (`docs/importer.md`).

| Άλλο | myip | nixpal | MyCS |
|---|---|---|---|
| Αυτόματη εφαρμογή πίστωσης | **off** (`NoAutoApplyCredit`) | on | **P:** `credits_auto_use` |
| Add funds | on | on (με παραγγελία) | **P:** `credits_enabled`, min/max |
| Φόρος | 24% exclusive, ένας κανόνας | EU VAT 27 χώρες, VIES, reverse charge | P~ `tax_enabled`, `tax_type`· PR G: `eu_vat_enabled` (nixpal ναι, myip όχι), `eu_vat_home_country` (nixpal EE), `eu_vat_requester` (`eu-vat.md`) |

## Συμπέρασμα
Η αρίθμηση και το proforma της nixpal **υπάρχουν ήδη ως ρυθμίσεις στο upstream** (`invoice_number_format`
`{year}-{number}`, `invoice_proforma`). Ο ίδιος κώδικας δουλεύει και στις δύο εταιρείες. Νέες ρυθμίσεις, όλες γενικές
και όχι ανά εταιρεία:
- Mass Pay
- ομαδοποίηση γραμμών
- PDF συνημμένο στο email
- σημειώσεις στο checkout
- block existing domains
- on-demand renewals
- «days before» ανά κύκλο
- υπενθυμίσεις
- κανόνες ΦΠΑ/VIES
- όλες οι ρυθμίσεις domains

Ο importer **διαβάζει** αυτές τις ρυθμίσεις από το `tblconfiguration` και τις γράφει στο MyCS (μέρος του parity).

- **Prorata Billing** (`tblproducts.proratabilling/proratadate/proratachargenextmonth`, nixpal 6 προϊόντα, μέρα 1 και 5, «charge next
  month» 20) → `products.prorata_day`, `products.prorata_next_after_day` (PR D, απόφαση 3).
- **Separate Invoices** (`tblclients.separateinvoices`, myip 3, nixpal 0) → `clients.separate_invoices`, ρύθμιση του πελάτη στο admin (PR C1).
- **Disable Automatic CC Processing** (`tblclients.disableautocc`) → `clients.auto_charge` ανάποδα, και η προεπιλεγμένη μέθοδος
  πληρωμής του πελάτη → `clients.default_billing_agreement_id` (PR C2, απόφαση 6).
- **Group Similar Line Items** (nixpal on) → **όχι**: οι γραμμές ανανέωσης είναι περίοδοι υπηρεσιών (`billing-cursor.md` §Ομαδοποίηση).

PR H2b (2026-10-15): `cc_expiry_notice_day` (WHMCS `CCDaySendExpiryNotices`, 25 και στις δύο εταιρείες)· `paypal_legacy_receivers`
(οι λογαριασμοί PayPal της εποχής του WHMCS, για το δίχτυ του IPN· nixpal και myip ο καθένας τους δικούς του).
