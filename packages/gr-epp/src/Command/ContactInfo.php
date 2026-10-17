<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\ContactInfo as Info;
use MyCS\GrEpp\Data\PostalInfo;
use MyCS\GrEpp\Response;
use SensitiveParameter;

/**
 * contact:info (the guide's examples 11, 12, 12.1–12.3): one of ours; another registrar's only with the ROID and the
 * authorisation code of a domain it owns.
 */
final class ContactInfo
{
    public static function request(string $id, string $clTRID, ?string $domainRoid = null, #[SensitiveParameter] ?string $domainAuthCode = null): string
    {
        $command = Builder::start('info', 'contact');
        $command->add($command->object, 'contact', 'id', $id);
        if ($domainRoid !== null && $domainAuthCode !== null) {
            $command->add($command->add($command->object, 'contact', 'authInfo'), 'contact', 'pw', $domainAuthCode)->setAttribute('roid', $domainRoid);
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Info
    {
        $xpath = $response->xpath();
        $data = Read::required($response, '//contact:infData');
        $postal = [];
        foreach ($xpath->query('contact:postalInfo', $data) as $info) {
            if ($info instanceof DOMElement) {
                $type = $info->getAttribute('type');
                $postal[$type] = new PostalInfo($type, (string) Read::text($xpath, 'contact:name', $info), Read::text($xpath, 'contact:org', $info),
                    Read::texts($xpath, 'contact:addr/contact:street', $info), Read::text($xpath, 'contact:addr/contact:city', $info),
                    Read::text($xpath, 'contact:addr/contact:sp', $info), Read::text($xpath, 'contact:addr/contact:pc', $info),
                    ($cc = Read::text($xpath, 'contact:addr/contact:cc', $info)) !== null ? strtoupper($cc) : null);
            }
        }
        $voice = $xpath->query('contact:voice', $data)->item(0);
        $disclose = $xpath->query('contact:disclose', $data)->item(0);

        return new Info(
            id: (string) Read::text($xpath, 'contact:id', $data),
            roid: (string) Read::text($xpath, 'contact:roid', $data),
            statuses: Read::statuses($xpath, 'contact:status', $data),
            postalInfo: $postal,
            voice: $voice !== null ? trim($voice->textContent) : null,
            voiceExtension: $voice instanceof DOMElement && $voice->getAttribute('x') !== '' ? $voice->getAttribute('x') : null,
            fax: Read::text($xpath, 'contact:fax', $data),
            email: Read::text($xpath, 'contact:email', $data),
            clientId: Read::text($xpath, 'contact:clID', $data),
            created: Read::date($xpath, 'contact:crDate', $data),
            updated: Read::date($xpath, 'contact:upDate', $data),
            disclosed: $disclose instanceof DOMElement ? in_array($disclose->getAttribute('flag'), ['1', 'true'], true) : null,
        );
    }
}
