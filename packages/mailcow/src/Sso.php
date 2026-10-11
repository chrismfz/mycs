<?php

namespace Nixpal\Mailcow;

/** mailcow domain-admin SSO: token from the API answer, and the login URL with it. */
final class Sso
{
    /**
     * @param  mixed  $json  decoded answer: {"token":..}, [{"msg":"<token>"}], [{"msg":{"token":..}}], ...
     */
    public static function extractToken($json)
    {
        if (!is_array($json)) {
            return '';
        }
        foreach (['token', 'sso_token'] as $key) {
            if (isset($json[$key]) && is_string($json[$key])) {
                return trim($json[$key]);
            }
        }
        if (isset($json[0]) && is_array($json[0])) {
            $row = $json[0];
            foreach (['token', 'sso_token'] as $key) {
                if (isset($row[$key]) && is_string($row[$key])) {
                    return trim($row[$key]);
                }
            }
            if (isset($row['msg']) && is_string($row['msg']) && preg_match('/^[A-Za-z0-9._-]{16,}$/', trim($row['msg']))) {
                return trim($row['msg']);
            }
            if (isset($row['msg']['token']) && is_string($row['msg']['token'])) {
                return trim($row['msg']['token']);
            }
        }

        return '';
    }

    /** base + path + ?sso_token=..., path defaulting to /domainadmin/ */
    public static function loginUrl($baseUrl, $path, $token)
    {
        $path = trim((string) $path);
        if ($path === '') {
            $path = '/domainadmin/';
        } elseif ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $url = rtrim((string) $baseUrl, '/') . $path;

        return $url . (strpos($url, '?') === false ? '?' : '&') . 'sso_token=' . rawurlencode((string) $token);
    }
}
