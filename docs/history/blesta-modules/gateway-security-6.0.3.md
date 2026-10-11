# Τα δικά μας gateways απέναντι στα κενά που έκλεισε το Blesta 6.0.3

Το 6.0.3 κλείνει πλαστογράφηση πληρωμών σε stock gateways (PayPal Standard, CCAvenue, CoinGate,
Stripe webhook)· ίδια μοτίβα μένουν σε άλλα (PayFast, CoinPayments, BitPay, PayPal Checkout, Alipay).
Σύνοψη από το session «Blesta security vulnerability analysis»: `docs/HANDOFF.md` §10. Έλεγχος των δικών μας (2026-10-09):

| Μοτίβο | `cardlink_eurobank` | `paypal_vault` |
|---|---|---|
| Fail-open στο `validate()` (συναλλαγή μετά από αποτυχημένη υπογραφή) | ✅ αποτυχία digest/ticket → error, καμία συναλλαγή· declined μόνο από επαληθευμένο return | — δεν έχει callback/webhook |
| Ποσό/νόμισμα/invoices/client από unsigned δεδομένα | ✅ από το vPOS digest + δικό μας HMAC ticket δεμένο με το order id | ✅ η χρέωση και το αποτέλεσμα έρχονται σύγχρονα από το Orders API |
| Tokens με HMAC, `hash_equals` | ✅ | — |
| Ownership ID από browser | ✅ invoices από το ticket που φτιάξαμε στο `buildProcess` | ⚠️→✅ **διορθώθηκε:** το `storeCc` δεχόταν οποιοδήποτε setup token· τώρα μόνο όσα εξέδωσε η φόρμα μας στο ίδιο session (μία χρήση, 3 ώρες) |
| Replay / refunded → approved | ✅ status μόνο προς τα μπρος, τα τελικά δεν αλλάζουν (PR #10) | ✅ `PayPal-Request-Id` + απόρριψη παλιού order (PR #5) |
| Escaping στα views / JS | ✅ Form helper | ✅ `Html->safe`, `textContent`· τα `json_encode` μέσα στο `<script>` τώρα με `JSON_HEX_*` |

`vivawallet`: δεν έχει γραφτεί· όταν γραφτεί, ίδια λίστα (webhook με επαλήθευση ή re-fetch της
συναλλαγής από το API, ποσό από το API, ownership, idempotency).

Στο staging: η προσθήκη λογαριασμού PayPal (client area pay page, checkout παραγγελίας, admin) πρέπει να
δουλεύει με το session του Blesta. Αν αποτυγχάνει με «setup token not issued in this session» στο gateway
log, πιθανές αιτίες: το Blesta δεν κρατά το `$_SESSION` ανάμεσα στη φόρμα και την αποθήκευση· ένα
παράλληλο request στο ίδιο session έγραψε το session από πάνω (οι DB session handlers δεν κλειδώνουν)·
ή πάνω από 10 φόρμες ανοίχτηκαν στο ίδιο session πριν την αποθήκευση.

Συνέπεια: λογαριασμός PayPal δεν αποθηκεύεται πια από μη διαδραστικές κλήσεις (π.χ. API `Accounts.addCc`)·
χρειάζεται πάντα έγκριση από τη φόρμα. Οι εισαγόμενοι λογαριασμοί (importer) δεν περνάνε από το `storeCc`.
