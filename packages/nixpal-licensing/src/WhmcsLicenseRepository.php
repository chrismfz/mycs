<?php

namespace Nixpal\Licensing;

use PDO;

/**
 * LicenseRepository over the WHMCS Licensing Addon schema (mod_licensing,
 * mod_licensinglog, tblhosting, tblproducts, tblclients, tblcustomfields*).
 *
 * Used to run the new zlic.php against the live WHMCS database before Blesta
 * exists (shadow-run). With $readOnly the repository never writes, so it can
 * run next to the old zlic.php without double logging or double binding.
 *
 * Product configoptions (Licensing Addon): 5 = Allow IP Conflict, 8 = Secret Key.
 */
final class WhmcsLicenseRepository implements LicenseRepository
{
    /** The product custom field migration/prepare_whmcs_copy.sh adds (name, description). */
    const PREPARE_FIELD = 'license_key';

    const PREPARE_FIELD_DESCRIPTION = 'Imported from the Licensing Addon';

    /** @var PDO */
    private $db;

    /** @var bool */
    private $readOnly;

    /** @var callable(): int */
    private $clock;

    public function __construct(PDO $db, $readOnly = false, $clock = null)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = $db;
        $this->readOnly = (bool) $readOnly;
        $this->clock = $clock ?: 'time';
    }

    public function findByKey($licenseKey)
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.licensekey, l.validdomain, l.validip, l.validdirectory, l.reissues,
                    l.status, l.lastaccess,
                    h.id AS serviceid, h.packageid, h.regdate, h.nextduedate, h.billingcycle,
                    p.name AS productname, p.configoption5, p.configoption8,
                    c.firstname, c.lastname, c.companyname, c.email
             FROM mod_licensing l
             JOIN tblhosting h ON h.id = l.serviceid
             JOIN tblproducts p ON p.id = h.packageid
             JOIN tblclients c ON c.id = h.userid
             WHERE l.licensekey = ?'
        );
        $stmt->execute([(string) $licenseKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $license = new License;
        $license->id = (int) $row['id'];
        $license->licenseKey = $row['licensekey'];
        $license->status = $row['status'];
        $license->validDomain = (string) $row['validdomain'];
        $license->validIp = (string) $row['validip'];
        $license->validDirectory = (string) $row['validdirectory'];
        $license->reissues = (int) $row['reissues'];
        $license->lastAccess = $row['lastaccess'];
        $license->secret = (string) $row['configoption8'];
        $license->allowIpConflict = $row['configoption5'] === 'on';

        $tags = [
            'registeredname' => trim($row['firstname'] . ' ' . $row['lastname']),
            'companyname' => (string) $row['companyname'],
            'email' => (string) $row['email'],
            'serviceid' => (string) $row['serviceid'],
            'productid' => (string) $row['packageid'],
            'productname' => (string) $row['productname'],
            // tblhosting.regdate is a DATE; verify.php prints it as a datetime.
            'regdate' => substr((string) $row['regdate'], 0, 10) . ' 00:00:00',
            'nextduedate' => (string) $row['nextduedate'],
            'billingcycle' => (string) $row['billingcycle'],
        ];
        $customfields = $this->customFields((int) $row['packageid'], (int) $row['serviceid']);
        if ($customfields !== null) {
            $tags['customfields'] = $customfields;
        }
        $license->serviceTags = $tags;

        return $license;
    }

    public function touch(License $license, $now)
    {
        if ($this->readOnly) {
            return;
        }
        $this->db->prepare('UPDATE mod_licensing SET lastaccess = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', $now), $license->id]);
    }

    public function bind(License $license, $now)
    {
        if ($this->readOnly) {
            return true;
        }
        $stmt = $this->db->prepare(
            "UPDATE mod_licensing
             SET validdomain = ?, validip = ?, validdirectory = ?, status = ?, lastaccess = ?
             WHERE id = ? AND status IN ('Active', 'Reissued')
               AND (TRIM(REPLACE(COALESCE(validip, ''), ',', '')) = '' OR status = 'Reissued')"
        );
        $stmt->execute([
            $license->validDomain,
            $license->validIp,
            $license->validDirectory,
            $license->status,
            date('Y-m-d H:i:s', $now),
            $license->id,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function log($license, $domain, $ip, $directory, $message)
    {
        if ($this->readOnly) {
            return;
        }
        $this->db->prepare(
            'INSERT INTO mod_licensinglog (licenseid, domain, ip, path, message, datetime) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $license ? $license->id : 0,
            (string) $domain,
            (string) $ip,
            (string) $directory,
            (string) $message,
            date('Y-m-d H:i:s', call_user_func($this->clock)),
        ]);
    }

    /**
     * "name=value|name=value" for the product's custom fields, or null when the
     * product has none (verify.php then omits the tag). Values are urlencoded;
     * the exact encoding of special characters is still to be confirmed by the
     * shadow-run (no client reads this tag).
     *
     * @return string|null
     */
    private function customFields($productId, $serviceId)
    {
        $stmt = $this->db->prepare(
            "SELECT f.fieldname, v.value
             FROM tblcustomfields f
             LEFT JOIN tblcustomfieldsvalues v ON v.fieldid = f.id AND v.relid = ?
             WHERE f.type = 'product' AND f.relid = ?
               AND NOT (f.fieldname = ? AND COALESCE(f.description, '') = ?)
             ORDER BY f.sortorder, f.id"
        );
        // The field migration/prepare_whmcs_copy.sh adds to a copy is not part of
        // what verify.php reported.
        $stmt->execute([$serviceId, $productId, self::PREPARE_FIELD, self::PREPARE_FIELD_DESCRIPTION]);
        $pairs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $pairs[] = $field['fieldname'] . '=' . urlencode((string) $field['value']);
        }

        return $pairs === [] ? null : implode('|', $pairs);
    }
}
