<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\Http\Transport;

final class FakeTransport implements Transport
{
    /** @var array<int,array{method:string,url:string,headers:array,body:?string}> */
    public $requests = [];

    /** @var array<int,array{0:int,1:string}> */
    private $responses = [];

    public function queueLogin($token = 'TOKEN1')
    {
        return $this->queue(200, ['code' => 0, 'data' => ['token' => $token, 'reseller_id' => 1]]);
    }

    public function queueData($data, $status = 200)
    {
        return $this->queue($status, ['code' => 0, 'data' => $data]);
    }

    public function queue($status, $body)
    {
        $this->responses[] = [$status, is_string($body) ? $body : json_encode($body)];

        return $this;
    }

    public function send($method, $url, array $headers, $body = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (!$this->responses) {
            throw new \LogicException('No response queued for ' . $method . ' ' . $url);
        }

        return array_shift($this->responses);
    }

    public function last()
    {
        return end($this->requests);
    }

    public function json($i)
    {
        return json_decode((string) $this->requests[$i]['body'], true);
    }
}
