<?php
if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

$root = getenv('SUITECRM_ROOT') ?: '/bitnami/suitecrm';
if (!is_dir($root . '/public/legacy')) {
    fwrite(STDERR, "Invalid SUITECRM_ROOT or public/legacy missing: {$root}\n");
    exit(2);
}
chdir($root . '/public/legacy');
require_once 'include/entryPoint.php';

global $current_user, $app_list_strings, $timedate, $sugar_version, $sugar_flavor, $suitecrm_version;
if (file_exists('sugar_version.php')) {
    require 'sugar_version.php';
}
if (file_exists('suitecrm_version.php')) {
    require 'suitecrm_version.php';
}

$current_user = BeanFactory::getBean('Users', '1');
if (empty($current_user->id)) {
    fwrite(STDERR, "Admin user id=1 not found\n");
    exit(2);
}

$outerVersion = 'unknown';
if (file_exists($root . '/VERSION')) {
    $outerVersion = trim((string)file_get_contents($root . '/VERSION'));
} elseif (file_exists($root . '/package.json')) {
    $pkg = json_decode((string)file_get_contents($root . '/package.json'), true);
    if (is_array($pkg) && !empty($pkg['version'])) {
        $outerVersion = (string)$pkg['version'];
    }
}

echo "SMOKE_OK"
    . " | admin_user=" . $current_user->user_name
    . " | suitecrm8_version=" . $outerVersion
    . " | legacy_suitecrm_version=" . ($suitecrm_version ?? 'n/a')
    . " | sugar_version=" . ($sugar_version ?? 'n/a')
    . " | sugar_flavor=" . ($sugar_flavor ?? 'n/a') . "\n";
exit(0);
