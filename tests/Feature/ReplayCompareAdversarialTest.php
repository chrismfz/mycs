<?php

namespace Tests\Feature;

use App\Services\Import\WhmcsReplayCompare;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #567: things WHMCS did (or the simulation did) that mycs:replay-compare must NOT report as the same
 * (a false «same» is what the team would trust before the cut-over), or must not report as different when they are the same.
 * Each test asserts what the tool SHOULD say. Invented data; the WHMCS tables carry the columns the real ones have.
 */
class ReplayCompareAdversarialTest extends TestCase
{
    private PDO $before;

    private PDO $after;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->before, $this->after] = [$this->whmcs(), $this->whmcs()];
        foreach ([$this->before, $this->after] as $pdo) {
            $pdo->exec("INSERT INTO tblinvoices VALUES (100, 7, '2026-11-01', 10, 0, 0, 0, 'Unpaid')");
            $pdo->exec("INSERT INTO tblhosting VALUES (1, 7, 'Active', '2026-11-01')");
            $pdo->exec("INSERT INTO tbldomains VALUES (1, 7, 'example.test', 'Active', '2026-11-01', '2026-11-01')");
            $pdo->exec('INSERT INTO tblaccounts VALUES (50, 100, 0, 0)');
            $pdo->exec("INSERT INTO tblemails VALUES (500, 7, 'Customer Invoice')");
        }
    }

    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, duedate TEXT, subtotal REAL, tax REAL, tax2 REAL, credit REAL, status TEXT)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, nextduedate TEXT)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, domain TEXT, status TEXT, expirydate TEXT, nextduedate TEXT)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INT, amountin REAL, amountout REAL)',
            'CREATE TABLE tblemails (id INTEGER PRIMARY KEY, userid INT, subject TEXT)',
        ] as $sql) {
            $pdo->exec($sql);
        }

        return $pdo;
    }

    /** @param list<array{day?: string, event: string, ref: string, detail: string}> $ledger */
    private function compare(array $ledger): array
    {
        return (new WhmcsReplayCompare($this->before, $this->after, $ledger))->run();
    }

    /** Every item the tool lists as a difference on one side, over all groups. @return list<string> */
    private function listed(array $result, string $side): array
    {
        $out = [];
        foreach ($result as $group) {
            $out = [...$out, ...$group[$side]];
        }

        return $out;
    }

    private function assertListed(array $result, string $side, string $pattern, string $why): void
    {
        $this->assertNotEmpty(
            array_filter($this->listed($result, $side), fn (string $item) => preg_match($pattern, $item) === 1),
            $why . ' - the tool listed: ' . json_encode($this->listed($result, $side), JSON_UNESCAPED_UNICODE),
        );
    }

    private function line(string $event, string $ref, string $detail): array
    {
        return ['day' => '2026-11-01', 'event' => $event, 'ref' => $ref, 'detail' => $detail];
    }

    /** ADV-567-1: an invoice WHMCS cancelled (or refunded, or put in Collections) between the copies is not compared. */
    public function test_an_invoice_whmcs_cancelled_between_the_copies_is_reported(): void
    {
        $this->after->exec("UPDATE tblinvoices SET status = 'Cancelled' WHERE id = 100");

        $result = $this->compare([]);

        $this->assertListed($result, 'only_whmcs', '/#100.*cancel/i', 'WHMCS cancelled invoice #100 and the simulation did not');
    }

    /** ADV-567-1: and the ledger's own `invoice status` events are never read. */
    public function test_an_invoice_status_change_in_the_ledger_is_reported(): void
    {
        $result = $this->compare([$this->line('invoice status', 'invoice #100', 'unpaid → cancelled')]);

        $this->assertListed($result, 'only_mycs', '/#100.*cancel/i', 'the simulation cancelled invoice #100 and WHMCS did not');
    }

    /** ADV-567-2: tbldomains is never read: a domain WHMCS renewed (expirydate +1 year) while the simulation did nothing is «same». */
    public function test_a_domain_whmcs_renewed_is_reported(): void
    {
        $this->after->exec("UPDATE tbldomains SET expirydate = '2027-11-01', nextduedate = '2027-11-01' WHERE id = 1");

        $result = $this->compare([]);

        $this->assertListed($result, 'only_whmcs', '/domain|example\.test/i', 'WHMCS renewed domain #1 and the simulation did not');
    }

    /** ADV-567-3: a mail the cron sends under a template that is not in MYCS_MAILS is dropped, so «mails» looks the same. */
    public function test_a_mail_kind_the_simulation_sent_that_is_not_mapped_is_reported(): void
    {
        $result = $this->compare([
            $this->line('mail', 'client #7', 'card_payment_due (sent)'),
            $this->line('mail', 'client #7', 'domain_expired_notice (sent)'),
            $this->line('mail', 'client #7', 'service_hold_ending (sent)'),
        ]);

        $this->assertNotEmpty($result['mails']['only_mycs'], 'three cron mails of the simulation, none of WHMCS: not the same');
    }

    /** ADV-567-3: and the same for a WHMCS cron mail whose subject is not in WHMCS_MAILS. */
    public function test_a_whmcs_cron_mail_with_an_unmapped_subject_is_reported(): void
    {
        $this->after->exec("INSERT INTO tblemails VALUES (501, 7, 'Credit Card Payment Due'), (502, 7, 'Credit Card Payment Failed')");

        $result = $this->compare([]);

        $this->assertNotEmpty($result['mails']['only_whmcs'], 'WHMCS sent two payment mails the simulation did not: not the same');
    }

    /** ADV-567-4: mails are counted per kind; to whom is thrown away (tblemails.userid and the ledger's «client #N» are both there). */
    public function test_the_same_mail_kind_to_a_different_client_is_a_difference(): void
    {
        $this->after->exec("INSERT INTO tblemails VALUES (501, 5, 'First Invoice Overdue Notice')");

        $result = $this->compare([$this->line('mail', 'client #6', 'invoice_overdue (sent)')]);

        $this->assertNotEmpty(
            [...$result['mails']['only_whmcs'], ...$result['mails']['only_mycs']],
            'WHMCS warned client 5 and the simulation client 6: reported as the same',
        );
    }

    /** ADV-567-5: WHMCS keeps credit on tblinvoices.credit and writes no tblaccounts row; the ledger's «from credit» payment is then «only the simulation». */
    public function test_an_invoice_paid_from_credit_is_the_same_in_both(): void
    {
        $this->after->exec("INSERT INTO tblinvoices VALUES (101, 9, '2026-11-08', 3.0, 0.6, 0, 3.6, 'Paid')");

        $result = $this->compare([
            $this->line('invoice created', 'invoice #101', 'client #9, total 3.60, due 2026-11-08'),
            $this->line('payment', 'invoice #101', 'paid 3.60 (succeeded) from credit'),
        ]);

        $this->assertSame([], $result['payments']['only_mycs'], 'WHMCS paid invoice 101 from the client\'s credit too');
        $this->assertSame([], $this->listed($result, 'only_whmcs'));
    }

    /** ADV-567-6: invoices are matched by content so that ids may differ, payments by invoice id, so a new invoice paid in the same step breaks. */
    public function test_a_new_invoice_paid_at_once_is_the_same_whatever_its_id(): void
    {
        $this->after->exec("INSERT INTO tblinvoices VALUES (101, 9, '2026-11-08', 3.0, 0.6, 0, 0, 'Paid')");
        $this->after->exec('INSERT INTO tblaccounts VALUES (51, 101, 3.6, 0)');

        $result = $this->compare([
            $this->line('invoice created', 'invoice #105', 'client #9, total 3.60, due 2026-11-08'),
            $this->line('payment', 'invoice #105', 'paid 3.60 (succeeded)'),
        ]);

        $this->assertSame(1, $result['invoices made']['both']);
        $this->assertSame([], $result['payments']['only_whmcs'], 'the same invoice, paid the same: only its id differs');
        $this->assertSame([], $result['payments']['only_mycs']);
    }

    /** ADV-567-7: a ledger of several days against one pair of copies: a suspension and its lifting are two events here and none there. */
    public function test_a_suspension_lifted_inside_the_stretch_is_not_a_difference(): void
    {
        $result = $this->compare([
            ['day' => '2026-11-01', 'event' => 'service status', 'ref' => 'service #1', 'detail' => 'active → suspended'],
            ['day' => '2026-11-03', 'event' => 'service status', 'ref' => 'service #1', 'detail' => 'suspended → active'],
        ]);

        $this->assertSame([], $result['services: status']['only_mycs'], 'WHMCS ends where it began: Active');
    }
}
