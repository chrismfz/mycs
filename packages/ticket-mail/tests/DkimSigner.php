<?php

namespace MyCS\TicketMail\Tests;

use OpenSSLAsymmetricKey;

/**
 * Signs a raw mail as a sending server would (RFC 6376), with a key made in the test, for the tests of Dkim: its own
 * plain reading of the RFC, so a test of the verifier is not the verifier checking itself (RFC 8463's example and a
 * dkimpy-signed mail in fixtures/ check both against a third party).
 */
final class DkimSigner
{
    public function __construct(
        public readonly string $algorithm,
        private readonly OpenSSLAsymmetricKey|string $secret,
        public readonly string $publicRecord,
    ) {}

    public static function rsa(int $bits = 2048): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        assert($key instanceof OpenSSLAsymmetricKey);
        $pem = (string) openssl_pkey_get_details($key)['key'];
        $der = base64_encode((string) base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? ''));

        return new self('rsa-sha256', $key, "v=DKIM1; k=rsa; p={$der}");
    }

    public static function ed25519(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self('ed25519-sha256', sodium_crypto_sign_secretkey($pair),
            'v=DKIM1; k=ed25519; p=' . base64_encode(sodium_crypto_sign_publickey($pair)));
    }

    /**
     * The mail with a DKIM-Signature on top.
     *
     * @param  list<string>  $headers  the names signed, as h= writes them
     * @param  array<string, string>  $tags  more tags (or other values: «a», «c», «d», «s» are given too), before b=
     */
    public function sign(string $raw, string $d = 'example.com', string $s = 'sel', string $c = 'relaxed/relaxed',
        array $headers = ['from', 'to', 'subject', 'date', 'message-id'], array $tags = []): string
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
        [$headerCanon, $bodyCanon] = explode('/', $c) + [1 => 'simple'];
        $tags = $tags + ['v' => '1', 'a' => $this->algorithm, 'c' => $c, 'd' => $d, 's' => $s, 'h' => implode(':', $headers),
            'bh' => base64_encode(hash('sha256', self::body($body, $bodyCanon), true))];
        $value = '';
        foreach ($tags as $name => $tag) {
            $value .= " {$name}={$tag};";
        }
        $field = "DKIM-Signature:{$value}\r\n b=";

        $fields = preg_split('/\r\n(?![ \t])/', $head) ?: [];
        $data = '';
        $used = [];
        foreach ($headers as $name) {
            foreach (array_reverse(array_keys($fields)) as $i) {
                if (!isset($used[$i]) && strcasecmp(explode(':', $fields[$i], 2)[0], $name) === 0) {
                    $used[$i] = true;
                    $data .= self::header($fields[$i], $headerCanon) . "\r\n";

                    break;
                }
            }
        }
        $data .= self::header($field, $headerCanon);

        $b = $this->algorithm === 'ed25519-sha256'
            ? sodium_crypto_sign_detached(hash('sha256', $data, true), (string) $this->secret)
            : (function () use ($data) {
                openssl_sign($data, $signature, $this->secret, OPENSSL_ALGO_SHA256);

                return (string) $signature;
            })();

        return $field . rtrim(chunk_split(base64_encode($b), 60, "\r\n "), "\r\n ") . "\r\n" . $raw;
    }

    private static function header(string $field, string $canon): string
    {
        if ($canon === 'simple') {
            return $field;
        }
        [$name, $value] = explode(':', $field, 2);

        return strtolower(trim($name)) . ':' . trim((string) preg_replace('/[ \t]+/', ' ', str_replace("\r\n", '', $value)));
    }

    private static function body(string $body, string $canon): string
    {
        $lines = explode("\r\n", $body);
        if ($canon === 'relaxed') {
            $lines = array_map(fn (string $line) => rtrim((string) preg_replace('/[ \t]+/', ' ', $line), ' '), $lines);
        }
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return $canon === 'relaxed' ? '' : "\r\n";
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
