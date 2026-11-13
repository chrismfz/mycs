# Η σειρά της ημέρας: cut-over της nixpal

Η σειρά των βημάτων τη μέρα που η nixpal περνά από το WHMCS στο MyCS. Το «γιατί» και οι λεπτομέρειες κάθε βήματος είναι
στο `cutover-runbook.md`: εδώ είναι μόνο η σειρά, ποιος κάνει τι και τι πρέπει να ισχύει πριν πάμε στο επόμενο. Τ = η ώρα
που κλείνει το WHMCS. Διάρκεια χωρίς απρόοπτα: περίπου 2 ώρες (το import της nixpal στις πρόβες: λίγα λεπτά· ο χρόνος
πάει στους ελέγχους).

Ρόλοι: **Ι** = ιδιοκτήτης (ό,τι γράφει στο WHMCS, στο PayPal, στο Stripe, στο DNS), **Ε** = όποιος τρέχει το MyCS.

## Πριν από τη μέρα
| # | Ποιος | Τι | Έτοιμο όταν |
|---|---|---|---|
| Π1 | Ι | MyCS στον orion ως `mycs.nixpal.com` (ROADMAP 8): deploy, SMTP ίδιο με το WHMCS, JetBackup | το site απαντά, mail δοκιμής φτάνει |
| Π2 | Ε | Δοκιμή επαναφοράς (backup → restore σε αντίγραφο) | η επαναφορά ανοίγει και το `mycs:money-check` βγάζει τα ίδια |
| Π3 | Ι | PayPal: «Transaction Search» στο REST app του MyCS (runbook §1, Gateways, βήμα 1) | τικ, **μέρες πριν**: το token το παίρνει με καθυστέρηση |
| Π4 | Ι | Mail στους πελάτες με ημερομηνία, ώρα και διάρκεια | σταλμένο |
| Π5 | Ε | Πρόβα σε φρέσκο αντίγραφο: `--dry-run` και import, οι λίστες του runbook §1 (Deleted domains, «left to be renewed», «got no period», `service_unbilled`, διπλές ανανεώσεις domain) | ό,τι βγαίνει έχει διορθωθεί στο WHMCS ή έχει απόφαση |
| Π6 | Ε | Πρόβα με αποθηκευμένες μεθόδους (API του WHMCS) | «μέθοδοι N/N» στο import |

## Τη μέρα
| # | Ποιος | Τι | Έλεγχος πριν το επόμενο |
|---|---|---|---|
| 1 | Ι | **Τ:** στο WHMCS κλείνουν το cron (και των registrars), το POP/piping των tickets, τα endpoints του Stripe του WHMCS, τα webhooks/IPN του PayPal του WHMCS (runbook §2) | κανένα cron του WHMCS μετά την Τ |
| 2 | Ι | Τα SQL «ακριβώς πριν από το dump» του runbook §1 (Collections, upgrades, Mass Pay, παλιές παραγγελίες, Add Funds, ακυρωμένες ανανεώσεις, ξεχασμένα τιμολόγια, domains Pending, qty > 1) | όλα «καμία γραμμή» |
| 3 | Ι | Το τελικό dump | `lastDailyCronInvocationTime` όχι μεταγενέστερο του `lastDailyCronEndTime` (αλλιώς ξανά dump) |
| 4 | Ε | `--dry-run` και μετά το import: `--with-logins`, το API του WHMCS (credentials στο περιβάλλον), `--ticket-attachments=/home/clnixpal/files/attachments`, `WHMCS_CC_ENCRYPTION_HASH` στο `.env`. Αν το instance έτρεξε cron με δοκιμαστικά: `--replace-live-data` **μόνο αυτή τη φορά** | το import τελείωσε· το log κρατιέται |
| 5 | Ε | **Πριν ανοίξει ο scheduler:** `grep` στο log για `in Collections`, `unpaid WHMCS upgrade`, `Payment Pending`, `got no period`, `unpaid domain renewal`· `mycs:money-check --rule=service_unbilled` (παλιά απλήρωτα με πίστωση: S11-MONEY-LEDGER-1)· `cpanel:sync --domains` | τίποτα απρόσμενο· ό,τι βγήκε ακυρώθηκε ή έχει απόφαση |
| 6 | Ε | Ρυθμίσεις: **Enable Suspension off, Enable Termination off** (Settings → Cron), Daily Summary Email «Every day», `cronjob_overdue_reminders` = `1,3,7`, `cronjob_delete_email_logs` = 365, `mail_must_verify` off | φαίνονται στο admin |
| 7 | Ι | Host: το `clients.nixpal.com` στο MyCS (orion), το WHMCS στο `old-clients`· `APP_URL` = `https://clients.nixpal.com` | https χωρίς προειδοποίηση· ένα `cart.php?a=add&pid=` κάνει 301 |
| 8 | Ε | `php artisan app:enable-imported-gateways` (**μετά** το 7: τα webhooks παίρνουν το `APP_URL`) | Stripe «Webhook ✓»· PayPal webhook με `webhook_id`· exit 0 |
| 9 | Ι | Στο PayPal: σβήνεται το webhook του WHMCS. Στο gateway PayPal του MyCS: «Reconcile from» = Τ | |
| 10 | Ι+Ε | Registrars: έλεγχος των «left to be renewed» με τον registrar (S10-DOMAINS-2), μετά ενεργοποίηση των συνδέσεων· στο CNIC ο διακόπτης «μεταφορά αντί για ανανέωση» | «Test connection» ✓ |
| 11 | Ε | Tickets: polling του τμήματος ανοιχτό, «Απαιτείται DMARC» ανοιχτό· ένα mail δοκιμής | το ticket ανοίγει και το staff παίρνει το mail |
| 12 | Ε | **Σημειώνεται η ώρα που ανοίγει το MyCS** (για το `mycs:rollback-report --since`) | γραμμένη |
| 13 | Ε | Ανοίγει ο scheduler. Έλεγχοι: login πελάτη με τον παλιό κωδικό, ένα τιμολόγιο και το PDF του, ο έλεγχος άδειας του `zlic.php` με ένα πραγματικό κλειδί (ίδιο JSON με το WHMCS), μία πληρωμή με κάρτα | όλα ✓ |
| 14 | Ε | Το staff ξεκινά τη σελίδα «Πρώτες μέρες» (`first-days.md`) | |

## Μετά
- **Προθεσμία επιστροφής: 48 ώρες** από το πρώτο ημερήσιο cron του MyCS (runbook §3). Μετά, μόνο διορθώσεις προς τα εμπρός.
- Το επόμενο πρωί: ο πρώτος έλεγχος PayPal (06:10) και ο έλεγχος χρημάτων (06:30), `first-days.md`.
- Καθάρισμα (runbook §4): νέο API κλειδί του WHMCS, αφαίρεση του `DecryptPassword` και της IP από το API, τα αρχεία με
  μυστικά και οι scratch βάσεις στον devbox.

## Πώς έγινε (2026-11-12/13) και τι κρατάμε για το myip
Το WHMCS πάγωσε στις 00:18 και το MyCS άνοιξε στις 00:59:47 στο `clients.nixpal.com`. Πριν, έγιναν 4 πρόβες στον ίδιο
server, με το WHMCS ζωντανό: η τελευταία με API, logins και CC hash έφερε όλα όσα μπορούσαν να έρθουν. Η εγκατάσταση
στον cPanel είναι στο `install.md`, «Σε server cPanel/CloudLinux». Τι δεν ήταν γραμμένο:
- **Η σειρά των domains:** το MyCS στήνεται και κάνει import στο `mycs.<company>` **ενώ το WHMCS κρατά ακόμα το δικό
  του domain**, γιατί το API του και η άδειά του θέλουν το domain τους. Μετά το import: WHMCS → `clients-old` και suspend,
  και τότε το MyCS → `clients`, με `whmapi1 modifyacct user=<user> DNS=<domain>`. Το Save του WHM δεν δούλευε, ενώ η
  κονσόλα δούλεψε. Το suspend κόβει το API, γι' αυτό γίνεται μόνο μετά το import.
- **Το API του WHMCS δέχεται μόνο τις IP της λίστας του:** οι κλήσεις από τον ίδιο server φτάνουν με τη δημόσια IP του.
  Η IP του server του MyCS μπαίνει στο «API IP Access Restriction» πριν από την πρόβα με API.
- **DNS:** στο zone του γονικού domain (`nixpal.com`, στον λογαριασμό `nixpal`) υπήρχαν εγγραφές NS για το `clients`. Το
  `modifyacct` αρνήθηκε («A DNS entry … already exists») μέχρι να σβηστούν. Στο ενδιάμεσο το `clients.nixpal.com` δεν
  λυνόταν καθόλου, ώσπου ο cPanel έφτιαξε το νέο zone. Για το myip: πριν το βήμα 7, `grep` για το subdomain στα zones του γονικού.
- **AutoSSL** αμέσως μετά το `modifyacct` (`/usr/local/cpanel/bin/autossl_check --user=<user>`): μέχρι να τρέξει, το
  domain σερβίρει self-signed.
- **Μετά από κάθε import ξαναμπαίνουν:** `app_url`, `timezone` (Europe/Athens), `staff_2fa_required` (off στις πρόβες),
  logo και favicon (τα αρχεία μένουν, οι ρυθμίσεις σβήνονται), και το «Captcha on sign-in». Το όνομα της εταιρείας και οι
  default nameservers έρχονται πλέον από το WHMCS (#829).
- **Gateways:** το `app:enable-imported-gateways` θέλει `--yes`, γιατί με `--no-interaction` δεν κάνει τίποτα. Το
  «Webhook ID» του PayPal είναι υποχρεωτικό στη φόρμα: ένα προσωρινό `pending`, που η εντολή το αντικαθιστά με το
  πραγματικό id. Το παλιό endpoint του Stripe αρκεί να γίνει disabled.
- **Login πελατών:** με το «Captcha on sign-in» = `after_failure` και το ALTCHA, η φόρμα δεν έστελνε τίποτα (#828). Μέχρι
  να γίνει merge: **Never**.
- **Αναστολές (ιδιοκτήτης, 2026-11-13):** ξαναμπήκαν όπως στο WHMCS (Suspension on, υπενθυμίσεις 1,2) αφού η λίστα «θα
  ανασταλούν» βγήκε άδεια. Το Termination μένει off.
- **zlic:** οι agents ξεκίνησαν να παίρνουν απαντήσεις από το MyCS μόλις έλυσε το DNS (535 απαντήσεις 200 την πρώτη ώρα). Τα
  «failed» του health είναι άδειες ήδη Expired, ίδιες με του WHMCS.
