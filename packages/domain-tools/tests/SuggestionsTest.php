<?php

namespace Nixpal\DomainTools\Tests;

use Nixpal\DomainTools\Suggestions;
use PHPUnit\Framework\TestCase;

final class SuggestionsTest extends TestCase
{
    public function test_only_sold_tlds_new_names_and_the_limit()
    {
        $names = ['GreekBakery.com', 'greekbakery.games', 'greek-bakery.com', 'greekbakery.co.uk', 'greekbakery.uk',
            'greekbakery.com', 'bad_name.com', 'greekbakeries.net', 'greekbakehouse.com'];
        $kept = Suggestions::filter($names, ['.com', 'uk', 'net'], ['greek-bakery.com'], 3);

        $this->assertSame(['greekbakery.com', 'greekbakery.uk', 'greekbakeries.net'], $kept);
        $this->assertSame([], Suggestions::filter($names, [], [], 5));
    }
}
