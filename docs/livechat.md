# Live chat: πελάτες, επισκέπτες (pre-sales) και operators (σχέδιο, 2026-10-22)

> Κατάσταση: **σχέδιο, προς έγκριση από τον owner.** Δεν έχει γραφτεί κώδικας. Έγινε threat-model review του σχεδίου
> (2026-10-22, §13). Οι κανόνες του §13 είναι μέρος του §3, και τα tests του μέρος του §9. Σχετικά: `tickets.md`
> (guest links, μοντέλο ασφάλειας), `captcha.md` (ALTCHA), `settings-map.md`.

## 0. Γιατί
Στις 22/10/2026 ένας αυτοματοποιημένος scanner επιτέθηκε στο chat.myip.gr (Live Helper Chat 4.90v, στον rigel) και
βρήκε τρία προβλήματα:
- **Stored XSS στο UI του operator:** το πεδίο email της φόρμας του επισκέπτη αποθηκεύτηκε όπως ήταν και τυπώθηκε
  χωρίς escape μέσα σε `href`.
- **Συνημμένα και logs μέσα στο web root, με directory listing:** κατέβηκαν 45 συνημμένα από 30 chats πελατών.
- **Hashes chats στο `cache/default.log`:** ο scanner τα διάβασε και μπήκε με αυτά σε chats.

Αντί να συνεχίσουμε να θωρακίζουμε μια εφαρμογή PHP της παλιάς σχολής, που ο κώδικάς της βρίσκεται μέσα στο web root
και τα updates της αντικαθιστούν τα patches, φτιάχνουμε μέσα στο MyCS ένα **μικρό** chat. Χρησιμοποιεί ό,τι έχουμε ήδη
ελέγξει: το escape των tickets, τα guest links, το ALTCHA, τα rate limits και τα συνημμένα σε sandbox. **Η ασφάλεια
προηγείται των features.** Όταν σταθεροποιηθεί, το LHC κλείνει.

## 1. Τι υπάρχει ήδη στο MyCS
- **Tickets με polling:** `wire:poll.10s` στον πελάτη (`resources/views/tickets/show.blade.php`) και στο admin
  (`edit-ticket.blade.php`).
- **Markdown με `html_input => escape` και `allow_unsafe_links => false`:** χρησιμοποιείται και για τους επισκέπτες
  (`tickets/guest.blade.php`).
- **Συνημμένα από controller με authz** και `Content-Security-Policy: default-src 'none'; sandbox`
  (`TicketAttachmentController`, `GuestTicketAttachmentController`).
- **Guest links:** το token αποθηκεύεται hashed, με λήξη και revoke (`TicketGuestLink`, `tickets.md` §3 Δ).
- **ALTCHA:** proof-of-work captcha στον δικό μας server, χωρίς εξωτερική υπηρεσία (`app/Services/Captcha/Altcha.php`).
- **Rate limits ανά επισκέπτη:** `VisitorKey` (`app/Services/Domains/Lookup/VisitorKey.php`).
- **Redis** για cache και queue, **υποχρεωτικό 2FA για το staff** και audit log.

**Τι δεν υπάρχει** (review, §13):
- **Δικαιώματα staff ανά τμήμα.** Τα tickets ελέγχονται με το γενικό `admin.tickets.viewAny` (`TicketPolicy`). Το
  `ticket_department_staff` είναι λίστα ειδοποιήσεων (`notifyStaff`), όχι λίστα πρόσβασης.
- **CSP στην εφαρμογή.** Το `X-Frame-Options: SAMEORIGIN` το βάζει σήμερα ο web server, όχι η εφαρμογή.
- **Reauth για staff.** Το `App\Support\Reauth` καλύπτει μόνο πελάτες.

## 2. Εύρος
**Μέσα:**
1. Chat **συνδεδεμένου πελάτη** από το client area, ανά τμήμα.
2. Chat **ανώνυμου επισκέπτη** από τα sites (myip.gr, unblock.myip.gr, nixpal), μέσω popup (§4).
3. Ουρά operators: ανάληψη, μεταφορά σε άλλον operator ή τμήμα, κλείσιμο.
4. Εσωτερικές σημειώσεις μέσα στο chat, ορατές μόνο στο staff.
5. Κανάλι operators: ένα κοινό δωμάτιο για το staff.
6. Παρουσία operators (online ή offline). Όταν δεν είναι κανείς online, ο επισκέπτης βλέπει φόρμα ticket.
7. **Ιστορικό για όλα τα chats** (§6α): των πελατών στην καρτέλα του πελάτη, των ανώνυμων σε λίστα με αναζήτηση. Δεν
   σβήνεται τίποτα αυτόματα.
8. Προαιρετικά «Δημιουργία ticket από chat», για θέματα που θέλουν συνέχεια.

**Έξω, και δεν σχεδιάζονται** (§11 γράφει πότε θα τα ξανασκεφτόμασταν):
- **Ενσωμάτωση με iframe.** Το popup κάνει την ίδια δουλειά. Το iframe θέλει χαλάρωση του `frame-ancestors`, cookies
  τρίτου origin (που το Safari μπλοκάρει) και fallback στο popup έτσι κι αλλιώς.
- **Συνημμένα από ανώνυμους.** Ήταν το βασικό διάνυσμα στο περιστατικό, και στο pre-sales σπάνια χρειάζονται. Όποιος
  θέλει να στείλει αρχείο κάνει login ή ανοίγει ticket.
- **Websockets (Reverb).** Για λίγους operators το polling αρκεί (§5), και δεν χρειάζεται νέος daemon, νέα θύρα ή
  αλλαγή στο CSP.
- Typing indicators, co-browsing, bots και AI, triggers και αυτόματες προσκλήσεις, ιχνηλάτηση σελίδων του επισκέπτη,
  GeoIP στο UI, ιδιωτικά μηνύματα μεταξύ operators.

## 3. Μοντέλο ασφάλειας (κανόνες που δεν σπάνε)
Κάθε κανόνας έχει test (§9) και ελέγχεται στο review.

**Α. Κάθε input είναι μη αξιόπιστο, από όποιον κι αν έρχεται, σε κάθε οθόνη.**

«Μη αξιόπιστο» σημαίνει όλους, όχι μόνο τους ανώνυμους:
- ο **επισκέπτης**;
- ο **πελάτης**: ο λογαριασμός μπορεί να έχει γίνει signup πριν από ένα λεπτό, ή να έχει παραβιαστεί;
- το **staff**: ένας λογαριασμός operator μπορεί να παραβιαστεί, και ένα XSS στο δωμάτιο staff ή σε σημείωση θα έτρεχε
  στους browsers όλων των άλλων operators.

Δεν υπάρχει «έμπιστο» πεδίο που τυπώνεται χωρίς escape:
- Η έξοδος γίνεται μόνο με Blade `{{ }}`, ή με **έναν** helper, `ChatText::render()`. Ισχύει για μηνύματα, σημειώσεις,
  δωμάτιο staff, ονόματα, emails και ονόματα αρχείων. Ο helper (F5):
  - κάνει escape στο HTML και δεν επιτρέπει unsafe links;
  - **δεν** αποδίδει εικόνες (ούτε remote, ούτε `data:`), μόνο κείμενο;
  - δίνει στα links `rel="noopener noreferrer nofollow"` και `target="_blank"`, και δείχνει ολόκληρο το URL;
  - αφαιρεί χαρακτήρες `\p{Cf}` (bidi, zero-width).
- Το `{!! ChatText::render(...) !!}` είναι το **μόνο** επιτρεπτό `{!! !!}` στα views του chat, και το ελέγχει ένα test με
  grep. **Κανένα** άλλο `{!! !!}`, `->html()`, `HtmlString` ή `->formatStateUsing()` που επιστρέφει HTML με δεδομένα
  χρήστη. Ισχύει και για τα Filament columns, τα infolists, τα notifications (το `body()` του Filament δεν κάνει escape,
  F21) και τα emails.
- Το badge ρόλου (επισκέπτης, πελάτης, staff) έρχεται πάντα από το `author_type`, ποτέ από το όνομα (F24).
- Δεδομένα χρήστη δεν μπαίνουν σε `href`, `src`, `style` ή JS. Το email εμφανίζεται ως απλό κείμενο, χωρίς link
  `mailto:`.
- Ίδια όρια μήκους και ίδιος έλεγχος (§3 Β) για επισκέπτη, πελάτη και staff.

**Β. Ελάχιστα πεδία, με έλεγχο στον server.**
- Ο επισκέπτης δίνει μόνο **όνομα** και **email**, και τα δύο προαιρετικά:
  - όνομα έως 60 χαρακτήρες, χωρίς χαρακτήρες ελέγχου ή `\p{Cf}` (όπως το `Guest::reply`);
  - email που περνά το `Contact::mailable()` και έχει έως 190 χαρακτήρες. Ο σκέτος κανόνας `email` του Laravel
    δέχεται quoted local parts με εισαγωγικά και κενά (F6). **Ό,τι δεν περνά τον έλεγχο απορρίπτεται και δεν
    αποθηκεύεται** (το LHC το αποθήκευε όπως ήταν).
- Ο πελάτης δεν δίνει κανένα στοιχείο: όνομα και email έρχονται από τον λογαριασμό του.
- Μήνυμα έως 2.000 χαρακτήρες, χωρίς χαρακτήρες ελέγχου εκτός από αλλαγή γραμμής (για όλους).
- Δεν δεχόμαστε από τον client τίποτα που μοιάζει με μεταδεδομένα (χώρα, σελίδα, user agent ως πεδίο φόρμας, chat
  variables). Ό,τι χρειάζεται, ο server το υπολογίζει μόνος του.

**Γ. Καμία πρόσβαση με κλειδί στο URL.**
- Ο επισκέπτης παίρνει τυχαίο token 256 bit, που αποθηκεύεται **hashed** (όπως στο `TicketGuestLink`), δένεται με το
  session του και λήγει (ρύθμιση, default 24 ώρες από το τελευταίο μήνυμα).
- Το chat id **δεν** αρκεί για πρόσβαση: κάθε αίτημα, **και κάθε poll**, ελέγχεται με policy:
  - πελάτης: ο λογαριασμός μέσω `owns()`, με δικαίωμα περιοχής `chat` (F10);
  - επισκέπτης: το token του session;
  - staff: **νέο** δικαίωμα ανά τμήμα (`chat_department_staff`) και permissions `admin.chats.view`, `.link`,
    `.delete`, `.search` (F3).
- Το token ζει **μόνο** στο session του server, ποτέ σε Livewire property ή στο HTML (F7).
- Το session ανανεώνεται (`regenerate`) όταν ξεκινά chat επισκέπτη (F8). Το token σβήνεται από το session στο κλείσιμο,
  στο login και στο logout (F22).
- Κανένα token ή hash δεν γράφεται σε log, σε URL ή σε μήνυμα σφάλματος.

**Δ. Η ταυτότητα έρχεται μόνο από το session.**
- Ένα email επισκέπτη **δεν** συνδέεται ποτέ αυτόματα με πελάτη, γιατί ο καθένας μπορεί να γράψει το email ενός πελάτη
  μας. Στο UI φαίνεται ως «email χωρίς επιβεβαίωση».
- Ο operator δεν δίνει στοιχεία λογαριασμού, κωδικούς ή τιμολόγια σε ανώνυμο chat. Για τέτοια θέματα ο επισκέπτης
  **κάνει login**. Η οδηγία φαίνεται στον operator στο ανώνυμο chat.
- **Ένα link στο email δεν αποδεικνύει ποιος γράφει στο chat** (F1). Αν ο επισκέπτης γράψει το email ενός πελάτη, ο
  πελάτης μπορεί να κάνει κλικ στο link από συνήθεια. Η επιβεβαίωση email σε chat γίνεται μόνο με κωδικό από το email
  που ο επισκέπτης γράφει **μέσα στο chat**. Ο κωδικός είναι μίας χρήσης, για αυτό το chat και αυτό το email, και λήγει
  σε 15 λεπτά.
- Αν ο επισκέπτης συνδεθεί κατά τη διάρκεια του chat, το chat **δεν** περνά αυτόματα στον λογαριασμό. Ο πελάτης
  ανοίγει νέο chat.
- Ένα ανώνυμο chat συνδέεται με πελάτη **μόνο χειροκίνητα από το staff** (§6α), με καταγραφή στο audit.
- Ένας συνδεδεμένος πελάτης είναι επαληθευμένος **ως λογαριασμός**, όχι ως έμπιστος: κανόνας Α.

**Ε. Κατάχρηση.**
- ALTCHA πριν ξεκινήσει chat επισκέπτη.
- Rate limits (ρυθμίσεις), ανά `VisitorKey` για επισκέπτες και ανά λογαριασμό για πελάτες:
  - έναρξη chat: 3 ανά 10 λεπτά;
  - μηνύματα: 20 ανά λεπτό;
  - ανοιχτά chats: 1 ανά session ή λογαριασμό, 3 ανά IP.
- Ο operator μπορεί να μπλοκάρει έναν επισκέπτη ή να κλείσει το chat ενός πελάτη:
  - το block επισκέπτη μπαίνει στο session και στην IP για ρυθμιζόμενο χρόνο, και προαιρετικά στο CFM blocklist με το
    χέρι;
  - για πελάτη μπαίνει «χωρίς chat» στον λογαριασμό, ώστε να μένει μόνο με tickets.
- Τα αδρανή chats κλείνουν αυτόματα (ρύθμιση, default 30 λεπτά χωρίς μήνυμα επισκέπτη ή πελάτη).

**ΣΤ. Συνημμένα.**
- **Ανώνυμοι επισκέπτες: ποτέ** (§2).
- Πελάτες και staff: ίδιος μηχανισμός με τα tickets:
  - private disk, λίστα τύπων και όριο μεγέθους;
  - σερβίρονται μόνο από controller με authz, `Content-Disposition: attachment` και CSP sandbox;
  - το όνομα του αρχείου είναι δεδομένο χρήστη (κανόνας Α). Αποθηκεύεται με τυχαίο όνομα.

  Τίποτα δεν σερβίρεται ως στατικό αρχείο.

**Ζ. Logs.**
- Δεν γράφουμε στα logs ολόκληρα αντικείμενα chat (`print_r`, `dump`) ούτε περιεχόμενο μηνυμάτων. Γράφουμε μόνο ids και
  γεγονότα.

## 4. Πώς μπαίνει στο site: launcher σε JS και popup
**Σήμερα (έλεγχος 2026-10-22):**

| Site | Τεχνολογία | Chat σήμερα |
|---|---|---|
| `myip.gr/` | Astro | LHC widget (`chat.myip.gr/.../widgetv2/index.js`, `LHC_API.args`, event `chat_started` στο gtag) |
| `myip.gr/clients/` | WHMCS (με κέλυφος Astro) | το ίδιο LHC widget. Το hook `myip_branding` περνά τα στοιχεία του συνδεδεμένου πελάτη |
| `unblock.myip.gr` | Apache, δικό του CSP | **Tawk.to**: ξεχάστηκε εκεί όταν τα υπόλοιπα sites πέρασαν στο LHC |

Τα στοιχεία που περνά το `myip_branding` γράφονται στον browser, άρα ο καθένας μπορεί να τα αλλάξει. Στο LHC σήμερα
είναι στην ουσία «email χωρίς επιβεβαίωση» (§3 Δ), και οι operators να το ξέρουν από τώρα.

**Εκκρεμεί τώρα, ανεξάρτητα από το MyCS:** το Tawk.to στο `unblock.myip.gr` είναι script τρίτου που κανείς δεν
παρακολουθεί, και chats που ίσως φτάνουν σε λογαριασμό που δεν κοιτάζει κανείς. Να αφαιρεθεί το script και τα `*.tawk.to`
από το CSP. Αν χρειάζεται chat εκεί μέχρι να βγει το MyCS, μπαίνει το widget του LHC όπως στο myip.gr.

**Launcher (`chat-launcher.js`, περίπου 40 γραμμές, χωρίς εξαρτήσεις):**
- Προσθέτει ένα κουμπί στη σελίδα. Με το κλικ ανοίγει `window.open('https://<mycs>/chat?dept=…&src=<site>', …,
  'noopener,popup,width=420,height=640')`.
- Δεν φορτώνει δεδομένα, δεν κάνει fetch, δεν βάζει cookies και δεν διαβάζει τίποτα από τη σελίδα. Ρυθμίζεται με
  `data-` attributes (τμήμα, κείμενο, θέση, χρώμα).
- Στο κλικ στέλνει event `chat_opened` στο υπάρχον `window.gtag` της σελίδας, αν υπάρχει (όπως σήμερα το
  `chat_started`, μέσα από το Consent Mode). Λόγω του `noopener`, η σελίδα δεν μαθαίνει τι γίνεται μέσα στο popup. Το
  πόσα chats ξεκίνησαν πραγματικά το μετράει το MyCS.
- Μπαίνει με δύο τρόπους:
  - **(προτείνεται)** ως αντίγραφο μέσα στον κώδικα του site, π.χ. ένα Astro component. Δεν υπάρχει script τρίτου
    origin, και αν παραβιαστεί το MyCS δεν τρέχει τίποτα στα sites;
  - ως `<script src="https://<mycs>/chat-launcher.js" integrity="sha384-…">` με SRI και σταθερή έκδοση στο όνομα.
- **Όχι** `LHC_API.args` ή αντίστοιχο: ο launcher δεν περνά ταυτότητα πελάτη. Ο πελάτης αποδεικνύει ποιος είναι μόνο
  με το session του στο MyCS.
- Το `src` (από ποιο site ήρθε) είναι μόνο ετικέτα για το staff. Ελέγχεται απέναντι σε λίστα και τυπώνεται με escape.

**Ανά site:**
- `myip.gr/` (Astro): component με τον launcher, στη θέση του LHC.
- `myip.gr/clients/`: μέχρι το cut-over από το WHMCS, ο launcher ως επισκέπτης (χωρίς ταυτότητα). Μετά, το client area
  είναι το ίδιο το MyCS, με το chat πελάτη ενσωματωμένο (§7).
- `unblock.myip.gr`: ο launcher. Το CSP του επιτρέπει ήδη `script-src https://*.myip.gr`, και επειδή ο launcher δεν
  κάνει fetch δεν χρειάζεται αλλαγή στο `connect-src`.
- nixpal: το ίδιο, προς το instance της nixpal.

**Η σελίδα `/chat`:**
- Ανοίγει στο ίδιο το domain του MyCS (first-party):
  - κανονικό session, Livewire και CSRF;
  - χωρίς CORS, χωρίς third-party cookies, χωρίς χαλάρωση του `frame-ancestors`.
- Η εφαρμογή στέλνει `frame-ancestors 'none'` και `X-Frame-Options: DENY` στο `/chat` και στο admin, με middleware
  (F8). Σήμερα υπάρχει μόνο `SAMEORIGIN` από τον web server. Αν ένα από τα sites παραβιαστεί, δεν μπορεί να διαβάσει
  τίποτα από το chat.
- Το `GET /chat` δεν αλλάζει τίποτα. Chat ξεκινά μόνο με POST και CSRF. Το τμήμα επιλέγεται στον server: πρέπει να
  δέχεται chat, να μην είναι `hidden`, και για επισκέπτη να μην είναι `clients_only`. Αλλιώς πάει στο default (F17).
- Ο πελάτης που είναι ήδη συνδεδεμένος στο MyCS μπαίνει στο chat ως πελάτης. Η σελίδα του δείχνει ποιος είναι, και
  υπάρχει επιλογή «συνέχεια ως επισκέπτης».

## 5. Μεταφορά: polling
- Livewire `wire:poll` μόνο όσο το chat είναι ανοιχτό και η καρτέλα ορατή (`wire:poll.visible`). Ζητάμε μόνο τα
  μηνύματα μετά το τελευταίο id (`where id > ?`, με index).
- Διαστήματα (ρυθμίσεις): 3 δευτερόλεπτα σε ενεργό chat, 10 δευτερόλεπτα στην ουρά των operators.
- **Παρουσία:** ο operator στέλνει heartbeat κάθε 30 δευτερόλεπτα όσο είναι online. Το heartbeat γράφεται στο Redis
  με TTL 90 δευτερολέπτων. Δεν χρειάζεται νέος πίνακας ή cron.
- Δεν χρειάζεται νέος daemon, θύρα ή αλλαγή στο CSP.

## 6. Δεδομένα
Ένα instance ανά εταιρεία, άρα **χωρίς `company_id`**.

- **`chats`:**
  - `id`, `department_id`, `source` (από ποιο site, τιμή από λίστα);
  - `client_id` (nullable): ο πελάτης, είτε από το session είτε από χειροκίνητη σύνδεση (§6α);
  - `visitor_token_hash` (nullable): μόνο σε chats που ξεκίνησαν ανώνυμα. Μένει και μετά τη σύνδεση με πελάτη, για να
    φαίνεται ότι ξεκίνησε ανώνυμα. Check: υπάρχει τουλάχιστον ένα από τα δύο;
  - `linked_by`, `linked_at` (nullable): ποιος και πότε συνέδεσε το ανώνυμο chat με πελάτη;
  - `visitor_name`, `visitor_email` (nullable, μόνο τιμές που πέρασαν τον έλεγχο);
  - `ip`, `status` (`waiting`, `active`, `closed`), `assigned_to` (staff);
  - `ticket_id` (nullable, αν δημιουργήθηκε ticket), `last_participant_message_at`, `closed_at`, `closed_by`,
    timestamps.
- **`chat_messages`:** `id`, `chat_id`, `author_type` (`client`, `visitor`, `staff`, `system`), `author_id`
  (nullable), `body`, `internal` (bool, σημείωση staff), `created_at`. Index `(chat_id, id)`. Full-text index στο
  `body` για την αναζήτηση.
- **`chat_message_attachments`:** όπως το `ticket_message_attachments` (μόνο για πελάτες και staff).
- **`chat_participants`:** ποιοι operators έγραψαν στο chat (για «τα chats μου» και τις μεταφορές).
- **`staff_room_messages`:** `id`, `user_id`, `body`, `created_at`.
- Το chat αλλάζει κατάσταση **μόνο** με actions: `StartChat`, `ClaimChat`, `TransferChat`, `PostChatMessage`,
  `CloseChat`, `LinkChatToClient`, `CreateTicketFromChat`. Η ανάληψη γίνεται με lock
  (`UPDATE ... WHERE assigned_to IS NULL`), ώστε δύο operators να μην αναλάβουν το ίδιο chat.
- Τα μηνύματα **δεν** αλλάζουν και **δεν** σβήνονται από το UI, ούτε από το staff. Η διαγραφή γίνεται μόνο με το §6α.

### 6α. Ιστορικό: κρατάμε όλα τα chats
- **Chats πελατών:**
  - στο admin, καρτέλα «Chats» στη σελίδα του πελάτη, δίπλα στα tickets, με όλα του τα chats (ανοιχτά και κλειστά) και
    τις εσωτερικές σημειώσεις;
  - ο πελάτης βλέπει τα δικά του κλειστά chats στο client area, μόνο για ανάγνωση και χωρίς τις εσωτερικές σημειώσεις.
- **Ανώνυμα chats:**
  - στο admin, λίστα «Chats» με φίλτρα (τμήμα, operator, site, ημερομηνία, ανώνυμα ή πελατών) και αναζήτηση σε όνομα,
    email και κείμενο;
  - ο επισκέπτης δεν έχει πρόσβαση μετά τη λήξη του token. Μπορεί να ζητήσει αντίγραφο στο email του, μία φορά ανά chat
    (F11):
    - το email επιβεβαίωσης δεν περιέχει κείμενο ή όνομα από τον επισκέπτη;
    - το link ανοίγει σελίδα με κουμπί POST, γιατί οι scanners του mail ακολουθούν τα GET;
    - το μυστικό του link κρύβεται στο `email_logs` και το mail δεν ξαναστέλνεται από το log;
    - το αντίγραφο δεν περιέχει εσωτερικές σημειώσεις.
- **Σύνδεση ανώνυμου chat με πελάτη:**
  - μόνο από το staff με το `admin.chats.link`, με κουμπί «Σύνδεση με πελάτη», με reauth (F18), και **μόνο σε κλειστό
    chat**;
  - η σύνδεση κόβει ατομικά την πρόσβαση του επισκέπτη: το token ανακαλείται, όπως το `GuestLinks::revoke` στα tickets,
    και μένει μόνο η σήμανση `started_anonymously` (F2);
  - καταγράφεται στο audit. Από εκεί και πέρα το chat φαίνεται στην καρτέλα του πελάτη στο admin, με σήμανση
    «ξεκίνησε ανώνυμα». **Δεν** φαίνεται στο client area του πελάτη, εκτός αν το staff το μοιραστεί ρητά (F2);
  - βοήθημα: στη σελίδα του πελάτη, «υπάρχουν N ανώνυμα chats με αυτό το email». Μετρά **μόνο** chats όπου το email
    επιβεβαιώθηκε με κωδικό (§3 Δ). Είναι μόνο ένδειξη, χωρίς σύνδεση και χωρίς να το βλέπει ο πελάτης.
- **Διατήρηση:**
  - default: **κανένα chat δεν σβήνεται αυτόματα**;
  - η IP σβήνεται σε 30 ημέρες (ρύθμιση), γιατί χρειάζεται μόνο για κατάχρηση;
  - διαγραφή ενός chat (π.χ. αίτημα GDPR) μόνο από admin με δικαίωμα, με reauth και λόγο, και καταγραφή στο audit χωρίς
    το περιεχόμενο;
  - η διαγραφή σβήνει και ό,τι αντίγραφο υπάρχει αλλού: αρχεία, εγγραφές στα `audits`, τα `email_logs` του αντιγράφου
    και τα full-text entries. Γι' αυτό τα μοντέλα του chat **δεν** είναι Auditable με όλα τους τα πεδία (όπως το
    `TicketMessage`). Γράφουν ρητά audit events μόνο με ids (F12);
  - η IP υπάρχει και σε sessions, rate-limiter keys και logs του web server. Το «σβήνεται σε 30 ημέρες» αφορά μόνο τον
    πίνακα `chats`, και τα backups έχουν τη δική τους διάρκεια.

## 7. Ροές
**Επισκέπτης:**
1. Κουμπί στο site, ανοίγει το popup `/chat`.
2. Αν δεν είναι κανείς online στο τμήμα: φόρμα guest ticket (υπάρχων μηχανισμός).
3. Αλλιώς, φόρμα με όνομα και email (προαιρετικά), πρώτο μήνυμα και ALTCHA. Δημιουργείται chat `waiting`.
4. Ο operator το αναλαμβάνει και το chat γίνεται `active`.
5. Το chat κλείνει από τον operator, τον επισκέπτη ή λόγω αδράνειας, και μένει στο ιστορικό (§6α).

**Πελάτης:** ίδια ροή από το client area, χωρίς φόρμα και ALTCHA, με συνημμένα. Τα κλειστά chats του φαίνονται στο
client area.

**Operator:**
1. Ουρά ανά τμήμα (μόνο τμήματα όπου έχει δικαίωμα), με ήχο και browser notification για νέο chat.
2. Ανάληψη, μεταφορά σε άλλον operator ή τμήμα, εσωτερικές σημειώσεις, block.
3. Στο chat πελάτη βλέπει δίπλα τα βασικά του λογαριασμού (υπηρεσίες, ανοιχτά tickets), όπως στα tickets.
4. Στο κλείσιμο: προαιρετικά «Δημιουργία ticket από chat». Για πελάτη, ticket στον λογαριασμό του. Για επισκέπτη, guest
   ticket μόνο αν το email επιβεβαιωθεί με κωδικό μέσα στο chat (§3 Δ). Ένα μόνο mail, χωρίς τις σημειώσεις (F4).
5. Δωμάτιο staff: κοινή λίστα μηνυμάτων με polling (κανόνας Α ισχύει κι εδώ).

## 8. Ρυθμίσεις (`settings-map.md`)
- `livechat_enabled`, `livechat_guest_enabled`, και τα τμήματα που δέχονται chat.
- Λίστα επιτρεπτών `source` (sites).
- Διαστήματα polling, TTL παρουσίας, λήξη token επισκέπτη, χρόνος αδράνειας.
- Rate limits (§3 Ε).
- Διατήρηση της IP (default 30 ημέρες). Τα chats δεν έχουν ρύθμιση αυτόματης διαγραφής (§6α).
- Κείμενο σημειώματος απορρήτου στο popup (στο `lang/`, ελληνικά και αγγλικά).

## 9. Tests
- **XSS:** το payload του LHC (`x" onmouseover="alert(1)" data-x="`) και κλασικά `<script>`, `<img onerror>` και
  `javascript:`. Μπαίνουν από **κάθε ρόλο**:
  - επισκέπτης: όνομα, email, μήνυμα;
  - πελάτης: μήνυμα, όνομα αρχείου, και όνομα και email του λογαριασμού;
  - staff: μήνυμα, σημείωση, δωμάτιο staff.

  Assert ότι βγαίνουν escaped σε **κάθε** οθόνη: popup, client area, ιστορικό πελάτη, ουρά, chat στο admin, λίστα
  chats, καρτέλα πελάτη, notification, ticket από chat, email αντιγράφου.
- **Validation:** μη έγκυρο email απορρίπτεται και δεν αποθηκεύεται. Πεδία εκτός λίστας αγνοούνται. Τα ίδια όρια ισχύουν
  για όλους τους ρόλους.
- **Authz:**
  - άλλος επισκέπτης, άλλος πελάτης ή operator χωρίς δικαίωμα στο τμήμα παίρνει 403;
  - το chat id χωρίς token δεν δίνει τίποτα;
  - το token δεν δουλεύει μετά τη λήξη ή από άλλο session;
  - ο πελάτης δεν βλέπει εσωτερικές σημειώσεις, ούτε στο ιστορικό.
- **Δ:** επισκέπτης με email πελάτη δεν βλέπει τίποτα από τον λογαριασμό και δεν συνδέεται αυτόματα. Login κατά τη
  διάρκεια του chat δεν μεταφέρει το chat. Η σύνδεση με πελάτη γίνεται μόνο από staff με δικαίωμα και γράφεται στο
  audit.
- **Abuse:** rate limits (επισκέπτης και πελάτης), ALTCHA υποχρεωτικό, block, αυτόματο κλείσιμο.
- **Ανάληψη:** δύο ταυτόχρονες αναλήψεις, μόνο μία πετυχαίνει (MariaDB, όπως τα `*Concurrency*`).
- **Συνημμένα:** οι επισκέπτες δεν μπορούν να ανεβάσουν. Τα συνημμένα πελάτη έχουν CSP sandbox, `attachment` και authz.
- **Logs:** κανένα token ή περιεχόμενο μηνύματος στα logs (spy στο logger).
- **Διατήρηση:** κανένα chat δεν σβήνεται αυτόματα. Η IP σβήνεται μετά τη λήξη της. Η διαγραφή από admin γράφεται στο
  audit χωρίς περιεχόμενο.

Επιπλέον, κάθε γραμμή του πίνακα του §13 έχει το δικό της test.

## 10. Review
Δεν αγγίζει χρήματα, άρα κατά το `CLAUDE.md` αρκεί ανεξάρτητο code review. Λόγω του περιστατικού της 22/10, προτείνεται
**και adversarial review** στα §3 Α–Ζ, με τους ίδιους κανόνες (failing tests που μένουν στο PR, πίνακας ευρημάτων ως
σχόλιο).

## 11. Υλοποίηση
**Μικρά PRs, περίπου μία εβδομάδα με tests:**
1. μοντέλο, actions και policies, δικαιώματα ανά τμήμα (F3), headers και session (F8), reauth staff (F18);
2. chat πελάτη στο client area και ουρά στο admin;
3. επισκέπτης (popup, ALTCHA, token, rate limits) και launcher;
4. ιστορικό: καρτέλα πελάτη, λίστα chats, σύνδεση με πελάτη;
5. δωμάτιο staff, παρουσία και ειδοποιήσεις;
6. ticket από chat, διατήρηση IP και διαγραφή από admin.

**Απόσυρση του LHC:** όταν το chat του MyCS τρέξει χωρίς προβλήματα για 2–4 εβδομάδες.
- Πριν από αυτό, τα παλιά chats του LHC γίνονται **import** στο MyCS, αφού θέλουμε να υπάρχουν όλα:
  - ως κλειστά chats, με τα μηνύματα και τα συνημμένα στο private disk;
  - όλα ως ανώνυμα, γιατί η ταυτότητα στο LHC δεν ήταν επαληθευμένη (§4). Το staff τα συνδέει με πελάτη χειροκίνητα
    (§6α), όπου χρειάζεται;
  - το import τρέχει πάλι χωρίς διπλότυπα, όπως ο importer του WHMCS;
  - εξαιρούνται τα chats του tester που σβήστηκαν στις 22/10.
- Μετά, τα κουμπιά των sites δείχνουν στο MyCS, και το chat.myip.gr κλείνει μαζί με το backup του.

**Πότε θα ξανασκεφτόμασταν όσα μένουν έξω (§2):**
- **Websockets:** αν οι operators γίνουν τόσοι που το polling φορτώνει τον server, ή αν η καθυστέρηση των 3
  δευτερολέπτων ενοχλεί στην πράξη.
- **Iframe:** αν τα popups μπλοκάρονται συστηματικά. Ο launcher ανοίγει το popup με κλικ του χρήστη, άρα κανονικά δεν
  μπλοκάρονται.
- **Συνημμένα από ανώνυμους:** όχι. Αν προκύψει ανάγκη, ο operator στέλνει link για guest ticket.

## 12. Ανοιχτά ερωτήματα για τον owner
1. Ποια τμήματα δέχονται chat επισκέπτη (μόνο Πωλήσεις, ή και Τεχνική υποστήριξη);
2. Ωράριο: το chat ανοίγει μόνο όταν κάποιος operator είναι online, ή και με βάση ωράριο;
3. Launcher: αντίγραφο μέσα στον κώδικα κάθε site (προτείνεται) ή `<script src>` από το MyCS με SRI;
4. Tawk.to στο `unblock.myip.gr`: να φύγει τώρα (§4); Χρειάζεται chat εκεί μέχρι να βγει το MyCS;
5. Πρέπει να ανοίξουμε το παλιό inbox του Tawk.to για chats που δεν απαντήθηκαν;

## 13. Threat model: ευρήματα review (2026-10-22)
Ανεξάρτητος reviewer, χωρίς το context του συγγραφέα, διάβασε το σχέδιο και τον κώδικα που επικαλείται, με ρόλους
επιτιθέμενου: ανώνυμος επισκέπτης, πελάτης με νέο λογαριασμό, παραβιασμένος πελάτης, παραβιασμένος ή κακόβουλος
operator, παραβιασμένο marketing site.
- **CONFIRMED:** ελέγχθηκε στον κώδικα ή στο κείμενο του σχεδίου.
- **PLAUSIBLE:** λογικό, αλλά επαληθεύεται μόνο στην υλοποίηση.

Όσα αλλάζουν κείμενο αλλού έχουν περαστεί εκεί (§1, §3, §4, §6α). Οι κανόνες και τα tests αυτού του πίνακα είναι
υποχρεωτικοί για την υλοποίηση.

| # | Σοβ. | Εύρημα | Κανόνας | Test | Κατάσταση |
|---|---|---|---|---|---|
| F1 | υψηλή | Ένα link επιβεβαίωσης στο email αποδεικνύει τον κάτοχο του inbox, όχι αυτόν που γράφει στο chat | Κωδικός από το email που γράφεται **μέσα στο chat**: μίας χρήσης, για αυτό το chat και αυτό το email, λήγει σε 15 λεπτά (§3 Δ) | Το link ή ο κωδικός από άλλο session δεν επιβεβαιώνει το chat | CONFIRMED (σχέδιο) |
| F2 | υψηλή | Η σύνδεση με πελάτη δεν κόβει τον επισκέπτη και βάζει κείμενο τρίτου στον λογαριασμό | Σύνδεση μόνο σε κλειστό chat. Ανάκληση token ατομικά. Δεν φαίνεται στο client area χωρίς ρητό share. Η ένδειξη «N chats» μετρά μόνο επιβεβαιωμένα (§6α) | Poll ή send μετά τη σύνδεση δίνει 403. Σύνδεση σε ενεργό chat απορρίπτεται | CONFIRMED (`GuestLinks::revoke`) |
| F3 | υψηλή | Δεν υπάρχουν δικαιώματα staff ανά τμήμα. Το `ticket_department_staff` είναι λίστα ειδοποιήσεων | Νέο `chat_department_staff` και permissions `admin.chats.view`, `.link`, `.delete`, `.search` σε ουρά, προβολή, actions, λίστα, αναζήτηση και counts (§3 Γ) | Operator χωρίς το τμήμα X: 403 ή not-found σε όλα τα παραπάνω και στα συνημμένα | CONFIRMED (`TicketPolicy`, `TicketDepartment::notifyStaff`) |
| F4 | υψηλή | Το «ticket από chat» θα έστελνε ένα mail ανά μήνυμα (`TicketMessageCreatedListener`) και θα έκανε δημόσιες τις σημειώσεις | Ένα ticket με σύνοψη ή ήσυχο transcript, χωρίς events ανά μήνυμα. Οι σημειώσεις περνούν `is_internal` ή καθόλου. Κείμενο επισκέπτη με `sender_vouched = false`, εκτός αν έγινε F1. Ένα μόνο mail | Chat με 5 μηνύματα staff και 2 σημειώσεις: ≤ 1 mail, και οι σημειώσεις δεν εμφανίζονται πουθενά δημόσια | CONFIRMED |
| F5 | μεσαία | Ο κανόνας του markdown αντιφάσκει, και αφήνει remote εικόνες (tracking pixel στους operators), autolinks και phishing | Ένας helper `ChatText::render()`: χωρίς εικόνες, links με `rel`, `\p{Cf}` αφαιρούνται. Grep test για το `{!! !!}` (§3 Α) | `![](…)`, `<img>`, `javascript:`, `data:` και σκέτο URL: κανένα `<img>`, κανένα επικίνδυνο href | CONFIRMED (views) |
| F6 | μεσαία | Ο σκέτος κανόνας `email` δέχεται quoted local parts με payload. Οι bidi και zero-width χαρακτήρες δεν καλύπτονται | `Contact::mailable()`. Αφαίρεση `[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\p{Cf}]` (§3 Β) | Email `"x\" onmouseover…"@a.gr` και όνομα με U+202E απορρίπτονται | CONFIRMED |
| F7 | μεσαία | Livewire: token στο snapshot, public fields που αλλάζει ο client, σημειώσεις σε public arrays, polls χωρίς authz, upload endpoint, `svg` στα `preview_mimes` | Token μόνο στο session. `#[Locked]` σε chat id και τμήμα. Ρόλος και `internal` μόνο από τον server. Μηνύματα ως computed. Policy σε κάθε poll. Οι components επισκέπτη χωρίς `WithFileUploads`. `svg` εκτός `preview_mimes` | POST `internal=true` αγνοείται. Το snapshot δεν έχει token ή σημειώσεις. Poll μετά από block δίνει τίποτα | CONFIRMED (`Guest.php`, `livewire.php`) |
| F8 | μεσαία | Η εφαρμογή δεν στέλνει headers κατά του framing (μόνο ο web server στέλνει `SAMEORIGIN`). Sibling hosts στο `.myip.gr` μπορούν να στήσουν cookie (cookie tossing) για session fixation | Middleware με `frame-ancestors 'none'` και `X-Frame-Options: DENY`. `session()->regenerate()` στο `StartChat`. Έλεγχος ότι το `SESSION_DOMAIN` είναι κενό και τα cookies `secure` | Headers υπάρχουν. Το session id αλλάζει στην έναρξη chat | CONFIRMED (headers). Το cookie tossing PLAUSIBLE |
| F9 | μεσαία | Οι guards πελάτη και staff μοιράζονται το session. Το «Login as client» θα έγραφε ως πελάτης | Οι components των operators μένουν μέσα στο Filament, με `Auth::guard('staff')` ρητά. Σε impersonation, κανένα chat πελάτη, ή αποθήκευση ως staff με σήμανση | Ίδιος browser με πελάτη και staff: κανένα μπέρδεμα ρόλου | CONFIRMED (`UseWebGuard`, `ImpersonateMiddleware`) |
| F10 | μεσαία | Δεν ορίζεται ποια logins ενός λογαριασμού βλέπουν τα chats | Chats ανά λογαριασμό, μέσω `owns()` με δικαίωμα `chat`, έλεγχος σε κάθε poll, `user_id` σε κάθε μήνυμα | Login χωρίς δικαίωμα ή με ανακληθέν grant: 403 και στο επόμενο poll | CONFIRMED (`BasePolicy::owns`) |
| F11 | μεσαία | Το αντίγραφο με email γίνεται mailbomb, το επιβεβαιώνουν scanners μέσω GET, και το link μένει στο `email_logs` | Ένα ανά chat, limits ανά διεύθυνση, IP και ημέρα. Mail χωρίς κείμενο επισκέπτη. Επιβεβαίωση με POST. Masking στο log, χωρίς resend (§6α) | Δεύτερο αίτημα δεν στέλνει. Το GET δεν στέλνει. Το log δείχνει `********` | CONFIRMED (`NotificationHelper`) |
| F12 | μεσαία | Περιεχόμενο chat θα αντιγραφόταν σε audits και debug logs όπου η διαγραφή δεν φτάνει | Όχι Auditable με όλα τα πεδία, ρητά audit events μόνο με ids. Errors χωρίς SQL bindings. Η διαγραφή φτάνει σε audits, `email_logs` και αρχεία (§6α) | Μετά από διαγραφή GDPR, κανένα body σε `audits`, `email_logs` ή `debug_logs` | CONFIRMED (`TicketMessage` Auditable) |
| F13 | μεσαία | Rate limits: πραγματική IP πίσω από proxy, CGNAT, IPv6, και races στο check-then-insert | Έλεγχος του `CallerIp` στο deploy. Blocks με `CallerIp::bucket` (/64), σύντομα από default. Πρώτα increment, μετά έλεγχος, ανά call. `Cache::lock` στην έναρξη | 50 sends σε ένα request σταματούν στο όριο. Δύο παράλληλα `StartChat` φτιάχνουν ένα chat | CONFIRMED (κώδικας). Το proxy setup PLAUSIBLE |
| F14 | μεσαία | Το route του ALTCHA δίνει 404 όταν έχει επιλεγεί άλλο captcha | Το chat επισκέπτη χρησιμοποιεί πάντα ALTCHA, με δικό του route. Αν δεν λειτουργεί, δεν ανοίγει chat επισκέπτη (fail closed) | Με `captcha = recaptcha`, το chat θέλει ακόμα ALTCHA. Replay απορρίπτεται | CONFIRMED (`routes/web.php`) |
| F15 | μεσαία | Το polling με `id > last` χάνει μηνύματα που κάνουν commit εκτός σειράς, και ξεχνά εύκολα το `internal = 0` | Επικάλυψη στο poll (π.χ. τα τελευταία 20 ids) με dedup. Πελάτης και επισκέπτης διαβάζουν μόνο μέσω `publicMessages()` | Δύο inserts με αντίστροφο commit φτάνουν και τα δύο. Κανένα `internal` σε πελάτη ή επισκέπτη | PLAUSIBLE |
| F16 | μεσαία | Και οι άλλες αλλαγές κατάστασης έχουν races (transfer, close, link, post, auto-close) | Κάθε action σε transaction με `lockForUpdate` και έλεγχο προϋποθέσεων μέσα στο lock. Το auto-close ως conditional update | Close και post ταυτόχρονα: ποτέ post μετά το close (MariaDB) | PLAUSIBLE |
| F17 | μεσαία | Το `dept` μπορεί να δείξει κρυφό ή `clients_only` τμήμα. Ένα GET που ξεκινά chat είναι CSRF | Το τμήμα επιλέγεται στον server. Το `src` αντιστοιχίζεται σε λίστα. Chat μόνο με POST (§4) | Επισκέπτης με κρυφό `dept` καταλήγει στο default. Το GET δεν φτιάχνει chat | CONFIRMED (`TicketDepartment`) |
| F18 | μεσαία | Η ανοιχτή ουρά κρατά το session του staff ζωντανό για πάντα. Δεν υπάρχει reauth για staff | Απόλυτο όριο στο session του staff (π.χ. 12 ώρες) και idle timer που αγνοεί τα polls. Reauth (κωδικός ή TOTP) για σύνδεση με πελάτη, διαγραφή και block στο CFM | Μετά το όριο, το poll δίνει 401. Διαγραφή χωρίς πρόσφατο reauth απορρίπτεται | CONFIRMED (`Reauth` μόνο για πελάτες) |
| F19 | μεσαία | Import του LHC: payloads, HTML ή BBCode, paths αρχείων από τη βάση, re-run μετά από διαγραφή | Τα όνομα και email περνούν από το §3 Β, το HTML γίνεται κείμενο. Τα αρχεία διαβάζονται με έλεγχο realpath, χωρίς symlinks, με `Attachments::refusal`. Κλειδί το LHC id, με tombstones. Χωρίς tokens, σήμανση `imported` | Το email-payload γίνεται null. Το path `../../.env` παραλείπεται. Re-run δεν επαναφέρει ό,τι σβήστηκε | PLAUSIBLE |
| F20 | χαμηλή | Τα system messages («μεταφέρθηκε στον X», «συνδέθηκε με Y») και τα κενά στα ids αποκαλύπτουν εσωτερικά | Τα system messages είναι από default ορατά μόνο στο staff. Τα raw ids δεν φαίνονται στον επισκέπτη | Ο επισκέπτης δεν βλέπει όνομα staff ή πελάτη | PLAUSIBLE |
| F21 | χαμηλή | Οι browser notifications δείχνουν περιεχόμενο στο lock screen. Το `body()` του Filament δεν κάνει escape | OS notification μόνο «Νέο chat – τμήμα». Τα δεδομένα χρήστη περνούν από `e()` | Όνομα `<a href=//e>` βγαίνει escaped | PLAUSIBLE |
| F22 | χαμηλή | Ο κύκλος ζωής του token δεν ορίζεται απέναντι στο session (login, logout, κοινός υπολογιστής) | Το token σβήνεται στο κλείσιμο, στο login και στο logout. Πραγματική διάρκεια = min(token, session) (§3 Γ) | Μετά από login, το popup δεν δείχνει το ανώνυμο chat | CONFIRMED (`Login`, `Logout`) |
| F23 | χαμηλή | Το δωμάτιο staff δεν έχει δικαιώματα, διατήρηση ή όρια | Permissions `admin.chat_room.view` και `.post`, ίδια όρια και ίδιο escape, ένταξη στη διαγραφή του F12 | Staff χωρίς δικαίωμα: 403 και στο poll | PLAUSIBLE |
| F24 | χαμηλή | Ο επισκέπτης παριστάνει τον operator με το όνομά του («MyIP Support», homoglyphs) | Το badge έρχεται από το `author_type`, και αφαιρούνται οι `\p{Cf}` (§3 Α) | Επισκέπτης με όνομα σαν το brand εμφανίζεται με badge «Επισκέπτης» παντού | PLAUSIBLE |

**Υποθέσεις του σχεδίου που ελέγχθηκαν και ισχύουν:**
- **Guest links:** token 256 bit, μόνο SHA-256 στη βάση, λήξη και revoke (`GuestLinks`).
- **`VisitorKey` / `CallerIp`:** δεν εμπιστεύεται το `X-Forwarded-For` χωρίς δηλωμένο proxy, και ομαδοποιεί το IPv6 ανά /64.
- **ALTCHA:** μίας χρήσης με ατομικό `Cache::add`, λήξη μέσα στην υπογραφή, όριο payload 8 KB.
- **Συνημμένα:** σερβίρονται ως download με `nosniff` και CSP sandbox. Ο τύπος ελέγχεται με libmagic, χωρίς SVG ή HTML.
- **Markdown των tickets:** escape παντού.
- **Session:** Redis, κρυπτογραφημένο, `http_only` και `secure` (έλεγχος στο live 2026-10-22). Regenerate στο login,
  invalidate στο logout.
- **Livewire:** όρια payload και `legacy_model_binding` off.
- **Gate denials:** επιστρέφουν «not found».
