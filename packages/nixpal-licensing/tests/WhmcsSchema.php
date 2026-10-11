<?php

namespace Nixpal\Licensing\Tests;

use PDO;

/** Minimal SQLite copy of the WHMCS tables the repository reads, seeded from the fixtures. */
final class WhmcsSchema
{
    const SECRET = 'TestProductSecret';

    public static function create(PDO $db)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE mod_licensing (id INTEGER PRIMARY KEY, serviceid INT, licensekey TEXT, validdomain TEXT,
            validip TEXT, validdirectory TEXT, reissues INT, status TEXT, lastaccess TEXT)');
        $db->exec('CREATE TABLE mod_licensinglog (id INTEGER PRIMARY KEY, licenseid INT, domain TEXT, ip TEXT,
            path TEXT, message TEXT, datetime TEXT)');
        $db->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, packageid INT, regdate TEXT,
            nextduedate TEXT, billingcycle TEXT)');
        $db->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, configoption5 TEXT, configoption8 TEXT)');
        $db->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT)');
        $db->exec('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INT, fieldname TEXT, sortorder INT, description TEXT NOT NULL DEFAULT \'\')');
        $db->exec('CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INT, relid INT, value TEXT)');

        $db->exec("INSERT INTO tblclients VALUES (1, 'Test', 'Client', 'Test Company', 'client@example.com')");
        // DEVNODE (no custom fields), Zonecloud Agent, Zonecloud Web Controller
        $db->exec("INSERT INTO tblproducts VALUES (8, 'DEVNODE', 'on', '" . self::SECRET . "')");
        $db->exec("INSERT INTO tblproducts VALUES (5, 'Zonecloud Agent', '', '" . self::SECRET . "')");
        $db->exec("INSERT INTO tblproducts VALUES (4, 'Zonecloud Web Controller', '', '" . self::SECRET . "')");
        $db->exec("INSERT INTO tblcustomfields (id, type, relid, fieldname, sortorder) VALUES (3, 'product', 5, 'comments', 1)");
        $db->exec("INSERT INTO tblcustomfields (id, type, relid, fieldname, sortorder) VALUES (1, 'product', 4, 'comments', 0)");

        $db->exec("INSERT INTO tblhosting VALUES (1001, 1, 8, '2023-10-23', '0000-00-00', 'Free Account')");
        $db->exec("INSERT INTO tblhosting VALUES (1002, 1, 5, '2023-07-25', '2025-05-25', 'Monthly')");
        $db->exec("INSERT INTO tblhosting VALUES (1003, 1, 4, '2024-02-01', '2026-09-01', 'Monthly')");

        $bind = "'host.example.com,www.host.example.com', '198.51.100.7', '/home/clnixpal/public_html'";
        $db->exec("INSERT INTO mod_licensing VALUES (1, 1001, 'ZCNode-00000000000000aa', $bind, 3, 'Active', '2023-11-04 12:30:57')");
        $db->exec("INSERT INTO mod_licensing VALUES (2, 1002, 'ZCAgent-00000000000000bb', $bind, 0, 'Expired', '2026-10-08 18:54:04')");
        $db->exec("INSERT INTO mod_licensing VALUES (3, 1003, 'ZCWeb-00000000000000cc', $bind, 0, 'Suspended', '2026-10-08 18:18:09')");
    }
}
