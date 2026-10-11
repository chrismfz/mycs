# Templates email και ειδοποιήσεων

> Από 2026-10-11. Αντικαθιστά το Blade των templates του Paymenter (`audit-security.md` H1–H2).

## Γιατί
Στο Paymenter τα templates που επεξεργάζεται το staff (θέμα και σώμα email, in-app ειδοποιήσεις, `mail_header`,
`mail_footer`, το «email template» του προϊόντος) γίνονταν **compile ως Blade**. Άρα όποιος μπορούσε να τα αλλάξει
μπορούσε και να τρέξει PHP στον server. Τώρα ένας μικρός renderer (`packages/mail-template`, χωρίς Laravel, με δικά του
tests) **διαβάζει μόνο δεδομένα**: δεν καλεί συναρτήσεις ή μεθόδους, δεν κάνει compile σε PHP.

## Σύνταξη (υποσύνολο του Twig)
| Τι | Παράδειγμα |
|---|---|
| Τιμή (με HTML escape) | `{{ invoice.number }}`, `{{ service.product.name }}`, `{{ user.first_name }}` |
| Φίλτρα | `{{ service.expires_at\|date("d/m/Y") }}`, `{{ cancellation.reason\|default("—") }}`, `upper`, `lower`, `raw` (χωρίς escape) |
| Συνθήκη | `{% if has_subscription %} … {% else %} … {% endif %}`, `{% if not cancellation.reason %}` |
| Σύγκριση | `{% if cancellation.type == "end_of_period" %}` (και `!=`) |
| Επανάληψη | `{% for item in items %} {{ loop.index }}. {{ item.description }} {% endfor %}` (`loop.first`, `loop.last`) |

- Μια ετικέτα `{% … %}` μόνη της στη γραμμή σβήνει όλη τη γραμμή, ώστε ένα `for` να φτιάχνει γραμμές πίνακα Markdown.
- Άγνωστη τιμή = κενό. Λάθος σύνταξη = σφάλμα με αριθμό γραμμής. Το admin το ελέγχει στην αποθήκευση· αν φτάσει
  σε αποστολή, η ειδοποίηση παραλείπεται και το σφάλμα καταγράφεται (δεν σταματά provisioning ή cron).
- Τα κρυφά πεδία των models (`password`, `tfa_secret`, `remember_token`) δεν διαβάζονται.
- Σώμα email: Markdown ή HTML, με escape στις τιμές. Θέμα και in-app: απλό κείμενο, χωρίς escape (το UI κάνει escape).

## Τιμές που υπάρχουν πάντα
- `app.name`, `app.url`, `app.logo_url`, `app.year`
- `user` (ο παραλήπτης)
- `urls.services`, `urls.new_ticket`, `urls.password_reset`, `urls.security`
- όταν υπάρχουν: `urls.invoice`, `urls.service`, `urls.ticket`

## Ανά ειδοποίηση
| Template | Τιμές |
|---|---|
| `new_invoice_created`, `invoice_paid`, `invoice_payment_failed` | `invoice`, `items`, `total`, `has_subscription` |
| `new_order_created` | `order`, `items` (υπηρεσίες), `total` |
| `new_server_created`, `server_suspended`, `server_terminated` | `service`· στο πρώτο και `product_info` (το snippet του προϊόντος, ήδη σε HTML) |
| `new_ticket_message` | `ticketMessage`, `message_html` (το μήνυμα σε ασφαλές HTML) |
| `email_verification`, `password_reset` | `url` |
| `service_cancellation_received` | `cancellation`, `service` |
| `new_login_detected` | `ip`, `device`, `time` |
| `mail_header` / `mail_footer` | `app.*`, `mail_css` (στο header, με `\|raw`) |

## Μετάβαση
Η migration `2026_10_11_000000_templates_without_blade` αντικαθιστά κάθε πεδίο που είναι ακόμα σε Blade με τη νέα
προεπιλογή του και κρατά ό,τι είναι ήδη στη νέα σύνταξη. Ό,τι είχε αλλαχτεί σε Blade (ή snippet προϊόντος σε Blade)
γράφεται στο log για να ξαναγραφτεί. Στο WHMCS τα templates είναι Smarty: ο importer **δεν** τα μεταφέρει αυτόματα.
