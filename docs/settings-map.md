# Ρυθμίσεις WHMCS → MyCS (ανά instance, 2026-10-11)

> Αρχή: **ένας κώδικας, διαφορετικές ρυθμίσεις ανά instance.** Ό,τι στο WHMCS είναι επιλογή (αρίθμηση, proforma,
> αυτόματη πίστωση, ομαδοποίηση γραμμών, …) γίνεται ρύθμιση και στο MyCS, όχι κώδικας για τη μία εταιρεία.
> Πηγές: screenshots General Settings του WHMCS (Ordering, Domains, Invoices) και `whmcs-usage.md`. Τα κλειδιά του
> Paymenter είναι από το `app/Classes/Settings.php` στο `5b69d45`.
> **P** = υπάρχει στο Paymenter · **P~** = υπάρχει μερικώς · **Ν** = νέα ρύθμιση.

## Τιμολόγια
| WHMCS | myip | nixpal | MyCS |
|---|---|---|---|
| Αριθμός τιμολογίου | = invoice ID· «Invoice Starting #» ελάχιστο **32.477** σήμερα | Sequential paid numbering **`{YEAR}-{NUMBER}`**, επόμενο **6819**· τα IDs των proforma φτάνουν το 7.214 | **P:** `invoice_number`, `invoice_number_format` (`{number}{year}{month}{day}`), `invoice_number_padding`, `invoice_proforma` (αριθμός μόνο στην πληρωμή, ξεχωριστός από το id). Ο μετρητής κρατάει τον **τελευταίο** αριθμό (myip: 32.476, nixpal: 6818). Τα IDs του WHMCS κρατιούνται και στα δύο instances |
| Enable Proforma Invoicing | off | **on** | **P:** `invoice_proforma` |
| Continuous Invoice Generation | off | off | **Ν** (default off = συμπεριφορά Paymenter: δεν βγάζει νέο τιμολόγιο αν υπάρχει pending) |
| Enable PDF Invoices (συνημμένο στο email) | off | on | **Ν** |
| PDF font/μέγεθος | Freesans, A4 | Helvetica, A4 | P~ (dompdf)· Freesans/DejaVu για τα ελληνικά |
| Store Client Data Snapshot | off | off | **P:** `invoice_snapshot` |
| **Enable Mass Payment** | on | on | **Ν** (#1137, `whmcs-usage.md`) |
| Clients Choose Gateway | on | on | P (επιλογή gateway στην πληρωμή) |
| Group Similar Line Items | off | **on** | **Ν** |
| Cancellation Request → ακύρωση απλήρωτων τιμολογίων | on | on | **M** (#61): σταθερή συμπεριφορά (on και στις δύο)· τιμολόγιο με χρήματα gateway ή με γραμμές άλλων υπηρεσιών μένει ανοιχτό για το staff (MT-2) |
| Auto-cancel subscriptions σε upgrade/ακύρωση | on | on | P~ (PayPal/Stripe subscriptions) → έλεγχος |
| Late fees | 0 (off) | 0 (off) | — δεν το χρειαζόμαστε |
| Accepted card types, Issue number | — | Visa/MasterCard | — (τα διαχειρίζεται το Stripe) |

## Παραγγελίες
| WHMCS (myip) | Τιμή | MyCS |
|---|---|---|
| Order Days Grace | 8 | **P:** `cronjob_order_cancel` |
| Default Order Form | Standard Cart | P (theme) |
| On-Demand Renewals (πρόωρη ανανέωση από πελάτη, ημέρες ανά κύκλο 31/92/183/366/731/1096) | off | **Ν** (#1167). Μαζί με το «Ανανέωση τώρα» του admin |
| Enable TOS Acceptance + URL | on, `https://myip.gr/tos/` | **P:** `tos` |
| Auto Redirect on Checkout | → payment gateway | P~ |
| Allow Notes on Checkout | on | **Ν** |
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
| **Auto Renew on Payment** | on | Ν: πολιτική on-paid (default)· on-issue ανά πελάτη· prepaid (`platform-evaluation.md`) |
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
| Support Module | Built-in | E (tickets του Ekdosi, όχι του Paymenter) |
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

## Αυτοματισμοί (από `whmcs-usage.md`)
| WHMCS | myip | nixpal | MyCS |
|---|---|---|---|
| Τιμολόγιο πριν τη λήξη | 14 default· ανά κύκλο: μήνας 10, τρίμηνο 15, εξάμηνο/έτος 20· domains 15 | 7 | P~ `cronjob_invoice` (μία τιμή) → **Ν: ανά κύκλο** |
| Auto suspend (Suspend Days, από την ημερομηνία λήξης) | 15 ημέρες | 8 | **P:** `cronjob_order_suspend` (από `expires_at`, όπως WHMCS) |
| — μόνο με ληξιπρόθεσμο τιμολόγιο | (όχι στο WHMCS) | | **P:** `cronjob_suspend_requires_invoice`, default off· on: τιμολόγιο ληξιπρόθεσμο **και** εκδομένο πριν από Suspend Days, ειδοποίηση staff για «ληγμένη χωρίς τιμολόγιο» (BIL-5) |
| Auto terminate (Termination Days, από την ημερομηνία λήξης) | 120 ημέρες | 60 | **P:** `cronjob_order_terminate` |
| — ελάχιστες ημέρες σε αναστολή | (όχι στο WHMCS) | | **P:** `cronjob_terminate_min_suspended_days`, default 1· 0 = ακριβώς WHMCS (BIL-4) |
| Auto unsuspend μετά την πληρωμή | on | on | P |
| Υπενθύμιση πριν τη λήξη | 1 ημέρα | 6 | **Ν** (υπενθυμίσεις του Ekdosi) |
| Ληξιπρόθεσμα | 1/3/5 | 1/2 | **Ν** (υπενθυμίσεις του Ekdosi) |
| Auto-close tickets | 48 ώρες | — | **P:** `cronjob_close_ticket` (σε ημέρες → Ν: ώρες) |
| Αυτόματη εφαρμογή πίστωσης | **off** (`NoAutoApplyCredit`) | on | **P:** `credits_auto_use` |
| Add funds | on | on (με παραγγελία) | **P:** `credits_enabled`, min/max |
| Φόρος | 24% exclusive, ένας κανόνας | EU VAT 27 χώρες, VIES, reverse charge | P~ `tax_enabled`, `tax_type` → **Ν** (`whmcs-usage.md`) |

## Συμπέρασμα
Η αρίθμηση και το proforma της nixpal **υπάρχουν ήδη ως ρυθμίσεις στο Paymenter** (`invoice_number_format`
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
