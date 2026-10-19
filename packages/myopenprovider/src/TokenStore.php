<?php

namespace Nixpal\MyOpenprovider;

/**
 * Where the bearer token from POST /v1/auth/login is kept between requests
 * (Blesta: module meta). Keys never contain the password.
 */
interface TokenStore
{
    /** @return string|null a token still valid, or null */
    public function get($key);

    /** @param int $ttl seconds the token may be used */
    public function set($key, $token, $ttl);

    public function forget($key);
}
