<?php

use Nixpal\Licensing\WhmcsLicenseRepository;
use Nixpal\Licensing\Zlic;

/**
 * nixpal license endpoint: drop-in replacement for the WHMCS-era zlic.php.
 *
 * Same URL, same input (license + license_PSK, POST first, GET for legacy
 * clients), same JSON. All logic lives in lib/nixpal-licensing; this file only
 * wires it up, so it can sit in any docroot (WHMCS today, Blesta later).
 *
 * Install: copy this file to the docroot as zlic.php, and the library (with
 * composer install --no-dev) plus config.php to a directory OUTSIDE the
 * docroot. Point NIXPAL_ZLIC_HOME at that directory, or keep the default
 * sibling ../zlic (e.g. /home/<user>/public_html/zlic.php -> /home/<user>/zlic).
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

$home = getenv('NIXPAL_ZLIC_HOME') ?: dirname(__DIR__) . '/zlic';

try {
    require $home . '/vendor/autoload.php';
    $config = require $home . '/config.php';

    $pdo = new PDO($config['dsn'], $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    switch ($config['schema']) {
        case 'whmcs':
            $repository = new WhmcsLicenseRepository($pdo, !empty($config['read_only']));
            break;
            // 'mycs': the MyCS repository comes with the nixpal_licensing extension (Phase 1).
        default:
            throw new RuntimeException('Unknown schema ' . $config['schema']);
    }

    $zlic = new Zlic(
        $repository,
        null,
        null,
        null,
        isset($config['directory']) ? $config['directory'] : '/home/clnixpal/public_html'
    );
    $response = $zlic->handle($_POST + $_GET, isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
} catch (Throwable $e) {
    error_log('[zlic] ' . get_class($e) . ': ' . $e->getMessage());
    // What zlic.php answered when verify.php was unreachable: clients treat it
    // as a failed check and fall back to their stored due date.
    // Plain array: the autoloader itself may be what failed.
    http_response_code(200);
    echo json_encode(['status' => 'Invalid', 'description' => 'Remote Check Failed']);

    return;
}

http_response_code($response->httpStatus);
echo $response->json();
