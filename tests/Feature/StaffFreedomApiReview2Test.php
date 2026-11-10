<?php

namespace Tests\Feature;

use App\Http\Middleware\Api\IdempotencyKey;
use App\Models\ApiKey;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * Second pass on PR #782 (head 4a9e665a): the Idempotency-Key middleware. No request leaves the machine.
 */
class StaffFreedomApiReview2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function send(ApiKey $key, string $header): Request
    {
        $request = Request::create('/api/v1/admin/invoices/502/payments/7/refunds', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"amount":"4.00","method":"credit"}');
        $request->headers->set('Idempotency-Key', $header);
        $request->attributes->set('api_key', $key);

        return $request;
    }

    /**
     * REV-782-R2-1. «A repeat while the first is still running → 409» holds for 60 seconds only: the marker is a cache
     * entry with a 60-second life, whatever the first request is doing. A request that takes longer (a gateway that
     * answers slowly, a wait for the payment locks) loses its marker while it still runs; the web server gives the client
     * its 504 at about that moment, the client sends again with the same key, and the repeat finds neither a marker nor
     * a kept answer: it is made a second time. The marker must outlive any request (it is removed when the first ends).
     */
    public function test_rev_782_r2_1_a_repeat_is_not_made_while_the_first_still_runs_after_a_minute(): void
    {
        $key = ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'review', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', 'REV2' . Str::random(8)), 'permissions' => []]);
        $header = (string) Str::uuid();
        $middleware = new IdempotencyKey;
        $made = 0;
        $repeat = null;

        $middleware->handle($this->send($key, $header), function () use (&$made, &$repeat, $middleware, $key, $header) {
            $made++; // the first request is at work (its refund is being made)
            $this->travel(61)->seconds();
            try {
                $repeat = $middleware->handle($this->send($key, $header), function () use (&$made) {
                    $made++;

                    return response()->json(['data' => 'made again'], 200);
                })->getStatusCode();
            } catch (HttpExceptionInterface $refused) {
                $repeat = $refused->getStatusCode();
            }

            return response()->json(['data' => 'made'], 200);
        });

        $this->assertSame(1, $made, 'the repeat was made while the first was still running');
        $this->assertSame(409, $repeat);
    }
}
