<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\License;
use App\Models\McpCall;
use App\Models\Staff;
use App\Services\Ops\NotFound;
use App\Services\Ops\Scrub;
use App\Support\CallerIp;
use App\Support\Redact;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * A MyCS MCP tool (docs/mcp.md): offered only to staff whose key and role allow the MCP and whose role allows what the
 * tool shows (permission()); checked again when called. Every call is recorded (mcp_calls: who, tool, scrubbed
 * arguments, outcome, duration). The answer is JSON; an error is an MCP error with a scrubbed message, never a stack.
 */
abstract class McpTool extends Tool
{
    /** The role permission what the tool shows needs (besides «Use the MCP Server»). */
    abstract protected function permission(): string;

    /**
     * @return array<array-key, mixed>
     */
    abstract protected function run(Request $request): array;

    public function shouldRegister(Request $request): bool
    {
        return self::allowed($request->user(), $this->permission());
    }

    public function handle(Request $request): Response
    {
        $staff = $request->user();
        $started = hrtime(true);
        $this->findKeys($request);
        if (!self::allowed($staff, $this->permission())) {
            $this->record($staff, $request, 'denied', null, $started);

            return Response::error('Not allowed.');
        }
        try {
            $data = $this->run($request);
        } catch (Throwable $e) {
            if (!$e instanceof NotFound) {
                report($e); // not a routine «no such record»
            }
            $message = $this->maskKeys((string) Scrub::text($e->getMessage()));
            $this->record($staff, $request, 'error', $message, $started);

            return Response::error($message !== '' ? $message : 'The tool failed.');
        }
        $this->record($staff, $request, 'ok', null, $started);

        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function allowed(mixed $staff, string $permission): bool
    {
        return $staff instanceof Staff && $staff->active && $staff->hasPermission(McpAuth::PERMISSION) && $staff->hasPermission($permission);
    }

    protected static function limit(Request $request, int $default, int $max): int
    {
        return max(1, min($max, (int) ($request->get('limit') ?? $default)));
    }

    /**
     * The tool's own arguments only, each bounded, secrets masked, license keys as prefix…last4 (reviews MCP-R2, R3).
     *
     * @return array<string, mixed>
     */
    private function recordedArguments(Request $request): array
    {
        $names = array_keys($this->schema(new JsonSchemaTypeFactory));
        $arguments = Redact::array(array_intersect_key($request->all(), array_flip($names)));

        return array_map(fn ($value) => is_string($value) ? mb_substr($this->maskKeys($value), 0, 255) : (is_scalar($value) || $value === null ? $value : '[…]'), $arguments);
    }

    /** @var list<string> license keys among this call's arguments */
    private array $keys = [];

    /** A license key of this call (an argument, «No service <key>»): its prefix and last 4 (review MCP-R2). */
    private function maskKeys(string $text): string
    {
        foreach ($this->keys as $key) {
            $text = str_replace($key, (string) Scrub::licenseKey($key), $text);
        }

        return $text;
    }

    /** The arguments that are license keys, looked up (any format: imported keys are not all hex). */
    private function findKeys(Request $request): void
    {
        $values = array_values(array_filter($request->all(), fn ($value) => is_string($value) && $value !== '' && mb_strlen($value) <= 191));
        $this->keys = $values === [] ? [] : License::query()->whereIn('license_key', $values)->pluck('license_key')->all();
    }

    private function record(mixed $staff, Request $request, string $outcome, ?string $error, int $started): void
    {
        try {
            $key = request()->attributes->get('api_key');
            McpCall::query()->create([
                'staff_id' => $staff instanceof Staff ? $staff->id : null,
                'api_key_id' => $key instanceof ApiKey ? $key->id : null,
                'tool' => $this->name(),
                'arguments' => $this->recordedArguments($request),
                'outcome' => $outcome,
                'error' => $error === null ? null : mb_substr($this->maskKeys($error), 0, 255),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'ip' => CallerIp::of(request()),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // the record never changes the answer
        }
    }
}
