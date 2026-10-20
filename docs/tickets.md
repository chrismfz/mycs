# Tickets: επικοινωνία χωρίς login, email και μοντέλο ασφάλειας (σχέδιο, 2026-10-19)

> Κατάσταση: **αποφάσεις owner 2026-10-20 (§7)**. Έγιναν το Α1 (§6α) και το Α2 (§6β). Σχετικά: `admin-ux.md` («Μενού επισκέπτη»),
> `captcha.md` (ALTCHA), `mail.md`.

## 0. Γιατί
Η «Επικοινωνία» του μενού επισκέπτη πρέπει να ανοίγει ticket χωρίς login, όπως το `submitticket.php` του WHMCS (myip.gr
31 links, nixpal.com 73), και οι απαντήσεις να πηγαίνουν και να έρχονται με email. Το piping είναι η πιο εκτεθειμένη
επιφάνεια ενός helpdesk: Zendesk και Atlassian την πάτησαν με μαντέψιμα IDs, εμπιστοσύνη στο `From` και CC που
μπαίνουν σε νήματα (π.χ. CVE-2024-49193 και η κλάση «ticket trick»: email επιβεβαίωσης τρίτου site που γίνεται ticket
και το διαβάζει όποιος το προκάλεσε). **Η ασφάλεια προηγείται της ευκολίας** (owner, 2026-10-19).

## 1. Τι υπάρχει σήμερα (MyCS, `ab3471e`)
- Ticket **πάντα** με πελάτη: `tickets.client_id` NOT NULL, cascade. Statuses open/replied/closed· τμήματα ως λίστα
  ονομάτων (`ticket_departments`)· αυτόματο κλείσιμο `replied` (`cronjob_close_ticket`).
- Πελάτης: δημιουργία, απάντηση, κλείσιμο· συνημμένα 10 MB **χωρίς λίστα τύπων**. Staff (Filament): απάντηση,
  ανάθεση, αλλαγή πεδίων, διαγραφή· **όχι** εσωτερικές σημειώσεις, ειδοποιήσεις staff, έτοιμες απαντήσεις, merge.
- Email προς πελάτη μόνο στην απάντηση staff (`new_ticket_message`), με `Message-ID: <id μηνύματος>@host`.
- **Piping (`app:fetch-emails`, `directorytree/imapengine`) — ευπαθές, και κλειστό στο live (`ticket_mail_piping = 0`,
  0 tickets, 2026-10-19):** ταιριάζει νήμα με **αριθμητικό, διαδοχικό** `In-Reply-To` (`^\d+@`, χωρίς έλεγχο host) και
  δέχεται τον αποστολέα αν το `From` είναι login του πελάτη· το `From` πλαστογραφείται. Επιπλέον: τελευταία 1 μέρα,
  χωρίς «seen», συνημμένα χωρίς όρια, μία εξαίρεση σταματά όλο το run, κανένα test. **Δεν ανοίγει ποτέ ως έχει.**

## 2. Τι έχει το Ekdosi (`/var/www/ekdosi`, `docs/ticket-system.md`, ~120 tests)
Πυρήνας email καλύτερος από τον δικό μας: `ImapMailbox`/`WebklexImapMailbox` (ανά μήνυμα, όρια μεγέθους, `\Seen` μόνο
μετά από επιτυχία, ένα λάθος δεν σταματά το run), `InboundTicketRouter` (blocklist, dedup Message-ID, νήμα μόνο αν ο
αποστολέας είναι κάτοχος/watcher), σταθερά Message-ID στα εξερχόμενα με References, φύλαξη συνημμένων (λίστα τύπων,
20 MB/αρχείο, 5 αρχεία, 25 MB/email), `OpenTicket`/`PostTicketMessage` με state machine 5 καταστάσεων, `MergeTickets`,
έτοιμες απαντήσεις, health του polling (`ticket_poll_runs`). Tickets επισκέπτη (`requester_email`) από email.
**Ούτε το Ekdosi αντέχει την κλάση επιθέσεων του §0:** εμπιστεύεται το `From`, προσθέτει αυτόματα τα To/Cc ως watchers,
νηματοποιεί και με tag στο θέμα με ~30 bits τυχαιότητας, δεν φιλτράρει auto-replies/bounces/loops.

## 3. Μοντέλο ασφάλειας (κανόνες που δεν σπάνε)
Κάθε κανόνας έχει test (§6) και ελέγχεται στο adversarial review.

**Α. Κανένα μαντέψιμο κλειδί νήματος.**
- Κάθε εξερχόμενο email έχει `Message-ID: <tk.{128 bit τυχαία}@{domain}>`, αποθηκευμένο στο μήνυμα πριν σταλεί (ίδιο
  σε κάθε retry).
- Μια απάντηση μπαίνει σε ticket **μόνο** αν κάποιο από τα `In-Reply-To`/`References` είναι **ακριβώς** δικό μας
  αποθηκευμένο Message-ID. Ποτέ με αριθμό, ποτέ μόνο με θέμα. Το `[#ref]` στο θέμα είναι μόνο για ανθρώπους.
- **Χωρίς `mailbox+token@`** (owner, 2026-10-20): δεν το εγγυώνται και οι δύο servers (mailcow και cPanel)· το
  τυχαίο Message-ID αρκεί για την κλάση του §0, όπου τα IDs μαντεύονταν.

**Β. Το `From` δεν αποδεικνύει ταυτότητα.**
- Διαβάζουμε **μόνο** το `Authentication-Results` που προσθέτει ο **δικός μας** server παραλαβής: ρύθμιση ανά mailbox
  `authserv_id` (π.χ. `mymail.myip.gr`)· κάθε άλλο `Authentication-Results` αγνοείται, γιατί μπορεί να το γράψει ο
  αποστολέας.
- Ρύθμιση ανά mailbox **«Απαιτείται DMARC»** (default: ναι). Με ναι, για να μπει σε **υπάρχον** ticket: DMARC `pass`
  (ή SPF/DKIM `pass` ευθυγραμμισμένο με το domain του `From`) **και** `From` = ο κάτοχος **και** κλειδί του Α.
  Με όχι (server χωρίς `Authentication-Results`, π.χ. το cPanel του orion, δοκιμή owner 2026-10-20): `From` = κάτοχος
  **και** κλειδί του Α· ένας επιτιθέμενος πρέπει να διαβάζει ήδη το inbox του κατόχου για να ξέρει το Message-ID. Η
  οθόνη του mailbox δείχνει ότι προστατεύεται λιγότερο. **Σύσταση:** και τα mailboxes της nixpal στο mailcow.
- Ό,τι δεν περνά: **καραντίνα** (ορατή στο staff με λόγο, «Επισύναψη στο #…» ή «Νέο ticket» με ένα κλικ), ποτέ αυτόματα.

**Γ. Κανείς δεν μπαίνει σε νήμα με email.**
- Τα To/Cc ενός εισερχόμενου **δεν** γίνονται watchers. Watchers μόνο από staff ή από τον κάτοχο στο portal.
- Οι απαντήσεις staff πηγαίνουν **μόνο** στον κάτοχο (και σε watchers που πρόσθεσε το staff), ποτέ «reply-all».

**Δ. Το email δεν δίνει πρόσβαση.**
- Επισκέπτης: βλέπει/απαντά στο ticket μόνο με **υπογεγραμμένο link** που στάλθηκε στο email του (λήξη, μυστικό ανά
  ticket, ακυρώνεται με «νέο νήμα»· PR Γ). Κανένα «βάλε το email σου και δες τα tickets σου».
- **Καμία αυτόματη σύνδεση** ticket επισκέπτη με λογαριασμό από το email: το staff το κάνει ρητά. Ένα ticket που
  άνοιξε με email **δεν** εμφανίζεται σε λογαριασμό χωρίς αυτή την ενέργεια.
- Νέο ticket από email σε λογαριασμό: μόνο αν ισχύει το Β (DMARC pass) **και** το `From` είναι login του λογαριασμού·
  αλλιώς ticket επισκέπτη.

**Ε. Αυτοματισμοί και loops.**
- Δεν ανοίγουν ούτε ξανανοίγουν tickets: `Auto-Submitted` ≠ `no`, `Precedence: bulk|junk|list|auto_reply`,
  `X-Autoreply`/`X-Autorespond`, `List-Id`/`List-Unsubscribe`, `Return-Path: <>`, MAILER-DAEMON/postmaster/no-reply
  αποστολείς, DSN (`multipart/report`). Καταγράφονται, δεν απαντώνται.
- Τα δικά μας εξερχόμενα έχουν `Auto-Submitted: auto-replied` στις αυτόματες απαντήσεις (επιβεβαίωση παραλαβής).
- Όριο ανά αποστολέα (π.χ. 10 νέα tickets/ώρα) και ανά διεύθυνση στη φόρμα· blocklist email/domain.

**ΣΤ. Περιεχόμενο και αρχεία.**
- HTML email → κείμενο· το κείμενο εμφανίζεται ως markdown με escaped HTML. Κανένα remote image/tracking.
- Συνημμένα: λίστα τύπων και μεγεθών (του Ekdosi), επαλήθευση MIME από το περιεχόμενο, ιδιωτικός δίσκος, όνομα αρχείου
  από εμάς, σερβίρονται **πάντα** ως `attachment` με `X-Content-Type-Options: nosniff`· όχι SVG/HTML inline.
  **Επισκέπτες: χωρίς συνημμένα στη φόρμα** (από email: ίδια όρια, ορατά στο staff).
- Τα εξερχόμενα **δεν** περιέχουν όλο το ιστορικό, μόνο τη νέα απάντηση και link (για τον επισκέπτη το υπογεγραμμένο).

**Ζ. Φόρμα «Επικοινωνία» (επισκέπτης).** Όνομα, Email, Θέμα, Τμήμα, Μήνυμα· ALTCHA (`captcha.md`), όριο ανά διεύθυνση,
honeypot. Χωρίς προτεραιότητα (μπαίνει η default) και χωρίς συνημμένα. Μετά την υποβολή: «Λάβαμε το μήνυμά σας» και
email επιβεβαίωσης στον επισκέπτη με τον αριθμό· **όχι** link με το περιεχόμενο πριν αποδείξει το email του (το
υπογεγραμμένο link είναι στο ίδιο email, άρα το βλέπει μόνο ο κάτοχος του inbox).

## 4. Τι παίρνουμε από το Ekdosi
| Από το Ekdosi | Πώς |
|---|---|
| `ImapMailbox` + `WebklexImapMailbox` + DTOs (`ParsedInboundEmail`, `InboundEmailAttachment`, `MailboxPollSummary`, `MailboxTestResult`) | σε `packages/ticket-mail` (χωρίς Laravel, CLAUDE.md), με το `webklex/php-imap`· αντικαθιστά το `directorytree/imapengine` του `FetchEmails` |
| `InboundTicketRouter` | λογική (blocklist, dedup, νήμα μόνο από κάτοχο), **συν** τα Α, Β, Γ, Ε του §3· χωρίς `company_id`/`Customer`, με τους πελάτες και τα logins του MyCS |
| Σχήμα εξερχόμενων (σταθερό Message-ID, References, retry με ίδιο ID) | με τυχαίο 128-bit αντί για `tk-{ref}-{id}-{rand}` |
| `TicketAttachments` (λίστα τύπων, όρια) | ως έχει, και για τα συνημμένα του portal που σήμερα δεν έχουν λίστα |
| `OpenTicket` / `PostTicketMessage` + state machine 5 καταστάσεων | ενιαίο σημείο εισόδου για portal, φόρμα, email, admin, API· οι 3 σημερινές καταστάσεις αντιστοιχίζονται |
| `ticket_poll_runs` + health | για τον έλεγχο του polling (και στο MCP `health`) |
| Fake mailbox των tests | για να ελέγχεται το routing χωρίς IMAP |

**Δεν** παίρνουμε: το Filament UI του (ελληνικό, με infolist τιμολόγησης· γράφεται στο MyCS με τη λίστα δυνατοτήτων
του ως προδιαγραφή), `TicketNotifier` (Ergani), `KnowledgeBase`, leads, το WHMCS inbox (είναι τιμολόγηση). Merge,
έτοιμες απαντήσεις, CSAT: αργότερα, χωριστά PRs.

## 5. Σχήμα (αλλαγές)
- **`ticket_departments` πίνακας** (αντί για τη λίστα ονομάτων `ticket_departments` στις ρυθμίσεις): όνομα, σειρά,
  κρυφό, «μόνο πελάτες», email τμήματος (π.χ. `support@myip.gr`, `sales@myip.gr`), IMAP host/port/user/password
  (κρυπτογραφημένο, `encrypted` cast), `authserv_id`, «Απαιτείται DMARC», ενεργό polling, `whmcs_id`. Mailbox **ανά
  τμήμα** (owner: support@ και sales@, 2–3 τμήματα), όπως το WHMCS και το Ekdosi. Τα εξερχόμενα φεύγουν από το email
  του τμήματος.
- `tickets`: `client_id` **nullable**· `guest_name`, `guest_email` (υποχρεωτικά όταν `client_id` null)· `reference`
  (ανθρώπινο, π.χ. `T-2026-10-19-XXXX`, μόνο για εμφάνιση)· `thread_token_hash`· `opened_via` (portal, form, email,
  staff, api)· `guest_link_secret_hash` (PR Γ).
- `ticket_messages`: `email_message_id` (unique), `via`, `is_internal` (εσωτερική σημείωση), `body_original`.
- `ticket_quarantine`: εισερχόμενα που δεν πέρασαν το §3 (λόγος, headers, ασφαλές preview), με ενέργειες staff.
- `ticket_blocked_senders`, `ticket_poll_runs`.
- Ό,τι σήμερα υποθέτει πελάτη (policies, listener ειδοποιήσεων, admin infolist/στήλες/φίλτρα, ClientSummary, API,
  `TemplateRenderer`, `Client::tickets()`): χειρίζεται τον επισκέπτη (λίστα στο σημερινό inventory).
- Καταστάσεις (5, όπως το Ekdosi): `open`, `answered`, `customer_reply`, `on_hold`, `closed`. Από το WHMCS: Open →
  open, Answered → answered, Customer-Reply → customer_reply, On Hold → on_hold, **In Progress → on_hold** (ή ξεχωριστή
  6η, §7), Closed → closed. Οι σημερινές `replied` → answered.
- `tickets.reference`: ο αριθμός που βλέπει ο πελάτης. Εισαγμένα: το `tid` του WHMCS ως έχει (myip 6 χαρακτήρες,
  nixpal 17), για να βρίσκονται όσα γράφουν οι πελάτες. Νέα: ίδιας μορφής ανά εταιρεία (ρύθμιση), μοναδικά. Μόνο για
  εμφάνιση· ποτέ κλειδί νήματος (§3 Α).

## 5α. Μετάβαση από το WHMCS (απογραφή 2026-10-20, μόνο αθροίσματα)
| | myip | nixpal |
|---|---|---|
| Τμήματα (tickets) | Support 15.355, Sales 1.624, Info 406· όλα με email και IMAP | Support 592, Pre-Sales 51· με email και IMAP |
| Tickets | 17.385 (από 2007· 1.756 τους τελευταίους 12 μήνες) | 643 (από 2020) |
| **Επισκέπτη** (`userid = 0`) | **6.146 (35%)** | 112 |
| Απαντήσεις (staff / επισκέπτη) | 27.736 (16.642 / 2.504) | 3.185 (1.609 / 148) |
| Σημειώσεις staff | 229 | 7 |
| Merged | 417 | 4 |
| Με συνημμένα (tickets / απαντήσεις) | 3.966 / 2.352 | 58 / 149 |
| `tid` | 6 χαρακτήρες | 17 χαρακτήρες |
| Καταστάσεις | Open, Answered, Customer-Reply, On Hold, In Progress, Closed | ίδιες |

**Ο σημερινός importer (`ImportFromWhmcs::importTickets`) δεν κάνει:** φτιάχνει **χρήστη και λογαριασμό για κάθε
επισκέπτη** (τυχαίος κωδικός), ή δένει το ticket σε όποιον έχει το ίδιο email (αντίθετο του §3 Δ: θα εμφάνιζε σε
λογαριασμό tickets που έστειλε όποιος έγραψε το email του)· δεν κρατά `tid`, καταστάσεις πέρα από 3, σημειώσεις,
συνημμένα, merged, email τμημάτων· δεν ξανατρέχει (`insert` με τα ίδια ids). **Ξαναγράφεται** ως `TicketImport`, όπως
ο `CatalogImport`: re-runnable με `whmcs_id` (τμήματα, tickets, μηνύματα, σημειώσεις), parity ανά τμήμα και status
(`importer.md`):
- Τμήματα → `ticket_departments` με το email τους· **τα IMAP credentials δεν αντιγράφονται** (ξαναμπαίνουν από το
  staff στο cut-over, CLAUDE.md: μόνο read-only στο WHMCS).
- Tickets με `userid` → ο λογαριασμός του login· **`userid = 0` → ticket επισκέπτη** (`guest_name`, `guest_email`),
  ποτέ νέος λογαριασμός, ποτέ αυτόματο δέσιμο με email.
- Απαντήσεις → μηνύματα (staff από `admin`, όπως σήμερα· επισκέπτη χωρίς user)· σημειώσεις → εσωτερικά μηνύματα·
  merged → στο ticket που τα κράτησε το WHMCS, με σημείωση.
- Συνημμένα: αντιγραφή από τον φάκελο attachments του WHMCS (read-only) στον ιδιωτικό δίσκο, με τα όρια του §3 ΣΤ ως
  έλεγχο· ό,τι λείπει ή δεν περνά καταγράφεται στο parity, δεν σταματά το import.
- Το περιεχόμενο (`message`) περνά από το ίδιο escape με τα νέα (WHMCS γράφει HTML/markdown).

**Απαντήσεις σε παλιά emails μετά το cut-over:** τα Message-ID του WHMCS δεν τα ξέρουμε, άρα κατά τον §3 Α πάνε σε
καραντίνα. Η καραντίνα **προτείνει** το ticket όταν το θέμα έχει `[Ticket ID: {tid}]` και ο αποστολέας είναι ο κάτοχος
(και DMARC pass όπου απαιτείται): το staff επισυνάπτει με ένα κλικ. Χωρίς αυτόματη επισύναψη (§7).

**Cut-over:** σβήνει το piping του WHMCS (ή αλλάζει το mailbox) την ίδια ώρα που ανοίγει το δικό μας· ένα mailbox
δεν διαβάζεται από δύο. Το `\Seen` μόνο μετά από επιτυχία (§2) σημαίνει ότι ό,τι δεν πέρασε ξαναδοκιμάζεται.

## 6. PRs (καθένα: code review **και** adversarial review, όπως τα χρήματα, λόγω §0)
- **Α (σε τρία PRs: Α1 θεμέλια — τμήματα, καταστάσεις, tickets επισκέπτη, αριθμός· Α2 εισερχόμενα και καραντίνα·
  Α3 εξερχόμενα και ειδοποιήσεις staff). Πυρήνας email και tickets επισκέπτη.** Από φρέσκο main (με το `bin/test` του #229). Σχήμα §5 (τμήματα ως
  πίνακας με mailbox, 5 καταστάσεις, tickets επισκέπτη), `packages/ticket-mail`, router με §3 Α–Ε, εξερχόμενα με
  τυχαία IDs και ειδοποίηση staff, καραντίνα στο admin, αντικατάσταση του `FetchEmails`. Tests: πλαστογραφημένο `From`,
  `Authentication-Results` από τον αποστολέα, μαντεμένο/αριθμητικό `In-Reply-To`, tag στο θέμα χωρίς κλειδί, CC
  injection, auto-reply/bounce/loop, email επιβεβαίωσης τρίτου site, dedup, συνημμένα εκτός ορίων, αποτυχία ενός
  μηνύματος. Piping μένει κλειστό ώσπου να μπει και το Β.
- **Β. Φόρμα «Επικοινωνία» χωρίς login.** §3 Ζ, email επιβεβαίωσης, η «Επικοινωνία» του μενού δείχνει εκεί για
  επισκέπτες (για logins παραμένει το «νέο ticket»).
- **Γ. Σελίδα επισκέπτη με υπογεγραμμένο link** (§3 Δ): ανάγνωση και απάντηση, λήξη, ακύρωση.
- **Δ. `TicketImport`** (§5α): μετά το Α (χρειάζεται τμήματα, καταστάσεις και tickets επισκέπτη). Parity με τα νούμερα
  του §5α. Πριν από το cut-over.
- Μετά: εσωτερικές σημειώσεις, έτοιμες απαντήσεις, merge, CSAT, email ανά τμήμα (τμήματα ως πίνακας).

## 6α. Α1: τι έγινε (2026-10-20)
- `ticket_departments` (όνομα, σειρά, κρυφό, μόνο πελάτες, email, `whmcs_id`), Configuration → Ticket Departments·
  η λίστα της ρύθμισης έγινε γραμμές (σε νέα εγκατάσταση Support και Sales, όπως το default της). Τμήμα με tickets
  κρύβεται, δεν σβήνεται. Το API δέχεται `department_id` και, για συμβατότητα, το όνομα.
- 5 καταστάσεις (`Ticket::STATUS_*`, `statusLabel()`/`statusColor()` σε ένα σημείο· τα `match` χωρίς default που θα
  έσκαγαν με νέα κατάσταση έφυγαν). Μεταβάσεις στο `TicketMessageCreatedListener`· εσωτερική σημείωση: τίποτα.
- Ticket επισκέπτη στο σχήμα (`client_id` nullable, `guest_name`, `guest_email`, `opened_via`): οι policies δεν το
  δίνουν σε κανέναν λογαριασμό (ούτε με το ίδιο email· test με mutation)· admin: «Επισκέπτης» στη λίστα, φίλτρο,
  «Link to client». Τίποτα δεν φτιάχνει ακόμα ticket επισκέπτη (Α2, Β). Email προς επισκέπτη: Α3.
- `reference` με μάσκα WHMCS (`ticket_reference_mask`, `TicketReference`)· τα υπάρχοντα κρατούν το id τους· portal,
  αναζήτηση και templates (`ticketMessage.ticket.reference`, και στα αποθηκευμένα) δείχνουν τον αριθμό.
- `ticket_messages`: `email_message_id` (unique), `via`, `is_internal`, `body_original`, για τα Α2/Α3.
- Ο παλιός importer προσαρμόστηκε μόνο ώστε να μη σπάσει (τμήματα με `whmcs_id`, καταστάσεις, `tid` → `reference`)·
  ο κανονικός είναι το PR Δ.
- Reviews (#239, διορθώσεις στο επόμενο PR): οι εσωτερικές σημειώσεις και τα αρχεία τους δεν φτάνουν ποτέ στον πελάτη
  (`Ticket::publicMessages()`: σελίδα, λίστα, widget, policy συνημμένων) ούτε κρατούν ανοιχτό ticket στο auto-close.
  Ticket του WHMCS χωρίς λογαριασμό έρχεται ως επισκέπτη, ποτέ στον λογαριασμό με το ίδιο email (§3 Δ). Αριθμός που
  πιάστηκε ταυτόχρονα ξανατραβιέται· μάσκα με ≥ 100.000 αριθμούς. Το API δέχεται ακόμα `replied`, `normal` και όνομα
  τμήματος, και δίνει ξανά το όνομα. Ανοιχτό για το Δ: `tid` του WHMCS που συμπίπτει με υπάρχοντα αριθμό.

## 6β. Α2: τι έγινε (2026-10-20)
- **`packages/ticket-mail`** (χωρίς Laravel, 30 tests): `Parser` (raw RFC 822 → `InboundEmail`, ένας parser για IMAP,
  καραντίνα και tests), `AuthenticationResults`, `Automation` (§3 Ε), `MessageId` (`tk.{128 bit}@domain`),
  `Attachments` (λίστα τύπων με έλεγχο **και του περιεχομένου** με libmagic, 20 MB/αρχείο, 5/μήνυμα, 25 MB/email),
  `HtmlToText`, `ReplyText`, και το `Imap\ImapEngineMailbox`.
- **Απόφαση: `directorytree/imapengine` + `zbateson/mail-mime-parser`** (ήταν ήδη εξαρτήσεις) αντί για το webklex του
  Ekdosi: ένα λιγότερο πακέτο, και το routing ελέγχεται με `.eml` χωρίς το αντικείμενο του IMAP client. Πρώτα headers
  και μέγεθος, μετά κάθε σώμα χωριστά (`BODY.PEEK`)· πάνω από 40 MB μόνο τα headers, στην καραντίνα.
- **Απόφαση: μετρά μόνο το πρώτο (επάνω) `Authentication-Results`** και μόνο αν έχει το `authserv_id` του τμήματος. Ο
  server μας γράφει το δικό του πάνω από ό,τι έστειλε ο αποστολέας, άρα ένα πλαστό με το ίδιο id είναι από κάτω και
  αγνοείται. Προϋπόθεση: ο server γράφει το header σε **κάθε** μήνυμα (mailcow/rspamd ναι). Αν όχι, το «Απαιτείται
  DMARC» μένει ναι και οι απαντήσεις πάνε στην καραντίνα (κλείνει με ασφάλεια). **Πριν ανοίξει το polling:** ένα
  πραγματικό email στο mailbox, και έλεγχος ότι το επάνω header είναι του mailcow.
- Τμήμα: IMAP host/port/SSL ή STARTTLS (ποτέ χωρίς κρυπτογράφηση)/login/κωδικός (`encrypted`, εκτός audit, δεν
  ξαναδείχνεται)/φάκελος, `authserv_id`, «Απαιτείται DMARC» (default ναι), «Διάβασε το mailbox» (default όχι),
  «Test connection». `app:fetch-emails` κάθε 5' για όσα έχουν polling.
- `InboundRouter` (σειρά στο docblock): dedup με Message-ID, blocklist, καραντίνα για χωρίς/πολλούς αποστολείς, δική
  μας διεύθυνση, αυτοματισμούς, γίγαντες· απάντηση μόνο με δικό μας Message-ID **σε μήνυμα που στείλαμε** (το
  Message-ID ενός εισερχόμενου είναι επιλογή του αποστολέα, ποτέ κλειδί), μόνο από τον κάτοχο (email επισκέπτη ή
  login του λογαριασμού με δικαίωμα tickets), και με DMARC όπου απαιτείται· αριθμός ticket στο θέμα χωρίς κλειδί →
  καραντίνα με πρόταση μόνο για τον κάτοχο· νέο ticket σε λογαριασμό μόνο με DMARC και login ενός λογαριασμού, αλλιώς
  επισκέπτη· 10 νέα/ώρα ανά αποστολέα.
- Καραντίνα (Support → Mail Quarantine, δικαίωμα `ticket_quarantine`): ανάγνωση ως κείμενο, «Attach to a ticket»
  (ξένος αποστολέας → «(Από x@y)» μπροστά), «Open a new ticket» (επισκέπτη ή σε λογαριασμό), «Dismiss», «Block the
  sender». Μία φορά η καθεμία (κλείδωμα γραμμής). Το raw στον ιδιωτικό δίσκο· τα χειρισμένα σβήνονται σε 90 μέρες.
  Blocked Senders: διεύθυνση ή `@domain`.
- Ίδιοι κανόνες αρχείων στο portal και στο admin· τα αρχεία κατεβαίνουν πάντα ως `attachment` με `nosniff` και
  sandbox CSP· προεπισκόπηση μόνο jpeg/png/gif/webp.
- Health `ticket_mail` (και στο MCP): FAIL αν mailbox με polling δεν διαβάστηκε 30' ή δεν συνδέθηκε· WARN για
  καραντίνα που περιμένει πάνω από μέρα. `ticket_poll_runs` 30 μέρες.
- Έφυγαν: το `FetchEmails` (αριθμητικό `In-Reply-To`, εμπιστοσύνη στο `From`), οι ρυθμίσεις `ticket_mail_*`, ο
  `ticket_mail_logs`. Τα εξερχόμενα έχουν ακόμα τα παλιά Message-ID, που **δεν** νηματοποιούν: τα τυχαία, το Reply-To
  του τμήματος και ειδοποιήσεις staff είναι το Α3. Γι' αυτό **το polling μένει κλειστό ως το Α3** (μια απάντηση σε
  email μας δεν θα έβρισκε κλειδί και θα γινόταν νέο ticket).

## 7. Αποφάσεις και ανοιχτά
Αποφασίστηκε (owner, 2026-10-20):
- Χωρίς `mailbox+token@` (§3 Α).
- Mailbox ανά τμήμα (support@, sales@· 2–3 τμήματα), §5.
- `Authentication-Results`: ο mailcow (myip) το γράφει· το cPanel (orion, nixpal) όχι → «Απαιτείται DMARC» ανά
  mailbox (§3 Β), σύσταση mailboxes της nixpal στο mailcow.
- Το έργο ξεκινά από φρέσκο main.

- **In Progress** του WHMCS → `on_hold` (5 καταστάσεις· το «σε εξέλιξη» φαίνεται από την ανάθεση).
- Απαντήσεις σε παλιά emails του WHMCS: **μόνο πρόταση** στην καραντίνα, καμία αυτόματη επισύναψη.
- Υπογεγραμμένο link επισκέπτη: **30 μέρες**, νέο με κάθε απάντηση staff.
- Αριθμός νέων tickets: **όπως το WHMCS της εταιρείας** (myip 6 χαρακτήρες· nixpal η μορφή του).

Ανοιχτά: κανένα για το PR Α.
