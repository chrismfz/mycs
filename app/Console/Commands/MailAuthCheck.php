<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\TicketDepartment;
use Illuminate\Console\Command;
use MyCS\BankAlerts\Authenticity;
use MyCS\Extensions\Gateways\BankTransfer\BankTransfer;
use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\Parser;
use ZBateson\MailMimeParser\Message;

/**
 * What our mail server's Authentication-Results says about one real mail, as tickets and bank alerts read it
 * (docs/tickets.md, «Ο server παραλαβής»): to run after a server change or upgrade. Prints the verdicts only.
 */
class MailAuthCheck extends Command
{
    protected $signature = 'app:mail-auth-check {file=- : A raw message, or only its headers (- reads stdin)}
        {--from= : The From to check (default: the mail\'s own)}
        {--bank : Check as a bank alert (the Bank transfer gateway\'s authserv-id and ARC sealer)}
        {--authserv= : This authserv-id, not the configured ones}
        {--sealer= : With --bank: this ARC sealing domain}';

    protected $description = 'Check what our mail server vouches for in one mail (Authentication-Results)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $raw = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            $this->error('Nothing to read.');

            return self::FAILURE;
        }
        $head = rtrim((string) preg_split('/\r?\n\r?\n/', ltrim($raw), 2)[0]) . "\r\n\r\n";
        $message = Message::from($head, false);
        $results = Parser::exactHeaders($message, 'Authentication-Results');
        $arc = fn (string $name) => Parser::exactHeaders($message, $name);
        $from = (string) ($this->option('from') ?: Parser::headersOnly($head, 0)->fromEmail);
        $top = $results === [] ? null : AuthenticationResults::parse($results[0]);

        $gateway = Gateway::query()->where('extension', 'BankTransfer')->first();
        $extension = $this->option('bank') && $gateway !== null ? ExtensionHelper::getExtension('gateway', 'BankTransfer', $gateway->settings) : null;
        $settings = $extension instanceof BankTransfer ? $extension->alertSettings() : null;
        $ids = match (true) {
            filled($this->option('authserv')) => [(string) $this->option('authserv')],
            (bool) $this->option('bank') => array_filter([$settings['authserv_id'] ?? '']),
            default => TicketDepartment::query()->whereNotNull('authserv_id')->where('authserv_id', '!=', '')->distinct()->pluck('authserv_id')->all(),
        };
        if ($ids === []) {
            $this->error('No authserv-id is configured: give --authserv.');

            return self::FAILURE;
        }
        foreach ($ids as $id) {
            $verdict = $this->option('bank')
                ? Authenticity::verdict($results, $arc('ARC-Seal'), $arc('ARC-Message-Signature'), $arc('ARC-Authentication-Results'), $id,
                    (string) ($this->option('sealer') ?: ($settings['arc_sealer'] ?? '')), $from)
                : AuthenticationResults::verdict($results, $id, $from);
            $this->line(sprintf('%s %s: header on top %s, readable %s, %s (%s)', $this->option('bank') ? 'bank' : 'tickets', $id,
                $top !== null && $top['authserv_id'] === strtolower(trim($id)) ? 'yes' : 'no', $top !== null ? 'yes' : 'no',
                $verdict->authenticated ? 'VOUCHED' : 'not vouched', $verdict->reason));
        }

        return self::SUCCESS;
    }
}
