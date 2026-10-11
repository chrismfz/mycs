<?php

// Copy to config.php next to vendor/ (outside the docroot). Never commit config.php.
return [
    // WHMCS today: the WHMCS database. Blesta later: the Blesta database.
    'dsn' => 'mysql:host=localhost;dbname=DATABASE;charset=utf8mb4',
    'username' => 'USER',
    'password' => 'PASSWORD',

    // 'whmcs' = Licensing Addon tables (mod_licensing, ...); 'blesta' = the nixpal_licensing plugin tables.
    'schema' => 'whmcs',

    // true = never write (no binding, no log, no lastaccess): for a shadow-run
    // next to the old zlic.php on the same database.
    'read_only' => true,

    // The "dir" zlic.php has always reported; kept for the bindings' history.
    'directory' => '/home/clnixpal/public_html',
];
