<?php

namespace Nixpal\MyOpenprovider;

/** Openprovider bearer tokens in the module meta, under our own keys. */
final class MetaTokenStore implements TokenStore
{
    /** @var MetaStore */
    private $meta;

    public function __construct(MetaStore $meta)
    {
        $this->meta = $meta;
    }

    public function get($key)
    {
        $data = $this->meta->getJson('myop_token_' . $key);
        if (!$data || empty($data['token']) || (int) ($data['until'] ?? 0) <= time() + 60) {
            return null;
        }

        return (string) $data['token'];
    }

    public function set($key, $token, $ttl)
    {
        $this->meta->setJson('myop_token_' . $key, ['token' => (string) $token, 'until' => time() + (int) $ttl]);
    }

    public function forget($key)
    {
        $this->meta->delete('myop_token_' . $key);
    }
}
