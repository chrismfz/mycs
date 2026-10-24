<?php

namespace MyCS\Extensions\Gateways\BankTransfer;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use App\Services\Invoice\PaymentReminders;
use App\Services\Money\BankTransferDetails;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;

/**
 * Bank transfer (myip, 43% of its payments; owner 2026-10-21): an offline method. It shows the company's bank accounts and
 * asks the customer to write the invoice's (proforma's) number in the transfer's reference; staff record the payment when
 * it reaches the bank (Add payment), as they do now. The same details go on an unpaid invoice's PDF and mail
 * (BankTransferDetails). Nothing is sent anywhere and nothing is paid from here.
 */
#[ExtensionMeta(
    name: 'Bank transfer',
    description: 'Show your bank accounts for a payment by bank transfer; staff record the payment when it arrives.',
    version: 'builtin',
    author: 'MyCS',
)]
class BankTransfer extends Gateway
{
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'accounts',
                'label' => 'Bank accounts',
                'type' => 'textarea',
                'required' => true,
                'description' => 'As the customer should read them: bank, beneficiary, IBAN, BIC; one account after the other, a blank line between them.',
            ],
            [
                'name' => 'currencies',
                'label' => 'Currencies',
                'type' => 'text',
                'required' => false,
                'description' => 'The currencies these accounts take, comma separated (EUR). Empty: any.',
            ],
            [
                'name' => 'show_on_invoices',
                'label' => 'Also on unpaid invoices (PDF and mail)',
                'type' => 'checkbox',
                'required' => false,
                'default' => true,
            ],
            // The banks' alert emails (Payments tracking, owner 2026-10-24): read, never moved, marked or deleted.
            ['name' => 'alerts_help', 'type' => 'placeholder', 'label' => new HtmlString('<span class="text-sm">'
                . e('Payments tracking: MyCS reads the Eurobank and Piraeus alert emails of this mailbox (credits and debits) without changing anything in it. What is already there when it first reads a folder counts for the balances only, never as a new payment.')
                . '</span>')],
            ['name' => 'alerts_enabled', 'label' => 'Read the bank alert emails', 'type' => 'checkbox', 'required' => false, 'default' => false],
            ['name' => 'alerts_host', 'label' => 'Alerts mailbox: IMAP host', 'type' => 'text', 'required' => false, 'placeholder' => 'mail.example.com'],
            ['name' => 'alerts_port', 'label' => 'IMAP port', 'type' => 'number', 'required' => false, 'default' => 993],
            ['name' => 'alerts_encryption', 'label' => 'Encryption', 'type' => 'select', 'required' => false, 'default' => 'ssl',
                'options' => ['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS']],
            ['name' => 'alerts_username', 'label' => 'IMAP username', 'type' => 'text', 'required' => false],
            ['name' => 'alerts_password', 'label' => 'IMAP password', 'type' => 'password', 'required' => false, 'encrypted' => true],
            ['name' => 'alerts_folders', 'label' => 'Folders', 'type' => 'text', 'required' => false, 'default' => 'INBOX',
                'description' => 'Comma separated (INBOX, Checked). A mail moved from one to another is read once.'],
            ['name' => 'alerts_authserv_id', 'label' => 'Our mail server (authserv-id)', 'type' => 'text', 'required' => false, 'placeholder' => 'mail.example.com',
                'description' => 'The name our server writes in Authentication-Results. Only a mail it vouches the bank sent counts for the balances and the matching.'],
            ['name' => 'alerts_arc_sealer', 'label' => 'Our ARC sealing domain', 'type' => 'text', 'required' => false, 'placeholder' => 'example.com',
                'description' => 'When the alerts reach the mailbox through a forward inside our server (sieve redirect): the domain our server seals with.'],
            ['name' => 'alerts_ignore', 'label' => 'Credits to ignore', 'type' => 'textarea', 'required' => false,
                'description' => 'One per line: a credit whose reference contains it is not for staff (the card acquirer\'s settlements, e.g. its merchant id).'],
        ];
    }

    /**
     * The alerts mailbox's settings, or null when reading is off or not set up.
     *
     * @return array{host: string, port: int, encryption: string, username: string, password: string, folders: list<string>, authserv_id: string, arc_sealer: string, ignore: list<string>}|null
     */
    public function alertSettings(): ?array
    {
        if (!$this->config('alerts_enabled') || blank($this->config('alerts_host')) || blank($this->config('alerts_username'))) {
            return null;
        }
        $list = fn (?string $value, string $by) => array_values(array_filter(array_map('trim', explode($by, (string) $value)), fn ($v) => $v !== ''));

        return [
            'host' => trim((string) $this->config('alerts_host')),
            'port' => (int) ($this->config('alerts_port') ?: 993),
            'encryption' => $this->config('alerts_encryption') === 'starttls' ? 'starttls' : 'ssl',
            'username' => trim((string) $this->config('alerts_username')),
            'password' => (string) $this->config('alerts_password'),
            'folders' => $list($this->config('alerts_folders') ?: 'INBOX', ',') ?: ['INBOX'],
            'authserv_id' => trim((string) $this->config('alerts_authserv_id')),
            'arc_sealer' => trim((string) $this->config('alerts_arc_sealer')),
            'ignore' => $list($this->config('alerts_ignore'), "\n"),
        ];
    }

    /** Offered only in a currency the accounts take (reviews of #287: never a USD invoice told to wire to EUR accounts). */
    public function canUseGateway($total, $currency, $type, $items = [])
    {
        return BankTransferDetails::takes($this->config('currencies'), $currency);
    }

    /**
     * The accounts, what is left to pay and the reference to write: shown in the payment window.
     *
     * @param  float  $total
     * @return \Illuminate\Contracts\View\View
     */
    public function pay(Invoice $invoice, $total)
    {
        // A saved method pays it on its due date: a transfer too would be paid twice (adversarial BT-ADV-3).
        if (PaymentReminders::chargedOnItsOwn($invoice)) {
            return View::file(__DIR__ . '/resources/views/charged.blade.php', ['due' => $invoice->due_at?->format('d/m/Y')]);
        }

        return View::file(__DIR__ . '/resources/views/pay.blade.php', [
            'accounts' => (string) $this->config('accounts'),
            'reference' => BankTransferDetails::reference($invoice),
            'total' => (string) $invoice->formattedRemaining,
        ]);
    }
}
