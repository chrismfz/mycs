<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\AccountInfo as Info;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Response;

/**
 * account:info (account-1.1, grepp.md §5.11, the guide's examples 64–66): the registrar's balance (D-15: chargeable
 * actions need a positive one), whether they are allowed, and when they will be suspended.
 */
final class AccountInfo
{
    public static function request(string $clTRID, ?string $roid = null): string
    {
        $command = Builder::start('info', 'account');
        if ($roid !== null) {
            $command->add($command->object, 'account', 'roid', $roid);
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Info
    {
        $xpath = $response->xpath();
        $data = Read::required($response, '//account:infData');
        $balance = $xpath->query('account:balance', $data)->item(0);
        $amount = $balance !== null ? trim($balance->textContent) : '';
        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new OutcomeUnknown("The registry gave a balance that is not an amount: {$amount}");
        }

        return new Info(
            roid: (string) Read::text($xpath, 'account:roid', $data),
            balance: $amount,
            currency: $balance instanceof DOMElement ? $balance->getAttribute('currency') : '',
            chargeableActionsAllowed: Read::text($xpath, 'account:caAllowed', $data) === 'true',
            paymentCode: Read::text($xpath, 'account:ddPaymentCode', $data),
            toBeSuspendedOn: Read::date($xpath, 'account:tbSuspendedOn', $data),
            suspendedOn: Read::date($xpath, 'account:suspendedOn', $data),
        );
    }
}
