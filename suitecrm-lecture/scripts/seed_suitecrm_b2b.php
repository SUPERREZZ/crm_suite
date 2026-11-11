<?php
/**
 * Idempotent B2B PURE CRM Seeder for SuiteCRM 8
 * Business Case: PT Sumber Kopi Nusantara (Surabaya, East Java)
 * 5-Year Comprehensive CRM Lifecycle (2021 - 2026)
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

$root = getenv('SUITECRM_ROOT') ?: '/bitnami/suitecrm';
if (!is_dir($root . '/public/legacy')) {
    fwrite(STDERR, "ERROR: Invalid SUITECRM_ROOT or missing public/legacy: {$root}\n");
    exit(2);
}
chdir($root . '/public/legacy');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require_once 'include/entryPoint.php';

global $current_user, $app_list_strings, $timedate, $current_language, $sugar_config, $db;

$current_user = BeanFactory::getBean('Users', '1');
if (empty($current_user->id)) {
    fwrite(STDERR, "ERROR: Admin user id=1 not found\n");
    exit(2);
}

if (empty($current_language)) {
    $current_language = $sugar_config['default_language'] ?? 'en_us';
}
if (empty($app_list_strings) || !is_array($app_list_strings)) {
    $app_list_strings = return_app_list_strings_language($current_language);
}
if (empty($timedate)) {
    $timedate = TimeDate::getInstance();
}

$stats = [];

/**
 * Validates that a dropdown key exists in $app_list_strings[$dom].
 */
function must_key(string $dom, string $key): string
{
    global $app_list_strings;
    if (!isset($app_list_strings[$dom]) || !is_array($app_list_strings[$dom])) {
        fwrite(STDERR, "ERROR: Dropdown '{$dom}' not found in \$app_list_strings.\n");
        exit(2);
    }
    if (!array_key_exists($key, $app_list_strings[$dom])) {
        $validKeys = implode(', ', array_keys($app_list_strings[$dom]));
        fwrite(STDERR, "ERROR: Invalid key '{$key}' for dropdown '{$dom}'. Valid keys: [{$validKeys}]\n");
        exit(2);
    }
    return $key;
}

/**
 * Idempotently retrieves or creates a SugarBean using retrieve_by_string_fields.
 */
function find_or_create(string $module, array $uniqueFields, array $data, ?string $customDateEntered = null): SugarBean
{
    global $stats, $db;
    if (!isset($stats[$module])) {
        $stats[$module] = ['created' => 0, 'skipped' => 0];
    }

    $labelParts = [];
    foreach ($uniqueFields as $k => $v) {
        $labelParts[] = "{$k}='{$v}'";
    }
    $label = implode(', ', $labelParts);

    $probe = BeanFactory::newBean($module);
    if (!$probe) {
        fwrite(STDERR, "ERROR: BeanFactory::newBean('{$module}') returned null\n");
        exit(2);
    }

    $found = $probe->retrieve_by_string_fields($uniqueFields, false, true);
    if ($found === null || empty($found->id)) {
        $probe2 = BeanFactory::newBean($module);
        $found = $probe2->retrieve_by_string_fields($uniqueFields, true, true);
    }

    // Handle length truncation for safety
    if ($found === null || empty($found->id)) {
        $truncatedFields = $uniqueFields;
        $changed = false;
        foreach ($uniqueFields as $f => $val) {
            $maxLen = isset($probe->field_defs[$f]['len']) ? (int)$probe->field_defs[$f]['len'] : 0;
            if ($maxLen > 0 && is_string($val)) {
                $encoded = to_html($val);
                if (strlen($encoded) > $maxLen) {
                    $truncatedFields[$f] = from_html(substr($encoded, 0, $maxLen));
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $probe3 = BeanFactory::newBean($module);
            $found = $probe3->retrieve_by_string_fields($truncatedFields, false, true);
        }
    }

    if ($found !== null && !empty($found->id)) {
        $stats[$module]['skipped']++;
        $updated = false;
        foreach ($data as $field => $value) {
            if ($field === 'user_hash' || $field === 'sugar_login' || $field === 'portal_only') {
                continue;
            }
            if ($value !== null && $value !== '' && (!isset($found->$field) || $found->$field === null || $found->$field === '')) {
                $found->$field = $value;
                $updated = true;
            }
        }
        if ($updated) {
            $found->save(false);
            echo "[UPDATE] {$module} ({$label}) -> id={$found->id}\n";
        } else {
            echo "[SKIP] {$module} ({$label}) -> id={$found->id}\n";
        }

        if ($customDateEntered !== null) {
            $tbl = $found->table_name;
            $db->query("UPDATE {$tbl} SET date_entered = '{$customDateEntered}' WHERE id = '{$found->id}'");
        }
        return $found;
    }

    $bean = BeanFactory::newBean($module);
    foreach ($data as $field => $value) {
        $bean->$field = $value;
    }
    $bean->save(false);

    if (empty($bean->id)) {
        fwrite(STDERR, "ERROR: Failed saving {$module} ({$label})\n");
        exit(2);
    }

    if ($customDateEntered !== null) {
        $tbl = $bean->table_name;
        $db->query("UPDATE {$tbl} SET date_entered = '{$customDateEntered}' WHERE id = '{$bean->id}'");
    }

    $stats[$module]['created']++;
    echo "[CREATE] {$module} ({$label}) -> id={$bean->id}\n";
    return $bean;
}

/**
 * Loads a relationship on $a and adds $bId.
 */
function link_beans(SugarBean $a, string $relName, string $bId): void
{
    if (!$a->load_relationship($relName)) {
        fwrite(
            STDERR,
            "ERROR: load_relationship('{$relName}') returned false on {$a->module_dir} (id={$a->id})\n"
        );
        exit(2);
    }
    $a->$relName->add($bId);
}

/**
 * Assigns primary email address cleanly in SuiteCRM email tables.
 */
function set_bean_email(SugarBean $bean, string $email): void
{
    if (empty($email)) {
        return;
    }
    if (isset($bean->emailAddress) && is_object($bean->emailAddress)) {
        $bean->emailAddress->addAddress($email, true, false, false, false, null, 'opted_in');
        $bean->emailAddress->save($bean->id, $bean->module_dir);
    }
}

/**
 * Helper to compute UTC DB datetime string from ISO string.
 */
function utc_db_datetime(string $dateTimeStr): string
{
    global $timedate;
    return $timedate->asDb(new DateTime($dateTimeStr, new DateTimeZone('UTC')));
}

/**
 * Helper to compute UTC DB date string (YYYY-MM-DD).
 */
function utc_db_date(string $dateTimeStr): string
{
    global $timedate;
    if (method_exists($timedate, 'asDbDate')) {
        return $timedate->asDbDate(new DateTime($dateTimeStr, new DateTimeZone('UTC')));
    }
    return (new DateTime($dateTimeStr, new DateTimeZone('UTC')))->format('Y-m-d');
}

echo "=== Starting PT Sumber Kopi Nusantara 5-Year B2B CRM Seeder ===\n";

// Transition cleanup for older legacy 1-year demo records if re-seeding existing database
$db->query("UPDATE calls SET deleted = 1 WHERE name != '[OVERDUE] Konfirmasi Demo Mesin Kopi Kontainer' AND status = 'Planned'");
$db->query("UPDATE opportunities SET deleted = 1 WHERE name IN ('[NEW BIZ] Paket Espresso 2-Group & Biji Kopi', '[RETENTION] Reaktivasi Kontrak Biji Kopi', '[UPSELL] Grinder Single Origin dan Sirup', '[WON] Mesin Aurelia dan Kontrak Kopi', '[LOST] Mesin Espresso Cabang')");
$db->query("UPDATE accounts SET deleted = 1 WHERE name = 'Grand Majapahit Hotel'");
$db->query("DELETE FROM accounts_contacts WHERE account_id IN (SELECT id FROM accounts WHERE deleted = 1)");
$db->query("UPDATE contacts SET deleted = 1 WHERE first_name = 'Wahyu' AND last_name = 'Pratama' AND title != 'Founder & Owner'");

// ---------------------------------------------------------------------------
// 0. Currency: IDR (Rp, rate = 1.0) and System Default Currency Setting
// ---------------------------------------------------------------------------
$idrCurrencyId = '';
try {
    $idrBean = find_or_create(
        'Currencies',
        ['iso4217' => 'IDR'],
        [
            'name' => 'Indonesian Rupiah',
            'symbol' => 'Rp',
            'iso4217' => 'IDR',
            'conversion_rate' => 1.0,
            'status' => 'Active',
        ]
    );
    $idrCurrencyId = (string)$idrBean->id;

    // Set system-wide default currency to IDR in config_override.php
    require_once 'modules/Configurator/Configurator.php';
    $configurator = new Configurator();
    $configurator->loadConfig();
    $configurator->config['default_currency_iso4217'] = 'IDR';
    $configurator->config['default_currency_name'] = 'Indonesian Rupiah';
    $configurator->config['default_currency_symbol'] = 'Rp';
    $configurator->config['currency'] = 'IDR';
    $configurator->config['default_currency_significant_digits'] = 0;

    $crmHost = getenv('SUITECRM_HOST') ?: 'localhost';
    if ($crmHost !== 'localhost' && $crmHost !== '127.0.0.1') {
        $configurator->config['site_url'] = 'http://' . $crmHost;
        echo "[CONFIG] Set site_url to http://{$crmHost} (clean reverse proxy)\n";
    }

    $configurator->saveConfig();
    echo "[CONFIG] System default currency set to IDR (Rp)\n";

    // Ensure all existing records use IDR currency
    $db->query("UPDATE opportunities SET currency_id = '{$idrCurrencyId}' WHERE deleted = 0");
    $db->query("UPDATE campaigns SET currency_id = '{$idrCurrencyId}' WHERE deleted = 0");
} catch (Throwable $e) {
    fwrite(STDERR, "[WARN] IDR currency / system config setup skipped: " . $e->getMessage() . "\n");
}

// ---------------------------------------------------------------------------
// 1. Users: bima & citra (Demo_2026!)
// ---------------------------------------------------------------------------
$userStatusActive = must_key('user_status_dom', 'Active');
$empStatusActive = isset($app_list_strings['employee_status_dom']['Active']) ? 'Active' : 'Active';

$demoPasswordHash = method_exists('User', 'getPasswordHash')
    ? User::getPasswordHash('Demo_2026!')
    : password_hash('Demo_2026!', PASSWORD_BCRYPT);

$userBima = find_or_create(
    'Users',
    ['user_name' => 'bima'],
    [
        'user_name' => 'bima',
        'first_name' => 'Bima',
        'last_name' => 'Arya',
        'title' => 'B2B Account Executive (Acquisition)',
        'department' => 'Sales & Business Development',
        'status' => $userStatusActive,
        'employee_status' => $empStatusActive,
        'is_admin' => 0,
        'user_hash' => $demoPasswordHash,
        'sugar_login' => 1,
        'portal_only' => 0,
    ]
);
set_bean_email($userBima, 'bima@sumberkopi.id');
if (!empty($idrCurrencyId)) {
    $userBima->setPreference('currency', $idrCurrencyId);
    $userBima->setPreference('currency_show_preferred', '1');
    $userBima->setPreference('default_currency_significant_digits', '0');
    $userBima->savePreferencesToDB();
}

$userCitra = find_or_create(
    'Users',
    ['user_name' => 'citra'],
    [
        'user_name' => 'citra',
        'first_name' => 'Citra',
        'last_name' => 'Lestari',
        'title' => 'Senior Customer Success & Key Accounts Manager',
        'department' => 'Customer Success & Retention',
        'status' => $userStatusActive,
        'employee_status' => $empStatusActive,
        'is_admin' => 0,
        'user_hash' => $demoPasswordHash,
        'sugar_login' => 1,
        'portal_only' => 0,
    ]
);
set_bean_email($userCitra, 'citra@sumberkopi.id');
if (!empty($idrCurrencyId)) {
    $userCitra->setPreference('currency', $idrCurrencyId);
    $userCitra->setPreference('currency_show_preferred', '1');
    $userCitra->setPreference('default_currency_significant_digits', '0');
    $userCitra->savePreferencesToDB();
}

// ---------------------------------------------------------------------------
// 2. Campaigns (3 spanning 2022, 2024, 2026)
// ---------------------------------------------------------------------------
$campTypeWeb = must_key('campaign_type_dom', 'Web');
$campStatusActive = must_key('campaign_status_dom', 'Active');
$campStatusComplete = must_key('campaign_status_dom', 'Complete');

$campExpo2026 = find_or_create(
    'Campaigns',
    ['name' => 'Pameran F&B Expo 2026 Surabaya'],
    [
        'name' => 'Pameran F&B Expo 2026 Surabaya',
        'campaign_type' => $campTypeWeb,
        'status' => $campStatusActive,
        'currency_id' => $idrCurrencyId,
        'start_date' => utc_db_date('2026-02-01'),
        'end_date' => utc_db_date('2026-03-31'),
        'budget' => 25000000.0,
        'actual_cost' => 21500000.0,
        'expected_revenue' => 200000000.0,
        'expected_cost' => 25000000.0,
        'assigned_user_id' => $userBima->id,
        'description' => 'Booth pameran Grand City Convention Surabaya - target cafe baru & hotel di Jawa Timur.',
    ],
    '2026-01-15 08:00:00'
);

$campFest2024 = find_or_create(
    'Campaigns',
    ['name' => 'East Java Coffee Festival 2024'],
    [
        'name' => 'East Java Coffee Festival 2024',
        'campaign_type' => $campTypeWeb,
        'status' => $campStatusComplete,
        'currency_id' => $idrCurrencyId,
        'start_date' => utc_db_date('2024-05-10'),
        'end_date' => utc_db_date('2024-05-15'),
        'budget' => 18000000.0,
        'actual_cost' => 17500000.0,
        'expected_revenue' => 150000000.0,
        'expected_cost' => 18000000.0,
        'assigned_user_id' => $userBima->id,
        'description' => 'Sponsorship cupping competition & workshop mesin espresso komersial.',
    ],
    '2024-04-01 09:00:00'
);

$campFair2022 = find_or_create(
    'Campaigns',
    ['name' => 'Surabaya Hospitality Fair 2022'],
    [
        'name' => 'Surabaya Hospitality Fair 2022',
        'campaign_type' => $campTypeWeb,
        'status' => $campStatusComplete,
        'currency_id' => $idrCurrencyId,
        'start_date' => utc_db_date('2022-08-01'),
        'end_date' => utc_db_date('2022-08-05'),
        'budget' => 30000000.0,
        'actual_cost' => 29000000.0,
        'expected_revenue' => 350000000.0,
        'expected_cost' => 30000000.0,
        'assigned_user_id' => $userCitra->id,
        'description' => 'Pameran peralatan hotel & restoran - penetrasi segmen hotel bintang 4 & 5.',
    ],
    '2022-07-10 10:00:00'
);

// ---------------------------------------------------------------------------
// 3. Accounts (15) & Contacts (15) - 5-Year Portfolio
// ---------------------------------------------------------------------------
$accTypeCustomer = must_key('account_type_dom', 'Customer');
$accTypeProspect = must_key('account_type_dom', 'Prospect');
$indHospitality = must_key('industry_dom', 'Hospitality');
$indRetail = must_key('industry_dom', 'Retail');
$indEducation = must_key('industry_dom', 'Education');
$indHealthcare = must_key('industry_dom', 'Healthcare');
$indOther = must_key('industry_dom', 'Other');

$accountsDefs = [
    // 5-YEAR VETERAN CLIENTS (Sejak 2021)
    'majapahit' => [
        'name' => 'Grand Majapahit Hotel Surabaya',
        'type' => $accTypeCustomer,
        'industry' => $indHospitality,
        'revenue' => 12000000000,
        'employees' => 180,
        'phone' => '031-5454000',
        'website' => 'https://grandmajapahit-hotel.com',
        'street' => 'Jl. Tunjungan No. 65, Genteng',
        'city' => 'Surabaya',
        'state' => 'Jawa Timur',
        'postal' => '60275',
        'assigned' => $userCitra->id,
        'date_entered' => '2021-05-10 09:15:00',
        'desc' => 'Klien hotel bintang 5 tertua sejak Mei 2021. Pengguna 2 unit mesin espresso komersial Aurelia Wave dan pasokan rutin biji kopi blend hotel 120 kg/bulan.',
        'contact' => [
            'first' => 'Antonius',
            'last' => 'Wibowo',
            'title' => 'Director of Food & Beverage',
            'dept' => 'F&B Management',
            'phone' => '0811-3344-5566',
            'email' => 'antonius.wibowo@grandmajapahit-hotel.com',
            'date_entered' => '2021-05-10 09:20:00',
        ],
    ],
    'titik_temu' => [
        'name' => 'Kopi Titik Temu Surabaya',
        'type' => $accTypeCustomer,
        'industry' => $indRetail,
        'revenue' => 3500000000,
        'employees' => 35,
        'phone' => '031-5678901',
        'website' => 'https://kopititiktemu.id',
        'street' => 'Jl. Raya Darmo No. 88, Wonokromo',
        'city' => 'Surabaya',
        'state' => 'Jawa Timur',
        'postal' => '60241',
        'assigned' => $userCitra->id,
        'date_entered' => '2021-10-05 11:30:00',
        'desc' => 'Cafe chain mitra sejak akhir 2021. Menggunakan mesin espresso multi-boiler Victoria Arduino di cabang Darmo dan Surabaya Barat. Kontrak 80 kg/bulan.',
        'contact' => [
            'first' => 'Danang',
            'last' => 'Prasetyo',
            'title' => 'Owner & Head of Operations',
            'dept' => 'Executive Office',
            'phone' => '0812-3456-7890',
            'email' => 'danang@kopititiktemu.id',
            'date_entered' => '2021-10-05 11:35:00',
        ],
    ],
    // VETERAN CLIENTS (Sejak 2022)
    'senja' => [
        'name' => 'Senja Roastery & Eatery',
        'type' => $accTypeCustomer,
        'industry' => $indRetail,
        'revenue' => 1800000000,
        'employees' => 18,
        'phone' => '031-5031234',
        'website' => 'https://senjaroastery.com',
        'street' => 'Jl. Jawa No. 12, Gubeng',
        'city' => 'Surabaya',
        'state' => 'Jawa Timur',
        'postal' => '60281',
        'assigned' => $userCitra->id,
        'date_entered' => '2022-02-14 14:00:00',
        'desc' => 'Artisan roastery & coffee shop mitra sejak awal 2022. Pembelian mesin Appia Life 2-Group dan pasokan rutin green beans Jawa Ijen & Dampit 150 kg/bulan.',
        'contact' => [
            'first' => 'Hendra',
            'last' => 'Wijaya',
            'title' => 'Managing Partner & Head Roaster',
            'dept' => 'Roastery Operations',
            'phone' => '0813-9876-5432',
            'email' => 'hendra@senjaroastery.com',
            'date_entered' => '2022-02-14 14:05:00',
        ],
    ],
    'batu_highland' => [
        'name' => 'Batu Highland Resort & Convention',
        'type' => $accTypeCustomer,
        'industry' => $indHospitality,
        'revenue' => 8500000000,
        'employees' => 95,
        'phone' => '0341-591234',
        'website' => 'https://batuhighlandresort.com',
        'street' => 'Jl. Trunojoyo No. 45, Oro-Oro Ombo',
        'city' => 'Batu',
        'state' => 'Jawa Timur',
        'postal' => '65316',
        'assigned' => $userCitra->id,
        'date_entered' => '2022-06-20 10:00:00',
        'desc' => 'Resort bintang 4 di Kota Batu sejak pertengahan 2022. Pengadaan 3 mesin espresso resto dan banquet hall, paket servis filter air 6-bulanan.',
        'contact' => [
            'first' => 'Guntur',
            'last' => 'Wicaksono',
            'title' => 'General Manager',
            'dept' => 'Hotel Operations',
            'phone' => '0812-4455-6677',
            'email' => 'guntur.w@batuhighlandresort.com',
            'date_entered' => '2022-06-20 10:10:00',
        ],
    ],
    'petrokimia' => [
        'name' => 'PT Petrokimia Sentosa Mess & Lounge',
        'type' => $accTypeCustomer,
        'industry' => $indOther,
        'revenue' => 25000000000,
        'employees' => 450,
        'phone' => '031-3981122',
        'website' => 'https://petrokimiasentosa.co.id',
        'street' => 'Jl. Jenderal Ahmad Yani No. 100, Sukorame',
        'city' => 'Gresik',
        'state' => 'Jawa Timur',
        'postal' => '61119',
        'assigned' => $userCitra->id,
        'date_entered' => '2022-11-15 13:45:00',
        'desc' => 'Akun korporasi B2B sejak akhir 2022. Mesin super-otomatis bean-to-cup untuk executive dining lounge dan pasokan biji kopi blend 50 kg/bulan stabil.',
        'contact' => [
            'first' => 'Bambang',
            'last' => 'Soedirman',
            'title' => 'Head of General Affairs & HR',
            'dept' => 'General Affairs',
            'phone' => '0811-7788-9900',
            'email' => 'bambang.s@petrokimiasentosa.co.id',
            'date_entered' => '2022-11-15 13:50:00',
        ],
    ],
    // MID-TERM CLIENTS (Sejak 2023)
    'sudut_kota' => [
        'name' => 'Kopi Sudut Kota Malang',
        'type' => $accTypeCustomer,
        'industry' => $indRetail,
        'revenue' => 1200000000,
        'employees' => 14,
        'phone' => '0341-356789',
        'website' => 'https://kopisudutkota.id',
        'street' => 'Jl. Ijen No. 42, Klojen',
        'city' => 'Malang',
        'state' => 'Jawa Timur',
        'postal' => '65115',
        'assigned' => $userBima->id,
        'date_entered' => '2023-03-08 15:20:00',
        'desc' => 'Cafe favorit mahasiswa di Kota Malang sejak 2023. Mesin Appia Life 2-group, ekspansi grinder single origin 2025/2026.',
        'contact' => [
            'first' => 'Clara',
            'last' => 'Shinta',
            'title' => 'Founder & Managing Director',
            'dept' => 'Executive Office',
            'phone' => '0812-1122-3344',
            'email' => 'clara@kopisudutkota.id',
            'date_entered' => '2023-03-08 15:25:00',
        ],
    ],
    'heritage_club' => [
        'name' => 'Surabaya Heritage Club & Lounge',
        'type' => $accTypeCustomer,
        'industry' => $indHospitality,
        'revenue' => 4200000000,
        'employees' => 40,
        'phone' => '031-7345678',
        'website' => 'https://surabayaheritageclub.id',
        'street' => 'Jl. Mayjen Sungkono No. 102, Dukuh Pakis',
        'city' => 'Surabaya',
        'state' => 'Jawa Timur',
        'postal' => '60225',
        'assigned' => $userCitra->id,
        'date_entered' => '2023-08-12 11:00:00',
        'desc' => 'Private dining and cigar lounge Surabaya Barat sejak 2023. Mesin 3-group custom brass finishing & suplai kopi single origin.',
        'contact' => [
            'first' => 'Ferry',
            'last' => 'Gunawan',
            'title' => 'Bar & Beverage Manager',
            'dept' => 'Beverage Division',
            'phone' => '0813-2233-4455',
            'email' => 'ferry.g@surabayaheritageclub.id',
            'date_entered' => '2023-08-12 11:10:00',
        ],
    ],
    // MID-TERM CLIENTS (Sejak 2024)
    'aksara' => [
        'name' => 'Kafe Aksara & Co-Working',
        'type' => $accTypeCustomer,
        'industry' => $indEducation,
        'revenue' => 950000000,
        'employees' => 12,
        'phone' => '031-8794321',
        'website' => 'https://aksaracoworking.id',
        'street' => 'Jl. Dr. Ir. H. Soekarno No. 201, MERR, Rungkut',
        'city' => 'Surabaya',
        'state' => 'Jawa Timur',
        'postal' => '60298',
        'assigned' => $userBima->id,
        'date_entered' => '2024-01-20 10:30:00',
        'desc' => 'Hub mahasiswa & digital nomad Surabaya Timur sejak awal 2024. Paket mesin espresso 1-Group dan konsinyasi kopi cold brew botol.',
        'contact' => [
            'first' => 'Nadia',
            'last' => 'Kusuma',
            'title' => 'Community & Operations Manager',
            'dept' => 'Operations',
            'phone' => '0817-5566-7788',
            'email' => 'nadia@aksaracoworking.id',
            'date_entered' => '2024-01-20 10:35:00',
        ],
    ],
    'rs_mitra' => [
        'name' => 'Rumah Sakit Mitra Sejahtera Cafeteria',
        'type' => $accTypeCustomer,
        'industry' => $indHealthcare,
        'revenue' => 5000000000,
        'employees' => 60,
        'phone' => '031-8945678',
        'website' => 'https://rsmitrasejahtera.com',
        'street' => 'Jl. Pahlawan No. 15, Magersari',
        'city' => 'Sidoarjo',
        'state' => 'Jawa Timur',
        'postal' => '61212',
        'assigned' => $userBima->id,
        'date_entered' => '2024-05-18 09:00:00',
        'desc' => 'Kantin rumah sakit swasta sejak Mei 2024. Mesin kopi bean-to-cup otomatis dokter lounge & stand kopi pengunjung.',
        'contact' => [
            'first' => 'Ratna',
            'last' => 'Sari',
            'title' => 'Koordinator Pengelola Fasilitas',
            'dept' => 'Fasilitas & Gizi',
            'phone' => '0812-8899-0011',
            'email' => 'dr.ratna@rsmitrasejahtera.com',
            'date_entered' => '2024-05-18 09:10:00',
        ],
    ],
    'aroma_bali' => [
        'name' => 'Aroma Kopi Luwak & Espresso Bar Bali',
        'type' => $accTypeCustomer,
        'industry' => $indRetail,
        'revenue' => 2800000000,
        'employees' => 22,
        'phone' => '0361-751234',
        'website' => 'https://aromaluwakbali.com',
        'street' => 'Jl. Sunset Road No. 88, Seminyak',
        'city' => 'Badung',
        'state' => 'Bali',
        'postal' => '80361',
        'assigned' => $userCitra->id,
        'date_entered' => '2024-09-14 16:00:00',
        'desc' => 'Espresso bar turis di Bali sejak akhir 2024. Suplai rutin biji kopi specialty Jawa Timur (Ijen Anaerob & Robusta Dampit).',
        'contact' => [
            'first' => 'Made',
            'last' => 'Suryawan',
            'title' => 'Owner & Head Barista',
            'dept' => 'Barista Team',
            'phone' => '0819-3322-1100',
            'email' => 'made@aromaluwakbali.com',
            'date_entered' => '2024-09-14 16:05:00',
        ],
    ],
    // RECENT CLIENTS (Sejak 2025)
    'djava_bistro' => [
        'name' => 'D Java Bistro & Bakery Madiun',
        'type' => $accTypeCustomer,
        'industry' => $indRetail,
        'revenue' => 800000000,
        'employees' => 10,
        'phone' => '0351-461234',
        'website' => 'https://djavabistro.com',
        'street' => 'Jl. Pahlawan No. 55, Kartoharjo',
        'city' => 'Madiun',
        'state' => 'Jawa Timur',
        'postal' => '63122',
        'assigned' => $userBima->id,
        'date_entered' => '2025-04-10 11:20:00',
        'desc' => 'Bistro & bakery modern di Madiun sejak 2025. Mesin Appia Life Compact 2G dan pasokan biji kopi house blend 40 kg/bulan.',
        'contact' => [
            'first' => 'Rudi',
            'last' => 'Hartono',
            'title' => 'Operational Manager',
            'dept' => 'F&B Operations',
            'phone' => '0813-7766-5544',
            'email' => 'rudi@djavabistro.com',
            'date_entered' => '2025-04-10 11:25:00',
        ],
    ],
    'cakrawala_univ' => [
        'name' => 'Cakrawala University Student Lounge',
        'type' => $accTypeCustomer,
        'industry' => $indEducation,
        'revenue' => 1500000000,
        'employees' => 15,
        'phone' => '031-5941234',
        'website' => 'https://cakrawala.ac.id',
        'street' => 'Jl. Menur Pumpungan No. 120, Sukolilo',
        'city' => 'Surabaya',
        'state' => 'Jawa Timur',
        'postal' => '60118',
        'assigned' => $userBima->id,
        'date_entered' => '2025-08-25 14:15:00',
        'desc' => 'Kiosk kopi inkubator bisnis kewirausahaan mahasiswa sejak 2025. Mesin komersial 1-group dan program training barista bersertifikat.',
        'contact' => [
            'first' => 'Siti',
            'last' => 'Rahmawati',
            'title' => 'Kepala UPT Kewirausahaan Kampus',
            'dept' => 'UPT Kewirausahaan',
            'phone' => '0812-6655-4433',
            'email' => 's.rahmawati@cakrawala.ac.id',
            'date_entered' => '2025-08-25 14:20:00',
        ],
    ],
    // ACTIVE PROSPECTS (2026)
    'kopi_kontainer' => [
        'name' => 'Kopi Kontainer Sidoarjo',
        'type' => $accTypeProspect,
        'industry' => $indRetail,
        'revenue' => 450000000,
        'employees' => 6,
        'phone' => '031-8961234',
        'website' => 'https://kopikontainersda.id',
        'street' => 'Jl. KH Mukmin No. 18, Sidokare',
        'city' => 'Sidoarjo',
        'state' => 'Jawa Timur',
        'postal' => '61214',
        'assigned' => $userBima->id,
        'date_entered' => '2026-02-15 10:00:00',
        'desc' => 'Prospek hangat hasil konversi pameran F&B Expo 2026 Surabaya. Rencana pembukaan kedai kopi kontainer outdoor.',
        'contact' => [
            'first' => 'Wahyu',
            'last' => 'Pratama',
            'title' => 'Founder & Owner',
            'dept' => 'Business Owner',
            'phone' => '0812-9988-7766',
            'email' => 'wahyu@kopikontainersda.id',
            'date_entered' => '2026-02-15 10:05:00',
        ],
    ],
    'intiland_tower' => [
        'name' => 'Gedung Perkantoran Intiland Tower Cafe',
        'type' => $accTypeProspect,
        'industry' => $indOther,
        'revenue' => 15000000000,
        'employees' => 200,
        'phone' => '031-5471234',
        'website' => 'https://intilandtower-sub.com',
        'street' => 'Jl. Panglima Sudirman No. 101, Genteng',
        'city' => 'Surabaya',
        'state' => 'Jawa Timur',
        'postal' => '60271',
        'assigned' => $userCitra->id,
        'date_entered' => '2026-01-22 09:30:00',
        'desc' => 'Prospek korporat pengadaan fasilitas coffee break lobby & lantai perkantoran eksekutif.',
        'contact' => [
            'first' => 'Kevin',
            'last' => 'Tanujaya',
            'title' => 'Property & Tenant Manager',
            'dept' => 'Building Management',
            'phone' => '0811-2244-6688',
            'email' => 'kevin.tanujaya@intilandtower-sub.com',
            'date_entered' => '2026-01-22 09:35:00',
        ],
    ],
    'blitar_hub' => [
        'name' => 'Blitar Coffee Community Hub',
        'type' => $accTypeProspect,
        'industry' => $indRetail,
        'revenue' => 350000000,
        'employees' => 5,
        'phone' => '0342-801234',
        'website' => 'https://blitarcoffeehub.org',
        'street' => 'Jl. Merdeka No. 70, Kepanjenkidul',
        'city' => 'Blitar',
        'state' => 'Jawa Timur',
        'postal' => '66117',
        'assigned' => $userBima->id,
        'date_entered' => '2026-02-28 16:00:00',
        'desc' => 'Komunitas barista Blitar penjajakan kemitraan micro-roasting and workshop equipment.',
        'contact' => [
            'first' => 'Agus',
            'last' => 'Santoso',
            'title' => 'Ketua Komunitas Kopi Blitar',
            'dept' => 'Community Management',
            'phone' => '0813-4455-7799',
            'email' => 'agus@blitarcoffeehub.org',
            'date_entered' => '2026-02-28 16:05:00',
        ],
    ],
];

$accounts = [];
$contacts = [];

foreach ($accountsDefs as $key => $def) {
    $accBean = find_or_create(
        'Accounts',
        ['name' => $def['name']],
        [
            'name' => $def['name'],
            'account_type' => $def['type'],
            'industry' => $def['industry'],
            'annual_revenue' => $def['revenue'],
            'employees' => $def['employees'],
            'phone_office' => $def['phone'],
            'website' => $def['website'],
            'billing_address_street' => $def['street'],
            'billing_address_city' => $def['city'],
            'billing_address_state' => $def['state'],
            'billing_address_postalcode' => $def['postal'],
            'billing_address_country' => 'Indonesia',
            'shipping_address_street' => $def['street'],
            'shipping_address_city' => $def['city'],
            'shipping_address_state' => $def['state'],
            'shipping_address_postalcode' => $def['postal'],
            'shipping_address_country' => 'Indonesia',
            'assigned_user_id' => $def['assigned'],
            'description' => $def['desc'],
        ],
        $def['date_entered']
    );
    $accounts[$key] = $accBean;

    // Contact
    $c = $def['contact'];
    $conBean = find_or_create(
        'Contacts',
        ['first_name' => $c['first'], 'last_name' => $c['last']],
        [
            'first_name' => $c['first'],
            'last_name' => $c['last'],
            'title' => $c['title'],
            'department' => $c['dept'],
            'phone_work' => $c['phone'],
            'phone_mobile' => $c['phone'],
            'primary_address_street' => $def['street'],
            'primary_address_city' => $def['city'],
            'primary_address_state' => $def['state'],
            'primary_address_postalcode' => $def['postal'],
            'primary_address_country' => 'Indonesia',
            'assigned_user_id' => $def['assigned'],
            'description' => "Kontak utama untuk {$def['name']}."
        ],
        $c['date_entered']
    );
    set_bean_email($conBean, $c['email']);
    $conBean->account_id = $accBean->id;
    $conBean->account_name = $accBean->name;
    $conBean->save(false);
    link_beans($accBean, 'contacts', $conBean->id);
    $contacts[$key] = $conBean;
}

// ---------------------------------------------------------------------------
// 4. Leads (6 across campaigns 2022, 2024, 2026)
// ---------------------------------------------------------------------------
$leadSrcTradeShow = must_key('lead_source_dom', 'Trade Show');
$leadSrcWebSite = must_key('lead_source_dom', 'Web Site');
$leadSrcCampaign = must_key('lead_source_dom', 'Campaign');
$leadStatNew = must_key('lead_status_dom', 'New');
$leadStatInProcess = must_key('lead_status_dom', 'In Process');
$leadStatRecycled = must_key('lead_status_dom', 'Recycled');
$leadStatDead = must_key('lead_status_dom', 'Dead');
$leadStatConverted = must_key('lead_status_dom', 'Converted');

$leadsData = [
    [
        'first' => 'Wahyu',
        'last' => 'Pratama (Lead)',
        'account' => 'Kopi Kontainer Sidoarjo',
        'title' => 'Pemilik Kedai',
        'phone' => '0812-9988-7766',
        'email' => 'wahyu.lead@kopikontainer.id',
        'source' => $leadSrcTradeShow,
        'status' => $leadStatInProcess,
        'campaign' => $campExpo2026->id,
        'assigned' => $userBima->id,
        'desc' => 'Tertarik paket bundling mesin espresso 1-group dan biji kopi blend 20 kg/bulan saat berkunjung ke booth pameran.',
        'date' => '2026-02-12 14:00:00'
    ],
    [
        'first' => 'Diana',
        'last' => 'Savitri',
        'account' => 'Puspa Bakery & Cafe Gresik',
        'title' => 'Owner',
        'phone' => '0813-1122-3344',
        'email' => 'diana@puspabakery.com',
        'source' => $leadSrcTradeShow,
        'status' => $leadStatNew,
        'campaign' => $campExpo2026->id,
        'assigned' => $userBima->id,
        'desc' => 'Mencari mesin kopi otomatis untuk sudut cafe di toko rotinya di Gresik.',
        'date' => '2026-02-13 11:30:00'
    ],
    [
        'first' => 'Arif',
        'last' => 'Rahman',
        'account' => 'Warkop Milenial Surabaya',
        'title' => 'Pengelola',
        'phone' => '0818-4455-6677',
        'email' => 'arif@warkopmilenial.id',
        'source' => $leadSrcWebSite,
        'status' => $leadStatRecycled,
        'campaign' => null,
        'assigned' => $userBima->id,
        'desc' => 'Budget belum mencukupi untuk mesin espresso komersial; ditawarkan paket manual brew drip untuk dihubungi kuartal berikutnya.',
        'date' => '2025-11-04 16:15:00'
    ],
    [
        'first' => 'Bagus',
        'last' => 'Wicaksono',
        'account' => 'Bagus Cafe & Eatery Kediri',
        'title' => 'Owner',
        'phone' => '0812-7788-9911',
        'email' => 'bagus@baguscafe.id',
        'source' => $leadSrcCampaign,
        'status' => $leadStatDead,
        'campaign' => $campFest2024->id,
        'assigned' => $userBima->id,
        'desc' => 'Batal membuka outlet cafe karena kendala perizinan lokasi.',
        'date' => '2024-06-01 10:00:00'
    ],
    [
        'first' => 'Maya',
        'last' => 'Anggraini',
        'account' => 'Java Heritage Lounge Surabaya',
        'title' => 'F&B Consultant',
        'phone' => '0811-6677-8899',
        'email' => 'maya@javaheritage.com',
        'source' => $leadSrcTradeShow,
        'status' => $leadStatConverted,
        'campaign' => $campFair2022->id,
        'assigned' => $userCitra->id,
        'desc' => 'Lead historis pameran 2022, berhasil dikonversi menjadi klien tetap.',
        'date' => '2022-08-10 15:00:00'
    ],
    [
        'first' => 'Tommy',
        'last' => 'Salim',
        'account' => 'East Coast Roasters Surabaya',
        'title' => 'Managing Director',
        'phone' => '0812-5544-3322',
        'email' => 'tommy@eastcoastroasters.id',
        'source' => $leadSrcCampaign,
        'status' => $leadStatConverted,
        'campaign' => $campFest2024->id,
        'assigned' => $userCitra->id,
        'desc' => 'Lead festival kopi 2024 dikonversi menjadi kontrak green beans tahunan.',
        'date' => '2024-05-20 13:30:00'
    ],
];

$leads = [];
foreach ($leadsData as $ld) {
    $leadBean = find_or_create(
        'Leads',
        ['first_name' => $ld['first'], 'last_name' => $ld['last']],
        [
            'first_name' => $ld['first'],
            'last_name' => $ld['last'],
            'account_name' => $ld['account'],
            'title' => $ld['title'],
            'phone_work' => $ld['phone'],
            'phone_mobile' => $ld['phone'],
            'lead_source' => $ld['source'],
            'status' => $ld['status'],
            'campaign_id' => $ld['campaign'],
            'assigned_user_id' => $ld['assigned'],
            'description' => $ld['desc'],
        ],
        $ld['date']
    );
    set_bean_email($leadBean, $ld['email']);
    $leads[] = $leadBean;
}

// ---------------------------------------------------------------------------
// 5. Opportunities (18 across 5-Year Lifecycle: 2021 - 2026)
// Names kept strictly <= 45 chars to respect varchar(50) limit!
// ---------------------------------------------------------------------------
$stageProspecting = must_key('sales_stage_dom', 'Prospecting');
$stageQualification = must_key('sales_stage_dom', 'Qualification');
$stageQuote = must_key('sales_stage_dom', 'Proposal/Price Quote');
$stageReview = must_key('sales_stage_dom', 'Negotiation/Review');
$stageWon = must_key('sales_stage_dom', 'Closed Won');
$stageLost = must_key('sales_stage_dom', 'Closed Lost');

$oppDefs = [
    // 2021 Deals (Initial Acquisition)
    [
        'name' => '[2021] Mesin Aurelia Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 135000000.0,
        'close_date' => '2021-07-15',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'New Business',
        'assigned' => $userCitra->id,
        'desc' => 'Pengadaan unit pertama mesin espresso Aurelia Wave 2-Group untuk main restaurant Grand Majapahit Hotel.',
        'date_entered' => '2021-06-01 10:00:00',
    ],
    [
        'name' => '[2021] Paket Mesin Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 85000000.0,
        'close_date' => '2021-11-20',
        'lead_source' => $leadSrcWebSite,
        'type' => 'New Business',
        'assigned' => $userCitra->id,
        'desc' => 'Paket opening cafe perdana Titik Temu di Jl. Raya Darmo.',
        'date_entered' => '2021-10-15 11:00:00',
    ],
    // 2022 Deals (Expansion & Roastery Supply)
    [
        'name' => '[2022] Mesin Appia Senja Roastery',
        'account' => 'senja',
        'contact' => 'senja',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 68000000.0,
        'close_date' => '2022-03-10',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'New Business',
        'assigned' => $userCitra->id,
        'desc' => 'Pengadaan mesin espresso Nuova Simonelli Appia Life 2G untuk roastery bar Senja.',
        'date_entered' => '2022-02-18 14:00:00',
    ],
    [
        'name' => '[2022] Pengadaan Mesin Batu Highland',
        'account' => 'batu_highland',
        'contact' => 'batu_highland',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 110000000.0,
        'close_date' => '2022-08-25',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'New Business',
        'assigned' => $userCitra->id,
        'desc' => 'Pengadaan 2 unit mesin espresso komersial restoran & convention hall Batu.',
        'date_entered' => '2022-07-15 09:30:00',
    ],
    [
        'name' => '[2022] Mesin Espresso Resto Cabang',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'stage' => $stageLost,
        'prob' => 0,
        'amount' => 75000000.0,
        'close_date' => '2022-10-14',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'Existing Business',
        'assigned' => $userCitra->id,
        'desc' => 'Kalah tender karena hotel memilih menyewa mesin dari distributor pihak ketiga di Jakarta.',
        'date_entered' => '2022-09-01 10:15:00',
    ],
    // 2023 Deals (Repeat Business & Corporate Lounge)
    [
        'name' => '[2023] Mesin Cabang 2 Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 92000000.0,
        'close_date' => '2023-04-18',
        'lead_source' => $leadSrcWebSite,
        'type' => 'Existing Business',
        'assigned' => $userCitra->id,
        'desc' => 'Pembukaan cabang ke-2 Kopi Titik Temu di Surabaya Barat.',
        'date_entered' => '2023-03-01 11:45:00',
    ],
    [
        'name' => '[2023] Mesin 3G Surabaya Heritage',
        'account' => 'heritage_club',
        'contact' => 'heritage_club',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 145000000.0,
        'close_date' => '2023-09-05',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'New Business',
        'assigned' => $userCitra->id,
        'desc' => 'Mesin custom 3-group brass finish untuk private lounge Surabaya Barat.',
        'date_entered' => '2023-08-15 15:00:00',
    ],
    [
        'name' => '[2023] Kontrak Biji Petrokimia 2023',
        'account' => 'petrokimia',
        'contact' => 'petrokimia',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 48000000.0,
        'close_date' => '2023-12-10',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'Existing Business',
        'assigned' => $userCitra->id,
        'desc' => 'Kontrak tahunan supply biji kopi blend lounge VIP PT Petrokimia Sentosa.',
        'date_entered' => '2023-11-20 10:30:00',
    ],
    // 2024 Deals (Co-working & Healthcare)
    [
        'name' => '[2024] Paket Mesin Kafe Aksara',
        'account' => 'aksara',
        'contact' => 'aksara',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 55000000.0,
        'close_date' => '2024-02-28',
        'lead_source' => $leadSrcWebSite,
        'type' => 'New Business',
        'assigned' => $userBima->id,
        'desc' => 'Pengadaan mesin espresso 1-Group dan grinder komersial untuk co-working space.',
        'date_entered' => '2024-01-25 14:00:00',
    ],
    [
        'name' => '[2024] Mesin Kopi RS Mitra Sejahtera',
        'account' => 'rs_mitra',
        'contact' => 'rs_mitra',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 60000000.0,
        'close_date' => '2024-06-15',
        'lead_source' => $leadSrcCampaign,
        'type' => 'New Business',
        'assigned' => $userBima->id,
        'desc' => 'Dua unit mesin bean-to-cup otomatis dokter lounge & cafetaria rumah sakit.',
        'date_entered' => '2024-05-22 09:15:00',
    ],
    [
        'name' => '[2024] Tender Espresso Stasiun',
        'account' => 'aksara',
        'contact' => 'aksara',
        'stage' => $stageLost,
        'prob' => 0,
        'amount' => 90000000.0,
        'close_date' => '2024-11-12',
        'lead_source' => $leadSrcWebSite,
        'type' => 'New Business',
        'assigned' => $userBima->id,
        'desc' => 'Gagal menang tender gerai kopi stasiun karena ruang booth dialihkan pengelola.',
        'date_entered' => '2024-10-01 13:00:00',
    ],
    // 2025 Deals (Hotel Upgrade & Regional Expansion)
    [
        'name' => '[2025] Mesin Aurelia Wave Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 140000000.0,
        'close_date' => '2025-05-20',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'Existing Business',
        'assigned' => $userCitra->id,
        'desc' => 'Penambahan unit ke-2 Aurelia Wave untuk executive lounge lantai 5 Grand Majapahit.',
        'date_entered' => '2025-04-10 11:30:00',
    ],
    [
        'name' => '[2025] Paket Mesin D Java Bistro',
        'account' => 'djava_bistro',
        'contact' => 'djava_bistro',
        'stage' => $stageWon,
        'prob' => 100,
        'amount' => 52000000.0,
        'close_date' => '2025-09-10',
        'lead_source' => $leadSrcWebSite,
        'type' => 'New Business',
        'assigned' => $userBima->id,
        'desc' => 'Ekspansi regional ke Madiun - paket mesin espresso compact dan supply kopi.',
        'date_entered' => '2025-08-01 10:00:00',
    ],
    // 2026 ACTIVE PIPELINE
    [
        'name' => '[2026] Grinder Single Origin Malang',
        'account' => 'sudut_kota',
        'contact' => 'sudut_kota',
        'stage' => $stageQuote,
        'prob' => 65,
        'amount' => 28500000.0,
        'close_date' => '2026-11-30',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'Existing Business',
        'assigned' => $userBima->id,
        'desc' => 'Penawaran upsell 2 unit grinder on-demand Eureka Olympus & pasokan 6 varian sirup gourmet.',
        'date_entered' => '2026-10-15 14:00:00',
    ],
    [
        'name' => '[2026] Kontrak Biji Espresso Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'stage' => $stageReview,
        'prob' => 80,
        'amount' => 65000000.0,
        'close_date' => '2026-12-15',
        'lead_source' => $leadSrcWebSite,
        'type' => 'Existing Business',
        'assigned' => $userCitra->id,
        'desc' => 'Final review addendum perpanjangan kontrak pasokan tahunan 2026-2027.',
        'date_entered' => '2026-10-20 11:00:00',
    ],
    [
        'name' => '[NEW BIZ] Paket Espresso Kopi Kontainer',
        'account' => 'kopi_kontainer',
        'contact' => 'kopi_kontainer',
        'stage' => $stageProspecting,
        'prob' => 10,
        'amount' => 45000000.0,
        'close_date' => '2026-12-31',
        'lead_source' => $leadSrcCampaign,
        'type' => 'New Business',
        'assigned' => $userBima->id,
        'desc' => 'Paket pemula kedai kopi kontainer: mesin espresso 1-Group, grinder on-demand, dan biji kopi 20kg.',
        'date_entered' => '2026-02-18 09:00:00',
    ],
    [
        'name' => '[RETENTION] Reaktivasi Kontrak Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'stage' => $stageQualification,
        'prob' => 20,
        'amount' => 72000000.0,
        'close_date' => '2026-11-28',
        'lead_source' => $leadSrcTradeShow,
        'type' => 'Existing Business',
        'assigned' => $userCitra->id,
        'desc' => 'Negosiasi pembaruan kontrak pasokan biji kopi blend dan preventive maintenance 2027.',
        'date_entered' => '2026-11-01 10:00:00',
    ],
    [
        'name' => '[LOST] Pengadaan Mesin Kopi Kampus C',
        'account' => 'cakrawala_univ',
        'contact' => 'cakrawala_univ',
        'stage' => $stageLost,
        'prob' => 0,
        'amount' => 38000000.0,
        'close_date' => '2026-06-10',
        'lead_source' => $leadSrcWebSite,
        'type' => 'Existing Business',
        'assigned' => $userBima->id,
        'desc' => 'Pengadaan unit ke-2 dibatalkan karena pemotongan anggaran internal fakultas.',
        'date_entered' => '2026-05-15 15:30:00',
    ],
];

$opportunities = [];
foreach ($oppDefs as $od) {
    $oppBean = find_or_create(
        'Opportunities',
        ['name' => $od['name']],
        [
            'name' => $od['name'],
            'opportunity_type' => $od['type'],
            'lead_source' => $od['lead_source'],
            'currency_id' => $idrCurrencyId,
            'amount' => $od['amount'],
            'amount_usdollar' => $od['amount'],
            'date_closed' => utc_db_date($od['close_date']),
            'sales_stage' => $od['stage'],
            'probability' => $od['prob'],
            'assigned_user_id' => $od['assigned'],
            'description' => $od['desc'],
        ],
        $od['date_entered']
    );
    $acc = $accounts[$od['account']];
    link_beans($acc, 'opportunities', $oppBean->id);

    if (isset($contacts[$od['contact']])) {
        link_beans($contacts[$od['contact']], 'opportunities', $oppBean->id);
    }
    $opportunities[$od['name']] = $oppBean;
}

// ---------------------------------------------------------------------------
// 6. Calls (18: 1 Overdue Planned assigned to admin + 17 Held historical calls)
// ---------------------------------------------------------------------------
$callStatusPlanned = must_key('call_status_dom', 'Planned');
$callStatusHeld = must_key('call_status_dom', 'Held');
$callDirOutbound = must_key('call_direction_dom', 'Outbound');
$callDirInbound = must_key('call_direction_dom', 'Inbound');

// 1 Overdue Planned Call assigned to admin (CRITICAL DEMO / VERIFY CHECK)
$callOverdue = find_or_create(
    'Calls',
    ['name' => '[OVERDUE] Konfirmasi Demo Mesin Kopi Kontainer'],
    [
        'name' => '[OVERDUE] Konfirmasi Demo Mesin Kopi Kontainer',
        'status' => $callStatusPlanned,
        'direction' => $callDirOutbound,
        'date_start' => utc_db_datetime('-3 days 09:00:00'),
        'date_end' => utc_db_datetime('-3 days 09:30:00'),
        'duration_hours' => 0,
        'duration_minutes' => 30,
        'parent_type' => 'Accounts',
        'parent_id' => $accounts['kopi_kontainer']->id,
        'assigned_user_id' => '1', // admin
        'description' => 'Telepon konfirmasi jadwal uji coba mesin espresso di showroom Surabaya dengan Bapak Wahyu.',
    ],
    utc_db_datetime('-4 days 10:00:00')
);
link_beans($callOverdue, 'contacts', $contacts['kopi_kontainer']->id);
if ($callOverdue->load_relationship('accounts')) {
    $callOverdue->accounts->add($accounts['kopi_kontainer']->id);
}

// 17 Held Historical Calls spanning 2021 to 2026
$heldCallsDefs = [
    // 2021
    [
        'name' => '[2021] Kickoff Delivery Mesin Aurelia Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2021-07-16 10:00:00',
        'user' => $userCitra->id,
        'desc' => 'Konfirmasi kedatangan tim teknisi dan unboxing mesin espresso di Grand Majapahit Hotel.',
    ],
    [
        'name' => '[2021] Evaluasi Pasca Pembukaan Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2021-11-28 14:30:00',
        'user' => $userCitra->id,
        'desc' => 'Follow up kepuasan pelanggan seminggu setelah opening cafe Titik Temu.',
    ],
    // 2022
    [
        'name' => '[2022] Review Profil Roasting Senja Roastery',
        'account' => 'senja',
        'contact' => 'senja',
        'date' => '2022-03-25 15:00:00',
        'user' => $userCitra->id,
        'desc' => 'Diskusi hasil roasting sampel biji kopi Ijen Honey dengan Hendra Wijaya.',
    ],
    [
        'name' => '[2022] Koordinasi Perawatan Filter Air Batu Highland',
        'account' => 'batu_highland',
        'contact' => 'batu_highland',
        'date' => '2022-09-02 11:00:00',
        'user' => $userCitra->id,
        'desc' => 'Jadwal teknisi pengecekan TDS dan water hardness di resort Batu.',
    ],
    [
        'name' => '[2022] Konfirmasi Kontrak Supply Petrokimia Lounge',
        'account' => 'petrokimia',
        'contact' => 'petrokimia',
        'date' => '2022-12-05 13:30:00',
        'user' => $userCitra->id,
        'desc' => 'Finalisasi PO bulanan biji espresso blend untuk ruang rapat VIP direksi.',
    ],
    // 2023
    [
        'name' => '[2023] Diskusi Mesin Cabang 2 Kopi Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2023-03-15 10:15:00',
        'user' => $userCitra->id,
        'desc' => 'Penyesuaian kapasitas daya listrik lokasi cabang baru sebelum kirim mesin.',
    ],
    [
        'name' => '[2023] Spesifikasi Mesin Custom Heritage Club',
        'account' => 'heritage_club',
        'contact' => 'heritage_club',
        'date' => '2023-08-20 16:00:00',
        'user' => $userCitra->id,
        'desc' => 'Konfirmasi warna finishing bodi mesin espresso 3-Group sesuai interior bar.',
    ],
    [
        'name' => '[2023] Konsultasi Kalibrasi Suhu Senja Roastery',
        'account' => 'senja',
        'contact' => 'senja',
        'date' => '2023-11-10 14:00:00',
        'user' => $userCitra->id,
        'desc' => 'Panduan telepon reset PID suhu ekstraksi mesin Appia Life.',
    ],
    // 2024
    [
        'name' => '[2024] Onboarding Mesin Kafe Aksara Rungkut',
        'account' => 'aksara',
        'contact' => 'aksara',
        'date' => '2024-02-15 09:30:00',
        'user' => $userBima->id,
        'desc' => 'Briefing teknis dan jadwal serah terima mesin ke Nadia Kusuma.',
    ],
    [
        'name' => '[2024] Koordinasi Mesin Bean to Cup RS Mitra',
        'account' => 'rs_mitra',
        'contact' => 'rs_mitra',
        'date' => '2024-05-30 11:20:00',
        'user' => $userBima->id,
        'desc' => 'Pelatihan staf kantin rumah sakit untuk pengoperasian mesin otomatis.',
    ],
    [
        'name' => '[2024] Ekspedisi Kopi Ijen ke Seminyak Bali',
        'account' => 'aroma_bali',
        'contact' => 'aroma_bali',
        'date' => '2024-09-25 15:30:00',
        'user' => $userCitra->id,
        'desc' => 'Pelacakan resi kargo darat pengiriman 100 kg biji kopi ke Kuta Bali.',
    ],
    // 2025
    [
        'name' => '[2025] Rencana Upgrade Mesin Kedua Grand Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2025-04-18 10:00:00',
        'user' => $userCitra->id,
        'desc' => 'Pembahasan penambahan unit mesin espresso di lounge eksekutif lantai 5.',
    ],
    [
        'name' => '[2025] Pengiriman Perdana Biji Kopi Madiun Bistro',
        'account' => 'djava_bistro',
        'contact' => 'djava_bistro',
        'date' => '2025-09-15 14:00:00',
        'user' => $userBima->id,
        'desc' => 'Pengecekan kualitas biji kopi pasca roasting batch pertama untuk D Java Bistro.',
    ],
    [
        'name' => '[2025] Persiapan Kiosk Kopi Mahasiswa Cakrawala',
        'account' => 'cakrawala_univ',
        'contact' => 'cakrawala_univ',
        'date' => '2025-09-05 13:00:00',
        'user' => $userBima->id,
        'desc' => 'Konfirmasi jadwal sesi workshop barista dasar bagi mahasiswa pengelola kiosk.',
    ],
    // 2026
    [
        'name' => '[2026] Diskusi Penggantian Burr Grinder Kopi Sudut',
        'account' => 'sudut_kota',
        'contact' => 'sudut_kota',
        'date' => '2026-10-18 11:00:00',
        'user' => $userBima->id,
        'desc' => 'Follow up penawaran grinder on-demand dengan Clara Shinta.',
    ],
    [
        'name' => '[2026] Review Tahunan Pasokan Kopi Grand Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2026-11-05 15:00:00',
        'user' => $userCitra->id,
        'desc' => 'Pengecekan konsumsi kuartal 4 dan penyesuaian kuota blend 2027.',
    ],
    [
        'name' => '[2026] Konsultasi Fasilitas Pantry Intiland Tower',
        'account' => 'intiland_tower',
        'contact' => 'intiland_tower',
        'date' => '2026-02-02 10:30:00',
        'user' => $userCitra->id,
        'desc' => 'Klarifikasi kebutuhan mesin espresso otomatis kantor dengan Kevin Tanujaya.',
    ],
];

foreach ($heldCallsDefs as $hcd) {
    $callBean = find_or_create(
        'Calls',
        ['name' => $hcd['name']],
        [
            'name' => $hcd['name'],
            'status' => $callStatusHeld,
            'direction' => $callDirOutbound,
            'date_start' => utc_db_datetime($hcd['date']),
            'date_end' => utc_db_datetime($hcd['date'] . ' +30 minutes'),
            'duration_hours' => 0,
            'duration_minutes' => 30,
            'parent_type' => 'Accounts',
            'parent_id' => $accounts[$hcd['account']]->id,
            'assigned_user_id' => $hcd['user'],
            'description' => $hcd['desc'],
        ],
        $hcd['date']
    );
    link_beans($callBean, 'contacts', $contacts[$hcd['contact']]->id);
    if ($callBean->load_relationship('accounts')) {
        $callBean->accounts->add($accounts[$hcd['account']]->id);
    }
}

// ---------------------------------------------------------------------------
// 7. Meetings (10 Held Meetings across 5-Year Lifecycle: 2021 - 2026)
// ---------------------------------------------------------------------------
$meetStatusHeld = must_key('meeting_status_dom', 'Held');

$meetingsDefs = [
    [
        'name' => '[2021] Commissioning & Barista Training Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2021-07-20 13:00:00',
        'user' => $userCitra->id,
        'desc' => 'Instalasi mesin Aurelia Wave, kalibrasi water filter 150 ppm, dan sertifikasi 6 barista hotel.',
    ],
    [
        'name' => '[2022] Review Kontrak Tahunan Grand Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2022-07-15 14:00:00',
        'user' => $userCitra->id,
        'desc' => 'Evaluasi kepuasan tamu terhadap espresso blend hotel dan perpanjangan kontrak pasokan.',
    ],
    [
        'name' => '[2022] Cupping Session Panen Baru Senja Roastery',
        'account' => 'senja',
        'contact' => 'senja',
        'date' => '2022-10-05 10:00:00',
        'user' => $userCitra->id,
        'desc' => 'Blind cupping 5 lot specialty Arabika Ijen dan Robusta Dampit bersama tim roastery.',
    ],
    [
        'name' => '[2023] Meeting Ekspansi Cabang 2 Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2023-03-20 15:30:00',
        'user' => $userCitra->id,
        'desc' => 'Penandatanganan kontrak mesin kedua dan alokasi kuota biji kopi house blend.',
    ],
    [
        'name' => '[2023] Technical Audit Mesin Batu Highland Resort',
        'account' => 'batu_highland',
        'contact' => 'batu_highland',
        'date' => '2023-11-25 11:00:00',
        'user' => $userCitra->id,
        'desc' => 'Audit performa steam boiler, penggantian filter air softening sebelum libur tahun baru.',
    ],
    [
        'name' => '[2024] QBR & Onboarding Kafe Aksara Rungkut',
        'account' => 'aksara',
        'contact' => 'aksara',
        'date' => '2024-02-20 14:00:00',
        'user' => $userBima->id,
        'desc' => 'Review operasional bulan pertama cafe & training maintenance harian mesin.',
    ],
    [
        'name' => '[2024] Evaluasi Pengadaan Kopi RS Mitra Sejahtera',
        'account' => 'rs_mitra',
        'contact' => 'rs_mitra',
        'date' => '2024-08-10 10:30:00',
        'user' => $userBima->id,
        'desc' => 'Review konsumsi biji kopi dokter lounge dan penyesuaian jadwal delivery mingguan.',
    ],
    [
        'name' => '[2025] Demo Mesin Komersial D Java Bistro Madiun',
        'account' => 'djava_bistro',
        'contact' => 'djava_bistro',
        'date' => '2025-08-20 13:00:00',
        'user' => $userBima->id,
        'desc' => 'Uji seduh mesin Appia Life di showroom Surabaya bersama manajemen D Java Bistro.',
    ],
    [
        'name' => '[2025] VIP Client Gathering & Product Launch 2025',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2025-11-15 18:00:00',
        'user' => $userCitra->id,
        'desc' => 'Apresiasi klien loyal 5 tahun dan peluncuran varian single origin anaerob terbaru.',
    ],
    [
        'name' => '[2026] Negosiasi Final Addendum Kontrak Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2026-11-08 14:00:00',
        'user' => $userCitra->id,
        'desc' => 'Pembahasan volume discount perpanjangan kontrak 2026-2027 di kantor Titik Temu Darmo.',
    ],
];

foreach ($meetingsDefs as $md) {
    $meetBean = find_or_create(
        'Meetings',
        ['name' => $md['name']],
        [
            'name' => $md['name'],
            'status' => $meetStatusHeld,
            'date_start' => utc_db_datetime($md['date']),
            'date_end' => utc_db_datetime($md['date'] . ' +1 hour'),
            'duration_hours' => 1,
            'duration_minutes' => 0,
            'parent_type' => 'Accounts',
            'parent_id' => $accounts[$md['account']]->id,
            'assigned_user_id' => $md['user'],
            'description' => $md['desc'],
        ],
        $md['date']
    );
    link_beans($meetBean, 'contacts', $contacts[$md['contact']]->id);
    if ($meetBean->load_relationship('accounts')) {
        $meetBean->accounts->add($accounts[$md['account']]->id);
    }
}

// ---------------------------------------------------------------------------
// 8. Cases (8 Cases: 6 Historical Closed across 2021-2025 + 2 Active 2026)
// ---------------------------------------------------------------------------
$caseStatNew = must_key('case_status_dom', 'Open_New');
$caseStatPending = must_key('case_status_dom', 'Open_Pending Input');
$caseStatClosed = must_key('case_status_dom', 'Closed_Closed');
$caseP1 = must_key('case_priority_dom', 'P1');
$caseP2 = must_key('case_priority_dom', 'P2');
$caseP3 = must_key('case_priority_dom', 'P3');

$casesDefs = [
    // Historical 2021
    [
        'name' => '[2021] Kalibrasi Awal Boiler Mesin Aurelia',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'status' => $caseStatClosed,
        'priority' => $caseP2,
        'assigned' => $userCitra->id,
        'date' => '2021-08-10 14:00:00',
        'desc' => 'Penyesuaian tekanan pressure stat boiler dari 1.0 bar ke 1.25 bar untuk stabilitas steam milk saat jam sarapan hotel. Masalah teratasi sempurna.',
    ],
    // Historical 2022
    [
        'name' => '[2022] Penggantian Gasket Portafilter Rutin',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'status' => $caseStatClosed,
        'priority' => $caseP3,
        'assigned' => $userCitra->id,
        'date' => '2022-05-14 11:30:00',
        'desc' => 'Penggantian karet gasket 8.5mm dan shower screen IMS pada kedua group head. Rembesan air ekstraksi teratasi.',
    ],
    // Historical 2023
    [
        'name' => '[2023] Error Sensor Suhu Mesin Appia Life',
        'account' => 'senja',
        'contact' => 'senja',
        'status' => $caseStatClosed,
        'priority' => $caseP1,
        'assigned' => $userCitra->id,
        'date' => '2023-03-22 09:15:00',
        'desc' => 'Lampu indikator suhu berkedip cepat akibat kabel probe NTC kendor. Teknisi mengencangkan soket dalam 2 jam.',
    ],
    // Historical 2023
    [
        'name' => '[2023] Water Softener Filter Replacement',
        'account' => 'batu_highland',
        'contact' => 'batu_highland',
        'status' => $caseStatClosed,
        'priority' => $caseP2,
        'assigned' => $userCitra->id,
        'date' => '2023-11-18 10:00:00',
        'desc' => 'Kadar kapur air pegunungan tinggi di Batu. Penggantian resin cation water softener cartridge untuk mencegah kerak boiler.',
    ],
    // Historical 2024
    [
        'name' => '[2024] Clogging Burr Grinder Espresso',
        'account' => 'sudut_kota',
        'contact' => 'sudut_kota',
        'status' => $caseStatClosed,
        'priority' => $caseP2,
        'assigned' => $userBima->id,
        'date' => '2024-07-09 13:45:00',
        'desc' => 'Grinder macet karena benda asing biji kopi. Teknisi membersihkan flat burr dan kalibrasi ulang micro-metric.',
    ],
    // Historical 2025
    [
        'name' => '[2025] Descaling dan Pembersihan Jalur Susu',
        'account' => 'rs_mitra',
        'contact' => 'rs_mitra',
        'status' => $caseStatClosed,
        'priority' => $caseP2,
        'assigned' => $userBima->id,
        'date' => '2025-04-12 15:20:00',
        'desc' => 'Pembersihan sanitasi jalur cappuccinatore otomatis mesin bean-to-cup rumah sakit. Lulus uji higienitas RS.',
    ],
    // Active 2026 (CRITICAL DEMO TICKET)
    [
        'name' => '[URGENT] Tekanan Steam Wand Turun',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'status' => $caseStatNew,
        'priority' => $caseP1,
        'assigned' => $userCitra->id,
        'date' => '2026-11-09 08:30:00',
        'desc' => 'Tekanan steam boiler turun dari 1.2 bar ke 0.5 bar saat jam sarapan tamu hotel. Barista kesulitan frothing susu latte.',
    ],
    [
        'name' => 'Jadwal Kalibrasi Mesin Kuartal 1',
        'account' => 'senja',
        'contact' => 'senja',
        'status' => $caseStatPending,
        'priority' => $caseP2,
        'assigned' => $userCitra->id,
        'date' => '2026-11-08 10:00:00',
        'desc' => 'Permintaan kalibrasi rutin tekanan pompa rotary 9 bar dan pengecekan elemen pemanas.',
    ],
];

foreach ($casesDefs as $cd) {
    $caseBean = find_or_create(
        'Cases',
        ['name' => $cd['name']],
        [
            'name' => $cd['name'],
            'status' => $cd['status'],
            'priority' => $cd['priority'],
            'account_id' => $accounts[$cd['account']]->id,
            'account_name' => $accounts[$cd['account']]->name,
            'assigned_user_id' => $cd['assigned'],
            'description' => $cd['desc'],
        ],
        $cd['date']
    );
    link_beans($caseBean, 'contacts', $contacts[$cd['contact']]->id);
    if ($caseBean->load_relationship('accounts')) {
        $caseBean->accounts->add($accounts[$cd['account']]->id);
    }
}

// ---------------------------------------------------------------------------
// 9. Notes (26 Notes across 5-Year History: 2021 - 2026)
// Complete Timeline: BAST, Cupping Reports, Maintenance, Addendums
// ---------------------------------------------------------------------------
$notesDefs = [
    // 2021
    [
        'name' => 'BAST-2021-001: Serah Terima Mesin Aurelia Wave Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2021-07-20 15:00:00',
        'desc' => "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Aurelia Wave 2G\nSerial No: NS-AW2G-2021-0881\nStatus: Terpasang, lulus uji kebocoran, grounding listrik 0.8V, air 140 ppm.",
    ],
    [
        'name' => 'Laporan Cupping Uji Pasokan Hotel Harvest 2021',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2021-08-15 11:00:00',
        'desc' => "UJI CUPPING TAHUN 2021\nBlend: Majapahit Signature Espresso (70% Arabika Ijen, 30% Robusta Dampit)\nScore: 84.5 (Flavor: Dark Chocolate, Roasted Almond, Sweet Molasses). Disetujui F&B Director.",
    ],
    [
        'name' => 'BAST-2021-002: Mesin Espresso Victoria Arduino Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2021-11-22 14:00:00',
        'desc' => "BERITA ACARA SERAH TERIMA\nUnit: Victoria Arduino Eagle One 2G\nSerial No: VA-E1-2021-104\nPelatihan barista pembukaan kedai selesai dilaksanakan untuk 4 staf.",
    ],
    // 2022
    [
        'name' => 'BAST-2022-003: Instalasi Mesin Appia Life Senja Roastery',
        'account' => 'senja',
        'contact' => 'senja',
        'date' => '2022-03-12 16:30:00',
        'desc' => "Instalasi dan uji seduh mesin Nuova Simonelli Appia Life 2G selesai. Tekanan boiler stabil 1.15 bar.",
    ],
    [
        'name' => 'Laporan Servis Preventif Tahunan (PMR) Grand Majapahit 2022',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2022-06-30 10:00:00',
        'desc' => "PREVENTIVE MAINTENANCE REPORT 2022\n1. Descaling boiler dan heat exchanger\n2. Penggantian vacuum valve & safety valve\n3. Penggantian gasket grouphead\nUnit siap beroperasi optimal untuk periode 1 tahun ke depan.",
    ],
    [
        'name' => 'BAST-2022-004: Paket Mesin Resto Batu Highland Resort',
        'account' => 'batu_highland',
        'contact' => 'batu_highland',
        'date' => '2022-08-28 11:00:00',
        'desc' => "Serah terima 2 unit mesin espresso komersial di Batu Highland Resort & Convention. Pengujian kelistrikan dan filter softening lulus standar.",
    ],
    [
        'name' => 'Addendum Kontrak Pasokan Korporasi Petrokimia 2022-2023',
        'account' => 'petrokimia',
        'contact' => 'petrokimia',
        'date' => '2022-12-10 14:00:00',
        'desc' => "Persetujuan addendum suplai biji kopi blend 50 kg/bulan dengan SLA maintenance 1x24 jam untuk ruang direksi.",
    ],
    // 2023
    [
        'name' => 'BAST-2023-005: Mesin Cabang Surabaya Barat Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2023-04-20 15:00:00',
        'desc' => "Pemasangan mesin unit kedua di cabang Surabaya Barat Kopi Titik Temu. Instalasi filter air dan grinder komersial tuntas.",
    ],
    [
        'name' => 'BAST-2023-006: Mesin Custom Brass Surabaya Heritage Club',
        'account' => 'heritage_club',
        'contact' => 'heritage_club',
        'date' => '2023-09-08 16:00:00',
        'desc' => "Serah terima mesin custom 3-group brass finish di Surabaya Heritage Club. Uji stabilitas temperatur PID multi-boiler tuntas.",
    ],
    [
        'name' => 'Laporan Cupping Panen Ijen Anaerob Harvest 2023',
        'account' => 'senja',
        'contact' => 'senja',
        'date' => '2023-10-14 11:00:00',
        'desc' => "EVALUASI CUPPING 2023\nVarietas: Kartika & USDA 762 (Ijen Anaerobic Natural)\nCupping Score: 87.25\nNotes: Winey, Blackberry, Jasmine, Brown Sugar. Alokasi 1.2 ton disepakati.",
    ],
    [
        'name' => 'Laporan Servis Preventif Tahunan (PMR) Grand Majapahit 2023',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2023-11-20 09:30:00',
        'desc' => "PREVENTIVE MAINTENANCE REPORT 2023\nPengecekan elemen pemanas, penggantian shower screen, dan uji pressure pump 9 bar di unit Aurelia Wave.",
    ],
    // 2024
    [
        'name' => 'BAST-2024-007: Mesin Kafe Aksara Co-Working Rungkut',
        'account' => 'aksara',
        'contact' => 'aksara',
        'date' => '2024-03-01 14:00:00',
        'desc' => "Serah terima mesin espresso 1-Group dan grinder dosis on-demand untuk co-working space mahasiswa.",
    ],
    [
        'name' => 'BAST-2024-008: Mesin Otomatis RS Mitra Sejahtera Sidoarjo',
        'account' => 'rs_mitra',
        'contact' => 'rs_mitra',
        'date' => '2024-06-18 10:30:00',
        'desc' => "Instalasi 2 unit mesin bean-to-cup dokter lounge dan kantin utama RS Mitra Sejahtera. Lulus verifikasi K3 Rumah Sakit.",
    ],
    [
        'name' => 'Laporan Pengiriman Ekspedisi Biji Kopi Bali 2024',
        'account' => 'aroma_bali',
        'contact' => 'aroma_bali',
        'date' => '2024-10-02 11:00:00',
        'desc' => "Konfirmasi penerimaan batch pertama specialty coffee Ijen & Robusta Dampit di outlet Seminyak Bali dalam kondisi kemasan nitrogen valve kedap udara.",
    ],
    [
        'name' => 'Laporan Servis Preventif Tahunan (PMR) Grand Majapahit 2024',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2024-11-15 13:00:00',
        'desc' => "PREVENTIVE MAINTENANCE REPORT 2024\nKalibrasi water level probe, flushing heat exchanger, dan overhaul flowmeter volumetrik.",
    ],
    // 2025
    [
        'name' => 'BAST-2025-009: Mesin Aurelia Unit Kedua Executive Lounge Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2025-05-25 15:30:00',
        'desc' => "Pemasangan unit mesin kedua Nuova Simonelli Aurelia Wave untuk executive lounge lantai 5 Grand Majapahit Hotel.",
    ],
    [
        'name' => 'BAST-2025-010: Mesin Espresso D Java Bistro Madiun',
        'account' => 'djava_bistro',
        'contact' => 'djava_bistro',
        'date' => '2025-09-12 14:00:00',
        'desc' => "Serah terima mesin Appia Life Compact dan pelatihan 3 barista di bistro Madiun.",
    ],
    [
        'name' => 'Laporan Cupping Panen Specialty 2025 Senja Roastery',
        'account' => 'senja',
        'contact' => 'senja',
        'date' => '2025-10-22 10:30:00',
        'desc' => "EVALUASI CUPPING 2025\nCupping Score: 86.5 (Red Apple, Caramel, Milk Chocolate). Perpanjangan suplai green bean 2026 disepakati.",
    ],
    [
        'name' => 'Laporan Servis Preventif Tahunan (PMR) Grand Majapahit 2025',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2025-11-10 11:00:00',
        'desc' => "PREVENTIVE MAINTENANCE REPORT 2025\nPengecekan 2 unit Aurelia Wave (Resto & Lounge). Seluruh komponen elektronik dan mekanikal dinyatakan prima.",
    ],
    // 2026
    [
        'name' => 'Notulensi Kunjungan Negosiasi Addendum Kontrak Titik Temu 2026',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2026-11-08 16:00:00',
        'desc' => "Pertemuan dengan Bapak Danang Prasetyo. Disepakati diskon volume 7% untuk perpanjangan kontrak pasokan biji kopi 2026-2027.",
    ],
    [
        'name' => 'Spesifikasi Teknis Pengadaan Grinder Kopi Sudut Kota Malang',
        'account' => 'sudut_kota',
        'contact' => 'sudut_kota',
        'date' => '2026-10-20 14:30:00',
        'desc' => "Proposal spesifikasi grinder Eureka Olympus 75E High Speed untuk bar single origin.",
    ],
    [
        'name' => 'Catatan Kebutuhan Pantry Eksekutif Gedung Intiland Tower',
        'account' => 'intiland_tower',
        'contact' => 'intiland_tower',
        'date' => '2026-02-05 11:00:00',
        'desc' => "Rencana penempatan mesin bean-to-cup touch screen di pantry eksekutif lantai 12 dan 18.",
    ],
    [
        'name' => 'Profil Profiling Kopi Uji Coba Kopi Kontainer Sidoarjo',
        'account' => 'kopi_kontainer',
        'contact' => 'kopi_kontainer',
        'date' => '2026-02-18 13:00:00',
        'desc' => "Uji coba blend Robusta 60% : Arabika 40% untuk menu es kopi susu kekinian. Cocok dengan selera target pasar anak muda Sidoarjo.",
    ],
    [
        'name' => 'Riwayat Servis Darurat: Perbaikan Steam Wand Grand Majapahit',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2026-11-09 11:30:00',
        'desc' => "Teknisi darurat dikirim ke lokasi hotel. Dilakukan penggantian anti-vacuum valve dan pembersihan kerak nozzle steam wand.",
    ],
    [
        'name' => 'Catatan Apresiasi Klien Loyalitas 5 Tahun: Grand Majapahit Hotel',
        'account' => 'majapahit',
        'contact' => 'majapahit',
        'date' => '2026-11-01 09:00:00',
        'desc' => "STATUS KLIEN VIP (5 TAHUN LOYAL)\nGrand Majapahit Hotel adalah mitra B2B pertama sejak Mei 2021. Diberikan status prioritas teknisi 24/7 dan complimentary barista refresher training tahunan.",
    ],
    [
        'name' => 'Catatan Apresiasi Klien Loyalitas 5 Tahun: Kopi Titik Temu',
        'account' => 'titik_temu',
        'contact' => 'titik_temu',
        'date' => '2026-11-02 09:00:00',
        'desc' => "STATUS KLIEN VIP (5 TAHUN LOYAL)\nKopi Titik Temu tumbuh bersama sejak kedai pertama 2021 hingga multi-outlet 2026. Prioritas alokasi panen pertama micro-lot specialty.",
    ],
];

foreach ($notesDefs as $nd) {
    $noteBean = find_or_create(
        'Notes',
        ['name' => $nd['name']],
        [
            'name' => $nd['name'],
            'parent_type' => 'Accounts',
            'parent_id' => $accounts[$nd['account']]->id,
            'description' => $nd['desc'],
        ],
        $nd['date']
    );

    if ($noteBean->load_relationship('accounts')) {
        $noteBean->accounts->add($accounts[$nd['account']]->id);
    }
    if (isset($contacts[$nd['contact']]) && $contacts[$nd['contact']]->load_relationship('notes')) {
        $contacts[$nd['contact']]->notes->add($noteBean->id);
    }
}

// ---------------------------------------------------------------------------
// Best-effort Admin preferences (Asia/Jakarta timezone & IDR currency)
// ---------------------------------------------------------------------------
try {
    $adminUser = BeanFactory::getBean('Users', '1');
    if (!empty($adminUser->id)) {
        $adminUser->setPreference('timezone', 'Asia/Jakarta');
        $adminUser->setPreference('datef', 'Y-m-d');
        $adminUser->setPreference('timef', 'H:i');
        if (!empty($idrCurrencyId)) {
            $adminUser->setPreference('currency', $idrCurrencyId);
            $adminUser->setPreference('currency_show_preferred', '1');
            $adminUser->setPreference('default_currency_significant_digits', '0');
        }
        $adminUser->savePreferencesToDB();
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[WARN] Admin preference setup skipped: " . $e->getMessage() . "\n");
}

echo "\n=== Seeding Summary ===\n";
foreach ($stats as $mod => $cnts) {
    echo sprintf("  %-15s : Created=%-3d  Skipped/Updated=%-3d\n", $mod, $cnts['created'], $cnts['skipped']);
}
echo "=== PT Sumber Kopi Nusantara 5-Year CRM Seeder Completed Successfully ===\n";
