<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Services\Proxmox\ConsoleRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;

/**
 * The customer's console relay (docs/proxmox.md, B6), run by systemd (deploy/mycs-console.service.example) behind
 * nginx's `location /console/ws` (deploy/nginx.conf.example). It ends on a deploy's queue:restart, as the queue worker
 * does, and systemd starts the release's code.
 */
class ProxmoxConsoleRelay extends Command
{
    protected $signature = 'proxmox:console-relay {--listen= : address:port (default: config services.proxmox_console.listen)}';

    protected $description = 'Relay the customers\' VPS consoles (noVNC) to their Proxmox nodes';

    public function handle(): int
    {
        $listen = (string) ($this->option('listen') ?: config('services.proxmox_console.listen'));
        $parts = parse_url((string) config('app.url'));
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $relay = new ConsoleRelay($listen, $origin, (string) config('services.proxmox_console.path'), fn (array $ticket) => self::connect($ticket),
            fn (string $message, array $context) => Log::info('proxmox:console-relay: ' . $message, $context));
        $this->info("Listening on {$relay->address()} for {$origin}");
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop) {
                $stop = true;
            });
            pcntl_signal(SIGINT, function () use (&$stop) {
                $stop = true;
            });
        }
        // A deploy's queue:restart ends it too (its consoles with it): systemd starts the release's code (Restart=always).
        $restart = Cache::get('illuminate:queue:restart');
        $checked = 0.0;
        $relay->run(function () use (&$stop, $restart, &$checked) {
            if (!$stop && microtime(true) - $checked > 10) {
                $checked = microtime(true);
                $stop = Cache::get('illuminate:queue:restart') !== $restart;
            }

            return $stop;
        });

        return self::SUCCESS;
    }

    /**
     * The ticket's node, over TLS as MyCS reaches it (its IP when set; a self-signed certificate only with that IP).
     *
     * @param  array<string, mixed>  $ticket
     * @return array{stream: resource, host: string, port: int, authorization: string}
     */
    public static function connect(array $ticket): array
    {
        $server = Server::query()->where('extension', 'Proxmox')->find($ticket['server_id']);
        $extension = $server === null ? null : ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Proxmox) {
            throw new RuntimeException('The ticket\'s server is no longer a Proxmox cluster.');
        }
        $target = $extension->websocketTarget((string) $ticket['base']);
        $address = $target['ip'] ?? $target['host'];
        $context = stream_context_create(['ssl' => [
            'peer_name' => $target['host'], 'SNI_enabled' => true,
            'verify_peer' => !$target['self_signed'], 'verify_peer_name' => !$target['self_signed'], 'allow_self_signed' => $target['self_signed'],
        ]]);
        $stream = @stream_socket_client('tls://' . (str_contains($address, ':') ? "[{$address}]" : $address) . ':' . $target['port'], $code, $message, 5,
            STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException("{$target['host']}: {$message}");
        }

        return ['stream' => $stream, 'host' => $target['host'], 'port' => $target['port'], 'authorization' => $target['authorization']];
    }
}
