<?php

namespace Nixpal\CardlinkEurobank;

/**
 * A vPOS return (browser POST or the background "Modirum HTTPClient" POST) after
 * {@see ReturnVerifier}. Only a verified return may be acted on.
 */
final class VposReturn
{
    /** @var bool */
    public $verified;

    /** @var string 'ok' or why it was refused */
    public $reason;

    /** @var array<string,string> the signed fields that were posted (no digest/noise) */
    public $fields;

    /** @var string[] posted field names in posted order, for the log (never values) */
    public $postedKeys;

    /** @var bool|null on a digest mismatch: does the posted order verify? (order vs secret) */
    public $postedOrderMatches;

    public function __construct($verified, $reason, array $fields, array $postedKeys, $postedOrderMatches = null)
    {
        $this->verified = (bool) $verified;
        $this->reason = (string) $reason;
        $this->fields = $fields;
        $this->postedKeys = $postedKeys;
        $this->postedOrderMatches = $postedOrderMatches;
    }

    public function status()
    {
        return strtoupper(trim($this->field('status')));
    }

    /**
     * Blesta transaction status, or null when nothing should be recorded (a customer
     * cancel, or a status we do not know).
     */
    public function blestaStatus()
    {
        switch ($this->status()) {
            case 'CAPTURED':
                return 'approved';
            case 'AUTHORIZED':
                // We never ask for pre-authorisation; if one shows up it is not money yet.
                return 'pending';
            case 'REFUSED':
                return 'declined';
            case 'ERROR':
                return 'error';
            default:
                return null;
        }
    }

    public function orderId()
    {
        return $this->field('orderid');
    }

    public function amount()
    {
        return $this->field('orderAmount');
    }

    /** ISO alpha code; vPOS may echo EUR as its numeric code 978. */
    public function currency()
    {
        $currency = strtoupper(trim($this->field('currency')));

        return $currency === '978' ? 'EUR' : $currency;
    }

    public function txId()
    {
        return $this->field('txId');
    }

    public function paymentRef()
    {
        return $this->field('paymentRef');
    }

    public function payMethod()
    {
        return $this->field('payMethod');
    }

    public function message()
    {
        return $this->field('message');
    }

    /** What is safe to log: names, status, ids and amounts, no customer data. */
    public function summary()
    {
        return [
            'verified' => $this->verified,
            'reason' => $this->reason,
            'posted_keys' => $this->postedKeys,
            'posted_order_matches' => $this->postedOrderMatches,
            'orderid' => substr($this->orderId(), 0, 60),
            'status' => substr($this->status(), 0, 20),
            'orderAmount' => substr($this->amount(), 0, 20),
            'currency' => substr($this->currency(), 0, 8),
            'payMethod' => substr($this->payMethod(), 0, 30),
            'txId' => substr($this->txId(), 0, 64),
            'paymentRef' => substr($this->paymentRef(), 0, 64),
            'message' => substr($this->message(), 0, 200),
        ];
    }

    private function field($name)
    {
        return isset($this->fields[$name]) ? (string) $this->fields[$name] : '';
    }
}
