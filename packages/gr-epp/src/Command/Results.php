<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/** Reading a write's answer into a Result: its code, the object's dates and name, the registry's protocol and comments. */
final class Results
{
    /** @param  string|null  $data  the response data element (domain:creData, domain:renData, domain:trnData) */
    public static function of(Response $response, ?string $data = null): Result
    {
        $xpath = $response->xpath();
        $node = $data !== null ? Read::required($response, "//{$data}") : null;
        $comments = $response->comments();

        return new Result(
            code: (int) $response->code,
            name: $node !== null ? Read::text($xpath, '*[local-name()="name" or local-name()="id"]', $node) : null,
            protocol: Read::text($xpath, '//extdomain:resData/extdomain:protocol'),
            created: $node !== null ? Read::date($xpath, '*[local-name()="crDate"]', $node) : null,
            expires: $node !== null ? Read::date($xpath, '*[local-name()="exDate"]', $node) : null,
            expiresOn: $node !== null ? Read::day($xpath, '*[local-name()="exDate"]', $node) : null,
            transferStatus: $node !== null ? Read::text($xpath, '*[local-name()="trStatus"]', $node) : null,
            comments: $comments,
            svTRID: $response->svTRID,
        );
    }
}
