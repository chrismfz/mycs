<?php

// clients.nixpal.com/zlic.php: a real file, so a web server that runs *.php files directly (nginx «location ~ \.php$»,
// Apache) still reaches the app. Seen as index.php (review R1): otherwise «/zlic.php» becomes the base URL and the path
// «/», the home page; this way the route /zlic.php (routes/zlic.php) answers.
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/index.php';
