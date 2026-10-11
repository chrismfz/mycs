<?php

namespace Nixpal\DomainTools\Tests;

use Nixpal\DomainTools\Http\Transport;

final class FakeTransport implements Transport
{
    /** @var array<string,array{0:int,1:string}> url => answer */
    public $answers = [];

    public $requests = [];

    public function get($url, array $headers = [], $timeout = 8)
    {
        $this->requests[] = $url;

        return $this->answers[$url] ?? [404, ''];
    }
}
