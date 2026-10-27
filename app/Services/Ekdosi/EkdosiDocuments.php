<?php

namespace App\Services\Ekdosi;

use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
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

    /** The invoice is not what Ekdosi read: merged away, split, or its lines changed since (R2-3 of #459). */
    public const CHANGED = 'invoice_changed';

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: array<string, mixed>} the HTTP status and the answer
     */
    public function record(array $body): array
    {
        $invoiceId = filter_var($body['invoice_id'] ?? $body['whmcs_invoice_id'] ?? null, FILTER_VALIDATE_INT);
        // AADE's MARK is a number: «0400…» is «400…», never a second one (ADV-5); «0» is none.
        // A string or a number only: «true» is no MARK «1» (R2-7).
        $sent = $body['mark'] ?? null;
        $mark = is_string($sent) || is_int($sent) ? ltrim(trim((string) $sent), '0') : '';
        if ($invoiceId === false || $invoiceId < 1 || !preg_match('/^[1-9]\d{0,31}$/', $mark)) {
            return [400, ['error' => 'bad_request', 'message' => 'mark requires {"invoice_id": <int>, "mark": "<digits>"}.']];
        }
        $code = is_scalar($body['code'] ?? $body['invcode'] ?? null) ? mb_substr(trim((string) ($body['code'] ?? $body['invcode'])), 0, 40) : '';
        $state = is_string($body['state'] ?? null) ? strtolower(trim($body['state'])) : '';
        $state = in_array($state, [EkdosiDocument::ACTIVE, EkdosiDocument::CANCELLED], true) ? $state : null;
        $pdfUrl = $this->pdfUrl($body['pdf_url'] ?? null, (int) $invoiceId);

        $fingerprint = is_string($body['fingerprint'] ?? null) ? $body['fingerprint'] : null;
        if (!Invoice::query()->whereKey($invoiceId)->whereNotNull('client_id')->exists()) {
            return [404, ['error' => self::NOT_FOUND, 'invoice_id' => $invoiceId]];
        }

        // Two write-backs of the same MARK at once: the unique index lets one in, the other updates it.
        for ($attempt = 1; ; $attempt++) {
            try {
                // Attempts 3: two first write-backs of one MARK under MariaDB deadlock on the gap lock, not the index.
                return DB::transaction(function () use ($invoiceId, $mark, $code, $state, $pdfUrl, $fingerprint) {
                    // The invoice locked as the payment paths and a merge or split lock it: one of those in flight is
                    // finished before this reads it, and none starts until this commits (R2-8).
                    $invoice = (new RecordPaymentService)->lockInvoice($invoiceId);
                    $document = EkdosiDocument::query()->where('mark', $mark)->lockForUpdate()->first();
                    if ($document !== null && (int) $document->invoice_id !== $invoiceId) {
                        return [409, ['error' => self::OTHER_INVOICE, 'invoice_id' => $document->invoice_id]];
                    }
                    // A new document must be for the invoice as Ekdosi read it: else its lines are elsewhere, or some
                    // are, and would reach no document or a second one. An update (a cancellation) of one already
                    // here is always taken.
                    if ($document === null && ($why = $this->changed($invoice, $fingerprint)) !== null) {
                        return [409, ['error' => self::CHANGED, 'invoice_id' => $invoiceId, 'message' => $why]];
                    }
                    $document ??= new EkdosiDocument(['invoice_id' => $invoiceId, 'mark' => $mark, 'state' => EkdosiDocument::ACTIVE]);
                    // A cancellation at AADE is final: a late or replayed «active» never brings it back (ADV-4).
                    if ($document->state === EkdosiDocument::CANCELLED) {
                        $state = null;
                    }
                    // What is not sent stays as it was: an older Ekdosi without code or PDF never wipes them.
                    $document->fill(array_filter(['code' => $code, 'state' => $state, 'pdf_url' => $pdfUrl], fn ($value) => $value !== null && $value !== ''));
                    $created = !$document->exists;
                    $document->save();
                    // Its document issued, a «Send to Ekdosi» (E5) is done: one cancelled at AADE later is not asked for
                    // again on the old request (R3-2 of #478); staff send it again.
                    if ($created && $document->state === EkdosiDocument::ACTIVE) {
                        EkdosiRequest::forget($invoiceId, 'issued');
                    }

                    return [200, ['status' => 'ok', 'created' => $created, 'invoice_id' => $invoiceId, 'mark' => $mark, 'state' => $document->state]];
                }, 3);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Why the invoice is not the one Ekdosi read, or null: merged into another, split (the split off lines on an invoice of
     * their own), or with the fingerprint Ekdosi read (`mycs.fingerprint`) no longer its own. Without a fingerprint
     * (an Ekdosi before K1) only a merge or a split is seen.
     */
    private function changed(Invoice $invoice, ?string $fingerprint): ?string
    {
        if ($invoice->merged_into_id !== null) {
            return 'Merged into invoice ' . $invoice->merged_into_id . ': its lines are there.';
        }
        if ($fingerprint !== null) {
            // Locking reads: a plain one would see the snapshot opened before the locks were waited for, not a merge or a
            // line change committed meanwhile (final review of #459).
            $invoice->setRelation('items', $invoice->items()->lockForUpdate()->get());
            $invoice->setRelation('adjustmentNotes', $invoice->adjustmentNotes()->lockForUpdate()->get());

            return hash_equals(EkdosiFeed::fingerprint($invoice), $fingerprint) ? null : 'Its lines changed since it was read: read it again.';
        }
        if (Invoice::query()->where('split_from_id', $invoice->id)->lockForUpdate()->exists()) {
            return 'Split since: part of its lines are on another invoice. Read it again (with its fingerprint).';
        }

        return null;
    }

    /**
     * Only a link on Ekdosi itself (its address in the settings), so even the secret's holder cannot leave one elsewhere.
     * Kept as rebuilt from its parts, never as sent: no userinfo, backslash or whitespace, where PHP and a browser read
     * the host differently (ADV-1: «https://evil\\@ekdosi/…» is evil to a browser), and under Ekdosi's own path.
     */
    private function pdfUrl(mixed $value, int $invoiceId): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        $base = parse_url((string) config('settings.ekdosi_url', ''));
        $ours = strtolower((string) ($base['host'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $basePath = rtrim((string) ($base['path'] ?? ''), '/') . '/';
        $fine = $parts !== false && $ours !== '' && $host === $ours && strlen($url) <= 1000
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && ($parts['port'] ?? 443) === ($base['port'] ?? 443)
            && !preg_match('/[\\\\@\s\x00-\x1f\x7f]/', $url)
            && str_starts_with($path, $basePath) && !self::dotSegment($path);
        if (!$fine) {
            // Not silently: a moved Ekdosi would otherwise lose every PDF link with no clue.
            Log::warning('Ekdosi: a PDF link not on Ekdosi\'s address was left out', ['invoice_id' => $invoiceId, 'host' => $host, 'expected' => $ours]);

            return null;
        }

        return 'https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** A «.» or «..» segment a browser resolves, also written %2e (R2-5): it would lead out of Ekdosi's path. */
    private static function dotSegment(string $path): bool
    {
        foreach (explode('/', str_ireplace('%2e', '.', $path)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }
}
