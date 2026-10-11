<?php

namespace Nixpal\Licensing\Tests\Oracle;

/**
 * The local-key code of reference/whmcs/zlic.php, copied verbatim (only wrapped
 * in functions) so tests can prove LocalKey is byte-identical to production.
 * Do not "improve" this file: it is the yardstick.
 */
final class ZlicReference
{
    /** zlic.php, the `if (($results['status'] ?? '') == "Active")` block. */
    public static function activeResults(array $results, $licensing_secret_key, $checkdate)
    {
        if (($results['status'] ?? '') == 'Active') {
            $results['checkdate'] = $checkdate;
            $data_encoded = json_encode($results);
            $data_encoded = base64_encode($data_encoded);
            $data_encoded = md5($checkdate . $licensing_secret_key) . $data_encoded;
            $data_encoded = strrev($data_encoded);
            $data_encoded = $data_encoded . md5($data_encoded . $licensing_secret_key);
            $data_encoded = wordwrap($data_encoded, 80, "\n", true);
            $results['localkey'] = $data_encoded;
        }
        $results['remotecheck'] = true;

        return $results;
    }

    /** zlic.php, the `if ($localkey)` decode branch, minus the binding checks. */
    public static function decode($localkey, $licensing_secret_key)
    {
        $localkey = str_replace("\n", '', $localkey); // Remove the line breaks
        $localdata = substr($localkey, 0, strlen($localkey) - 32); // Extract License Data
        $md5hash = substr($localkey, strlen($localkey) - 32); // Extract MD5 Hash
        if ($md5hash == md5($localdata . $licensing_secret_key)) {
            $localdata = strrev($localdata); // Reverse the string
            $md5hash = substr($localdata, 0, 32); // Extract MD5 Hash
            $localdata = substr($localdata, 32); // Extract License Data
            $localdata = base64_decode($localdata);
            $localkeyresults = json_decode($localdata, true);
            if (!is_array($localkeyresults)) {
                $localkeyresults = [];
            }
            $originalcheckdate = $localkeyresults['checkdate'] ?? '';
            if ($md5hash == md5($originalcheckdate . $licensing_secret_key)) {
                return $localkeyresults;
            }
        }

        return null;
    }
}
