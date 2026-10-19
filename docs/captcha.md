# Captcha (2026-10-19)

Settings → Security → Captcha: Google reCAPTCHA v2/v3, Cloudflare Turnstile, hCaptcha (του upstream) ή **ALTCHA
(self-hosted)**, το δικό μας. Χρησιμοποιείται στο login, στην εγγραφή και στην επαναφορά κωδικού (`App\Traits\Captchable`)
και θα μπει στην επικοινωνία χωρίς login.

## ALTCHA
Proof of work: ο browser κάνει λίγη δουλειά (PBKDF2), ο server ελέγχει. Χωρίς λογαριασμό σε τρίτους, χωρίς cookies,
χωρίς κλήσεις έξω (CLAUDE.md: τίποτα που «τηλεφωνεί σπίτι»).
- **Server:** η επίσημη `altcha-org/altcha` (MIT, χωρίς εξαρτήσεις). Μόνο `createChallenge()` / `verifySolution()`,
  που είναι τοπικά· **όχι** Sentinel ή API του altcha.org. Adapter: `App\Services\Captcha\Altcha`.
- **Widget:** το `altcha` του npm (MIT), από το δικό μας build (`resources/js/altcha-entry.js`, μόνο στις σελίδες με
  captcha). Καλεί μόνο το `GET /captcha/altcha` (όριο 30/λεπτό ανά διεύθυνση, 404 όταν δεν είναι το επιλεγμένο
  captcha)· το footer «Protected by ALTCHA» κρυμμένο. Ελληνικά και αγγλικά.
- **Κλειδιά:** δύο, παραγόμενα από το `APP_KEY` (HMAC), τίποτα να ρυθμιστεί ή να φυλαχτεί. Το δεύτερο κάνει τον έλεγχο
  φθηνό (δεν ξαναγίνεται η δουλειά στον server). Νέο `APP_KEY` = άκυρες μόνο οι ανοιχτές προκλήσεις.
- **Μία φορά:** μια λύση γίνεται δεκτή μία φορά (`Cache::add`, ατομικό)· ελέγχεται στο submit, όχι σε κάθε ενημέρωση
  πεδίου (αλλιώς ένα live πεδίο θα την «ξόδευε» πριν το submit). Μετά από κάθε αίτημα το widget ξαναρχίζει.
- **Ρυθμίσεις:** δυσκολία (χαμηλή για παλιά κινητά, κανονική, υψηλή σε επίθεση: `Altcha::DIFFICULTIES`) και διάρκεια
  πρόκλησης σε λεπτά (default 10, έως 60). Η λήξη είναι μέσα στην υπογραφή.

Γιατί όχι πακέτο Laravel (`grantholle/laravel-altcha`): λίγες γραμμές πάνω στην ίδια βιβλιοθήκη, με single use κλειστό
εξ ορισμού, κλειδί στο `.env` και δικό του route· θα το παρακάμπταμε σχεδόν παντού.
