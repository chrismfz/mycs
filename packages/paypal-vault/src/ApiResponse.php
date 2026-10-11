<?php

namespace Nixpal\PaypalVault;

final class ApiResponse
{
    /** @var int */
    public $status;

    /** @var array<string,mixed> */
    public $body;

    public function __construct($status, array $body)
    {
        $this->status = (int) $status;
        $this->body = $body;
    }

    public function ok()
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * PayPal's error text: the first issue's description, else the top-level message.
     */
    public function errorMessage()
    {
        if ($this->ok()) {
            return null;
        }
        $issue = isset($this->body['details'][0]) ? $this->body['details'][0] : [];
        $parts = array_filter([
            isset($issue['issue']) ? $issue['issue'] : (isset($this->body['name']) ? $this->body['name'] : null),
            isset($issue['description']) ? $issue['description'] : (isset($this->body['message']) ? $this->body['message'] : null),
        ]);

        return $parts ? implode(': ', $parts) : 'HTTP ' . $this->status;
    }

    /** First issue code (e.g. INSTRUMENT_DECLINED), or null. */
    public function issue()
    {
        return isset($this->body['details'][0]['issue']) ? (string) $this->body['details'][0]['issue'] : null;
    }
}
