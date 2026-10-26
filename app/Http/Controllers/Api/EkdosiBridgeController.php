<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ekdosi\BadBridgeArgument;
use App\Services\Ekdosi\EkdosiDocuments;
use App\Services\Ekdosi\EkdosiFeed;
use App\Services\Ekdosi\EkdosiPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Ekdosi bridge (docs/ekdosi-bridge.md, E1): one POST, signed as Ekdosi signs its WHMCS bridge
 * (`X-Webhook-Signature: sha256=<HMAC-SHA256 of the raw body>`, the shared secret of the settings), with the plugin's ops:
 *
 * - `ping`: is it there and on (Ekdosi probes before it switches a company over);
 * - `invoices` {status, since, offset, limit}: the Inbox feed (EkdosiFeed::page);
 * - `invoice` {invoice_id}: one, whatever its status;
 * - `resolve` {invoice_id}: only its lines' recipients;
 * - `mark` {invoice_id, mark, fingerprint?, code?, state?, pdf_url?}: the write-back (EkdosiDocuments);
 * - `add_payment` {invoice_id, amount, transid}: a payment Ekdosi received for an invoice issued on credit (EkdosiPayments).
 *
 * Off in the settings: 404, as if there were nothing. The plugin's error codes: 400, 401, 404, 409, 422, 500.
 */
class EkdosiBridgeController extends Controller
{
    private const MAX_BODY = 65536;

    public function __invoke(Request $request, EkdosiFeed $feed, EkdosiDocuments $documents, EkdosiPayments $payments): JsonResponse
    {
        if (!config('settings.ekdosi_enabled')) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $secret = (string) config('settings.ekdosi_secret', '');
        // As the settings ask (32 at least): a short one stored some other way is no secret (adversarial review of #459).
        if (strlen($secret) < 32) {
            return response()->json(['error' => 'secret_not_configured', 'message' => 'Set the shared secret (32 characters at least) in MyCS\'s settings first.'], 422);
        }
        $raw = $request->getContent();
        if ($raw === '') {
            return response()->json(['error' => 'empty_body'], 400);
        }
        if (strlen($raw) > self::MAX_BODY) {
            return response()->json(['error' => 'body_too_large'], 413);
        }
        $header = (string) $request->header('X-Webhook-Signature', '');
        if (!str_starts_with($header, 'sha256=') || !hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7))) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return response()->json(['error' => 'bad_request', 'message' => 'Body must be JSON.'], 400);
        }

        $id = fn () => (int) (filter_var($body['invoice_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        try {
            switch ($body['op'] ?? null) {
                case 'ping':
                    return response()->json(['status' => 'ok', 'source' => 'mycs', 'ops' => ['ping', 'invoices', 'invoice', 'resolve', 'mark', 'add_payment']]);
                case 'invoices':
                    // Sent but not a date string: never the beginning of time instead of the cut-over (R2-7).
                    if (array_key_exists('since', $body) && $body['since'] !== null && !is_string($body['since'])) {
                        throw new BadBridgeArgument('since');
                    }
                    $page = $feed->page(
                        is_string($body['status'] ?? null) ? $body['status'] : 'paid_unfiled',
                        is_string($body['since'] ?? null) ? $body['since'] : null,
                        (int) (filter_var($body['offset'] ?? 0, FILTER_VALIDATE_INT) ?: 0),
                        (int) (filter_var($body['limit'] ?? 100, FILTER_VALIDATE_INT) ?: 100),
                        $this->userIds($body),
                    );

                    return response()->json(['status' => 'ok'] + $page);
                case 'invoice':
                case 'resolve':
                    if ($id() < 1) {
                        return response()->json(['error' => 'bad_request', 'message' => $body['op'] . ' requires {"invoice_id": <int>}.'], 400);
                    }
                    $found = $body['op'] === 'invoice' ? $feed->one($id()) : $feed->routing($id());
                    if ($found === null) {
                        return response()->json(['error' => 'invoice_not_found', 'invoice_id' => $id()], 404);
                    }

                    return response()->json($body['op'] === 'invoice' ? ['status' => 'ok', 'invoice' => $found] : $found);
                case 'mark':
                    [$status, $answer] = $documents->record($body);

                    return response()->json($answer, $status);
                case 'add_payment':
                    [$status, $answer] = $payments->record($body);

                    return response()->json($answer, $status);
                default:
                    return response()->json(['error' => 'unknown_op', 'message' => 'op must be ping, invoices, invoice, resolve, mark or add_payment.'], 400);
            }
        } catch (BadBridgeArgument $e) {
            return response()->json(['error' => 'bad_request', 'message' => 'Invalid ' . $e->getMessage() . '.'], 400);
        } catch (\Throwable $e) {
            report($e);

            // Nothing of the inside to the caller: the log has it.
            return response()->json(['error' => 'query_failed'], 500);
        }
    }

    /**
     * `userids`: only these accounts (E3), a list of up to 1000 ids; absent, all. Anything else is a 400, never all.
     *
     * @param  array<string, mixed>  $body
     * @return list<int>|null
     */
    private function userIds(array $body): ?array
    {
        if (!array_key_exists('userids', $body) || $body['userids'] === null) {
            return null;
        }
        $ids = $body['userids'];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 1000) {
            throw new BadBridgeArgument('userids');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new BadBridgeArgument('userids');
            }
            $out[] = $id;
        }

        return $out;
    }
}
