<?php
/**
 * Automated Installer for SuiteCRM 8 Indonesian Language Pack (id_ID)
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

$root = getenv('SUITECRM_ROOT') ?: '/bitnami/suitecrm';
if (!is_dir($root . '/public/legacy')) {
    fwrite(STDERR, "ERROR: Invalid SUITECRM_ROOT: {$root}\n");
    exit(2);
}
chdir($root . '/public/legacy');
require_once 'include/entryPoint.php';

global $current_user, $sugar_config, $sugar_version;
$current_user = BeanFactory::getBean('Users', '1');
if (empty($current_user->id)) {
    fwrite(STDERR, "ERROR: Admin user id=1 not found\n");
    exit(2);
}

$zipCandidates = [
    '/seed/id_ID_SuiteCRM_lang_8.9.zip',
    '/tmp/id_ID.zip',
];

$zipFile = null;
foreach ($zipCandidates as $cand) {
    if (file_exists($cand)) {
        $zipFile = $cand;
        break;
    }
}

if (!$zipFile) {
    fwrite(STDERR, "ERROR: Language pack zip not found in candidate paths\n");
    exit(2);
}

echo "[LANG] Extracting {$zipFile} ...\n";
$extractTo = '/tmp/lang_id';
$zip = new ZipArchive();
if ($zip->open($zipFile) === true) {
    $zip->extractTo($extractTo);
    $zip->close();
} else {
    fwrite(STDERR, "ERROR: Failed to open zip {$zipFile}\n");
    exit(2);
}

function copy_dir_recursive($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst, 0775, true);
    while (false !== ($file = readdir($dir))) {
        if ($file !== '.' && $file !== '..') {
            if (is_dir($src . '/' . $file)) {
                copy_dir_recursive($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

if (is_dir($extractTo . '/include')) {
    copy_dir_recursive($extractTo . '/include', 'include');
}
if (is_dir($extractTo . '/modules')) {
    copy_dir_recursive($extractTo . '/modules', 'modules');
}
if (is_dir($extractTo . '/install')) {
    copy_dir_recursive($extractTo . '/install', 'install');
}

echo "[LANG] Registering id_ID in config.php ...\n";
$clean_config = loadCleanConfig();
$clean_config['languages']['id_ID'] = 'Indonesian (Indonesia)';
$clean_config['disabled_languages'] = '';
rebuildConfigFile($clean_config, $sugar_version);

require_once 'modules/Administration/Administration.php';
$admin = new Administration();
$admin->saveSetting('system', 'languages', json_encode([
    'en_us' => 'English (US)',
    'id_ID' => 'Indonesian (Indonesia)',
]));

echo "[LANG] Rebuilding language caches ...\n";
require_once 'modules/Administration/QuickRepairAndRebuild.php';
$repair = new RepairAndClear();
$repair->repairAndClearAll(['clearAll'], ['Users', 'Accounts', 'Opportunities', 'Leads'], false, false);

echo "[LANG] Indonesian Language Pack (id_ID) successfully installed and enabled!\n";
exit(0);

