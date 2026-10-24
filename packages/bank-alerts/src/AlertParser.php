<?php

namespace MyCS\BankAlerts;

use DateTimeImmutable;
use DateTimeZone;
use MyCS\TicketMail\Parser;
use Throwable;
use ZBateson\MailMimeParser\Message;

/**
 * A raw alert email into a BankAlert, or null when it is none (another bank's, a newsletter, a security notice):
 * - Eurobank: «πιστώθηκε/χρεώθηκε με 1.234,56 EUR», «Αιτιολογία: …», «Νέο υπόλοιπο: …» (plain text, Cp1253);
 * - Piraeus: «Ποσό Συναλλαγής: 100.44 EUR (ΠΙ)» (ΧΡ and a minus for a debit), «Διαθέσιμο Υπόλοιπο», «Αιτιολογία 1/2»,
 *   «Ημερομηνία Εκτέλεσης: 22/10/26 15:20» (HTML).
 * The bank is told by the From's domain; whether the bank really sent it, by Authenticity.
 */
final class AlertParser
{
    public const EUROBANK = 'eurobank';

    public const PIRAEUS = 'piraeus';

    /** The From domains of each bank's alerts. */
    public const DOMAINS = ['eurobank.gr' => self::EUROBANK, 'piraeusbank.gr' => self::PIRAEUS];

    public static function parse(string $raw, string $authservId, string $sealer, string $timezone = 'Europe/Athens'): ?BankAlert
    {
        try {
            $email = Parser::parse($raw);
            $message = Message::from($raw, false);
        } catch (Throwable) {
            return null;
        }
        $bank = self::DOMAINS[(string) $email->fromDomain()] ?? null;
        if ($bank === null) {
            return null;
        }
        $values = fn (string $name) => array_map(fn ($h) => trim($h->getRawValue()), $message->getAllHeadersByName($name));
        $verdict = Authenticity::verdict($email->authenticationResults, $values('ARC-Seal'), $values('ARC-Authentication-Results'),
            $authservId, $sealer, $email->fromEmail);

        $fields = $bank === self::EUROBANK ? self::eurobank($email->body) : self::piraeus($email->body, $timezone);
        if ($fields === null) {
            return null;
        }

        return new BankAlert($bank, $fields['direction'], $fields['amount'], $fields['balance'], $fields['reason'],
            $fields['executed_at'] ?? $email->date, $email->messageId, $verdict->authenticated, $verdict->reason);
    }

    /** @return array{direction: string, amount: string, balance: ?string, reason: string}|null */
    public static function eurobank(string $text): ?array
    {
        if (preg_match('/(πιστώθηκε|χρεώθηκε)\s+με\s+([\d.,]+)\s*EUR/u', $text, $m) !== 1) {
            return null;
        }
        $amount = Amount::parse($m[2], ',');
        if ($amount === null || str_starts_with($amount, '-')) {
            return null;
        }
        $reason = preg_match('/^\s*Αιτιολογία:[ \t]*(.*)$/mu', $text, $r) === 1 ? self::reason($r[1]) : '';
        $balance = preg_match('/^\s*Νέο υπόλοιπο:\s*(-?[\d.,]+)\s*EUR/mu', $text, $b) === 1 ? Amount::parse($b[1], ',') : null;

        return ['direction' => $m[1] === 'πιστώθηκε' ? BankAlert::CREDIT : BankAlert::DEBIT, 'amount' => $amount, 'balance' => $balance, 'reason' => $reason];
    }

    /** @return array{direction: string, amount: string, balance: ?string, reason: string, executed_at: ?DateTimeImmutable}|null */
    public static function piraeus(string $text, string $timezone = 'Europe/Athens'): ?array
    {
        $field = fn (string $label) => preg_match('/^\s*' . $label . ':[ \t]*(.*)$/mu', $text, $m) === 1 ? trim($m[1]) : null;
        $written = $field('Ποσό\s+Συναλλαγής');
        if ($written === null || preg_match('/^(-?[\d.,]+)\s*EUR\s*\((ΠΙ|ΧΡ)\)/u', $written, $m) !== 1) {
            return null;
        }
        $amount = Amount::parse($m[1], '.');
        if ($amount === null) {
            return null;
        }
        $debit = $m[2] === 'ΧΡ';
        $balanceText = $field('Διαθέσιμο\s+Υπόλοιπο') ?? $field('Λογιστικό\s+Υπόλοιπο');
        $balance = $balanceText !== null && preg_match('/^(-?[\d.,]+)\s*EUR/u', $balanceText, $b) === 1 ? Amount::parse($b[1], '.') : null;
        $reason = self::reason(trim(($field('Αιτιολογία\s+1') ?? '') . ' ' . ($field('Αιτιολογία\s+2') ?? '')));
        $executed = null;
        if (($when = $field('Ημερομηνία\s+Εκτέλεσης')) !== null) {
            $executed = DateTimeImmutable::createFromFormat('!d/m/y H:i', $when, new DateTimeZone($timezone)) ?: null;
        }

        return ['direction' => $debit ? BankAlert::DEBIT : BankAlert::CREDIT, 'amount' => ltrim($amount, '-'), 'balance' => $balance,
            'reason' => $reason, 'executed_at' => $executed];
    }

    /** One line, its spaces folded, its closing full stop dropped (Eurobank ends every line with one). */
    private static function reason(string $reason): string
    {
        return mb_substr(rtrim(trim((string) preg_replace('/\s+/u', ' ', $reason)), '.'), 0, 500);
    }
}
