<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\MetaStore;
use Nixpal\MyOpenprovider\MetaTokenStore;
use PHPUnit\Framework\TestCase;

final class MetaStoreTest extends TestCase
{
    /** @var array<string,string> */
    private $rows = [];

    private function store()
    {
        return new MetaStore(
            function ($key) {
                return $this->rows[$key] ?? null;
            },
            function ($key, $value) {
                $this->rows[$key] = $value;
            },
            function ($key) {
                unset($this->rows[$key]);
            }
        );
    }

    public function test_writes_one_key_without_touching_others()
    {
        $this->rows = ['token_until_date_abcde' => 'x', 'myop_renewal_1' => '{"status":"pending"}'];
        $store = $this->store();
        $store->setJson('myop_renewal_2', ['status' => 'done']);

        $this->assertSame(['status' => 'pending'], $store->getJson('myop_renewal_1'));
        $this->assertSame(['status' => 'done'], $store->getJson('myop_renewal_2'));
        $this->assertSame('x', $this->rows['token_until_date_abcde']);

        $store->delete('myop_renewal_2');
        $this->assertNull($store->getJson('myop_renewal_2'));
        $this->assertNull($store->get('missing'));
    }

    public function test_keys_longer_than_the_column_are_refused()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->set(str_repeat('k', 33), 'v');
    }

    public function test_tokens_expire()
    {
        $tokens = new MetaTokenStore($this->store());
        $tokens->set('abc', 'T', 3600);
        $this->assertSame('T', $tokens->get('abc'));
        $this->assertArrayHasKey('myop_token_abc', $this->rows);

        $tokens->set('abc', 'T', 30); // less than the 60 s margin
        $this->assertNull($tokens->get('abc'));

        $tokens->set('abc', 'T', 3600);
        $tokens->forget('abc');
        $this->assertNull($tokens->get('abc'));
    }
}
