# Blesta multi-company: μία εγκατάσταση για nixpal και myip.gr;

> 2026-10-09. Από την τεκμηρίωση του Blesta και τη βάση του staging (`csnixpal`, Blesta 6.0.0).
>
> **Απόφαση (2026-10-09): όχι multi-company.** Ξεχωριστή εγκατάσταση ανά εταιρεία, μία-μία
> (πρώτα nixpal, μετά myip.gr). Το σημείωμα μένει για αναφορά, αν ξανανοίξει το θέμα.

## Πώς δουλεύει
- Μία εγκατάσταση, πολλές **εταιρείες** (companies). Η κάθε εταιρεία έχει δικούς της πελάτες, πακέτα,
  order forms, extensions (modules, gateways, plugins εγκαθίστανται ανά εταιρεία), branding/θέμα,
  email templates, αρίθμηση τιμολογίων, φόρους, νομίσματα.
- Η εταιρεία επιλέγεται από το **hostname**: κάθε εταιρεία έχει το δικό της (`companies.hostname`), και
  όλα τα hostnames πρέπει να δείχνουν στο ίδιο document root, στον ίδιο server. Κάθε vhost μπορεί να
  έχει δικό του SSL. Η τεκμηρίωση προτείνει subdomains (π.χ. `clients.nixpal.com`, `clients.myip.gr`).
- **Γλώσσα και ζώνη ώρας είναι ανά εταιρεία**: στη βάση του staging τα `language`, `timezone`,
  `client_set_lang`, `date_format`, `inv_format`, `inv_start` είναι στο `company_settings`. Άρα η μία
  εταιρεία μπορεί να είναι ελληνικά και η άλλη αγγλικά, και κάθε πελάτης να διαλέγει γλώσσα αν το
  επιτρέπει η εταιρεία (`client_set_lang`).
- **License:** κάθε επιπλέον εταιρεία θέλει addon license πάνω στο κύριο (όχι δεύτερη εγκατάσταση).
  Τιμή: να ζητηθεί από το Blesta (οι παλιές τιμές στα forums δεν ισχύουν).

## Τι σημαίνει για τα δικά μας
- Τα modules/gateways/plugins μας εγκαθίστανται ανά εταιρεία με δικές τους ρυθμίσεις (module rows,
  package meta, gateway meta είναι ήδη ανά εταιρεία).
- `nixpal_licensing`: μόνο στη nixpal. Οι ρυθμίσεις του plugin έχουν `company_id`· ο πίνακας αδειών όχι,
  που αρκεί όσο το licensing ζει σε μία εταιρεία. Το `zlic.php` θα σερβίρεται από το hostname της nixpal.
- `paypal_vault`, `mailcow`, `cardlink_eurobank`: ανά εταιρεία, με δικά της credentials.
- Ekdosi: δύο tenants (μία εταιρεία Blesta → ένα tenant), ήδη έτσι στο Ekdosi.
- Import: ο Import Manager εισάγει στην τρέχουσα εταιρεία· nixpal και myip γίνονται χωριστά imports,
  ο καθένας στη δική του εταιρεία. Τα WHMCS ids (`id_value`) είναι ανά εταιρεία.
- Αρίθμηση τιμολογίων ανά εταιρεία (HANDOFF: η nixpal συνεχίζει από > 32453).

## Υπέρ / κατά
- Υπέρ: μία αναβάθμιση, ένα cron, ένας server, ένα σημείο για τα δικά μας extensions· staff με πρόσβαση
  και στις δύο.
- Κατά: κοινός server και βάση (ένα πρόβλημα πέφτει και στις δύο), κοινό backup/restore, κοινή
  έκδοση PHP/Blesta. Πελάτης που είναι και στις δύο εταιρείες: δύο ξεχωριστοί λογαριασμοί.

## Αν ξανανοίξει
- Τιμή addon license.
- Πού θα ζει η κοινή εγκατάσταση και τα hostnames.
- Δοκιμή στο staging: δεύτερη εταιρεία με `el_gr`, έλεγχος θέματος και emails ανά εταιρεία.

Τα δικά μας extensions δεν προϋποθέτουν multi-company: δουλεύουν το ίδιο σε ξεχωριστές εγκαταστάσεις.
