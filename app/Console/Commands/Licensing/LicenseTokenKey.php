<?php

namespace App\Console\Commands\Licensing;

use Illuminate\Console\Command;
use Nixpal\Licensing\Token;

/**
 * The signing key of the license tokens (docs/licensing.md, «Signed token»). Without --show: a new pair, printed for
 * staff to put in .env (the secret) and in the clients (the public one). Nothing is written: the key never passes
 * through the database, a log or the repository. With --show: the public key of the configured secret.
 */
class LicenseTokenKey extends Command
{
    protected $signature = 'mycs:license-token-key {--show : The public key of the key in .env}';

    protected $description = 'Make (or show the public half of) the ed25519 key that signs license tokens';

    public function handle(): int
    {
        if ($this->option('show')) {
            $public = Token::publicKey((string) config('services.license_token.secret'));
            if ($public === null) {
                $this->error('MYCS_LICENSE_TOKEN_KEY is not set (or is not an ed25519 secret key): no tokens are issued.');

                return self::FAILURE;
            }
            $this->line('kid:        ' . (config('services.license_token.kid') ?: 'k1'));
            $this->line('public key: ' . $public);

            return self::SUCCESS;
        }

        $pair = Token::keypair();
        $this->line('Put these two lines in .env (never in the repository), then php artisan config:cache:');
        $this->newLine();
        $this->line('MYCS_LICENSE_TOKEN_KEY=' . $pair['secret']);
        $this->line('MYCS_LICENSE_TOKEN_KID=k' . now()->format('Ymd'));
        $this->newLine();
        $this->line('And this public key, with that kid, in the clients:');
        $this->line($pair['public']);
        $this->newLine();
        $this->comment('Keep the secret where the other .env secrets are kept: a lost key is a new pair and new clients.');

        return self::SUCCESS;
    }
}
