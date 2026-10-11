# Τι χρησιμοποιούμε στο WHMCS (απογραφή 2026-10-11) και πού βρίσκεται στο MyCS

> Μόνο SELECT, `ls` και `grep` σε rigel (myip, `myipgr_clients`) και orion (nixpal, `clnixpal_nixcldb`). Μόνο αθροίσματα.
> «12μ» = τελευταίοι 12 μήνες.

## myip.gr (900 πελάτες, 552 ενεργοί)
| Περιοχή | Χρήση |
|---|---|
| Τιμολόγια | Paid 17.449 (1.794 στους 12μ), Cancelled 3.154, Unpaid 103, Refunded 35, Collections 15, Draft 27 |
| Γραμμές (12μ) | Hosting 1.243, Domain renew 1.012, Register 150, Transfer 90, χειροκίνητες 93, **Mass Pay 47**, AddFunds 23, Addon 29, Upgrade 22, Redemption fee 19 |
| Mass Pay / merge | **Ναι:** 94 συνδυαστικά τιμολόγια· «Merged Invoice IDs» 62× στους 12μ |
| Πιστώσεις | **Πολλές:** 4.708 κινήσεις, 2.622 τιμολόγια πληρωμένα με πίστωση· **χειροκίνητη εφαρμογή** (`NoAutoApplyCredit` on)· 22 πελάτες με υπόλοιπο |
| Υπερπληρωμές / μερικές | ~139 υπερπληρωμένα (custom hook `overpayment_detection.php`)· 181 με πολλές πληρωμές |
| Φόρος | ΦΠΑ 24% exclusive, ανά γραμμή· 11 πελάτες εξαιρούνται |
| Αρίθμηση | Αριθμός = invoice ID (χωρίς sequential, χωρίς proforma). Συνέχεια μετά το **32453** |
| Κύκλοι (ενεργές) | Ετήσιο 379, Διετές 146, Μηνιαίο 53, Τριετές 41, Free 59, Τρίμηνο 13, Εξάμηνο 11 |
| Upgrades | Πακέτο 396 (27 στους 12μ)· **αλλαγή κύκλου μέσω upgrade: 0**. Οι αλλαγές κύκλου γίνονται μάλλον με χειροκίνητη επεξεργασία της υπηρεσίας («Modified Product/Service» 463× στους 12μ) |
| Ακυρώσεις | End of period 476, Immediate 99· αυτόματη επεξεργασία + custom hook |
| Config options | **Ναι:** 157 ομάδες, 230 options, σε 282 υπηρεσίες |
| Addons | Λίγα (12 ενεργά) |
| Suspend overrides | «suspend until» σε **170** υπηρεσίες, «never suspend» σε 36 |
| Modules (προϊόντα) | cPanel 29, TheSSLStore 57, Centova 10, Proxmox 7, mailcow 5, Hetzner 3, DirectAdmin 3, ngm 1, grepp owner change/transfer 3 |
| Domains | 1.862 συνολικά· grepp 1.383 ενεργά, Openprovider 243, CNIC 209· περίοδος .gr 2 έτη· 35 TLDs· redemption fee σε 27· ειδοποιήσεις λήξης 60/30/15/10/5 ημέρες (3.280 στους 12μ)· do-not-renew 68· ID protection 6, DNS 17 |
| Πληρωμές (12μ) | Έμβασμα 42,7% · **Eurobank vPOS 42,3%** · PayPal ppcpv 9,5% · Stripe 3,7% · PayPal legacy 1,6% |
| Αποθηκευμένα | Stripe 50, PayPal ppcpv 48, Stripe SEPA 4 |
| Υπενθυμίσεις τιμολογίων | 1 ημέρα πριν τη λήξη· ληξιπρόθεσμα στις 1/3/5 ημέρες |
| Πελάτες | 6 ομάδες (38 μέλη)· 432 επαφές· 101 multi-user· **custom fields:** ΑΦΜ/VIES, ΔΟΥ, Δραστηριότητα, «Θα ήθελα τιμολόγιο», Σημειώσεις, σήμανση «δύσκολος πελάτης» (2.925 τιμές) |
| Γλώσσες | Ελληνικά 248, αγγλικά 37, default 614 |
| Tickets | 3 τμήματα, όλα με email piping· 12μ: 1.703 tickets, **~μισά από guests**· 453 με συνημμένα, 72 merge, 227 σημειώσεις, 835 φίλτρα spam· 9 predefined replies· αξιολογήσεις απαντήσεων 363· auto-close 48 ώρες |
| KB / announcements | KB 97 άρθρα· 5 announcements· 4 downloads |
| Email | 15.275 στους 12μ· custom templates σε 2 γλώσσες (product 20, general 13, domain 5, admin 5) |
| Αυτοματισμοί | Suspend 15 ημέρες, terminate 120, auto-unsuspend· τιμολόγιο πριν τη λήξη: default 14 ημέρες, ανά κύκλο 10/15/20, domains 15 |
| Promotions / orders | 23 κωδικοί, λίγη χρήση· 467 παραγγελίες στους 12μ |
| Δουλειά staff (12μ, 9.271) | Αλλαγή ημερομηνιών domain 479 · αλλαγή υπηρεσίας 463 · Mark Paid 456 · αποδοχή παραγγελίας 449 · προσθήκη πληρωμής 359 · δημιουργία τιμολογίου 314 · χειροκίνητα renew/transfer domains ~800 · αλλαγή υποσυνόλου τιμολογίου 141 · EPP codes 94 · unsuspend 85 · change package 71 · merge τιμολογίων 62 · διαγραφή τιμολογίου 54 |
| Δικά μας modules / hooks | ekdosi_bridge, PaymentsTrackingMatch, gr_management, grwhoisapi, domain_search, unblockip, name_server_tracker κ.ά.· ~20 hooks (βλ. HANDOFF §4.4) |

## nixpal (233 πελάτες, 31 ενεργοί, ~336 άδειες)
| Περιοχή | Χρήση |
|---|---|
| Τιμολόγια | Paid 5.827 (849 στους 12μ), max ID 7.214 |
| Γραμμές (12μ) | Hosting 4.075, **PromoHosting 894**, Mass Pay 80· **GroupDiscount** σε κάθε κύκλο |
| Mass Pay | **Πολύ:** 157 συνδυαστικά τιμολόγια |
| Πιστώσεις | **Πολλές:** 4.250 κινήσεις· **αυτόματη** εφαρμογή |
| Φόρος | **EU VAT:** έδρα EE, 27 κανόνες ανά χώρα, **VIES** και **reverse charge**, 30 πελάτες εξαιρούνται |
| Αρίθμηση | **Sequential `{YEAR}-{NUMBER}` + proforma** |
| Κύκλοι | Μηνιαίο 321, Ετήσιο 9, Διετές 2, Τριετές 1 |
| Ακυρώσεις | Immediate 411, end of period 57 |
| Licensing | **Πυρήνας:** 588.507 checks στους 12μ· 324 Active / 11 Suspended / 1.003 Expired |
| Promotions / ομάδες | 4 κωδικοί με **295 χρήσεις** (3 recurring)· 3 ομάδες πελατών με έκπτωση 50/10/0% |
| Πληρωμές (12μ) | **Stripe 62,5%**, PayPal legacy 21,3%, PayPal ppcpv 16,2%· αυτόματη χρέωση στη λήξη· 5 PayPal subscriptions |
| Αποθηκευμένα | Stripe 52, ppcpv 15 |
| Υπενθυμίσεις | 6 ημέρες πριν· ληξιπρόθεσμα στις 1/2 ημέρες· τιμολόγιο 7 ημέρες πριν |
| Αυτοματισμοί | Suspend 8 ημέρες, terminate 60· suspend-until σε 14 υπηρεσίες |
| Πελάτες | Διεθνείς: 7 γλώσσες, ~8 χώρες |
| Tickets | 2 τμήματα με piping· **44** στους 12μ |
| Staff | Μόνο **292** ενέργειες στους 12μ: η nixpal τρέχει σχεδόν μόνη της |

## Parity backlog: πού βρίσκεται κάθε απαραίτητο στο MyCS
Πηγή: **P** = Paymenter (υπάρχει) · **E** = Ekdosi (port) · **L** = `lib/` αυτού του repo · **Ν** = νέο, γράφεται.
Η στήλη «Φάση» ακολουθεί το `ekdosi-vs-paymenter.md`.

| Απαραίτητο | Πηγή | Φάση | Σημείωση |
|---|---|---|---|
| Τιμολόγια ανανέωσης ανά κύκλο (μήνας–τριετία), «days before» **ανά κύκλο** | P + Ν | 1 | Το P έχει μία τιμή για όλους τους κύκλους |
| Υπενθυμίσεις πριν τη λήξη + 1η/2η/3η ληξιπρόθεσμου | E | 1 | |
| Auto suspend/unsuspend/terminate με ημέρες, overrides ανά υπηρεσία (suspend until, never suspend) | P (+ `suspend_hold_until`) + Ν (never suspend) | 1 | Status μόνο μετά την επιτυχία του job |
| **Αλλαγή κύκλου χρέωσης** (και χειροκίνητα από admin) | Ν | 1 | `decisions.md` §7· σήμερα γίνεται με το χέρι 463×/έτος |
| Mass Pay + **merge τιμολογίων** | E (allocator) + Ν | 1 | Πολύ και στις δύο εταιρείες· Paymenter #1137 |
| Πιστώσεις: add funds, αυτόματη **ή** χειροκίνητη εφαρμογή (ρύθμιση), add/remove από admin, ledger | P (βασικό) + E | 1 | Το P δεν έχει ledger· nixpal αυτόματα, myip χειροκίνητα |
| Μερικές πληρωμές, Mark Paid, χειροκίνητη πληρωμή, refunds, υπερπληρωμή → πίστωση | P + E | 1 | |
| **EU VAT ανά χώρα + VIES + reverse charge** + εξαιρέσεις (nixpal) | Ν (+ E: `ViesLookup`) | 1 | Το P έχει έναν συντελεστή ανά χώρα, χωρίς ΑΦΜ και reverse charge |
| Αρίθμηση `{YEAR}-{NUMBER}` + proforma (nixpal)· invoice ID (myip, σήμερα > 32.476) | P (ρυθμίσεις) | 1 / 5 | Ίδιος κώδικας, ρύθμιση ανά instance (`settings-map.md`) |
| Promotions recurring + **εκπτώσεις ομάδας πελατών** | P (coupons) + Ν (ομάδες) | 1 | Το P δεν έχει ομάδες πελατών |
| Stripe με αποθηκευμένες κάρτες, auto-charge· PayPal ppcpv vault | P (+ L `paypal-vault`) | 1 | Fix `round()` (`upstream-triage.md`) |
| PayPal legacy subscriptions (nixpal 77 υπηρεσίες, 5 subs) | Ν | 1 | Μετάβαση σε vault ή forwarder IPN |
| Licensing + `zlic.php` (588k checks/έτος) | L `nixpal-licensing` | 1 | Contract byte-for-byte· cache/rate-limit |
| Ακυρώσεις (immediate / end of period) με αυτόματη επεξεργασία· ακύρωση προϊόντος όταν ακυρώνεται το τιμολόγιο | P + Ν | 1 | Ο κανόνας του hook `CancelProductOnCancelInvoice` |
| Upgrade/downgrade πακέτου με pro-rata | P | 1 | + #1266 |
| Config options | P | 1 | |
| Export εσόδων/εξόδων ανά έτος (λογιστής nixpal) | Ν | 1 | |
| Εύκολη επεξεργασία από admin: ημερομηνίες, ποσά υπηρεσίας, υποσύνολα τιμολογίου | P (Filament) + Ν | 1–2 | Συχνές χειροκίνητες αλλαγές στο myip |
| Domains: πολλοί registrars, register/transfer/renew, sync, ειδοποιήσεις 60/30/15/10/5, redemption fee, EPP code, do-not-renew, additional fields/IDN | E + L (`domain-tools`, `myopenprovider`) | 2 | |
| Προϊόντα .gr (owner change/transfer) | Ν (+ `gr-epp`) | 4 | |
| Custom fields πελάτη (ΑΦΜ, ΔΟΥ, «Θα ήθελα τιμολόγιο», σημειώσεις, σήμανση «δύσκολος») + επαφές + multi-user | E (πελάτης/επαφές) + P (custom properties) | 2–3 | Το «Θα ήθελα τιμολόγιο» οδηγεί το bridge προς Ekdosi |
| Tickets: piping σε όλα τα τμήματα, **guest tickets**, συνημμένα, merge, σημειώσεις, φίλτρα spam, auto-close, αξιολογήσεις, predefined replies | E | 3 | Το P δεν δέχεται νέα tickets από email |
| Gateways: έμβασμα, **Eurobank vPOS** (42% του myip), Stripe SEPA | L `cardlink-eurobank` + E + Ν (SEPA) | 3 | |
| Modules: cPanel, DirectAdmin | P | 1 / 3 | |
| Modules: TheSSLStore, Proxmox, Centova, mailcow, ngm, Hetzner | Ν (mailcow/ngm: L) | 3 | TheSSLStore: 57 προϊόντα στο myip |
| Templates email el/en, επεξεργάσιμα | P + Ν (locale, sandbox) | 3 | |
| Bridge προς Ekdosi (myip) | E (`ekdosi_bridge`) + Ν | 3 | |
| Business rules από τα ~20 hooks του myip | Ν | 3 | HANDOFF §4.4 |
| KB (97 άρθρα), announcements, downloads | P (announcements) + Ν (KB) | 3 | Lightly used |

**Χρησιμοποιούνται λίγο (nice to have):** addons, quotes, bundles, πελάτες με separate invoices (3), domain ID
protection/DNS/email forwarding, mass mail, 2FA πελατών (10), πολλά νομίσματα (1 πελάτης σε USD).

**Δεν χρησιμοποιούνται (παράλειψη):** late fees, billable items, affiliates, escalation rules, network issues, surveys
(οι αξιολογήσεις απαντήσεων χρησιμοποιούνται), fraud modules, φόρος δεύτερου επιπέδου, premium domains, δωρεάν domain
με hosting.
