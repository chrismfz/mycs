<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\TokenStore;

final class MemoryTokenStore implements TokenStore
{
    public $tokens = [];

    public function get($key)
    {
        return $this->tokens[$key] ?? null;
    }

    public function set($key, $token, $ttl)
    {
        $this->tokens[$key] = $token;
    }

    public function forget($key)
    {
        unset($this->tokens[$key]);
    }
}
