# MyCS

Client system (billing, provisioning, domains, support) για **nixpal** και **myip.gr**, που αντικαθιστά το WHMCS.

- **Hard fork του [Paymenter](https://github.com/Paymenter/Paymenter)** στο `5b69d45` (v1.5.8+41, 2026-10-10), χωρίς το
  ιστορικό του upstream. Δεν ακολουθεί το upstream· παίρνουμε μόνο ό,τι θέλουμε με cherry-pick
  (`git fetch upstream && git cherry-pick <sha>`).
- Laravel 13, Filament 5, Livewire 4: το ίδιο stack με το Ekdosi, από όπου έρχονται domains, tickets, υπενθυμίσεις,
  κατανομή πληρωμών και MCP.
- Το Ekdosi μένει η τιμολογιέρα/myDATA. Το MyCS του στέλνει τιμολόγια και πληρωμές, όπως σήμερα το WHMCS.

**Ξεκίνα από το [`docs/README.md`](docs/README.md)**: αποφάσεις, audits, parity με το WHMCS, φάσεις.
Κανόνες για όποιον δουλεύει εδώ: [`CLAUDE.md`](CLAUDE.md).

## Άδεια
MIT. Περιλαμβάνει κώδικα του Paymenter (© Paymenter, MIT, βλ. [`LICENSE`](LICENSE)).
