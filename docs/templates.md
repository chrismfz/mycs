# Templates email και ειδοποιήσεων

> Από 2026-10-11. Αντικαθιστά το Blade των templates του upstream (`history/audit-security.md` H1–H2).

## Γιατί
Στο upstream τα templates που επεξεργάζεται το staff (θέμα και σώμα email, in-app ειδοποιήσεις, `mail_header`,
`mail_footer`, το «email template» του προϊόντος) γίνονταν **compile ως Blade**. Άρα όποιος μπορούσε να τα αλλάξει
μπορούσε και να τρέξει PHP στον server. Τώρα ένας μικρός renderer (`packages/mail-template`, χωρίς Laravel, με δικά του
tests) **διαβάζει μόνο δεδομένα**: δεν καλεί συναρτήσεις ή μεθόδους, δεν κάνει compile σε PHP.

## Σύνταξη (υποσύνολο του Twig)
| Τι | Παράδειγμα |
|---|---|
| Τιμή (με HTML escape) | `{{ invoice.number }}`, `{{ service.product.name }}`, `{{ service.mailName }}` (η υπηρεσία: προϊόν και domain, label ή άδεια: label και IP, αλλιώς κλειδί με μάσκα), `{{ user.first_name }}` |
| Φίλτρα | `{{ service.expires_at\|date("d/m/Y") }}`, `{{ cancellation.reason\|default("—") }}`, `upper`, `lower`, `raw` (χωρίς escape) |
| Συνθήκη | `{% if has_subscription %} … {% else %} … {% endif %}`, `{% if not cancellation.reason %}` |
| Σύγκριση | `{% if cancellation.type == "end_of_period" %}` (και `!=`) |
| Επανάληψη | `{% for item in items %} {{ loop.index }}. {{ item.description }} {% endfor %}` (`loop.first`, `loop.last`) |

- Μια ετικέτα `{% … %}` μόνη της στη γραμμή σβήνει όλη τη γραμμή, ώστε ένα `for` να φτιάχνει γραμμές πίνακα Markdown.
- Άγνωστη τιμή = κενό. Λάθος σύνταξη = σφάλμα με αριθμό γραμμής. Το admin το ελέγχει στην αποθήκευση· αν φτάσει
  σε αποστολή, η ειδοποίηση παραλείπεται και το σφάλμα καταγράφεται (δεν σταματά provisioning ή cron).
- Τα κρυφά πεδία των models (`password`, `tfa_secret`, `remember_token`) δεν διαβάζονται.
- **Ένα template μόνο διαβάζει** (#158): τα αποθηκευμένα πεδία ενός record και, πέρα από αυτά, **μόνο** τις σχέσεις και
  τους accessors που επιτρέπει το `TemplateRenderer::READS` (π.χ. `service.product`, `invoice.items`,
  `invoice.formattedTotal`, `client.name`, `ticketMessage.user`). Οτιδήποτε άλλο διαβάζεται κενό: μέθοδοι, audits,
  settings (κλειδιά modules/registrars), σχέσεις προς άλλους πελάτες (κοινό login, οι λογαριασμοί ενός server), οι
  σημειώσεις του staff. Ένα record ή μια λίστα δεν τυπώνεται ολόκληρο (θα ήταν όλο το JSON του). Μια νέα ανάγκη
  προστίθεται εκεί, με test.
- Σώμα email: Markdown ή HTML, με escape στις τιμές. Θέμα και in-app: απλό κείμενο, χωρίς escape (το UI κάνει escape).

## Τιμές που υπάρχουν πάντα
- `app.name`, `app.url`, `app.logo_url`, `app.year`
- `user` (ο παραλήπτης)
- `urls.services`, `urls.new_ticket`, `urls.password_reset`, `urls.security`
- όταν υπάρχουν: `urls.invoice`, `urls.service`, `urls.ticket`

## Ανά ειδοποίηση
| Template | Τιμές |
|---|---|
| `new_invoice_created`, `invoice_paid`, `invoice_payment_failed` | `invoice`, `items`, `total`, `subtotal`, `tax` (κενά χωρίς ΦΠΑ, Δ1), `has_subscription` |
| `new_order_created` | `order`, `items` (υπηρεσίες), `total` |
| `new_server_created`, `server_suspended`, `server_terminated` | `service`· στο πρώτο και `product_info` (το snippet του προϊόντος, ήδη σε HTML), `service_password`, `service_server`/`server_hostname`/`server_ip`/`service_ips`, και ό,τι δίνει το module της υπηρεσίας (βλ. παρακάτω) |
| `new_ticket_message` | `ticketMessage`, `message_html` (το μήνυμα σε ασφαλές HTML) |
| `email_verification`, `password_reset` | `url` |
| `service_cancellation_received` | `cancellation`, `service` |
| `credit_applied` | `invoice`, `credit.applied`, `credit.remaining` |
| `invoice_refunded` | `invoice`, `refund.amount`, `refund.to_credit`, `refund.by_gateway` |
| `new_login_detected` | `ip`, `device`, `time` |
| `mail_header` / `mail_footer` | `app.*`, `mail_css` (στο header, με `\|raw`) |

## Τιμές ενός module στο welcome email
Ένα server module μπορεί να δώσει δικές του τιμές στο `new_server_created` (και στο snippet του προϊόντος) με μια μέθοδο
`welcomeData(Service $service, $settings, $properties): array` (`ExtensionHelper::welcomeData`). Αν αποτύχει, το email
φεύγει χωρίς αυτές. Ένα κλειδί με όνομα μυστικού (`…password…`) δεν γράφεται στο email log.

| Module | Τιμές |
|---|---|
| CentovaCast | `centova_host`, `centova_port`, `centova_source_password` (διαβάζονται από το Centova τη στιγμή της αποστολής· αν δεν απαντά, μένει το port που κρατά η υπηρεσία και ο κωδικός πηγής λείπει) |
| CPanel | `panel_url` (`https://<hostname>:2083`), `webmail_url` (`:2096`), και `whm_url` (`:2087`) μόνο σε λογαριασμό reseller. Από το hostname του server, χωρίς κλήση. |
| Ngm | `panel_url` (η σελίδα σύνδεσης του panel, όπως τη δείχνει η σελίδα της υπηρεσίας) |

Γράφονται μέσα σε `{% if centova_source_password %} … {% endif %}`, ώστε να μη μένει μισή γραμμή. Ο importer αντιστοιχίζει
μόνο αυτές τις δύο γραμμές του Smarty του WHMCS (`importer.md` → Welcome email προϊόντος).

## Τι λέει το welcome email για hosting και μηχανήματα (ιδιοκτήτης 2026-11-11)
Ό,τι έλεγαν τα «Hosting / Reseller / Dedicated-VPS Welcome» του WHMCS και δεν έλεγε το δικό μας. Όλα υπό συνθήκη, ώστε
ένα προϊόν χωρίς αυτά να μη δείχνει άδειο μπλοκ:

| Τιμή | Πότε | Από πού |
|---|---|---|
| `panel_url`, `webmail_url`, `whm_url` | cPanel, ngm | `welcomeData` του module (πίνακας παραπάνω) |
| `server_hostname`, `server_ip` | cPanel, DirectAdmin | ο server της υπηρεσίας· με το hostname μπαίνουν και οι γραμμές FTP (μόνο όταν το email έχει username) και `mail.<domain>`. Το hostname μόνο ως **όνομα**: server ορισμένος με διεύθυνση IP (συχνά του LAN) δεν δίνει hostname ούτε links (`NotificationHelper::hostName`)· η IP μόνο αν είναι δημόσια. |
| `nameservers` | cPanel, DirectAdmin, ngm | οι ρυθμίσεις «Default Nameserver 1–5» (Ordering): όλοι οι servers του myip είχαν τους ίδιους στο WHMCS, άρα **δεν** υπάρχει πεδίο ανά server. Κενές = το μπλοκ λείπει. |
| `ssh_host` | Proxmox, Offline Dedicated, Offline VM | η πρώτη IP της υπηρεσίας (`ssh root@…`, port 22). Για μηχάνημα Windows το staff αλλάζει το template ή το snippet του προϊόντος. |
| `service_ips` | όλα | dedicated και assigned IPs |

Τα ίδια `server_hostname`, `server_ip`, `nameservers` φαίνονται και στη σελίδα της υπηρεσίας του πελάτη
(`NotificationHelper::welcomeServer`, το «Server Information» του WHMCS). Μόνο σε υπηρεσία active ή suspended: μια
απλήρωτη παραγγελία ή μια υπηρεσία που έληξε δεν έχει λογαριασμό εκεί. Η migration `2027_02_22_000000` προσθέτει το
μπλοκ μόνο σε template που είναι ακόμα όπως το έγραψε ο seeder.

**Δεν μεταφέρθηκαν, με απόφαση:** το προσωρινό URL `http://IP/~user/`, οι custom nameservers ενός VPS (1 υπηρεσία) και ο
οδηγός εκκίνησης WHM των resellers: ο τελευταίος γράφεται με το χέρι ως snippet στα προϊόντα reseller (Admin → προϊόν →
«Email template»), μία φορά.

## Μετάβαση
Η migration `2026_10_11_000000_templates_without_blade` αντικαθιστά κάθε πεδίο που είναι ακόμα σε Blade με τη νέα
προεπιλογή του και κρατά ό,τι είναι ήδη στη νέα σύνταξη. Ό,τι είχε αλλαχτεί σε Blade (ή snippet προϊόντος σε Blade)
γράφεται στο log για να ξαναγραφτεί. Στο WHMCS τα templates είναι Smarty: ο importer **δεν** τα μεταφέρει αυτόματα
(εξαίρεση: το welcome email ενός προϊόντος ως snippet, `importer.md`).
