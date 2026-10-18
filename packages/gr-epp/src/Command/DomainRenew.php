<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;
use SensitiveParameter;

/**
 * domain:renew (the guide's examples 51–54): even years 2–10, with its current expiry day as the registry has it (the
 * registry's confirmation: read fresh with domain:info, its expiresOn). Never cancelled. Within 15 days of expiry
 * another registrar's name may be renewed here: that is also a change of registrar (its registrant one of our contacts,
 * and its authorisation code).
 */
final class DomainRenew
{
    public static function request(string $name, string $currentExpiry, int $years, string $clTRID,
        ?string $registrantId = null, #[SensitiveParameter] ?string $authCode = null): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentExpiry) !== 1) {
            throw new InvalidArgumentException("The current expiry is a day (Y-m-d): {$currentExpiry}");
        }
        if (($registrantId === null) !== ($authCode === null)) {
            throw new InvalidArgumentException('Renewing another registrar\'s name needs both a registrant of ours and its authorisation code.');
        }
        $command = Builder::start('renew', 'domain');
        $command->add($command->object, 'domain', 'name', $name);
        $command->add($command->object, 'domain', 'curExpDate', $currentExpiry);
        $command->add($command->object, 'domain', 'period', (string) Rules::years($years))->setAttribute('unit', 'y');
        if ($registrantId !== null && $authCode !== null) {
            $renew = $command->extension('extdomain', 'renew');
            $command->add($renew, 'extdomain', 'registrantid', Rules::contactId($registrantId));
            $command->add($renew, 'extdomain', 'currentPW', $authCode);
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response, 'domain:renData');
    }
}
