<?php

namespace Nixpal\Mailcow;

/**
 * The package limits a mailcow domain is created/edited with (the WHMCS module's
 * configoption1-7). Quotas are in MB, as mailcow's domain API takes them.
 */
final class DomainLimits
{
    const FRAMES = ['s', 'm', 'h', 'd'];

    /** @var int */
    public $aliases = 100;

    /** @var int */
    public $mailboxes = 10;

    /** @var int */
    public $defaultQuotaMb = 1024;

    /** @var int */
    public $maxQuotaMb = 1024;

    /** @var int */
    public $domainQuotaMb = 10240;

    /** @var int */
    public $rateLimit = 10;

    /** @var string */
    public $rateFrame = 's';

    /**
     * @param  array<string,mixed>  $meta  package meta (aliases, mailboxes, defquota, maxquota, quota, rl_value, rl_frame)
     */
    public static function fromMeta(array $meta)
    {
        $limits = new self;
        $int = function ($key, $default) use ($meta) {
            $value = $meta[$key] ?? '';

            return is_numeric($value) ? max(0, (int) $value) : $default;
        };
        $limits->aliases = $int('aliases', $limits->aliases);
        $limits->mailboxes = $int('mailboxes', $limits->mailboxes);
        $limits->defaultQuotaMb = $int('defquota', $limits->defaultQuotaMb);
        $limits->maxQuotaMb = $int('maxquota', $limits->maxQuotaMb);
        $limits->domainQuotaMb = $int('quota', $limits->domainQuotaMb);
        $limits->rateLimit = $int('rl_value', $limits->rateLimit);
        $frame = (string) ($meta['rl_frame'] ?? '');
        $limits->rateFrame = in_array($frame, self::FRAMES, true) ? $frame : $limits->rateFrame;

        return $limits;
    }

    /**
     * Problems a package admin should fix before saving (mailcow would refuse the domain).
     *
     * @return string[] codes: defquota_over_maxquota, maxquota_over_quota
     */
    public function problems()
    {
        $problems = [];
        if ($this->maxQuotaMb > 0 && $this->defaultQuotaMb > $this->maxQuotaMb) {
            $problems[] = 'defquota_over_maxquota';
        }
        if ($this->domainQuotaMb > 0 && $this->maxQuotaMb > $this->domainQuotaMb) {
            $problems[] = 'maxquota_over_quota';
        }

        return $problems;
    }

    /** The "attr" for creating a domain. */
    public function toAttr($domain)
    {
        return ['description' => (string) $domain] + $this->toLimitAttr() + [
            'backupmx' => '0',
            'relay_all_recipients' => '0',
        ];
    }

    /** Only the limits, for editing an existing domain. */
    public function toLimitAttr()
    {
        return [
            'aliases' => (string) $this->aliases,
            'mailboxes' => (string) $this->mailboxes,
            'defquota' => (string) $this->defaultQuotaMb,
            'maxquota' => (string) $this->maxQuotaMb,
            'quota' => (string) $this->domainQuotaMb,
            'rl_value' => (string) $this->rateLimit,
            'rl_frame' => $this->rateFrame,
        ];
    }
}
