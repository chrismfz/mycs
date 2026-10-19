# Tickets: επικοινωνία χωρίς login, email και μοντέλο ασφάλειας (σχέδιο, 2026-10-19)

> Κατάσταση: **προς έγκριση**. Τίποτα από εδώ δεν έχει υλοποιηθεί. Σχετικά: `admin-ux.md` («Μενού επισκέπτη»),
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
- Κάθε εξερχόμενο email έχει `Message-ID: <tk.{128 bit τυχαία}@{domain}>`, αποθηκευμένο στο μήνυμα πριν σταλεί.
- Το `Reply-To` είναι `{mailbox}+{token}@{domain}` όπου `token` = 128 bit τυχαία ανά ticket (αποθηκευμένο hash του),
  ανανεώνεται αν το staff το ζητήσει («νέο νήμα»). Αν ο mail server δεν υποστηρίζει `+`, μόνο το Message-ID.
- Μια απάντηση μπαίνει σε ticket **μόνο** αν κάποιο από τα `In-Reply-To`/`References` είναι **ακριβώς** δικό μας
  αποθηκευμένο Message-ID, ή το παραλήπτης-token ταιριάζει. Ποτέ με αριθμό, ποτέ μόνο με θέμα. Το tag στο θέμα
  (`[#ref]`) είναι μόνο για ανθρώπους.

**Β. Το `From` δεν αποδεικνύει ταυτότητα.**
- Διαβάζουμε **μόνο** το `Authentication-Results` που προσθέτει ο **δικός μας** server παραλαβής (ρύθμιση:
  `ticket_mail_authserv_id`, π.χ. `mymail.myip.gr`· οποιοδήποτε άλλο `Authentication-Results` αγνοείται, γιατί το
  γράφει ο αποστολέας).
- Για να μπει σε **υπάρχον** ticket: DMARC `pass` (ή SPF/DKIM `pass` ευθυγραμμισμένο με το domain του `From`)
  **και** το `From` = ο κάτοχος του ticket (email επισκέπτη ή login του λογαριασμού) **και** κλειδί νήματος του Α.
- Αλλιώς: **καραντίνα** (ορατή στο staff με λόγο, «Επισύναψη στο #…» ή «Νέο ticket» με ένα κλικ), ποτέ αυτόματα.

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
- `tickets`: `client_id` **nullable**· `guest_name`, `guest_email` (υποχρεωτικά όταν `client_id` null)· `reference`
  (ανθρώπινο, π.χ. `T-2026-10-19-XXXX`, μόνο για εμφάνιση)· `thread_token_hash`· `opened_via` (portal, form, email,
  staff, api)· `guest_link_secret_hash` (PR Γ).
- `ticket_messages`: `email_message_id` (unique), `via`, `is_internal` (εσωτερική σημείωση), `body_original`.
- `ticket_quarantine`: εισερχόμενα που δεν πέρασαν το §3 (λόγος, headers, ασφαλές preview), με ενέργειες staff.
- `ticket_blocked_senders`, `ticket_poll_runs`.
- Ό,τι σήμερα υποθέτει πελάτη (policies, listener ειδοποιήσεων, admin infolist/στήλες/φίλτρα, ClientSummary, API,
  `TemplateRenderer`, `Client::tickets()`): χειρίζεται τον επισκέπτη (λίστα στο σημερινό inventory).
- Ο importer WHMCS: τα tickets επισκέπτη του WHMCS γίνονται tickets επισκέπτη, όχι νέοι λογαριασμοί (σήμερα φτιάχνει
  user και λογαριασμό: `ImportFromWhmcs` 728-768).

## 6. PRs (καθένα: code review **και** adversarial review, όπως τα χρήματα, λόγω §0)
- **Α. Πυρήνας email και tickets επισκέπτη.** Σχήμα §5, `packages/ticket-mail`, router με §3 Α–Ε, εξερχόμενα με
  τυχαία IDs και ειδοποίηση staff, καραντίνα στο admin, αντικατάσταση του `FetchEmails`. Tests: πλαστογραφημένο `From`,
  `Authentication-Results` από τον αποστολέα, μαντεμένο/αριθμητικό `In-Reply-To`, tag στο θέμα χωρίς κλειδί, CC
  injection, auto-reply/bounce/loop, email επιβεβαίωσης τρίτου site, dedup, συνημμένα εκτός ορίων, αποτυχία ενός
  μηνύματος. Piping μένει κλειστό ώσπου να μπει και το Β.
- **Β. Φόρμα «Επικοινωνία» χωρίς login.** §3 Ζ, email επιβεβαίωσης, η «Επικοινωνία» του μενού δείχνει εκεί για
  επισκέπτες (για logins παραμένει το «νέο ticket»).
- **Γ. Σελίδα επισκέπτη με υπογεγραμμένο link** (§3 Δ): ανάγνωση και απάντηση, λήξη, ακύρωση.
- Μετά: εσωτερικές σημειώσεις, έτοιμες απαντήσεις, merge, CSAT, email ανά τμήμα (τμήματα ως πίνακας).

## 7. Ανοιχτά (για τον owner)
1. Ποιος server παραλαμβάνει το mailbox των tickets και αν γράφει `Authentication-Results` (DMARC/DKIM/SPF): χωρίς
   αυτό, το Β μπορεί μόνο να στέλνει **όλα** τα εισερχόμενα σε υπάρχον ticket σε καραντίνα.
2. Υποστηρίζει ο server διευθύνσεις `mailbox+token@`; (αλλιώς μόνο Message-ID στο Α.)
3. Mailbox ανά εταιρεία ή ανά τμήμα; (Το Ekdosi έχει ανά τμήμα· προτείνω ένα ανά εταιρεία στο Α, ανά τμήμα αργότερα.)
4. Διάρκεια υπογεγραμμένου link επισκέπτη (πρόταση: 30 μέρες, ανανεώνεται με κάθε απάντηση staff).
