<?php

namespace MyCS\BankAlerts\Tests;

/** Alert emails built as the banks send them (structure, charset, labels), with made-up people and numbers. */
final class Fixtures
{
    /** The headers our server adds to a mail it forwarded to the alerts mailbox (sieve redirect, ARC sealed by us). */
    public static function forwardedByUs(string $bankDomain, string $result = 'pass'): string
    {
        return "Authentication-Results: mx.example.test;\r\n\tarc=pass (\"example.test:s=dkim:i=1\")\r\n"
            . "ARC-Seal: i=1; a=rsa-sha256; d=example.test; s=dkim; cv=none; t=1792740475;\r\n\tb=AAAA\r\n"
            . "ARC-Message-Signature: i=1; a=rsa-sha256; c=relaxed/relaxed; d=example.test; s=dkim; t=1792740475;\r\n\th=from:subject:date; bh=AAAA; b=AAAA\r\n"
            . "ARC-Authentication-Results: i=1;\r\n\tmx.example.test;\r\n\tdkim={$result} header.d={$bankDomain} header.s=s1 header.b=AAAA;\r\n"
            . "\tdmarc={$result} (policy=reject) header.from={$bankDomain};\r\n\tspf={$result} smtp.mailfrom=alerts@{$bankDomain}\r\n";
    }

    public static function eurobank(string $verb, string $amount, string $reason, string $balance, ?string $auth = null, string $account = '0000'): string
    {
        $text = "Αγαπητέ πελάτη,\n\n    Σας ενημερώνουμε ότι {$verb} με {$amount} EUR ο λογαριασμός σας με τελευταία ψηφία *{$account}.\n"
            . "    Αιτιολογία: {$reason}.\n    Νέο υπόλοιπο: {$balance} EUR.\n\n    Σημείωση:\n\n    Μην απαντάτε σε αυτό το μήνυμα.\n";
        $body = chunk_split(base64_encode((string) iconv('UTF-8', 'Windows-1253', $text)), 76, "\r\n");
        $subject = '=?Cp1253?B?' . base64_encode((string) iconv('UTF-8', 'Windows-1253', 'Ενημέρωση Eurobank: ' . ($verb === 'πιστώθηκε' ? 'Πίστωση' : 'Χρέωση') . ' λογαριασμού')) . '?=';

        return ($auth ?? self::forwardedByUs('eurobank.gr'))
            . "Date: Fri, 23 Oct 2026 10:27:47 +0300\r\nFrom: \"eurobank@eurobank.gr\" <eurobank@eurobank.gr>\r\nTo: <alerts@example.test>\r\n"
            . "Subject: {$subject}\r\nMIME-Version: 1.0\r\nMessage-ID: <" . md5($text) . "@mailrelay.eurobank.gr>\r\n"
            . "Content-Type: multipart/mixed;\r\n\tboundary=\"----=_Part_1\"\r\n\r\n\r\n------=_Part_1\r\n"
            . "Content-Type: text/plain; charset=\"Cp1253\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$body}\r\n------=_Part_1--\r\n";
    }

    public static function piraeus(string $amount, string $type, string $reason1, string $reason2, string $balance, ?string $auth = null): string
    {
        $row = fn (string $label, string $value) => '<tr><td style="text-align: right;"><span>' . $label . ':</span></td><td style="text-align: left;"><span style="font-weight: bold;">'
            . htmlspecialchars($value) . '</span></td></tr>';
        $html = '<html><head><meta content="text/html; charset=UTF-8" http-equiv="Content-Type"/><title>Υπηρεσία Piraeus Alerts</title>'
            . '<style>body {{Margin: 0;}}</style></head><body><table><tr><td><span>Υπηρεσία Piraeus Alerts</span></td></tr><tr><td><table><tbody>'
            . '<tr><td><span>Μεταβολή στο Υπόλοιπο του Λογαριασμού  </span></td></tr>'
            . $row('Λογαριασμός', 'ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE') . $row('Ποσό Συναλλαγής', $amount) . $row('Τύπος  Συναλλαγής', $type)
            . $row('Ημερομηνία Εκτέλεσης', '22/10/26 15:20') . $row('Ημερομηνία Αξίας', '22/10/2026')
            . $row('Λογιστικό Υπόλοιπο', $balance) . $row('Διαθέσιμο Υπόλοιπο', $balance)
            . $row('Αιτιολογία 1', $reason1) . $row('Αιτιολογία 2', $reason2)
            . '</tbody></table></td></tr><tr><td><a href="mailto:support@piraeusbank.gr">support@piraeusbank.gr</a></td></tr></table></body></html>';

        return ($auth ?? self::forwardedByUs('piraeusbank.gr'))
            . "MIME-Version: 1.0\r\nFrom: Piraeus Alerts <IBankV2Email@piraeusbank.gr>\r\nTo: <alerts@example.test>\r\n"
            . "Date: Thu, 22 Oct 2026 15:23:43 +0300\r\nSubject: =?utf-8?B?" . base64_encode($type . ' ' . $amount) . "?=\r\n"
            . "Content-Type: text/html; charset=\"utf-8\"\r\nContent-Transfer-Encoding: base64\r\nMessage-ID: <" . md5($html) . "@smtp.piraeusgroup.gr>\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n");
    }
}
