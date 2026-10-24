<?php

namespace MyCS\Proxmox\Tests;

use MyCS\Proxmox\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array{0: string, 1: string, 2: array<string, string>}> base, path, headers */
    public array $sent = [];

    /** @var array<string, true> bases that do not answer */
    public array $down = [];

    /** @var array<string, int> bases that answer this status to everything */
    public array $statuses = [];

    /** @param  array<string, array{0: int, 1: mixed}>  $answers  path => [status, data] */
    public function __construct(public array $answers = []) {}

    public function send(string $method, string $base, string $path, array $headers): array
    {
        $this->sent[] = [$base, $path, $headers];
        if (isset($this->down[$base])) {
            return [0, ''];
        }
        if (isset($this->statuses[$base])) {
            return [$this->statuses[$base], ''];
        }
        $key = substr($path, strlen('/api2/json/'));
        if (!isset($this->answers[$key])) {
            return [500, json_encode(['data' => null, 'message' => 'no such path'])];
        }
        [$status, $data] = $this->answers[$key];

        return [$status, json_encode(['data' => $data])];
    }
}
