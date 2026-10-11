<?php

namespace Nixpal\PaypalVault\Tests;

use Nixpal\PaypalVault\Http\Transport;

final class FakeTransport implements Transport
{
    /** @var array<int,array{method:string,url:string,headers:array,body:?string}> */
    public $requests = [];

    /** @var array<int,array{0:int,1:string}> */
    private $responses = [];

    public function __construct()
    {
        $this->queueToken();
    }

    public function queueToken($expiresIn = 32400)
    {
        return $this->queue(200, ['access_token' => 'AT-' . count($this->responses), 'expires_in' => $expiresIn]);
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

    public function lastJson()
    {
        return json_decode($this->last()['body'], true);
    }
}
