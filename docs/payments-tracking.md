# Payments tracking (τράπεζες)

Port του δικού μας κώδικα του WHMCS (`bank-payments-logging.php`, addon `PaymentsTrackingMatch`, widgets «Payments
tracking» και «Bank Totals»). Απόφαση του υπευθύνου, 2026-10-24.

## Τι κάνει
- Το `bank:alerts` τρέχει κάθε 3 λεπτά και διαβάζει τα emails ειδοποίησης της Eurobank και της Πειραιώς από το mailbox
  του gateway «Bank transfer» (Setup → Payment Gateways). Το mailbox μένει **ανέγγιχτο**: χωρίς μετακίνηση, χωρίς
  σημάδι «διαβασμένο» (BODY.PEEK), χωρίς διαγραφή.
  - Κάθε φάκελος διαβάζεται από το UID μετά το τελευταίο που διαβάστηκε. Αν αλλάξει το UIDVALIDITY, η ανάγνωση
    ξεκινά από την αρχή χωρίς διπλοεγγραφές.
  - Ένα email καταγράφεται μία φορά με βάση το Message-ID του, σε όποιον φάκελο κι αν μεταφερθεί.
  - Ό,τι υπήρχε ήδη σε έναν φάκελο την πρώτη φορά που διαβάζεται μπαίνει ως **history**: μετρά για τα υπόλοιπα, όχι
    ως νέα πληρωμή.
- Κάθε κίνηση (`bank_movements`) έχει τράπεζα, πίστωση ή χρέωση, ποσό (decimal), το υπόλοιπο μετά την κίνηση,
  αιτιολογία και ώρα.
  - Οι **χρεώσεις** κρατιούνται μόνο για το υπόλοιπο. Το WHMCS κρατούσε μόνο τις πιστώσεις, οπότε έδειχνε το υπόλοιπο
    της τελευταίας κατάθεσης.
  - Πιστώσεις με αιτιολογία από τη λίστα «Credits to ignore», π.χ. το merchant id του vPOS (εκκαθαρίσεις καρτών),
    γίνονται `ignored`.
- **Αυθεντικότητα** (`MyCS\BankAlerts\Authenticity`): ο καθένας μπορεί να στείλει email με From «eurobank.gr».
  - Αυθεντικό είναι μόνο ό,τι πιστοποιεί ο δικός μας mail server:
    - είτε απευθείας `dmarc=pass` (ή dkim/spf) για το domain της τράπεζας στο πρώτο Authentication-Results του server
      μας·
    - είτε, για τα emails που φτάνουν με προώθηση μέσα στον server μας (sieve redirect), όλα τα εξής:
      - `arc=pass` από τον server μας·
      - η σφραγίδα `i=1` είναι του δικού μας domain·
      - το δικό μας `ARC-Authentication-Results i=1` λέει dmarc pass.
  - Ό,τι δεν πιστοποιείται φαίνεται με ⚠ και **δεν** μετρά στα υπόλοιπα. Δεν θα γίνεται ποτέ αυτόματο matching.

## Dashboard
- **Payments tracking** (`admin.widgets.payments_tracking`): οι πιστώσεις με pending, ειδωμένες, ignored και history.
  - Με το δικαίωμα `admin.bank_payments.update` γίνεται ✓ «Seen to», και ↩ για επιστροφή σε pending.
  - Το 🔍 δείχνει τα τιμολόγια που αναφέρει η αιτιολογία και τα απλήρωτα με αυτό ακριβώς το υπόλοιπο.
  - Θέση: δεξιά του Income. Τα renewals μπαίνουν στην ίδια γραμμή από κάτω.
- **Bank totals** (`admin.widgets.bank_totals`, μόνο για admins): μια λεπτή γραμμή κάτω από το Registrar credit.
  - Δείχνει τα υπόλοιπα της Eurobank και της Πειραιώς, το διαθέσιμο του Stripe και του PayPal, και το σύνολο σε EUR.
  - Το `gateways:balances` τρέχει κάθε ώρα. Αν ένα gateway δεν διαβάζεται, μένει το τελευταίο ποσό, με το σφάλμα.
  - Το PayPal θέλει το δικαίωμα «balance» στην εφαρμογή του.

## Επόμενο PR: αυτόματο matching
Το matching του WHMCS ήταν: αριθμός τιμολογίου στην αιτιολογία **και** ακριβές ποσό απλήρωτου, με ένα μόνο υποψήφιο, οπότε
η πληρωμή μπαίνει στο Bank transfer. Θα γίνει πάνω στις κινήσεις `pending`, που είναι αυθεντικές και όχι history.
Επειδή αγγίζει χρήματα, θέλει lock και adversarial review.

## Cut-over (myip)
- Στο gateway «Bank transfer» συμπληρώνονται:
  - το mailbox, με τα ίδια στοιχεία που έχει σήμερα το `conf.ini` στον rigel·
  - φάκελοι `INBOX, Checked`·
  - authserv-id `mymail.myip.gr` και ARC sealer `myip.gr`·
  - στο «Credits to ignore», το merchant id του vPOS.
- Όσο τρέχει παράλληλα και το WHMCS, εκείνο μετακινεί τα emails στο `Checked`. Η MyCS τα βρίσκει εκεί και δεν τα
  διαβάζει δεύτερη φορά.
- Στο cut-over σταματά το cron του `bank-payments-logging.php` και τα emails μένουν στο INBOX.
