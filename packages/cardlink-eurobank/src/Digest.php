<?php

namespace Nixpal\CardlinkEurobank;

/**
 * vPOS digest: base64(sha256(concatenated field values . shared secret)), raw hash.
 */
final class Digest
{
    /**
     * Request leg, as eurobanklib.php: iconv //IGNORE over the values, then the secret
     * appended outside the transliteration.
     *
     * @param  array<string,string>  $fields  in posting order, without "digest"
     */
    public static function request(array $fields, $secret)
    {
        unset($fields['digest']);
        $input = implode('', array_map('strval', array_values($fields)));
        $normalised = @iconv('UTF-8', 'UTF-8//IGNORE', $input);

        return self::hash($normalised === false ? $input : $normalised, $secret);
    }

    /** Return leg, as eurobankreturn.php: the raw values, no iconv. */
    public static function response($signString, $secret)
    {
        return self::hash((string) $signString, $secret);
    }

    private static function hash($input, $secret)
    {
        return base64_encode(hash('sha256', $input . $secret, true));
    }
}
