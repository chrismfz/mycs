<?php

namespace Nixpal\Licensing;

/**
 * One license row plus what verify.php reports about its service and product.
 * Plain data holder: the repository loads and saves it.
 */
final class License
{
    const ACTIVE = 'Active';

    const REISSUED = 'Reissued';

    const SUSPENDED = 'Suspended';

    const EXPIRED = 'Expired';

    /** @var int */
    public $id;

    /** @var string */
    public $licenseKey;

    /** @var string Active|Reissued|Suspended|Expired */
    public $status;

    /** @var string comma separated */
    public $validDomain = '';

    /** @var string comma separated */
    public $validIp = '';

    /** @var string comma separated */
    public $validDirectory = '';

    /** @var int */
    public $reissues = 0;

    /** @var string|null Y-m-d H:i:s */
    public $lastAccess;

    /** @var string product PSK ("Secret Key") */
    public $secret;

    /** @var bool skip the IP check ("Allow IP Conflict") */
    public $allowIpConflict = false;

    /**
     * Service/product tags exactly as verify.php names them, in its order:
     * registeredname, companyname, email, serviceid, productid, productname,
     * regdate, nextduedate, billingcycle, and customfields only when the
     * product has custom fields.
     *
     * @var array<string,string>
     */
    public $serviceTags = [];

    /** @return string[] */
    public function validIps()
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->validIp)), fn ($ip) => $ip !== ''));
    }
}
