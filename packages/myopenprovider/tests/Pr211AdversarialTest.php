<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\Client;
use Nixpal\MyOpenprovider\OpenproviderException;
use PHPUnit\Framework\TestCase;

/**
 * PR #211 adversarial review: the app's OpenproviderRegistrar now takes a 2xx carrying an error code as «maybe done»
 * (a write so answered is not known to be undone). This client, in the same PR, still calls it definitive («nothing
 * was done»), and a definitive failure is what its renewal caller may ask again: the two disagree on the one question
 * that decides a second charge.
 */
final class Pr211AdversarialTest extends TestCase
{
    /** ADV-3 (fails): a renew answered 200 + code ≠ 0 is not known to be undone: never «definitive». */
    public function test_a_renew_answered_200_with_an_error_code_is_not_definitive()
    {
        $http = (new FakeTransport)->queueLogin()->queue(200, ['code' => 10001, 'desc' => 'Internal error, please try again']);
        $client = new Client($http, 'reseller', 'secret-pass', false);

        try {
            $client->renewDomain(5, 1);
            $this->fail('an error code taken as a renewal');
        } catch (OpenproviderException $e) {
            $this->assertFalse($e->isDefinitive(), 'answered with an error is «maybe done» (as in OpenproviderRegistrar::request since #211)');
        }
    }
}
