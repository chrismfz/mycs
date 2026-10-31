<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * A product's own welcome email in WHMCS (a template staff wrote: `tblproducts.welcomeemail` → a `tblemailtemplates` row with
 * `custom` = 1) as the product's snippet, the `{{ product_info }}` of the «service activated» email (owner, 2026-10-31: the
 * ZoneCloud one, from the products once, not a general Smarty converter).
 *
 * MyCS's own email already says who the customer is, the product, the payment method, the amount, the cycle and the next due
 * date, and ends with the signature; so what is kept of a WHMCS template is only its other lines (an instruction, a link),
 * and «License Key: {$service_domain}» of a licensing product (WHMCS keeps the key in the service's domain) as the key of the
 * license. A line with any other Smarty variable is dropped and said. A product that has a snippet here keeps it.
 */
final class WhmcsWelcomeSnippetImport
{
    /** Said by the email already. */
    private const STANDARD = ['client_name', 'service_product_name', 'service_payment_method', 'service_recurring_amount', 'service_billing_cycle',
        'service_next_due_date', 'service_first_payment_amount', 'service_username', 'service_password', 'service_dedicated_ip', 'signature'];

    /** @var array<string, int> */
    private array $counts = ['products' => 0, 'snippets' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        try {
            $rows = $this->whmcs->query('SELECT p.id, p.name, t.id AS template, t.name AS template_name, t.message
                FROM tblproducts p JOIN tblemailtemplates t ON t.id = p.welcomeemail WHERE t.custom = 1 ORDER BY p.id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        foreach ($rows as $row) {
            $this->counts['products']++;
            // The full import keeps WHMCS's product ids; only the catalog import also writes `whmcs_id`.
            $product = DB::table('products')->where('whmcs_id', (int) $row['id'])->first(['id', 'email_template'])
                ?? DB::table('products')->where('id', (int) $row['id'])->whereNull('whmcs_id')->first(['id', 'email_template']);
            if ($product === null || trim((string) $product->email_template) !== '') {
                $this->counts['skipped']++;

                continue;
            }
            $licensing = $this->isLicensing((int) $product->id);
            $snippet = $this->snippet((string) $row['message'], $licensing, "Product #{$row['id']} «{$row['name']}» (template «{$row['template_name']}»)");
            if ($snippet === '') {
                $this->counts['skipped']++;

                continue;
            }
            DB::table('products')->where('id', $product->id)->update(['email_template' => $snippet]);
            $this->counts['snippets']++;
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** A product sold through the nixpal licensing server: its service's «domain» in WHMCS is the license key. */
    private function isLicensing(int $productId): bool
    {
        return DB::table('products')->join('extensions', 'extensions.id', '=', 'products.server_id')
            ->where('products.id', $productId)->where('extensions.extension', 'NixpalLicensing')->exists();
    }

    private function snippet(string $message, bool $licensing, string $label): string
    {
        $text = (string) preg_replace('~</p>|<br\s*/?>|</div>|</li>~i', "\n", $message);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $kept = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s{2,}/u', ' ', str_replace("\u{00A0}", ' ', $line)));
            if ($line === '' || preg_match('/^(thank you|dear)\b/i', $line) === 1 || preg_match('/^your order for\b/i', $line) === 1) {
                continue;
            }
            preg_match_all('/\{\$([a-z0-9_]+)\}/i', $line, $vars);
            $names = array_map('strtolower', $vars[1]);
            if (array_intersect($names, self::STANDARD) !== []) {
                continue; // the email says it
            }
            if ($names === ['service_domain'] && $licensing && preg_match('/^license key\s*:/i', $line) === 1) {
                $kept[] = '**License key:** `{{ service.license.license_key }}`';

                continue;
            }
            if ($names !== []) {
                $this->notes[] = "{$label}: a line with {\$" . implode('}, {$', $names) . '} is not carried (a variable this app has no counterpart for): add it to the product\'s snippet by hand.';

                continue;
            }
            // A link as Markdown takes it; a stray brace would be read as a template.
            $line = str_replace(['{', '}'], ['&#123;', '&#125;'], $line);
            $kept[] = (string) preg_replace('~(?<![<(\[])\bhttps?://[^\s<>\)]+~i', '<$0>', $line);
        }

        return implode("\n\n", $kept);
    }
}
