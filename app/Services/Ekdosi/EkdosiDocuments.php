<?php

namespace App\Services\Ekdosi;

use App\Models\EkdosiDocument;
use App\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The write-back (docs/ekdosi-bridge.md, E1): a fiscal document Ekdosi issued from an invoice, by its MARK. The same MARK
 * again updates it (its code, PDF, or «cancelled» when Ekdosi cancelled it at AADE); a MARK already on another invoice is
 * refused. An invoice with any document is out of the feed.
 */
final class EkdosiDocuments
{
    public const NOT_FOUND = 'invoice_not_found';

    public const OTHER_INVOICE = 'mark_on_another_invoice';

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: array<string, mixed>} the HTTP status and the answer
     */
    public function record(array $body): array
    {
        $invoiceId = filter_var($body['invoice_id'] ?? $body['whmcs_invoice_id'] ?? null, FILTER_VALIDATE_INT);
        $mark = is_scalar($body['mark'] ?? null) ? trim((string) $body['mark']) : '';
        if ($invoiceId === false || $invoiceId < 1 || !preg_match('/^\d{1,32}$/', $mark)) {
            return [400, ['error' => 'bad_request', 'message' => 'mark requires {"invoice_id": <int>, "mark": "<digits>"}.']];
        }
        $code = is_scalar($body['code'] ?? $body['invcode'] ?? null) ? mb_substr(trim((string) ($body['code'] ?? $body['invcode'])), 0, 40) : '';
        $state = is_string($body['state'] ?? null) ? strtolower(trim($body['state'])) : '';
        $state = in_array($state, [EkdosiDocument::ACTIVE, EkdosiDocument::CANCELLED], true) ? $state : null;
        $pdfUrl = $this->pdfUrl($body['pdf_url'] ?? null, (int) $invoiceId);

        if (!Invoice::query()->whereKey($invoiceId)->whereNotNull('client_id')->exists()) {
            return [404, ['error' => self::NOT_FOUND, 'invoice_id' => $invoiceId]];
        }

        // Two write-backs of the same MARK at once: the unique index lets one in, the other updates it.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($invoiceId, $mark, $code, $state, $pdfUrl) {
                    $document = EkdosiDocument::query()->where('mark', $mark)->lockForUpdate()->first();
                    if ($document !== null && (int) $document->invoice_id !== $invoiceId) {
                        return [409, ['error' => self::OTHER_INVOICE, 'invoice_id' => $document->invoice_id]];
                    }
                    $document ??= new EkdosiDocument(['invoice_id' => $invoiceId, 'mark' => $mark, 'state' => EkdosiDocument::ACTIVE]);
                    // What is not sent stays as it was: an older Ekdosi without code or PDF never wipes them.
                    $document->fill(array_filter(['code' => $code, 'state' => $state, 'pdf_url' => $pdfUrl], fn ($value) => $value !== null && $value !== ''));
                    $created = !$document->exists;
                    $document->save();

                    return [200, ['status' => 'ok', 'created' => $created, 'invoice_id' => $invoiceId, 'mark' => $mark, 'state' => $document->state]];
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    /** Only a link on Ekdosi itself (its address in the settings), so even the secret's holder cannot leave one elsewhere. */
    private function pdfUrl(mixed $value, int $invoiceId): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return null;
        }
        $base = (string) config('settings.ekdosi_url', '');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $ours = (string) (parse_url($base, PHP_URL_HOST) ?? '');
        if (strlen($url) > 1000 || !preg_match('#^https://#i', $url) || $ours === '' || strcasecmp($host, $ours) !== 0) {
            // Not silently: a moved Ekdosi would otherwise lose every PDF link with no clue.
            Log::warning('Ekdosi: a PDF link not on Ekdosi\'s address was left out', ['invoice_id' => $invoiceId, 'host' => $host, 'expected' => $ours]);

            return null;
        }

        return $url;
    }
}
