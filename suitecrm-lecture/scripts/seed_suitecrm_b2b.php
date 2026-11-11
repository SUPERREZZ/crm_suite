<?php
/**
 * Idempotent B2B PURE CRM Seeder for SuiteCRM 8
 * Business Case: PT Sumber Kopi Nusantara (Surabaya, East Java)
 * 10-Year Comprehensive CRM Lifecycle (2016 - 2026) - 3x Data Volume & Rich Dashboard
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

echo "=== Starting PT Sumber Kopi Nusantara 10-Year B2B CRM Seeder (2016-2026) ===\n";

// Transition cleanup for older legacy 1-year demo records if re-seeding existing database
$db->query("UPDATE calls SET deleted = 1 WHERE name != '[OVERDUE] Konfirmasi Demo Mesin Kopi Kontainer' AND status = 'Planned' AND name NOT LIKE '[PLANNED]%'");
$db->query("UPDATE opportunities SET deleted = 1 WHERE name IN ('[NEW BIZ] Paket Espresso 2-Group & Biji Kopi', '[RETENTION] Reaktivasi Kontrak Biji Kopi', '[UPSELL] Grinder Single Origin dan Sirup', '[WON] Mesin Aurelia dan Kontrak Kopi', '[LOST] Mesin Espresso Cabang')");
$db->query("UPDATE accounts SET deleted = 1 WHERE name = 'Grand Majapahit Hotel'");
$db->query("DELETE FROM accounts_contacts WHERE account_id IN (SELECT id FROM accounts WHERE deleted = 1)");

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
    $crmProto = getenv('SUITECRM_PROTO') ?: 'http';
    if ($crmHost !== 'localhost' && $crmHost !== '127.0.0.1') {
        $configurator->config['site_url'] = $crmProto . '://' . $crmHost;
        echo "[CONFIG] Set site_url to {$crmProto}://{$crmHost} (clean reverse proxy)\n";
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

$userMap = [
    'admin' => '1',
    '1' => '1',
    'bima' => $userBima->id,
    'citra' => $userCitra->id,
];

// ---------------------------------------------------------------------------
// 2. Campaigns (9 Campaigns across 2016 - 2026)
// ---------------------------------------------------------------------------
$campTypeWeb = must_key('campaign_type_dom', 'Web');
$campStatusActive = must_key('campaign_status_dom', 'Active');
$campStatusComplete = must_key('campaign_status_dom', 'Complete');

$campaignsData = json_decode(<<<'JSON'
[
  {
    "key": "camp_2016",
    "name": "Grand Opening Expo Surabaya 2016",
    "type": "Web",
    "status": "Complete",
    "start": "2016-03-01",
    "end": "2016-03-05",
    "budget": 20000000.0,
    "actual": 19500000.0,
    "rev": 250000000.0,
    "assigned": "citra",
    "date": "2016-02-15 08:00:00",
    "desc": "Pameran pembukaan PT Sumber Kopi Nusantara di Grand City Surabaya. Penetrasi segmen hotel bintang 5 & cafe."
  },
  {
    "key": "camp_2017",
    "name": "East Java Cafe & Roastery Tour 2017",
    "type": "Web",
    "status": "Complete",
    "start": "2017-05-10",
    "end": "2017-05-18",
    "budget": 18000000.0,
    "actual": 17800000.0,
    "rev": 180000000.0,
    "assigned": "bima",
    "date": "2017-04-20 09:00:00",
    "desc": "Roadshow demo mesin espresso & cupping roadshow Malang, Batu, Surabaya."
  },
  {
    "key": "camp_2018",
    "name": "Surabaya Hospitality Equipment Expo 2018",
    "type": "Web",
    "status": "Complete",
    "start": "2018-08-15",
    "end": "2018-08-20",
    "budget": 25000000.0,
    "actual": 24200000.0,
    "rev": 320000000.0,
    "assigned": "citra",
    "date": "2018-07-10 10:00:00",
    "desc": "Pameran peralatan Horeca Jawa Timur - fokus mesin espresso multi-boiler dan grinder komersial."
  },
  {
    "key": "camp_2019",
    "name": "East Java Coffee Week Festival 2019",
    "type": "Web",
    "status": "Complete",
    "start": "2019-09-05",
    "end": "2019-09-10",
    "budget": 22000000.0,
    "actual": 21500000.0,
    "rev": 280000000.0,
    "assigned": "bima",
    "date": "2019-08-01 09:30:00",
    "desc": "Sponsor utama kompetisi barista dan cupping specialty coffee Jawa Timur di JX International."
  },
  {
    "key": "camp_2020",
    "name": "B2B Digital Beverage Webinar 2020",
    "type": "Web",
    "status": "Complete",
    "start": "2020-05-15",
    "end": "2020-05-16",
    "budget": 8000000.0,
    "actual": 7500000.0,
    "rev": 120000000.0,
    "assigned": "bima",
    "date": "2020-04-25 11:00:00",
    "desc": "Webinar strategi bisnis kafe takeaway & higienitas mesin masa pandemi 2020."
  },
  {
    "key": "camp_2022",
    "name": "Surabaya Hospitality Fair 2022",
    "type": "Web",
    "status": "Complete",
    "start": "2022-08-01",
    "end": "2022-08-05",
    "budget": 30000000.0,
    "actual": 29000000.0,
    "rev": 350000000.0,
    "assigned": "citra",
    "date": "2022-07-10 10:00:00",
    "desc": "Pameran peralatan hotel & restoran - penetrasi segmen hotel bintang 4 & 5 Jawa Timur."
  },
  {
    "key": "camp_2023",
    "name": "Barista Championship & Demo 2023",
    "type": "Web",
    "status": "Complete",
    "start": "2023-06-12",
    "end": "2023-06-15",
    "budget": 24000000.0,
    "actual": 23500000.0,
    "rev": 290000000.0,
    "assigned": "bima",
    "date": "2023-05-01 13:00:00",
    "desc": "Sponsorship kejuaraan barista regional dan peluncuran lini grinder on-demand generasi baru."
  },
  {
    "key": "camp_2024",
    "name": "East Java Coffee Festival 2024",
    "type": "Web",
    "status": "Complete",
    "start": "2024-05-10",
    "end": "2024-05-15",
    "budget": 20000000.0,
    "actual": 19500000.0,
    "rev": 210000000.0,
    "assigned": "bima",
    "date": "2024-04-01 09:00:00",
    "desc": "Sponsorship cupping competition & workshop mesin espresso komersial."
  },
  {
    "key": "camp_2026",
    "name": "Pameran F&B Expo 2026 Surabaya",
    "type": "Web",
    "status": "Active",
    "start": "2026-02-01",
    "end": "2026-03-31",
    "budget": 25000000.0,
    "actual": 21500000.0,
    "rev": 200000000.0,
    "assigned": "bima",
    "date": "2026-01-15 08:00:00",
    "desc": "Booth pameran Grand City Convention Surabaya - target cafe baru & hotel di Jawa Timur."
  }
]
JSON
, true);

$campaigns = [];
foreach ($campaignsData as $cd) {
    $campBean = find_or_create(
        'Campaigns',
        ['name' => $cd['name']],
        [
            'name' => $cd['name'],
            'campaign_type' => $campTypeWeb,
            'status' => $cd['status'] === 'Active' ? $campStatusActive : $campStatusComplete,
            'currency_id' => $idrCurrencyId,
            'start_date' => utc_db_date($cd['start']),
            'end_date' => utc_db_date($cd['end']),
            'budget' => (float)$cd['budget'],
            'actual_cost' => (float)$cd['actual'],
            'expected_revenue' => (float)$cd['rev'],
            'expected_cost' => (float)$cd['budget'],
            'assigned_user_id' => $userMap[$cd['assigned']],
            'description' => $cd['desc'],
        ],
        $cd['date']
    );
    $campaigns[$cd['key']] = $campBean;
}

// ---------------------------------------------------------------------------
// 3. Accounts (45+) & Contacts (45+) - 10-Year Portfolio (2016-2026)
// ---------------------------------------------------------------------------
$accTypeCustomer = must_key('account_type_dom', 'Customer');
$accTypeProspect = must_key('account_type_dom', 'Prospect');
$indMap = [
    'Hospitality' => must_key('industry_dom', 'Hospitality'),
    'Retail' => must_key('industry_dom', 'Retail'),
    'Education' => must_key('industry_dom', 'Education'),
    'Healthcare' => must_key('industry_dom', 'Healthcare'),
    'Other' => must_key('industry_dom', 'Other'),
];

$accountsData = json_decode(<<<'JSON'
[
  {
    "key": "majapahit",
    "year": 2016,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Grand Majapahit Hotel Surabaya",
    "rev": 15000000000,
    "emp": 220,
    "phone": "031-5454000",
    "web": "https://grandmajapahit-hotel.com",
    "street": "Jl. Tunjungan No. 65, Genteng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60275",
    "assigned": "citra",
    "date": "2016-04-12 09:15:00",
    "desc": "Klien hotel bintang 5 tertua sejak April 2016 (Mitra 10 Tahun). Menggunakan 3 unit mesin Aurelia Wave & kontrak 150 kg/bulan Majapahit Blend.",
    "contact": {
      "first": "Antonius",
      "last": "Wibowo",
      "title": "Director of Food & Beverage",
      "dept": "F&B Management",
      "phone": "0811-3344-5566",
      "email": "antonius.wibowo@grandmajapahit-hotel.com",
      "date": "2016-04-12 09:20:00"
    }
  },
  {
    "key": "titik_temu",
    "year": 2016,
    "type": "Customer",
    "ind": "Retail",
    "name": "Kopi Titik Temu Surabaya",
    "rev": 4500000000,
    "emp": 45,
    "phone": "031-5678901",
    "web": "https://kopititiktemu.id",
    "street": "Jl. Raya Darmo No. 88, Wonokromo",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60241",
    "assigned": "citra",
    "date": "2016-09-05 11:30:00",
    "desc": "Mitra specialty coffee tertua sejak 2016 (Mitra 10 Tahun). Berkembang dari 1 kedai menjadi 4 cabang di Surabaya dan Sidoarjo.",
    "contact": {
      "first": "Danang",
      "last": "Prasetyo",
      "title": "Owner & Head of Operations",
      "dept": "Executive Office",
      "phone": "0812-3456-7890",
      "email": "danang@kopititiktemu.id",
      "date": "2016-09-05 11:35:00"
    }
  },
  {
    "key": "surabaya_club",
    "year": 2016,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Surabaya Golf & Country Club Dining",
    "rev": 8000000000,
    "emp": 90,
    "phone": "031-7412345",
    "web": "https://surabayagolfclub.id",
    "street": "Jl. Golf Barat No. 1, Dukuh Pakis",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60224",
    "assigned": "citra",
    "date": "2016-11-20 14:00:00",
    "desc": "Club house eksekutif sejak 2016. Pengadaan mesin komersial 2-Group VIP lounge dan pasokan house blend rutin.",
    "contact": {
      "first": "Edward",
      "last": "Kusuma",
      "title": "Club General Manager",
      "dept": "Operations",
      "phone": "0811-3322-1144",
      "email": "edward.k@surabayagolfclub.id",
      "date": "2016-11-20 14:10:00"
    }
  },
  {
    "key": "senja",
    "year": 2017,
    "type": "Customer",
    "ind": "Retail",
    "name": "Senja Roastery & Eatery",
    "rev": 2200000000,
    "emp": 22,
    "phone": "031-5031234",
    "web": "https://senjaroastery.com",
    "street": "Jl. Jawa No. 12, Gubeng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60281",
    "assigned": "citra",
    "date": "2017-02-14 14:00:00",
    "desc": "Roastery & cafe artisan mitra sejak 2017. Pengguna mesin Appia Life 2G dan pasokan rutin green beans Jawa Ijen & Dampit 180 kg/bulan.",
    "contact": {
      "first": "Hendra",
      "last": "Wijaya",
      "title": "Managing Partner & Head Roaster",
      "dept": "Roastery Operations",
      "phone": "0813-9876-5432",
      "email": "hendra@senjaroastery.com",
      "date": "2017-02-14 14:05:00"
    }
  },
  {
    "key": "rawon_kalkun",
    "year": 2017,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Restoran Taman Rawon Surabaya",
    "rev": 3200000000,
    "emp": 35,
    "phone": "031-5345678",
    "web": "https://tamanrawon.co.id",
    "street": "Jl. Embong Malang No. 44, Genteng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60261",
    "assigned": "bima",
    "date": "2017-06-18 10:30:00",
    "desc": "Restoran kuliner legendaris Surabaya sejak 2017. Menyajikan es kopi susu Jawa Drip & espresso tradisional.",
    "contact": {
      "first": "Hj. Siti",
      "last": "Aminah",
      "title": "Owner & Founder",
      "dept": "Executive Office",
      "phone": "0812-3344-7788",
      "email": "aminah@tamanrawon.co.id",
      "date": "2017-06-18 10:35:00"
    }
  },
  {
    "key": "batu_highland",
    "year": 2017,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Batu Highland Resort & Convention",
    "rev": 9500000000,
    "emp": 110,
    "phone": "0341-591234",
    "web": "https://batuhighlandresort.com",
    "street": "Jl. Trunojoyo No. 45, Oro-Oro Ombo",
    "city": "Batu",
    "state": "Jawa Timur",
    "postal": "65316",
    "assigned": "citra",
    "date": "2017-10-10 10:00:00",
    "desc": "Resort bintang 4 di Kota Batu mitra sejak akhir 2017. 3 mesin espresso resto, banquet hall, dan program water filtration.",
    "contact": {
      "first": "Guntur",
      "last": "Wicaksono",
      "title": "General Manager",
      "dept": "Hotel Operations",
      "phone": "0812-4455-6677",
      "email": "guntur.w@batuhighlandresort.com",
      "date": "2017-10-10 10:10:00"
    }
  },
  {
    "key": "petrokimia",
    "year": 2018,
    "type": "Customer",
    "ind": "Other",
    "name": "PT Petrokimia Sentosa Mess & Lounge",
    "rev": 30000000000,
    "emp": 500,
    "phone": "031-3981122",
    "web": "https://petrokimiasentosa.co.id",
    "street": "Jl. Jenderal Ahmad Yani No. 100, Sukorame",
    "city": "Gresik",
    "state": "Jawa Timur",
    "postal": "61119",
    "assigned": "citra",
    "date": "2018-03-15 13:45:00",
    "desc": "Akun korporasi B2B sejak 2018. Mesin super-otomatis bean-to-cup executive lounge & pasokan 60 kg/bulan stabil.",
    "contact": {
      "first": "Bambang",
      "last": "Soedirman",
      "title": "Head of General Affairs & HR",
      "dept": "General Affairs",
      "phone": "0811-7788-9900",
      "email": "bambang.s@petrokimiasentosa.co.id",
      "date": "2018-03-15 13:50:00"
    }
  },
  {
    "key": "semen_indonesia",
    "year": 2018,
    "type": "Customer",
    "ind": "Other",
    "name": "Gedung Diklat Semen Indonesia Gresik",
    "rev": 22000000000,
    "emp": 350,
    "phone": "031-3971234",
    "web": "https://diklat-semenindonesia.co.id",
    "street": "Jl. Veteran No. 1, Kebomas",
    "city": "Gresik",
    "state": "Jawa Timur",
    "postal": "61122",
    "assigned": "bima",
    "date": "2018-07-22 09:30:00",
    "desc": "Pusat pelatihan korporat sejak 2018. Penyediaan coffee corner self-service peserta training dan tamu BUMN.",
    "contact": {
      "first": "Hartono",
      "last": "Prabowo",
      "title": "Facility & Catering Manager",
      "dept": "General Support",
      "phone": "0812-8877-6655",
      "email": "hartono.p@diklat-semenindonesia.co.id",
      "date": "2018-07-22 09:35:00"
    }
  },
  {
    "key": "java_heritage",
    "year": 2018,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Java Heritage Club & Cigar Lounge",
    "rev": 5500000000,
    "emp": 50,
    "phone": "031-7345678",
    "web": "https://javaheritageclub.id",
    "street": "Jl. Mayjen Sungkono No. 102, Dukuh Pakis",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60225",
    "assigned": "citra",
    "date": "2018-11-05 15:00:00",
    "desc": "Lounge eksklusif mitra sejak 2018. Mesin custom brass 3-group dan kurasi biji kopi single origin vintage.",
    "contact": {
      "first": "Ferry",
      "last": "Gunawan",
      "title": "Bar & Beverage Manager",
      "dept": "Beverage Division",
      "phone": "0813-2233-4455",
      "email": "ferry.g@javaheritageclub.id",
      "date": "2018-11-05 15:10:00"
    }
  },
  {
    "key": "kopi_jalanan",
    "year": 2019,
    "type": "Customer",
    "ind": "Retail",
    "name": "Kedai Kopi Jalanan Malang",
    "rev": 1400000000,
    "emp": 16,
    "phone": "0341-367890",
    "web": "https://kopijalananmalang.id",
    "street": "Jl. Soekarno Hatta No. 25, Lowokwaru",
    "city": "Malang",
    "state": "Jawa Timur",
    "postal": "65141",
    "assigned": "bima",
    "date": "2019-02-18 11:00:00",
    "desc": "Kedai kopi mahasiswa favorit di Jalan Suhat Malang sejak 2019. Paket mesin 2-Group Appia Life & blend kopi susu.",
    "contact": {
      "first": "Reza",
      "last": "Pahlawan",
      "title": "Founder & Operational Lead",
      "dept": "Operations",
      "phone": "0812-7766-5533",
      "email": "reza@kopijalananmalang.id",
      "date": "2019-02-18 11:05:00"
    }
  },
  {
    "key": "suhat_roaster",
    "year": 2019,
    "type": "Customer",
    "ind": "Retail",
    "name": "Suhat Artisan Coffee Roasters",
    "rev": 1900000000,
    "emp": 18,
    "phone": "0341-489012",
    "web": "https://suhatroasters.com",
    "street": "Jl. Semanggi Timur No. 7, Lowokwaru",
    "city": "Malang",
    "state": "Jawa Timur",
    "postal": "65141",
    "assigned": "citra",
    "date": "2019-05-12 14:30:00",
    "desc": "Micro-roastery mitra sejak 2019. Pengadaan green beans specialty Jawa Timur dan kalibrasi mesin cupping lab.",
    "contact": {
      "first": "Dedi",
      "last": "Kurniawan",
      "title": "Head Roaster",
      "dept": "Production",
      "phone": "0813-4455-6677",
      "email": "dedi@suhatroasters.com",
      "date": "2019-05-12 14:35:00"
    }
  },
  {
    "key": "brawijaya_hub",
    "year": 2019,
    "type": "Customer",
    "ind": "Education",
    "name": "Brawijaya Academic Center Lounge",
    "rev": 2500000000,
    "emp": 25,
    "phone": "0341-551611",
    "web": "https://lounge.ub.ac.id",
    "street": "Jl. Veteran No. 10, Lowokwaru",
    "city": "Malang",
    "state": "Jawa Timur",
    "postal": "65145",
    "assigned": "bima",
    "date": "2019-08-20 10:15:00",
    "desc": "Lounge dosen dan tamu rektorat Universitas Brawijaya sejak 2019. Mesin bean-to-cup otomatis kapasitas tinggi.",
    "contact": {
      "first": "Dr. Ir.",
      "last": "Budi Santoso",
      "title": "Kepala Biro Umum Kampus",
      "dept": "Biro Umum",
      "phone": "0811-3677-8899",
      "email": "budi.s@ub.ac.id",
      "date": "2019-08-20 10:20:00"
    }
  },
  {
    "key": "banyuwangi_resort",
    "year": 2019,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Ijen View Luxury Resort Banyuwangi",
    "rev": 7000000000,
    "emp": 85,
    "phone": "0333-421234",
    "web": "https://ijenviewresort.com",
    "street": "Jl. Raya Jember No. 100, Giri",
    "city": "Banyuwangi",
    "state": "Jawa Timur",
    "postal": "68425",
    "assigned": "citra",
    "date": "2019-11-15 16:00:00",
    "desc": "Resort wisata Kawah Ijen sejak 2019. Mengusung konsep single origin kopi Ijen segar langsung dari kebun mitra.",
    "contact": {
      "first": "Made",
      "last": "Artana",
      "title": "F&B Manager",
      "dept": "Hospitality",
      "phone": "0812-9988-1122",
      "email": "artana@ijenviewresort.com",
      "date": "2019-11-15 16:05:00"
    }
  },
  {
    "key": "rs_mitra",
    "year": 2020,
    "type": "Customer",
    "ind": "Healthcare",
    "name": "Rumah Sakit Mitra Sejahtera Cafeteria",
    "rev": 6000000000,
    "emp": 75,
    "phone": "031-8945678",
    "web": "https://rsmitrasejahtera.com",
    "street": "Jl. Pahlawan No. 15, Magersari",
    "city": "Sidoarjo",
    "state": "Jawa Timur",
    "postal": "61212",
    "assigned": "bima",
    "date": "2020-04-10 09:00:00",
    "desc": "Penyediaan kopi otomatis higienis touchless untuk dokter lounge & tenaga medis garda depan masa pandemi 2020.",
    "contact": {
      "first": "Ratna",
      "last": "Sari",
      "title": "Koordinator Fasilitas & Gizi",
      "dept": "Fasilitas & Gizi",
      "phone": "0812-8899-0011",
      "email": "dr.ratna@rsmitrasejahtera.com",
      "date": "2020-04-10 09:10:00"
    }
  },
  {
    "key": "siloam_surabaya",
    "year": 2020,
    "type": "Customer",
    "ind": "Healthcare",
    "name": "Siloam Hospital Doctors Lounge Surabaya",
    "rev": 12000000000,
    "emp": 150,
    "phone": "031-5038999",
    "web": "https://siloamhospitals.com",
    "street": "Jl. Raya Gubeng No. 70, Gubeng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60281",
    "assigned": "citra",
    "date": "2020-07-14 11:20:00",
    "desc": "Fasilitas pantry premium dokter spesialis sejak 2020. 2 unit mesin kopi bean-to-cup higienis & pasokan blend 45 kg/bulan.",
    "contact": {
      "first": "dr. Hendro",
      "last": "Gunawan Sp.PD",
      "title": "Ketua Komite Medis",
      "dept": "Komite Medis",
      "phone": "0811-3455-6677",
      "email": "dr.hendro@siloamhospitals.com",
      "date": "2020-07-14 11:25:00"
    }
  },
  {
    "key": "telkom_landmark",
    "year": 2020,
    "type": "Customer",
    "ind": "Other",
    "name": "Telkom Landmark Tower Digital Hub",
    "rev": 40000000000,
    "emp": 600,
    "phone": "031-8291234",
    "web": "https://telkomdigitallounge.id",
    "street": "Jl. Ketintang No. 156, Gayungan",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60231",
    "assigned": "bima",
    "date": "2020-09-25 14:00:00",
    "desc": "Smart pantry kantor inovasi digital Telkom sejak 2020. Mesin otomatis terintegrasi RFID card karyawan.",
    "contact": {
      "first": "Ahmad",
      "last": "Fauzi",
      "title": "Workspace Experience Lead",
      "dept": "Digital Hub",
      "phone": "0813-1122-8899",
      "email": "ahmad.fauzi@telkomdigitallounge.id",
      "date": "2020-09-25 14:05:00"
    }
  },
  {
    "key": "kopi_darurat",
    "year": 2020,
    "type": "Customer",
    "ind": "Retail",
    "name": "Kopi Drive-Thru Merdeka Surabaya",
    "rev": 1800000000,
    "emp": 20,
    "phone": "031-5991234",
    "web": "https://kopidrivethru.id",
    "street": "Jl. Dr. Ir. H. Soekarno No. 88, Rungkut",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60293",
    "assigned": "bima",
    "date": "2020-11-18 15:30:00",
    "desc": "Model kedai drive-thru pionir pandemi 2020 di jalan MERR. Mesin high-volume 3-group ekstraksi cepat.",
    "contact": {
      "first": "Satria",
      "last": "Yudha",
      "title": "Managing Director",
      "dept": "Operations",
      "phone": "0812-5566-7788",
      "email": "satria@kopidrivethru.id",
      "date": "2020-11-18 15:35:00"
    }
  },
  {
    "key": "sudut_kota",
    "year": 2021,
    "type": "Customer",
    "ind": "Retail",
    "name": "Kopi Sudut Kota Malang",
    "rev": 1500000000,
    "emp": 16,
    "phone": "0341-356789",
    "web": "https://kopisudutkota.id",
    "street": "Jl. Ijen No. 42, Klojen",
    "city": "Malang",
    "state": "Jawa Timur",
    "postal": "65115",
    "assigned": "bima",
    "date": "2021-03-08 15:20:00",
    "desc": "Cafe favorit mahasiswa & profesional muda Kota Malang sejak 2021. Mesin Appia Life 2-group dan ekspansi grinder on-demand.",
    "contact": {
      "first": "Clara",
      "last": "Shinta",
      "title": "Founder & Managing Director",
      "dept": "Executive Office",
      "phone": "0812-1122-3344",
      "email": "clara@kopisudutkota.id",
      "date": "2021-03-08 15:25:00"
    }
  },
  {
    "key": "bumi_surabaya",
    "year": 2021,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Bumi Surabaya City Resort Banquet",
    "rev": 18000000000,
    "emp": 250,
    "phone": "031-5311234",
    "web": "https://bumisurabaya.com",
    "street": "Jl. Jenderal Basuki Rahmat No. 106, Genteng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60271",
    "assigned": "citra",
    "date": "2021-06-15 10:00:00",
    "desc": "Resort bintang 5 pusat kota Surabaya sejak 2021. Layanan kopi perjamuan kenegaraan dan pesta pernikahan.",
    "contact": {
      "first": "Johan",
      "last": "Pranoto",
      "title": "Banquet Operations Manager",
      "dept": "Banquet Division",
      "phone": "0811-3122-4455",
      "email": "johan.p@bumisurabaya.com",
      "date": "2021-06-15 10:10:00"
    }
  },
  {
    "key": "unair_campus",
    "year": 2021,
    "type": "Customer",
    "ind": "Education",
    "name": "Universitas Airlangga Faculty Lounge",
    "rev": 3000000000,
    "emp": 30,
    "phone": "031-5914042",
    "web": "https://unair.ac.id",
    "street": "Jl. Airlangga No. 4, Gubeng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60286",
    "assigned": "bima",
    "date": "2021-09-10 13:30:00",
    "desc": "Lounge dosen FEB & FK Unair sejak 2021. Pengadaan 2 unit bean-to-cup dan biji kopi blend Nusantara.",
    "contact": {
      "first": "Prof. Dr.",
      "last": "Agus Triyono",
      "title": "Wakil Dekan Bidang Sumber Daya",
      "dept": "Dekanat",
      "phone": "0812-3211-9988",
      "email": "agus.triyono@unair.ac.id",
      "date": "2021-09-10 13:35:00"
    }
  },
  {
    "key": "surabaya_suites",
    "year": 2021,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Surabaya Suites Hotel & Resto",
    "rev": 9000000000,
    "emp": 100,
    "phone": "031-5316833",
    "web": "https://surabayasuites.com",
    "street": "Jl. Pemuda No. 31, Genteng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60271",
    "assigned": "citra",
    "date": "2021-12-05 11:00:00",
    "desc": "Hotel heritage pusat kota sejak 2021. Mesin espresso restoran utama dan kafe pastry lobi.",
    "contact": {
      "first": "Mira",
      "last": "Kencanawati",
      "title": "Executive Assistant Manager",
      "dept": "Hotel Management",
      "phone": "0813-3344-9900",
      "email": "mira.k@surabayasuites.com",
      "date": "2021-12-05 11:05:00"
    }
  },
  {
    "key": "heritage_club",
    "year": 2022,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Surabaya Heritage Club & Lounge",
    "rev": 4800000000,
    "emp": 45,
    "phone": "031-7345888",
    "web": "https://surabayaheritageclub.id",
    "street": "Jl. Mayjen Sungkono No. 102, Dukuh Pakis",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60225",
    "assigned": "citra",
    "date": "2022-03-12 11:00:00",
    "desc": "Private dining and cigar lounge Surabaya Barat sejak 2022. Mesin 3-group custom brass finishing & suplai kopi single origin.",
    "contact": {
      "first": "Ferry",
      "last": "Gunawan (Bar)",
      "title": "Head Sommelier & Bar Manager",
      "dept": "Beverage",
      "phone": "0813-2233-4499",
      "email": "ferry.bar@surabayaheritageclub.id",
      "date": "2022-03-12 11:10:00"
    }
  },
  {
    "key": "jember_specialty",
    "year": 2022,
    "type": "Customer",
    "ind": "Retail",
    "name": "Jember Specialty Coffee Laboratory",
    "rev": 1600000000,
    "emp": 15,
    "phone": "0331-487654",
    "web": "https://jemberspecialtylab.com",
    "street": "Jl. Gajah Mada No. 120, Kaliwates",
    "city": "Jember",
    "state": "Jawa Timur",
    "postal": "68131",
    "assigned": "bima",
    "date": "2022-05-18 14:00:00",
    "desc": "Laboratorium kopi dan academy barista Jember sejak 2022. Alat cupping profesional dan mesin espresso training.",
    "contact": {
      "first": "Bagus",
      "last": "Setiawan",
      "title": "Q-Grader & Founder",
      "dept": "Training Academy",
      "phone": "0812-4433-2211",
      "email": "bagus@jemberspecialtylab.com",
      "date": "2022-05-18 14:05:00"
    }
  },
  {
    "key": "bromo_terrace",
    "year": 2022,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Bromo Terrace Hotel & Sky Bar",
    "rev": 7500000000,
    "emp": 70,
    "phone": "0335-581234",
    "web": "https://bromoterracehotel.com",
    "street": "Jl. Raya Bromo No. 88, Sukapura",
    "city": "Probolinggo",
    "state": "Jawa Timur",
    "postal": "67254",
    "assigned": "citra",
    "date": "2022-08-25 10:30:00",
    "desc": "Hotel pegunungan wisata Bromo sejak 2022. Mesin komersial heavy duty tahan dingin dan cuaca ekstrem.",
    "contact": {
      "first": "Indra",
      "last": "Lesmana",
      "title": "Resort Operations Director",
      "dept": "Resort Operations",
      "phone": "0811-3566-7788",
      "email": "indra@bromoterracehotel.com",
      "date": "2022-08-25 10:35:00"
    }
  },
  {
    "key": "maspion_tower",
    "year": 2022,
    "type": "Customer",
    "ind": "Other",
    "name": "Maspion Square Executive Dining",
    "rev": 20000000000,
    "emp": 280,
    "phone": "031-8471234",
    "web": "https://maspionsquare.com",
    "street": "Jl. Ahmad Yani No. 73, Margorejo",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60238",
    "assigned": "citra",
    "date": "2022-10-14 15:15:00",
    "desc": "Ruang jamuan dewan komisaris grup Maspion sejak 2022. Mesin espresso Italia multi-boiler dan suplai kopi VIP.",
    "contact": {
      "first": "Rudy",
      "last": "Soeprapto",
      "title": "Corporate Secretary General Affairs",
      "dept": "General Affairs",
      "phone": "0812-3000-8899",
      "email": "rudy.s@maspionsquare.com",
      "date": "2022-10-14 15:20:00"
    }
  },
  {
    "key": "kediri_roastery",
    "year": 2022,
    "type": "Customer",
    "ind": "Retail",
    "name": "Kediri Roastery Hub & Academy",
    "rev": 1300000000,
    "emp": 14,
    "phone": "0354-681234",
    "web": "https://kediriroastery.id",
    "street": "Jl. Dhoho No. 95, Kemasan",
    "city": "Kediri",
    "state": "Jawa Timur",
    "postal": "64129",
    "assigned": "bima",
    "date": "2022-12-01 11:20:00",
    "desc": "Sentra roastery dan komunitas kedai kopi Karesidenan Kediri sejak akhir 2022. Suplai green bean 100 kg/bulan.",
    "contact": {
      "first": "Tri",
      "last": "Nugroho",
      "title": "Head Roaster & Co-Owner",
      "dept": "Production",
      "phone": "0813-5788-9900",
      "email": "tri@kediriroastery.id",
      "date": "2022-12-01 11:25:00"
    }
  },
  {
    "key": "aksara",
    "year": 2023,
    "type": "Customer",
    "ind": "Education",
    "name": "Kafe Aksara & Co-Working",
    "rev": 1100000000,
    "emp": 14,
    "phone": "031-8794321",
    "web": "https://aksaracoworking.id",
    "street": "Jl. Dr. Ir. H. Soekarno No. 201, MERR, Rungkut",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60298",
    "assigned": "bima",
    "date": "2023-01-20 10:30:00",
    "desc": "Hub mahasiswa & digital nomad Surabaya Timur sejak 2023. Mesin espresso 1-Group dan konsinyasi kopi cold brew botol.",
    "contact": {
      "first": "Nadia",
      "last": "Kusuma",
      "title": "Community & Operations Manager",
      "dept": "Operations",
      "phone": "0817-5566-7788",
      "email": "nadia@aksaracoworking.id",
      "date": "2023-01-20 10:35:00"
    }
  },
  {
    "key": "graha_pena",
    "year": 2023,
    "type": "Customer",
    "ind": "Other",
    "name": "Graha Pena Press Cafe Surabaya",
    "rev": 8500000000,
    "emp": 120,
    "phone": "031-8283333",
    "web": "https://grahapena-sub.com",
    "street": "Jl. Ahmad Yani No. 88, Ketintang",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60231",
    "assigned": "citra",
    "date": "2023-04-15 14:00:00",
    "desc": "Gedung media pers terbesar di Jawa Timur sejak 2023. Coffee shop lobi gedung perkantoran dan ruang redaksi wartawan.",
    "contact": {
      "first": "Yudi",
      "last": "Ananta",
      "title": "Building Facility Manager",
      "dept": "Building Management",
      "phone": "0812-3111-2233",
      "email": "yudi.a@grahapena-sub.com",
      "date": "2023-04-15 14:05:00"
    }
  },
  {
    "key": "ciputra_golf",
    "year": 2023,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Ciputra Golf Club House Restaurant",
    "rev": 14000000000,
    "emp": 160,
    "phone": "031-7441111",
    "web": "https://ciputragolf.com",
    "street": "Jl. CitraRaya Utama, Lakarsantri",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60219",
    "assigned": "citra",
    "date": "2023-07-20 11:30:00",
    "desc": "Klub golf premier Surabaya Barat sejak 2023. Mesin espresso premium 2 unit dan layanan kopi turnamen VIP.",
    "contact": {
      "first": "Hendra",
      "last": "Tjahjadi",
      "title": "Director of Clubhouse & F&B",
      "dept": "F&B Division",
      "phone": "0811-3299-4455",
      "email": "hendra.t@ciputragolf.com",
      "date": "2023-07-20 11:35:00"
    }
  },
  {
    "key": "its_research",
    "year": 2023,
    "type": "Customer",
    "ind": "Education",
    "name": "ITS Robotics Center Tech Cafe",
    "rev": 2000000000,
    "emp": 20,
    "phone": "031-5994251",
    "web": "https://robotics.its.ac.id",
    "street": "Kampus ITS Sukolilo, Keputih",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60111",
    "assigned": "bima",
    "date": "2023-09-18 15:45:00",
    "desc": "Cafe riset robotika mahasiswa & dosen ITS sejak 2023. Kolaborasi riset otomatisasi seduh kopi pour-over robotik.",
    "contact": {
      "first": "Dr. Eng.",
      "last": "Dian Rahayu",
      "title": "Kepala Laboratorium Robotika",
      "dept": "Riset & Inovasi",
      "phone": "0813-3122-3344",
      "email": "dian.rahayu@its.ac.id",
      "date": "2023-09-18 15:50:00"
    }
  },
  {
    "key": "pakuwon_mall",
    "year": 2023,
    "type": "Customer",
    "ind": "Retail",
    "name": "Pakuwon Food Society Specialty Kiosk",
    "rev": 2800000000,
    "emp": 24,
    "phone": "031-7390888",
    "web": "https://pakuwonmall.com",
    "street": "Jl. Mayjen Jonosewojo No. 2, Babatan",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60227",
    "assigned": "citra",
    "date": "2023-11-28 13:00:00",
    "desc": "Kiosk specialty coffee di area Food Society Pakuwon Mall Surabaya sejak 2023. Traffic padat akhir pekan 300 cup/hari.",
    "contact": {
      "first": "Stella",
      "last": "Widjaja",
      "title": "Retail Operations Supervisor",
      "dept": "Retail Operations",
      "phone": "0812-8877-1122",
      "email": "stella.w@pakuwonmall.com",
      "date": "2023-11-28 13:05:00"
    }
  },
  {
    "key": "aroma_bali",
    "year": 2024,
    "type": "Customer",
    "ind": "Retail",
    "name": "Aroma Kopi Luwak & Espresso Bar Bali",
    "rev": 3500000000,
    "emp": 28,
    "phone": "0361-751234",
    "web": "https://aromaluwakbali.com",
    "street": "Jl. Sunset Road No. 88, Seminyak",
    "city": "Badung",
    "state": "Bali",
    "postal": "80361",
    "assigned": "citra",
    "date": "2024-02-14 16:00:00",
    "desc": "Espresso bar turis di Bali sejak 2024. Suplai rutin biji kopi specialty Jawa Timur (Ijen Anaerob & Robusta Dampit).",
    "contact": {
      "first": "Made",
      "last": "Suryawan",
      "title": "Owner & Head Barista",
      "dept": "Barista Team",
      "phone": "0819-3322-1100",
      "email": "made@aromaluwakbali.com",
      "date": "2024-02-14 16:05:00"
    }
  },
  {
    "key": "ubaya_lounge",
    "year": 2024,
    "type": "Customer",
    "ind": "Education",
    "name": "Ubaya Student Hub Cafeteria",
    "rev": 1700000000,
    "emp": 18,
    "phone": "031-2981000",
    "web": "https://ubaya.ac.id",
    "street": "Jl. Raya Kalirungkut, Rungkut",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60293",
    "assigned": "bima",
    "date": "2024-05-10 10:00:00",
    "desc": "Kantin modern kampus Ubaya Tenggilis sejak 2024. Mesin espresso 2-Group dan edukasi kewirausahaan mahasiswa.",
    "contact": {
      "first": "Lina",
      "last": "Marlina",
      "title": "Manajer Kemahasiswaan & Fasilitas",
      "dept": "Direktorat Kemahasiswaan",
      "phone": "0812-3499-8877",
      "email": "lina.m@ubaya.ac.id",
      "date": "2024-05-10 10:05:00"
    }
  },
  {
    "key": "gresik_petro",
    "year": 2024,
    "type": "Customer",
    "ind": "Other",
    "name": "Petrokimia Gresik Innovation Center",
    "rev": 25000000000,
    "emp": 400,
    "phone": "031-3982200",
    "web": "https://petrokimia-gresik.com",
    "street": "Jl. Jenderal Ahmad Yani, Gresik",
    "city": "Gresik",
    "state": "Jawa Timur",
    "postal": "61119",
    "assigned": "citra",
    "date": "2024-07-22 14:15:00",
    "desc": "Gedung pusat riset dan riset agroteknologi sejak 2024. Smart coffee bar dan lounge kolaborasi peneliti.",
    "contact": {
      "first": "Ir.",
      "last": "Danang Sasongko",
      "title": "VP Innovation & Technology",
      "dept": "Inovasi",
      "phone": "0811-3377-6655",
      "email": "danang.sasongko@petrokimia-gresik.com",
      "date": "2024-07-22 14:20:00"
    }
  },
  {
    "key": "pasir_putih",
    "year": 2024,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Pasir Putih Resort Situbondo",
    "rev": 5000000000,
    "emp": 60,
    "phone": "0338-389123",
    "web": "https://pasirputihresort.id",
    "street": "Jl. Raya Pasir Putih No. 1, Bungatan",
    "city": "Situbondo",
    "state": "Jawa Timur",
    "postal": "68358",
    "assigned": "bima",
    "date": "2024-09-18 11:30:00",
    "desc": "Resort pantai pantura Jawa Timur sejak 2024. Mesin espresso seaside cafe dan paket perawatan anti-korosi garam laut.",
    "contact": {
      "first": "Agus",
      "last": "Sudrajat",
      "title": "Resort General Manager",
      "dept": "Hotel Operations",
      "phone": "0812-7788-2233",
      "email": "agus.s@pasirputihresort.id",
      "date": "2024-09-18 11:35:00"
    }
  },
  {
    "key": "mojokerto_cafe",
    "year": 2024,
    "type": "Customer",
    "ind": "Retail",
    "name": "Kopi Majapahit Kolese Mojokerto",
    "rev": 950000000,
    "emp": 12,
    "phone": "0321-321456",
    "web": "https://kopimajapahit.id",
    "street": "Jl. Benteng Pancasila No. 40, Magersari",
    "city": "Mojokerto",
    "state": "Jawa Timur",
    "postal": "61318",
    "assigned": "bima",
    "date": "2024-11-20 15:00:00",
    "desc": "Kedai kopi bernuansa sejarah Majapahit sejak 2024. Mesin espresso 1-Group dan pasokan kopi robusta Pacet gunung Welirang.",
    "contact": {
      "first": "Bayu",
      "last": "Aji",
      "title": "Owner & Conceptor",
      "dept": "Management",
      "phone": "0813-8899-3344",
      "email": "bayu@kopimajapahit.id",
      "date": "2024-11-20 15:05:00"
    }
  },
  {
    "key": "djava_bistro",
    "year": 2025,
    "type": "Customer",
    "ind": "Retail",
    "name": "D Java Bistro & Bakery Madiun",
    "rev": 1000000000,
    "emp": 12,
    "phone": "0351-461234",
    "web": "https://djavabistro.com",
    "street": "Jl. Pahlawan No. 55, Kartoharjo",
    "city": "Madiun",
    "state": "Jawa Timur",
    "postal": "63122",
    "assigned": "bima",
    "date": "2025-02-10 11:20:00",
    "desc": "Bistro & bakery modern di Madiun sejak awal 2025. Mesin Appia Life Compact 2G dan pasokan biji kopi house blend 50 kg/bulan.",
    "contact": {
      "first": "Rudi",
      "last": "Hartono",
      "title": "Operational Manager",
      "dept": "F&B Operations",
      "phone": "0813-7766-5544",
      "email": "rudi@djavabistro.com",
      "date": "2025-02-10 11:25:00"
    }
  },
  {
    "key": "cakrawala_univ",
    "year": 2025,
    "type": "Customer",
    "ind": "Education",
    "name": "Cakrawala University Student Lounge",
    "rev": 1800000000,
    "emp": 16,
    "phone": "031-5941234",
    "web": "https://cakrawala.ac.id",
    "street": "Jl. Menur Pumpungan No. 120, Sukolilo",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60118",
    "assigned": "bima",
    "date": "2025-04-25 14:15:00",
    "desc": "Kiosk kopi inkubator bisnis kewirausahaan mahasiswa sejak 2025. Mesin komersial 1-group dan program sertifikasi barista.",
    "contact": {
      "first": "Siti",
      "last": "Rahmawati",
      "title": "Kepala UPT Kewirausahaan Kampus",
      "dept": "UPT Kewirausahaan",
      "phone": "0812-6655-4433",
      "email": "s.rahmawati@cakrawala.ac.id",
      "date": "2025-04-25 14:20:00"
    }
  },
  {
    "key": "tugu_hotel",
    "year": 2025,
    "type": "Customer",
    "ind": "Hospitality",
    "name": "Tugu Luxury Boutique Hotel Malang",
    "rev": 11000000000,
    "emp": 130,
    "phone": "0341-363891",
    "web": "https://tuguhotels.com",
    "street": "Jl. Tugu No. 3, Klojen",
    "city": "Malang",
    "state": "Jawa Timur",
    "postal": "65119",
    "assigned": "citra",
    "date": "2025-07-15 09:45:00",
    "desc": "Hotel butik mewah penuh karya seni antik Malang sejak 2025. Pengadaan mesin espresso custom bronze dan blend kopi kolonial.",
    "contact": {
      "first": "Alexander",
      "last": "Suherman",
      "title": "Hotel Resident Manager",
      "dept": "Executive Committee",
      "phone": "0811-3444-9988",
      "email": "alexander@tuguhotels.com",
      "date": "2025-07-15 09:50:00"
    }
  },
  {
    "key": "pakuwon_tower",
    "year": 2025,
    "type": "Customer",
    "ind": "Other",
    "name": "Pakuwon Tower Executive Lounge",
    "rev": 35000000000,
    "emp": 450,
    "phone": "031-8288888",
    "web": "https://pakuwontower.com",
    "street": "Jl. Embong Malang No. 1-5, Tegalsari",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60261",
    "assigned": "citra",
    "date": "2025-09-30 16:00:00",
    "desc": "Pantry eksekutif lantai 30 Pakuwon Tower Surabaya sejak 2025. 2 unit mesin espresso bean-to-cup touch display digital.",
    "contact": {
      "first": "Caroline",
      "last": "Wijaya",
      "title": "Tenant Relations Director",
      "dept": "Property Management",
      "phone": "0812-3311-5566",
      "email": "caroline.w@pakuwontower.com",
      "date": "2025-09-30 16:05:00"
    }
  },
  {
    "key": "mayapada_hospital",
    "year": 2025,
    "type": "Customer",
    "ind": "Healthcare",
    "name": "Mayapada Hospital Specialty Corner",
    "rev": 15000000000,
    "emp": 180,
    "phone": "031-99245555",
    "web": "https://mayapadahospital.com",
    "street": "Jl. Mayjen Sungkono No. 20, Sawahan",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60224",
    "assigned": "bima",
    "date": "2025-11-18 10:30:00",
    "desc": "Corner specialty coffee modern lobi rumah sakit bertaraf internasional Mayapada Surabaya sejak akhir 2025.",
    "contact": {
      "first": "dr. Stephanie",
      "last": "Pranata MARS",
      "title": "Direktur Operasional Rumah Sakit",
      "dept": "Direksi",
      "phone": "0811-3099-7788",
      "email": "stephanie.p@mayapadahospital.com",
      "date": "2025-11-18 10:35:00"
    }
  },
  {
    "key": "kopi_kontainer",
    "year": 2026,
    "type": "Prospect",
    "ind": "Retail",
    "name": "Kopi Kontainer Sidoarjo",
    "rev": 500000000,
    "emp": 6,
    "phone": "031-8961234",
    "web": "https://kopikontainersda.id",
    "street": "Jl. KH Mukmin No. 18, Sidokare",
    "city": "Sidoarjo",
    "state": "Jawa Timur",
    "postal": "61214",
    "assigned": "bima",
    "date": "2026-02-15 10:00:00",
    "desc": "Prospek hangat hasil konversi pameran F&B Expo 2026 Surabaya. Rencana pembukaan kedai kopi kontainer outdoor.",
    "contact": {
      "first": "Wahyu",
      "last": "Pratama",
      "title": "Founder & Owner",
      "dept": "Business Owner",
      "phone": "0812-9988-7766",
      "email": "wahyu@kopikontainersda.id",
      "date": "2026-02-15 10:05:00"
    }
  },
  {
    "key": "intiland_tower",
    "year": 2026,
    "type": "Prospect",
    "ind": "Other",
    "name": "Gedung Perkantoran Intiland Tower Cafe",
    "rev": 16000000000,
    "emp": 210,
    "phone": "031-5471234",
    "web": "https://intilandtower-sub.com",
    "street": "Jl. Panglima Sudirman No. 101, Genteng",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60271",
    "assigned": "citra",
    "date": "2026-01-22 09:30:00",
    "desc": "Prospek korporat pengadaan fasilitas coffee break lobby & lantai perkantoran eksekutif.",
    "contact": {
      "first": "Kevin",
      "last": "Tanujaya",
      "title": "Property & Tenant Manager",
      "dept": "Building Management",
      "phone": "0811-2244-6688",
      "email": "kevin.tanujaya@intilandtower-sub.com",
      "date": "2026-01-22 09:35:00"
    }
  },
  {
    "key": "blitar_hub",
    "year": 2026,
    "type": "Prospect",
    "ind": "Retail",
    "name": "Blitar Coffee Community Hub",
    "rev": 400000000,
    "emp": 5,
    "phone": "0342-801234",
    "web": "https://blitarcoffeehub.org",
    "street": "Jl. Merdeka No. 70, Kepanjenkidul",
    "city": "Blitar",
    "state": "Jawa Timur",
    "postal": "66117",
    "assigned": "bima",
    "date": "2026-02-28 16:00:00",
    "desc": "Komunitas barista Blitar penjajakan kemitraan micro-roasting and workshop equipment.",
    "contact": {
      "first": "Agus",
      "last": "Santoso",
      "title": "Ketua Komunitas Kopi Blitar",
      "dept": "Community Management",
      "phone": "0813-4455-7799",
      "email": "agus@blitarcoffeehub.org",
      "date": "2026-02-28 16:05:00"
    }
  },
  {
    "key": "royal_plaza",
    "year": 2026,
    "type": "Prospect",
    "ind": "Retail",
    "name": "Royal Plaza Foodcourt Modern Espresso",
    "rev": 800000000,
    "emp": 8,
    "phone": "031-8271122",
    "web": "https://royalplazasurabaya.com",
    "street": "Jl. Ahmad Yani No. 16-18, Wonokromo",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60243",
    "assigned": "bima",
    "date": "2026-04-10 11:00:00",
    "desc": "Prospek pengadaan mesin espresso 2-Group untuk gerai minuman cepat saji di foodcourt Royal Plaza.",
    "contact": {
      "first": "Jessica",
      "last": "Gunawan",
      "title": "Franchise Owner",
      "dept": "F&B Franchise",
      "phone": "0812-7711-3355",
      "email": "jessica.g@royalplazasurabaya.com",
      "date": "2026-04-10 11:05:00"
    }
  },
  {
    "key": "marina_convention",
    "year": 2026,
    "type": "Prospect",
    "ind": "Hospitality",
    "name": "Marina Convention Center Surabaya",
    "rev": 12000000000,
    "emp": 140,
    "phone": "031-5931234",
    "web": "https://marinaconvention.co.id",
    "street": "Jl. Raya Marina Asri No. 1, Sukolilo",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60111",
    "assigned": "citra",
    "date": "2026-05-15 14:30:00",
    "desc": "Pusat konvensi dan pameran tepi pantai Surabaya. Penjajakan tender pengadaan 4 station coffee corner permanen.",
    "contact": {
      "first": "Bramantyo",
      "last": "Utomo",
      "title": "Chief Operating Officer",
      "dept": "Direksi Operasional",
      "phone": "0811-3488-9900",
      "email": "bramantyo@marinaconvention.co.id",
      "date": "2026-05-15 14:35:00"
    }
  },
  {
    "key": "gresik_mall",
    "year": 2026,
    "type": "Prospect",
    "ind": "Retail",
    "name": "Icon Mall Gresik Artisan Bakery",
    "rev": 1100000000,
    "emp": 14,
    "phone": "031-3991234",
    "web": "https://iconmallgresik.com",
    "street": "Jl. Dr. Wahidin Sudirohusodo, Kebomas",
    "city": "Gresik",
    "state": "Jawa Timur",
    "postal": "61124",
    "assigned": "bima",
    "date": "2026-06-20 10:00:00",
    "desc": "Prospek bakery artisan premium pembukaan cabang Icon Mall. Kebutuhan mesin espresso 2-Group compact.",
    "contact": {
      "first": "Fiona",
      "last": "Angelia",
      "title": "Head Baker & Co-Owner",
      "dept": "Bakery Management",
      "phone": "0813-2288-7711",
      "email": "fiona@iconmallgresik.com",
      "date": "2026-06-20 10:05:00"
    }
  },
  {
    "key": "pasuruan_industrial",
    "year": 2026,
    "type": "Prospect",
    "ind": "Other",
    "name": "Pasuruan Industrial Estate PIER Lounge",
    "rev": 28000000000,
    "emp": 380,
    "phone": "0343-741234",
    "web": "https://pier-pasuruan.co.id",
    "street": "Kawasan Industri PIER, Rembang",
    "city": "Pasuruan",
    "state": "Jawa Timur",
    "postal": "67152",
    "assigned": "citra",
    "date": "2026-07-12 13:15:00",
    "desc": "Pengelola kawasan industri PIER. Kebutuhan mesin bean-to-cup ruang pertemuan investor asing dan tamu VIP.",
    "contact": {
      "first": "Gunadi",
      "last": "Prawiro",
      "title": "General Affairs & Investor Relations",
      "dept": "General Affairs",
      "phone": "0812-3399-5511",
      "email": "gunadi@pier-pasuruan.co.id",
      "date": "2026-07-12 13:20:00"
    }
  },
  {
    "key": "malang_creative",
    "year": 2026,
    "type": "Prospect",
    "ind": "Education",
    "name": "Malang Creative Center MCC Kiosk",
    "rev": 750000000,
    "emp": 10,
    "phone": "0341-401234",
    "web": "https://mcc.malangkota.go.id",
    "street": "Jl. Ahmad Yani No. 53, Blimbing",
    "city": "Malang",
    "state": "Jawa Timur",
    "postal": "65126",
    "assigned": "bima",
    "date": "2026-08-18 15:00:00",
    "desc": "Gedung pusat industri kreatif Pemkot Malang. Penjajakan unit kafe kurasi kopi lokal Dampit & Kawi.",
    "contact": {
      "first": "Taufik",
      "last": "Hidayat",
      "title": "Koordinator Inkubator Kuliner",
      "dept": "Bidang Ekonomi Kreatif",
      "phone": "0813-4411-9922",
      "email": "taufik.mcc@malangkota.go.id",
      "date": "2026-08-18 15:05:00"
    }
  },
  {
    "key": "surabaya_east_coast",
    "year": 2026,
    "type": "Prospect",
    "ind": "Retail",
    "name": "East Coast Center Cafe Pakuwon City",
    "rev": 1300000000,
    "emp": 15,
    "phone": "031-5911888",
    "web": "https://pakuwoncity.com",
    "street": "Jl. Kejawan Putih Tambak, Mulyorejo",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60112",
    "assigned": "bima",
    "date": "2026-09-08 11:30:00",
    "desc": "Prospek cafe keluarga di mall East Coast Center Pakuwon City Surabaya Timur.",
    "contact": {
      "first": "Eunike",
      "last": "Subroto",
      "title": "Store Manager",
      "dept": "Cafe Operations",
      "phone": "0812-8800-4433",
      "email": "eunike@eastcoast-sub.com",
      "date": "2026-09-08 11:35:00"
    }
  },
  {
    "key": "surabaya_west_residence",
    "year": 2026,
    "type": "Prospect",
    "ind": "Other",
    "name": "CitraLand Bukit Golf Clubhouse Lounge",
    "rev": 18000000000,
    "emp": 190,
    "phone": "031-7401234",
    "web": "https://citralandsurabaya.com",
    "street": "Jl. Bukit Golf Utama, Sambikerep",
    "city": "Surabaya",
    "state": "Jawa Timur",
    "postal": "60218",
    "assigned": "citra",
    "date": "2026-10-05 14:00:00",
    "desc": "Lounge eksklusif perumahan mewah CitraLand Surabaya Barat. Pengadaan mesin otomatis dan biji kopi specialty harian.",
    "contact": {
      "first": "Dharma",
      "last": "Kurniawan",
      "title": "Estate Club Manager",
      "dept": "Clubhouse Management",
      "phone": "0811-3011-8899",
      "email": "dharma.k@citralandsurabaya.com",
      "date": "2026-10-05 14:05:00"
    }
  }
]
JSON
, true);

$accounts = [];
$contacts = [];

foreach ($accountsData as $def) {
    $accType = $def['type'] === 'Customer' ? $accTypeCustomer : $accTypeProspect;
    $ind = $indMap[$def['ind']] ?? $indMap['Other'];
    $assignedUser = $userMap[$def['assigned']];

    $accBean = find_or_create(
        'Accounts',
        ['name' => $def['name']],
        [
            'name' => $def['name'],
            'account_type' => $accType,
            'industry' => $ind,
            'annual_revenue' => (float)$def['rev'],
            'employees' => (int)$def['emp'],
            'phone_office' => $def['phone'],
            'website' => $def['web'],
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
            'assigned_user_id' => $assignedUser,
            'description' => $def['desc'],
        ],
        $def['date']
    );
    $accounts[$def['key']] = $accBean;

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
            'assigned_user_id' => $assignedUser,
            'description' => "Kontak utama untuk {$def['name']}."
        ],
        $c['date']
    );
    set_bean_email($conBean, $c['email']);
    $conBean->account_id = $accBean->id;
    $conBean->account_name = $accBean->name;
    $conBean->save(false);
    link_beans($accBean, 'contacts', $conBean->id);
    $contacts[$def['key']] = $conBean;
}

// ---------------------------------------------------------------------------
// 4. Leads (18 Leads spanning 2016-2026)
// ---------------------------------------------------------------------------
$leadSrcTradeShow = must_key('lead_source_dom', 'Trade Show');
$leadSrcWebSite = must_key('lead_source_dom', 'Web Site');
$leadSrcCampaign = must_key('lead_source_dom', 'Campaign');
$leadStatNew = must_key('lead_status_dom', 'New');
$leadStatInProcess = must_key('lead_status_dom', 'In Process');
$leadStatRecycled = must_key('lead_status_dom', 'Recycled');
$leadStatDead = must_key('lead_status_dom', 'Dead');
$leadStatConverted = must_key('lead_status_dom', 'Converted');

$leadSrcMap = [
    'Trade Show' => $leadSrcTradeShow,
    'Web Site' => $leadSrcWebSite,
    'Campaign' => $leadSrcCampaign,
];
$leadStatMap = [
    'New' => $leadStatNew,
    'In Process' => $leadStatInProcess,
    'Recycled' => $leadStatRecycled,
    'Dead' => $leadStatDead,
    'Converted' => $leadStatConverted,
];

$leadsData = json_decode(<<<'JSON'
[
  {
    "first": "Taufiq",
    "last": "Ismail",
    "acc": "Surabaya Golf Dining",
    "title": "Club F&B Manager",
    "phone": "0811-2233-4411",
    "email": "taufiq@surabayagolf.id",
    "src": "Trade Show",
    "status": "Converted",
    "camp": "camp_2016",
    "assigned": "citra",
    "desc": "Lead pembukaan booth pameran 2016 dikonversi ke akun member golf.",
    "date": "2016-03-08 14:00:00"
  },
  {
    "first": "Rina",
    "last": "Wulandari",
    "acc": "Kedai Kopi Nostalgia",
    "title": "Owner",
    "phone": "0812-4455-6611",
    "email": "rina@kopinostalgia.id",
    "src": "Trade Show",
    "status": "Dead",
    "camp": "camp_2016",
    "assigned": "bima",
    "desc": "Batal buka kedai karena kendala pendanaan awal 2016.",
    "date": "2016-03-12 11:30:00"
  },
  {
    "first": "Hendra",
    "last": "Wijaya (Lead)",
    "acc": "Senja Roastery & Eatery",
    "title": "Head Roaster",
    "phone": "0813-9876-5432",
    "email": "hendra.lead@senjaroastery.com",
    "src": "Trade Show",
    "status": "Converted",
    "camp": "camp_2017",
    "assigned": "citra",
    "desc": "Lead festival cafe tour 2017 berhasil dikonversi menjadi klien roastery setia.",
    "date": "2017-05-15 15:00:00"
  },
  {
    "first": "Rahmat",
    "last": "Hidayat",
    "acc": "Kopi Rombong Gresik",
    "title": "Pengelola",
    "phone": "0817-3344-5511",
    "email": "rahmat@kopirombong.id",
    "src": "Web Site",
    "status": "Recycled",
    "camp": null,
    "assigned": "bima",
    "desc": "Anggaran belum memenuhi syarat mesin komersial; ditawarkan paket manual brew.",
    "date": "2017-09-20 10:15:00"
  },
  {
    "first": "Ferry",
    "last": "Gunawan (Lead)",
    "acc": "Java Heritage Club",
    "title": "Bar Manager",
    "phone": "0813-2233-4455",
    "email": "ferry.lead@javaheritageclub.id",
    "src": "Trade Show",
    "status": "Converted",
    "camp": "camp_2018",
    "assigned": "citra",
    "desc": "Lead pameran hotel 2018, pengadaan mesin custom brass.",
    "date": "2018-08-18 16:30:00"
  },
  {
    "first": "Denny",
    "last": "Cahyadi",
    "acc": "Surabaya Bistro Concept",
    "title": "Investor",
    "phone": "0812-5566-7711",
    "email": "denny@surabayabistro.id",
    "src": "Trade Show",
    "status": "Dead",
    "camp": "camp_2018",
    "assigned": "bima",
    "desc": "Rencana pembangunan bistro dialihkan ke Jakarta.",
    "date": "2018-09-05 13:00:00"
  },
  {
    "first": "Reza",
    "last": "Pahlawan (Lead)",
    "acc": "Kedai Kopi Jalanan Malang",
    "title": "Founder",
    "phone": "0812-7766-5533",
    "email": "reza.lead@kopijalananmalang.id",
    "src": "Campaign",
    "status": "Converted",
    "camp": "camp_2019",
    "assigned": "bima",
    "desc": "Lead festival kopi 2019 Malang dikonversi ke paket mesin Appia 2G.",
    "date": "2019-09-12 11:00:00"
  },
  {
    "first": "Farhan",
    "last": "Akbar",
    "acc": "Warung Kopi Milenial Jember",
    "title": "Owner",
    "phone": "0818-7788-9911",
    "email": "farhan@warkopmilenial.id",
    "src": "Campaign",
    "status": "Recycled",
    "camp": "camp_2019",
    "assigned": "bima",
    "desc": "Tunda pengadaan mesin sampai renovasi ruko selesai.",
    "date": "2019-10-10 14:20:00"
  },
  {
    "first": "dr. Ratna",
    "last": "Sari (Lead)",
    "acc": "RS Mitra Sejahtera Cafeteria",
    "title": "Fasilitas Gizi RS",
    "phone": "0812-8899-0011",
    "email": "ratna.lead@rsmitra.id",
    "src": "Web Site",
    "status": "Converted",
    "camp": "camp_2020",
    "assigned": "bima",
    "desc": "Lead webinar digital 2020 dikonversi pengadaan mesin higienis RS.",
    "date": "2020-05-18 10:00:00"
  },
  {
    "first": "Samsul",
    "last": "Bahri",
    "acc": "Kedai Kopi Tenda Darurat",
    "title": "Owner",
    "phone": "0813-1144-7722",
    "email": "samsul@kopitenda.id",
    "src": "Campaign",
    "status": "Dead",
    "camp": "camp_2020",
    "assigned": "bima",
    "desc": "Usaha tutup terdampak pembatasan sosial pandemi 2020.",
    "date": "2020-08-15 15:45:00"
  },
  {
    "first": "Maya",
    "last": "Anggraini",
    "acc": "Java Heritage Lounge Surabaya",
    "title": "F&B Consultant",
    "phone": "0811-6677-8899",
    "email": "maya@javaheritage.com",
    "src": "Trade Show",
    "status": "Converted",
    "camp": "camp_2022",
    "assigned": "citra",
    "desc": "Lead historis pameran 2022, berhasil dikonversi menjadi klien tetap.",
    "date": "2022-08-10 15:00:00"
  },
  {
    "first": "Dimas",
    "last": "Kurnia",
    "acc": "Kedai Retro Malang",
    "title": "Pengelola",
    "phone": "0812-3322-1155",
    "email": "dimas@kedairetro.id",
    "src": "Web Site",
    "status": "In Process",
    "camp": null,
    "assigned": "bima",
    "desc": "Diskusi paket sewa mesin espresso untuk kedai bernuansa jadul.",
    "date": "2022-10-15 11:00:00"
  },
  {
    "first": "Tommy",
    "last": "Salim",
    "acc": "East Coast Roasters Surabaya",
    "title": "Managing Director",
    "phone": "0812-5544-3322",
    "email": "tommy@eastcoastroasters.id",
    "src": "Campaign",
    "status": "Converted",
    "camp": "camp_2023",
    "assigned": "citra",
    "desc": "Lead championship barista 2023 dikonversi kontrak green beans tahunan.",
    "date": "2023-06-20 13:30:00"
  },
  {
    "first": "Lukman",
    "last": "Hakim",
    "acc": "Boutique Cafe Pasuruan",
    "title": "Owner",
    "phone": "0817-6655-4422",
    "email": "lukman@boutiquecafe.id",
    "src": "Campaign",
    "status": "Dead",
    "camp": "camp_2023",
    "assigned": "bima",
    "desc": "Lokasi usaha dialihkan untuk toko retail non-F&B.",
    "date": "2023-08-14 16:15:00"
  },
  {
    "first": "Made",
    "last": "Suryawan (Lead)",
    "acc": "Aroma Kopi Luwak Bali",
    "title": "Head Barista",
    "phone": "0819-3322-1100",
    "email": "made.lead@aromaluwakbali.com",
    "src": "Campaign",
    "status": "Converted",
    "camp": "camp_2024",
    "assigned": "citra",
    "desc": "Lead festival kopi 2024 dikonversi pasokan kopi specialty Jawa Timur ke Bali.",
    "date": "2024-05-18 14:00:00"
  },
  {
    "first": "Arif",
    "last": "Rahman",
    "acc": "Warkop Milenial Surabaya",
    "title": "Pengelola",
    "phone": "0818-4455-6677",
    "email": "arif@warkopmilenial.id",
    "src": "Web Site",
    "status": "Recycled",
    "camp": null,
    "assigned": "bima",
    "desc": "Budget belum mencukupi untuk mesin espresso komersial; ditawarkan paket drip.",
    "date": "2024-11-04 16:15:00"
  },
  {
    "first": "Wahyu",
    "last": "Pratama (Lead)",
    "acc": "Kopi Kontainer Sidoarjo",
    "title": "Pemilik Kedai",
    "phone": "0812-9988-7766",
    "email": "wahyu.lead@kopikontainer.id",
    "src": "Trade Show",
    "status": "In Process",
    "camp": "camp_2026",
    "assigned": "bima",
    "desc": "Tertarik paket bundling mesin espresso 1-group dan biji kopi blend 20 kg/bulan saat berkunjung ke booth pameran 2026.",
    "date": "2026-02-12 14:00:00"
  },
  {
    "first": "Diana",
    "last": "Savitri",
    "acc": "Puspa Bakery & Cafe Gresik",
    "title": "Owner",
    "phone": "0813-1122-3344",
    "email": "diana@puspabakery.com",
    "src": "Trade Show",
    "status": "New",
    "camp": "camp_2026",
    "assigned": "bima",
    "desc": "Mencari mesin kopi otomatis untuk sudut cafe di toko rotinya di Gresik hasil pameran F&B Expo 2026.",
    "date": "2026-02-13 11:30:00"
  }
]
JSON
, true);

$leads = [];
foreach ($leadsData as $ld) {
    $campId = (!empty($ld['camp']) && isset($campaigns[$ld['camp']])) ? $campaigns[$ld['camp']]->id : null;
    $leadBean = find_or_create(
        'Leads',
        ['first_name' => $ld['first'], 'last_name' => $ld['last']],
        [
            'first_name' => $ld['first'],
            'last_name' => $ld['last'],
            'account_name' => $ld['acc'],
            'title' => $ld['title'],
            'phone_work' => $ld['phone'],
            'phone_mobile' => $ld['phone'],
            'lead_source' => $leadSrcMap[$ld['src']],
            'status' => $leadStatMap[$ld['status']],
            'campaign_id' => $campId,
            'assigned_user_id' => $userMap[$ld['assigned']],
            'description' => $ld['desc'],
        ],
        $ld['date']
    );
    set_bean_email($leadBean, $ld['email']);
    $leads[] = $leadBean;
}

// ---------------------------------------------------------------------------
// 5. Opportunities (54 Opportunities spanning 10-Year Lifecycle: 2016-2026)
// Names kept strictly <= 45 chars to respect varchar(50) limit!
// ---------------------------------------------------------------------------
$stageProspecting = must_key('sales_stage_dom', 'Prospecting');
$stageQualification = must_key('sales_stage_dom', 'Qualification');
$stageQuote = must_key('sales_stage_dom', 'Proposal/Price Quote');
$stageReview = must_key('sales_stage_dom', 'Negotiation/Review');
$stageWon = must_key('sales_stage_dom', 'Closed Won');
$stageLost = must_key('sales_stage_dom', 'Closed Lost');

$stageMap = [
    'Prospecting' => $stageProspecting,
    'Qualification' => $stageQualification,
    'Proposal/Price Quote' => $stageQuote,
    'Negotiation/Review' => $stageReview,
    'Closed Won' => $stageWon,
    'Closed Lost' => $stageLost,
];

$oppsData = json_decode(<<<'JSON'
[
  {
    "name": "[2016] Mesin Aurelia Grand Majapahit",
    "acc": "majapahit",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 125000000.0,
    "close": "2016-05-20",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2016-04-15 10:00:00",
    "desc": "Pengadaan unit pertama Aurelia Wave 2G resto utama Grand Majapahit Hotel."
  },
  {
    "name": "[2016] Paket Mesin Kopi Titik Temu",
    "acc": "titik_temu",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 65000000.0,
    "close": "2016-10-15",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "citra",
    "date": "2016-09-10 11:00:00",
    "desc": "Paket opening kedai perdana Titik Temu Jl. Raya Darmo."
  },
  {
    "name": "[2016] Mesin 2G Surabaya Golf Lounge",
    "acc": "surabaya_club",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 85000000.0,
    "close": "2016-12-10",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2016-11-25 14:00:00",
    "desc": "Pengadaan mesin espresso 2-Group VIP dining club house golf."
  },
  {
    "name": "[2017] Mesin Appia Senja Roastery",
    "acc": "senja",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 55000000.0,
    "close": "2017-03-25",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2017-02-20 14:00:00",
    "desc": "Pengadaan mesin espresso Appia Life 2G untuk roastery bar Senja."
  },
  {
    "name": "[2017] Paket Drip Taman Rawon Cafe",
    "acc": "rawon_kalkun",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 38000000.0,
    "close": "2017-07-30",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2017-06-25 11:00:00",
    "desc": "Pengadaan mesin espresso compact dan pasokan kopi blend resto."
  },
  {
    "name": "[2017] Mesin Resto Batu Highland",
    "acc": "batu_highland",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 95000000.0,
    "close": "2017-11-15",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2017-10-15 09:30:00",
    "desc": "Pengadaan 2 unit mesin espresso komersial resort Batu."
  },
  {
    "name": "[2017] Tender Mesin Kafe Stasiun",
    "acc": "senja",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 60000000.0,
    "close": "2017-09-10",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "bima",
    "date": "2017-08-01 10:15:00",
    "desc": "Kalah tender gerai stasiun karena keterbatasan slot listrik lokasi."
  },
  {
    "name": "[2018] Kontrak Kopi Majapahit 2018",
    "acc": "majapahit",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 72000000.0,
    "close": "2018-04-10",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2018-03-01 10:00:00",
    "desc": "Perpanjangan kontrak suplai biji kopi blend 120 kg/bulan."
  },
  {
    "name": "[2018] Mesin Otomatis Petrokimia VIP",
    "acc": "petrokimia",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 50000000.0,
    "close": "2018-05-18",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2018-04-01 13:45:00",
    "desc": "Pengadaan mesin bean-to-cup executive dining PT Petrokimia."
  },
  {
    "name": "[2018] Coffee Corner Diklat Semen",
    "acc": "semen_indonesia",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 45000000.0,
    "close": "2018-08-25",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2018-07-28 09:30:00",
    "desc": "Penyediaan 2 unit mesin kopi otomatis aula diklat Semen Gresik."
  },
  {
    "name": "[2018] Mesin 3G Java Heritage Club",
    "acc": "java_heritage",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 130000000.0,
    "close": "2018-12-05",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2018-11-10 15:00:00",
    "desc": "Mesin custom brass finish 3-group lounge private Surabaya."
  },
  {
    "name": "[2018] Mesin Resto Hotel Cabang 2",
    "acc": "majapahit",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 80000000.0,
    "close": "2018-10-20",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2018-09-15 11:30:00",
    "desc": "Pihak hotel memilih sewa mesin dari vendor Jakarta."
  },
  {
    "name": "[2019] Mesin Kopi Jalanan Malang",
    "acc": "kopi_jalanan",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 58000000.0,
    "close": "2019-03-30",
    "src": "Campaign",
    "type": "New Business",
    "assigned": "bima",
    "date": "2019-02-25 11:00:00",
    "desc": "Pengadaan mesin espresso 2-Group Appia Life kedai jalan Suhat."
  },
  {
    "name": "[2019] Cupping Lab Suhat Roasters",
    "acc": "suhat_roaster",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 42000000.0,
    "close": "2019-06-15",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2019-05-18 14:30:00",
    "desc": "Peralatan laboratorium cupping & sample roaster kopi specialty."
  },
  {
    "name": "[2019] Mesin Resort Ijen Banyuwangi",
    "acc": "banyuwangi_resort",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 88000000.0,
    "close": "2019-12-18",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2019-11-20 16:00:00",
    "desc": "Mesin komersial 2-Group resto resort pemandangan Kawah Ijen."
  },
  {
    "name": "[2019] Kiosk Kopi Brawijaya Kampus",
    "acc": "brawijaya_hub",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 35000000.0,
    "close": "2019-09-15",
    "src": "Campaign",
    "type": "New Business",
    "assigned": "bima",
    "date": "2019-08-25 10:15:00",
    "desc": "Pengadaan dibatalkan pihak kampus karena pergantian rektorat."
  },
  {
    "name": "[2019] Tender Mesin Resto Bandara",
    "acc": "titik_temu",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 95000000.0,
    "close": "2019-11-05",
    "src": "Web Site",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2019-10-01 13:00:00",
    "desc": "Kalah tender gerai bandara Juanda T2."
  },
  {
    "name": "[2020] Mesin Otomatis RS Mitra Sda",
    "acc": "rs_mitra",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 48000000.0,
    "close": "2020-05-10",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2020-04-15 09:00:00",
    "desc": "Penyediaan mesin kopi bean-to-cup higienis dokter lounge."
  },
  {
    "name": "[2020] Mesin Drive-Thru Merdeka",
    "acc": "kopi_darurat",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 75000000.0,
    "close": "2020-12-15",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2020-11-22 15:30:00",
    "desc": "Mesin high speed 3-group kedai kopi drive-thru MERR."
  },
  {
    "name": "[2020] Paket Mesin Kafe Hotel Batu",
    "acc": "batu_highland",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 70000000.0,
    "close": "2020-06-20",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2020-05-01 10:00:00",
    "desc": "Pembekuan anggaran renovasi kafe akibat sepinya okupansi pandemi."
  },
  {
    "name": "[2020] Mesin Kopi Rektorat UB",
    "acc": "brawijaya_hub",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 40000000.0,
    "close": "2020-10-15",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2020-09-01 11:30:00",
    "desc": "Kegiatan kampus beralih daring penuh (WFH)."
  },
  {
    "name": "[2021] Mesin Aurelia Wave Majapahit",
    "acc": "majapahit",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 135000000.0,
    "close": "2021-07-15",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2021-06-01 10:00:00",
    "desc": "Upgrade unit Aurelia Wave 2-Group main restaurant Grand Majapahit."
  },
  {
    "name": "[2021] Paket Mesin Titik Temu Cbg 2",
    "acc": "titik_temu",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 85000000.0,
    "close": "2021-11-20",
    "src": "Web Site",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2021-10-15 11:00:00",
    "desc": "Paket mesin opening cabang kedua Titik Temu Surabaya Barat."
  },
  {
    "name": "[2021] Mesin Appia Kopi Sudut Malang",
    "acc": "sudut_kota",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 62000000.0,
    "close": "2021-04-18",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "bima",
    "date": "2021-03-15 15:20:00",
    "desc": "Mesin 2-Group Appia Life kedai kopi favorit mahasiswa Ijen Malang."
  },
  {
    "name": "[2021] Mesin Banquet Bumi Surabaya",
    "acc": "bumi_surabaya",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 115000000.0,
    "close": "2021-08-28",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2021-07-01 10:00:00",
    "desc": "Pengadaan mesin espresso banquet hall perjamuan bintang 5."
  },
  {
    "name": "[2021] Kios Kopi Kampus B Unair",
    "acc": "unair_campus",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 35000000.0,
    "close": "2021-10-14",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2021-09-15 13:30:00",
    "desc": "Batal pengadaan karena perizinan tempat kantin universitas."
  },
  {
    "name": "[2022] Mesin Appia Senja Batch 2",
    "acc": "senja",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 68000000.0,
    "close": "2022-03-10",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2022-02-18 14:00:00",
    "desc": "Penambahan unit mesin espresso roastery bar Senja Gubeng."
  },
  {
    "name": "[2022] Pengadaan Mesin Batu Highland",
    "acc": "batu_highland",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 110000000.0,
    "close": "2022-08-25",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2022-07-15 09:30:00",
    "desc": "Pengadaan 2 unit mesin komersial resto & convention Batu."
  },
  {
    "name": "[2022] Mesin Sky Bar Bromo Terrace",
    "acc": "bromo_terrace",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 78000000.0,
    "close": "2022-10-18",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2022-09-01 10:30:00",
    "desc": "Mesin espresso sky bar pemandangan Gunung Bromo Probolinggo."
  },
  {
    "name": "[2022] Mesin Resto Cabang Malang",
    "acc": "majapahit",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 75000000.0,
    "close": "2022-10-14",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2022-09-01 10:15:00",
    "desc": "Kalah tender hotel memilih sewa dari vendor luar kota."
  },
  {
    "name": "[2022] Mesin Barista Lab Jember",
    "acc": "jember_specialty",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 45000000.0,
    "close": "2022-06-30",
    "src": "Campaign",
    "type": "New Business",
    "assigned": "bima",
    "date": "2022-05-20 14:00:00",
    "desc": "Penundaan pendanaan hibah penelitian kopi regional Jember."
  },
  {
    "name": "[2023] Mesin Cabang 3 Titik Temu",
    "acc": "titik_temu",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 92000000.0,
    "close": "2023-04-18",
    "src": "Web Site",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2023-03-01 11:45:00",
    "desc": "Pembukaan cabang ke-3 Kopi Titik Temu di Sidoarjo."
  },
  {
    "name": "[2023] Mesin 3G Heritage Lounge",
    "acc": "heritage_club",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 145000000.0,
    "close": "2023-09-05",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2023-08-15 15:00:00",
    "desc": "Mesin custom brass finish private lounge Surabaya Barat."
  },
  {
    "name": "[2023] Kontrak Biji Petrokimia 2023",
    "acc": "petrokimia",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 48000000.0,
    "close": "2023-12-10",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2023-11-20 10:30:00",
    "desc": "Kontrak tahunan supply biji kopi blend lounge VIP PT Petrokimia."
  },
  {
    "name": "[2023] Mesin Ciputra Golf Club Resto",
    "acc": "ciputra_golf",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 120000000.0,
    "close": "2023-08-30",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2023-07-25 11:30:00",
    "desc": "Mesin espresso premier turnamen golf dan banquet."
  },
  {
    "name": "[2023] Kiosk Kopi Mulyosari Unair",
    "acc": "aksara",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 40000000.0,
    "close": "2023-03-15",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2023-02-10 10:30:00",
    "desc": "Keterbatasan daya listrik lokasi kiosk mitra kampus."
  },
  {
    "name": "[2024] Paket Mesin Kafe Aksara",
    "acc": "aksara",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 55000000.0,
    "close": "2024-02-28",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2024-01-25 14:00:00",
    "desc": "Pengadaan mesin espresso 1-Group co-working space MERR."
  },
  {
    "name": "[2024] Mesin Kopi RS Mitra Sejahtera",
    "acc": "rs_mitra",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 60000000.0,
    "close": "2024-06-15",
    "src": "Campaign",
    "type": "Existing Business",
    "assigned": "bima",
    "date": "2024-05-22 09:15:00",
    "desc": "Dua unit mesin bean-to-cup dokter lounge & cafeteria RS."
  },
  {
    "name": "[2024] Suplai Specialty Bali Bar",
    "acc": "aroma_bali",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 85000000.0,
    "close": "2024-10-10",
    "src": "Campaign",
    "type": "New Business",
    "assigned": "citra",
    "date": "2024-09-01 16:00:00",
    "desc": "Kontrak pasokan specialty Ijen Anaerob ke Seminyak Bali."
  },
  {
    "name": "[2024] Tender Espresso Stasiun",
    "acc": "aksara",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 90000000.0,
    "close": "2024-11-12",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2024-10-01 13:00:00",
    "desc": "Gagal menang tender gerai kopi stasiun ruang dialihkan."
  },
  {
    "name": "[2024] Mesin Kopi Cafe Situbondo",
    "acc": "pasir_putih",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 52000000.0,
    "close": "2024-10-25",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2024-09-20 11:30:00",
    "desc": "Batal karena pengelola memilih mesin refurbished pihak ketiga."
  },
  {
    "name": "[2025] Mesin Aurelia Majapahit Lnt 5",
    "acc": "majapahit",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 140000000.0,
    "close": "2025-05-20",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2025-04-10 11:30:00",
    "desc": "Penambahan unit ke-3 Aurelia Wave executive lounge lantai 5."
  },
  {
    "name": "[2025] Paket Mesin D Java Bistro",
    "acc": "djava_bistro",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 52000000.0,
    "close": "2025-09-10",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2025-08-01 10:00:00",
    "desc": "Ekspansi regional ke Madiun - paket mesin compact dan supply kopi."
  },
  {
    "name": "[2025] Mesin Bronze Tugu Hotel",
    "acc": "tugu_hotel",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 135000000.0,
    "close": "2025-08-15",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2025-07-20 09:45:00",
    "desc": "Mesin custom bronze finishing hotel butik mewah Malang."
  },
  {
    "name": "[2025] Mesin Digital Pakuwon Tower",
    "acc": "pakuwon_tower",
    "stage": "Closed Won",
    "prob": 100,
    "amt": 110000000.0,
    "close": "2025-11-20",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2025-10-05 16:00:00",
    "desc": "2 unit mesin bean-to-cup touch display digital lantai 30."
  },
  {
    "name": "[2025] Mesin Kiosk Kopi Kampus C",
    "acc": "cakrawala_univ",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 38000000.0,
    "close": "2025-06-10",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2025-05-15 15:30:00",
    "desc": "Pengadaan unit ke-2 dibatalkan pemotongan anggaran fakultas."
  },
  {
    "name": "[2026] Grinder On-Demand Kopi Sudut",
    "acc": "sudut_kota",
    "stage": "Proposal/Price Quote",
    "prob": 65,
    "amt": 28500000.0,
    "close": "2026-11-30",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "bima",
    "date": "2026-10-15 14:00:00",
    "desc": "Penawaran upsell 2 unit grinder Eureka Olympus single origin bar."
  },
  {
    "name": "[2026] Kontrak Biji Espresso Titik",
    "acc": "titik_temu",
    "stage": "Negotiation/Review",
    "prob": 80,
    "amt": 65000000.0,
    "close": "2026-12-15",
    "src": "Web Site",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2026-10-20 11:00:00",
    "desc": "Final review addendum perpanjangan kontrak pasokan tahunan 2027."
  },
  {
    "name": "[NEW BIZ] Paket Mesin Kontainer",
    "acc": "kopi_kontainer",
    "stage": "Prospecting",
    "prob": 10,
    "amt": 45000000.0,
    "close": "2026-12-31",
    "src": "Campaign",
    "type": "New Business",
    "assigned": "bima",
    "date": "2026-02-18 09:00:00",
    "desc": "Paket pemula kedai kontainer: mesin 1-Group grinder dan kopi."
  },
  {
    "name": "[RETENTION] Kontrak Grand Majapahit",
    "acc": "majapahit",
    "stage": "Qualification",
    "prob": 20,
    "amt": 72000000.0,
    "close": "2026-11-28",
    "src": "Trade Show",
    "type": "Existing Business",
    "assigned": "citra",
    "date": "2026-11-01 10:00:00",
    "desc": "Negosiasi pembaruan kontrak pasokan biji kopi blend dan PMR 2027."
  },
  {
    "name": "[2026] Mesin Pantry Intiland Tower",
    "acc": "intiland_tower",
    "stage": "Qualification",
    "prob": 20,
    "amt": 58000000.0,
    "close": "2026-12-20",
    "src": "Trade Show",
    "type": "New Business",
    "assigned": "citra",
    "date": "2026-01-25 09:30:00",
    "desc": "Pengadaan fasilitas coffee break lobby kantor eksekutif Intiland."
  },
  {
    "name": "[2026] Micro-Roasting Blitar Hub",
    "acc": "blitar_hub",
    "stage": "Prospecting",
    "prob": 10,
    "amt": 35000000.0,
    "close": "2026-12-15",
    "src": "Campaign",
    "type": "New Business",
    "assigned": "bima",
    "date": "2026-03-05 16:00:00",
    "desc": "Kemitraan peralatan roastery dan pasokan green bean komunitas Blitar."
  },
  {
    "name": "[2026] Mesin 2G Royal Plaza Kios",
    "acc": "royal_plaza",
    "stage": "Proposal/Price Quote",
    "prob": 65,
    "amt": 52000000.0,
    "close": "2026-12-28",
    "src": "Web Site",
    "type": "New Business",
    "assigned": "bima",
    "date": "2026-04-15 11:00:00",
    "desc": "Proposal pengadaan mesin Appia Life compact franchise foodcourt."
  },
  {
    "name": "[LOST] Pengadaan Kopi Kampus C",
    "acc": "cakrawala_univ",
    "stage": "Closed Lost",
    "prob": 0,
    "amt": 38000000.0,
    "close": "2026-06-10",
    "src": "Web Site",
    "type": "Existing Business",
    "assigned": "bima",
    "date": "2026-05-15 15:30:00",
    "desc": "Pengadaan unit ke-2 dibatalkan karena pemotongan anggaran fakultas."
  }
]
JSON
, true);

$opportunities = [];
foreach ($oppsData as $od) {
    $oppBean = find_or_create(
        'Opportunities',
        ['name' => $od['name']],
        [
            'name' => $od['name'],
            'opportunity_type' => $od['type'],
            'lead_source' => $leadSrcMap[$od['src']],
            'currency_id' => $idrCurrencyId,
            'amount' => (float)$od['amt'],
            'amount_usdollar' => (float)$od['amt'],
            'date_closed' => utc_db_date($od['close']),
            'sales_stage' => $stageMap[$od['stage']],
            'probability' => (int)$od['prob'],
            'assigned_user_id' => $userMap[$od['assigned']],
            'description' => $od['desc'],
        ],
        $od['date']
    );

    if (isset($accounts[$od['acc']])) {
        $acc = $accounts[$od['acc']];
        link_beans($acc, 'opportunities', $oppBean->id);
    }
    if (isset($contacts[$od['acc']])) {
        link_beans($contacts[$od['acc']], 'opportunities', $oppBean->id);
    }
    $opportunities[$od['name']] = $oppBean;
}

// ---------------------------------------------------------------------------
// 6. Calls (55 Calls: 1 Overdue Planned admin + 8 Planned Dashboard + 46 Held)
// ---------------------------------------------------------------------------
$callStatusPlanned = must_key('call_status_dom', 'Planned');
$callStatusHeld = must_key('call_status_dom', 'Held');
$callDirOutbound = must_key('call_direction_dom', 'Outbound');
$callDirInbound = must_key('call_direction_dom', 'Inbound');

$callsData = json_decode(<<<'JSON'
[
  {
    "name": "[OVERDUE] Konfirmasi Demo Mesin Kopi Kontainer",
    "acc": "kopi_kontainer",
    "status": "Planned",
    "dir": "Outbound",
    "start": "-3 days 09:00:00",
    "end": "-3 days 09:30:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "1",
    "desc": "Telepon konfirmasi jadwal uji coba mesin espresso di showroom Surabaya dengan Bapak Wahyu.",
    "date": "-4 days 10:00:00"
  },
  {
    "name": "[PLANNED] Finalisasi Kontrak Biji Grand Majapahit",
    "acc": "majapahit",
    "status": "Planned",
    "dir": "Outbound",
    "start": "+2 days 10:00:00",
    "end": "+2 days 10:30:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "citra",
    "desc": "Follow up perpanjangan kontrak pasokan kopi 2027 dengan Antonius Wibowo.",
    "date": "2026-11-10 09:00:00"
  },
  {
    "name": "[PLANNED] Demo Mesin Espresso Kopi Kontainer",
    "acc": "kopi_kontainer",
    "status": "Planned",
    "dir": "Outbound",
    "start": "+3 days 14:00:00",
    "end": "+3 days 14:30:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "bima",
    "desc": "Jadwal demo langsung mesin 1-Group di showroom PT Sumber Kopi Nusantara.",
    "date": "2026-11-10 10:30:00"
  },
  {
    "name": "[PLANNED] Konfirmasi Spesifikasi Pantry Intiland",
    "acc": "intiland_tower",
    "status": "Planned",
    "dir": "Inbound",
    "start": "+4 days 11:00:00",
    "end": "+4 days 11:30:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "citra",
    "desc": "Menerima telepon dari Kevin Tanujaya mengenai kapasitas listrik lantai 18.",
    "date": "2026-11-10 11:00:00"
  },
  {
    "name": "[PLANNED] Review Penawaran Grinder Kopi Sudut",
    "acc": "sudut_kota",
    "status": "Planned",
    "dir": "Outbound",
    "start": "+5 days 15:00:00",
    "end": "+5 days 15:30:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "bima",
    "desc": "Follow up diskon volume 2 unit grinder on-demand Eureka Olympus.",
    "date": "2026-11-10 13:00:00"
  },
  {
    "name": "[PLANNED] Diskusi Addendum Kopi Titik Temu 2027",
    "acc": "titik_temu",
    "status": "Planned",
    "dir": "Outbound",
    "start": "+6 days 13:30:00",
    "end": "+6 days 14:00:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "citra",
    "desc": "Konfirmasi klausul SLA maintenance dan jadwal delivery mingguan.",
    "date": "2026-11-10 14:00:00"
  },
  {
    "name": "[PLANNED] Uji Seduh Sample Kopi Komunitas Blitar",
    "acc": "blitar_hub",
    "status": "Planned",
    "dir": "Outbound",
    "start": "+7 days 10:30:00",
    "end": "+7 days 11:00:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "bima",
    "desc": "Telepon koordinasi pengiriman 5 kg sampel green beans specialty ke Blitar.",
    "date": "2026-11-10 15:00:00"
  },
  {
    "name": "[PLANNED] Evaluasi Teknis Kios Royal Plaza",
    "acc": "royal_plaza",
    "status": "Planned",
    "dir": "Outbound",
    "start": "+8 days 16:00:00",
    "end": "+8 days 16:30:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "bima",
    "desc": "Pengecekan jalur air bersih dan saluran pembuangan foodcourt Royal Plaza.",
    "date": "2026-11-10 16:00:00"
  },
  {
    "name": "[PLANNED] Briefing Kunjungan Direksi Marina Convention",
    "acc": "marina_convention",
    "status": "Planned",
    "dir": "Outbound",
    "start": "+10 days 09:00:00",
    "end": "+10 days 09:30:00",
    "dur_h": 0,
    "dur_m": 30,
    "assigned": "1",
    "desc": "Persiapan meeting tender 4 coffee corner permanen Marina Convention Center.",
    "date": "2026-11-10 16:30:00"
  },
  {
    "name": "[2016] Kickoff Delivery Mesin Grand Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "dir": "Outbound",
    "start": "2016-05-22 10:00:00",
    "assigned": "citra",
    "desc": "Konfirmasi unboxing dan instalasi mesin Aurelia Wave pertama di Grand Majapahit Hotel."
  },
  {
    "name": "[2016] Uji Kalibrasi Air Grand Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "dir": "Outbound",
    "start": "2016-06-15 14:00:00",
    "assigned": "citra",
    "desc": "Pengecekan TDS air filter setelah 1 bulan pengoperasian."
  },
  {
    "name": "[2016] Koordinasi Pembukaan Kopi Titik Temu",
    "acc": "titik_temu",
    "status": "Held",
    "dir": "Outbound",
    "start": "2016-10-18 11:30:00",
    "assigned": "citra",
    "desc": "Briefing instalasi mesin komersial sebelum grand opening kedai Darmo."
  },
  {
    "name": "[2016] Konsultasi Menu Kopi Surabaya Golf",
    "acc": "surabaya_club",
    "status": "Held",
    "dir": "Outbound",
    "start": "2016-12-15 15:00:00",
    "assigned": "citra",
    "desc": "Penyesuaian resep espresso blend untuk member clubhouse golf."
  },
  {
    "name": "[2017] Diskusi Profil Roasting Senja Roastery",
    "acc": "senja",
    "status": "Held",
    "dir": "Outbound",
    "start": "2017-03-28 14:00:00",
    "assigned": "citra",
    "desc": "Evaluasi profil sangrai Ijen Honey dengan Hendra Wijaya."
  },
  {
    "name": "[2017] Resep Kopi Susu Tradisional Taman Rawon",
    "acc": "rawon_kalkun",
    "status": "Held",
    "dir": "Outbound",
    "start": "2017-08-05 10:30:00",
    "assigned": "bima",
    "desc": "Pengaturan rasio ekstraksi kopi robusta Dampit untuk es kopi susu gula aren."
  },
  {
    "name": "[2017] Koordinasi Filter Air Batu Highland",
    "acc": "batu_highland",
    "status": "Held",
    "dir": "Outbound",
    "start": "2017-11-20 11:00:00",
    "assigned": "citra",
    "desc": "Jadwal teknisi pengecekan water softener air pegunungan Batu."
  },
  {
    "name": "[2017] Review Tahunan Pasokan Kopi Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "dir": "Outbound",
    "start": "2017-12-10 15:30:00",
    "assigned": "citra",
    "desc": "Evaluasi kepuasan tamu terhadap espresso blend hotel tahun kedua."
  },
  {
    "name": "[2018] Konfirmasi Kontrak Lounge Petrokimia",
    "acc": "petrokimia",
    "status": "Held",
    "dir": "Outbound",
    "start": "2018-05-22 13:30:00",
    "assigned": "citra",
    "desc": "Finalisasi PO bulanan biji espresso blend ruang rapat direksi Gresik."
  },
  {
    "name": "[2018] Pelatihan Staf Diklat Semen Indonesia",
    "acc": "semen_indonesia",
    "status": "Held",
    "dir": "Outbound",
    "start": "2018-08-30 09:30:00",
    "assigned": "bima",
    "desc": "Jadwal training cleaning rutin mesin bean-to-cup peserta pelatihan BUMN."
  },
  {
    "name": "[2018] Spesifikasi Mesin Custom Java Heritage",
    "acc": "java_heritage",
    "status": "Held",
    "dir": "Outbound",
    "start": "2018-11-15 16:00:00",
    "assigned": "citra",
    "desc": "Konfirmasi finishing bodi kuningan mesin espresso 3-Group."
  },
  {
    "name": "[2018] Perawatan Berkala Mesin Titik Temu",
    "acc": "titik_temu",
    "status": "Held",
    "dir": "Inbound",
    "start": "2018-06-12 11:00:00",
    "assigned": "citra",
    "desc": "Laporan penggantian karet gasket grouphead dan shower screen IMS."
  },
  {
    "name": "[2018] Pasokan Kopi Libur Akhir Tahun Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "dir": "Outbound",
    "start": "2018-12-18 10:00:00",
    "assigned": "citra",
    "desc": "Antisipasi lonjakan okupansi tahun baru dengan pengiriman extra 50 kg."
  },
  {
    "name": "[2019] Onboarding Mesin Kopi Jalanan Malang",
    "acc": "kopi_jalanan",
    "status": "Held",
    "dir": "Outbound",
    "start": "2019-04-05 14:00:00",
    "assigned": "bima",
    "desc": "Briefing operasional mesin komersial kedai mahasiswa jalan Suhat."
  },
  {
    "name": "[2019] Kalibrasi Suhu Mesin Suhat Roasters",
    "acc": "suhat_roaster",
    "status": "Held",
    "dir": "Outbound",
    "start": "2019-06-20 15:30:00",
    "assigned": "citra",
    "desc": "Setting PID boiler stabil untuk uji seduh standar Specialty Coffee Association."
  },
  {
    "name": "[2019] Ekspedisi Mesin ke Banyuwangi",
    "acc": "banyuwangi_resort",
    "status": "Held",
    "dir": "Outbound",
    "start": "2019-12-22 11:00:00",
    "assigned": "citra",
    "desc": "Pelacakan resi kargo darat pengiriman unit ke Ijen View Resort."
  },
  {
    "name": "[2019] Servis Preventif Tahunan Senja Roastery",
    "acc": "senja",
    "status": "Held",
    "dir": "Outbound",
    "start": "2019-03-15 10:00:00",
    "assigned": "citra",
    "desc": "Descaling boiler dan overhaul pressure pump 9 bar."
  },
  {
    "name": "[2019] Review Stok Kopi Rawon Taman Surabaya",
    "acc": "rawon_kalkun",
    "status": "Held",
    "dir": "Outbound",
    "start": "2019-09-08 14:30:00",
    "assigned": "bima",
    "desc": "Peningkatan kuota biji kopi blend 40 kg per bulan."
  },
  {
    "name": "[2020] Koordinasi Mesin Dokter Lounge RS Mitra",
    "acc": "rs_mitra",
    "status": "Held",
    "dir": "Outbound",
    "start": "2020-05-15 11:20:00",
    "assigned": "bima",
    "desc": "Pelatihan staf gizi pengoperasian mesin otomatis higienis touchless."
  },
  {
    "name": "[2020] Pengadaan Mesin Siloam Hospital",
    "acc": "siloam_surabaya",
    "status": "Held",
    "dir": "Outbound",
    "start": "2020-07-20 10:00:00",
    "assigned": "citra",
    "desc": "Briefing sanitasi dan protokol steril pantry dokter spesialis."
  },
  {
    "name": "[2020] Setting RFID Telkom Digital Hub",
    "acc": "telkom_landmark",
    "status": "Held",
    "dir": "Outbound",
    "start": "2020-10-02 14:00:00",
    "assigned": "bima",
    "desc": "Integrasi mesin kopi otomatis dengan kartu akses gedung Telkom."
  },
  {
    "name": "[2020] Instalasi Mesin Kopi Drive-Thru MERR",
    "acc": "kopi_darurat",
    "status": "Held",
    "dir": "Outbound",
    "start": "2020-12-20 16:00:00",
    "assigned": "bima",
    "desc": "Uji seduh mesin high speed saat peresmian jalur drive-thru pertama."
  },
  {
    "name": "[2020] Konsultasi Pengurangan Kuota Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "dir": "Inbound",
    "start": "2020-04-18 10:00:00",
    "assigned": "citra",
    "desc": "Relaksasi jadwal delivery kopi masa pembatasan aktivitas hotel."
  },
  {
    "name": "[2021] Delivery Aurelia Wave Unit 2 Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "dir": "Outbound",
    "start": "2021-07-16 10:00:00",
    "assigned": "citra",
    "desc": "Konfirmasi unboxing dan uji fungsi mesin kedua resto utama hotel."
  },
  {
    "name": "[2021] Evaluasi Pasca Pembukaan Titik Temu 2",
    "acc": "titik_temu",
    "status": "Held",
    "dir": "Outbound",
    "start": "2021-11-28 14:30:00",
    "assigned": "citra",
    "desc": "Follow up kepuasan pelanggan cabang baru Surabaya Barat."
  },
  {
    "name": "[2021] Onboarding Kopi Sudut Kota Malang",
    "acc": "sudut_kota",
    "status": "Held",
    "dir": "Outbound",
    "start": "2021-04-22 13:00:00",
    "assigned": "bima",
    "desc": "Pelatihan kalibrasi grinder espresso untuk 4 barista muda Malang."
  },
  {
    "name": "[2021] Pengecekan Mesin Banquet Bumi Surabaya",
    "acc": "bumi_surabaya",
    "status": "Held",
    "dir": "Outbound",
    "start": "2021-09-05 11:00:00",
    "assigned": "citra",
    "desc": "Uji kestabilan suhu boiler saat jamuan makan malam 300 undangan."
  },
  {
    "name": "[2021] Koordinasi Pantry Unair FEB",
    "acc": "unair_campus",
    "status": "Held",
    "dir": "Outbound",
    "start": "2021-09-20 15:00:00",
    "assigned": "bima",
    "desc": "Penyediaan biji kopi fresh roast untuk lounge dekanat."
  },
  {
    "name": "[2022] Review Profil Roasting Senja 2022",
    "acc": "senja",
    "status": "Held",
    "dir": "Outbound",
    "start": "2022-03-25 15:00:00",
    "assigned": "citra",
    "desc": "Diskusi hasil panen kopi Ijen Honey terbaru bersama Hendra Wijaya."
  },
  {
    "name": "[2022] Perawatan Filter Air Batu Highland",
    "acc": "batu_highland",
    "status": "Held",
    "dir": "Outbound",
    "start": "2022-09-02 11:00:00",
    "assigned": "citra",
    "desc": "Jadwal teknisi penggantian resin ion exchanger water softener."
  },
  {
    "name": "[2022] Instalasi Mesin Bromo Terrace Sky Bar",
    "acc": "bromo_terrace",
    "status": "Held",
    "dir": "Outbound",
    "start": "2022-10-22 14:00:00",
    "assigned": "citra",
    "desc": "Teknisi mengonfirmasi performa steam boiler pada elevasi 1.800 mdpl."
  },
  {
    "name": "[2022] Pelatihan Staf Maspion Executive",
    "acc": "maspion_tower",
    "status": "Held",
    "dir": "Outbound",
    "start": "2022-10-28 10:30:00",
    "assigned": "citra",
    "desc": "Edukasi pembuatan latte art untuk staf pantry dewan komisaris."
  },
  {
    "name": "[2022] Suplai Green Bean Kediri Roastery Hub",
    "acc": "kediri_roastery",
    "status": "Held",
    "dir": "Outbound",
    "start": "2022-12-10 13:30:00",
    "assigned": "bima",
    "desc": "Konfirmasi pengiriman perdana 150 kg Robusta Dampit & Arabika Argopuro."
  },
  {
    "name": "[2023] Diskusi Mesin Cabang 3 Titik Temu",
    "acc": "titik_temu",
    "status": "Held",
    "dir": "Outbound",
    "start": "2023-03-15 10:15:00",
    "assigned": "citra",
    "desc": "Penyesuaian daya listrik lokasi cabang Sidoarjo sebelum kirim mesin."
  },
  {
    "name": "[2023] Custom Brass Mesin Heritage Club",
    "acc": "heritage_club",
    "status": "Held",
    "dir": "Outbound",
    "start": "2023-08-20 16:00:00",
    "assigned": "citra",
    "desc": "Konfirmasi aksen logo ukir kuningan pada cover belakang mesin."
  },
  {
    "name": "[2023] Kalibrasi Suhu Mesin Senja Roastery",
    "acc": "senja",
    "status": "Held",
    "dir": "Outbound",
    "start": "2023-11-10 14:00:00",
    "assigned": "citra",
    "desc": "Panduan telepon setting PID suhu ekstraksi 93.5 C mesin Appia Life."
  },
  {
    "name": "[2023] Review Pasokan Kopi Ciputra Golf",
    "acc": "ciputra_golf",
    "status": "Held",
    "dir": "Outbound",
    "start": "2023-09-05 11:30:00",
    "assigned": "citra",
    "desc": "Peningkatan kuota blend untuk turnamen piala gubernur Jawa Timur."
  },
  {
    "name": "[2023] Diskusi Riset Pour-Over Robotik ITS",
    "acc": "its_research",
    "status": "Held",
    "dir": "Outbound",
    "start": "2023-10-12 15:00:00",
    "assigned": "bima",
    "desc": "Penyediaan biji kopi specialty seragam untuk kalibrasi lengan robot."
  },
  {
    "name": "[2024] Onboarding Mesin Kafe Aksara Rungkut",
    "acc": "aksara",
    "status": "Held",
    "dir": "Outbound",
    "start": "2024-02-15 09:30:00",
    "assigned": "bima",
    "desc": "Briefing teknis dan jadwal serah terima mesin ke Nadia Kusuma."
  },
  {
    "name": "[2024] Koordinasi Bean to Cup RS Mitra",
    "acc": "rs_mitra",
    "status": "Held",
    "dir": "Outbound",
    "start": "2024-05-30 11:20:00",
    "assigned": "bima",
    "desc": "Pelatihan staf kantin rumah sakit untuk sanitasi milk wand otomatis."
  },
  {
    "name": "[2024] Ekspedisi Kopi Ijen ke Seminyak Bali",
    "acc": "aroma_bali",
    "status": "Held",
    "dir": "Outbound",
    "start": "2024-09-25 15:30:00",
    "assigned": "citra",
    "desc": "Pelacakan resi kargo laut pengiriman 120 kg specialty ke Bali."
  },
  {
    "name": "[2024] Evaluasi Kopi Kolese Mojokerto",
    "acc": "mojokerto_cafe",
    "status": "Held",
    "dir": "Outbound",
    "start": "2024-12-05 14:00:00",
    "assigned": "bima",
    "desc": "Feedback menu es kopi susu kelapa muda menggunakan robusta Pacet."
  },
  {
    "name": "[2025] Rencana Upgrade Mesin Majapahit Lt 5",
    "acc": "majapahit",
    "status": "Held",
    "dir": "Outbound",
    "start": "2025-04-18 10:00:00",
    "assigned": "citra",
    "desc": "Pembahasan penambahan unit mesin espresso di lounge eksekutif lantai 5."
  },
  {
    "name": "[2025] Pengiriman Biji Kopi Madiun Bistro",
    "acc": "djava_bistro",
    "status": "Held",
    "dir": "Outbound",
    "start": "2025-09-15 14:00:00",
    "assigned": "bima",
    "desc": "Pengecekan kualitas biji kopi pasca roasting batch pertama untuk D Java Bistro."
  },
  {
    "name": "[2025] Pelatihan Kiosk Cakrawala Univ",
    "acc": "cakrawala_univ",
    "status": "Held",
    "dir": "Outbound",
    "start": "2025-09-05 13:00:00",
    "assigned": "bima",
    "desc": "Konfirmasi jadwal sesi workshop barista dasar bagi mahasiswa pengelola kiosk."
  },
  {
    "name": "[2025] Setup Mesin Tugu Hotel Malang",
    "acc": "tugu_hotel",
    "status": "Held",
    "dir": "Outbound",
    "start": "2025-08-01 11:00:00",
    "assigned": "citra",
    "desc": "Uji seduh blend kopi kolonial dengan mesin custom bronze."
  }
]
JSON
, true);

foreach ($callsData as $cd) {
    $startIso = str_starts_with($cd['start'], '+') || str_starts_with($cd['start'], '-')
        ? $cd['start']
        : $cd['start'];
    $startDate = utc_db_datetime($startIso);
    $durH = isset($cd['dur_h']) ? (int)$cd['dur_h'] : 0;
    $durM = isset($cd['dur_m']) ? (int)$cd['dur_m'] : 30;
    $assignedUser = isset($userMap[$cd['assigned']]) ? $userMap[$cd['assigned']] : '1';

    $callBean = find_or_create(
        'Calls',
        ['name' => $cd['name']],
        [
            'name' => $cd['name'],
            'status' => $cd['status'] === 'Planned' ? $callStatusPlanned : $callStatusHeld,
            'direction' => $cd['dir'] === 'Inbound' ? $callDirInbound : $callDirOutbound,
            'date_start' => $startDate,
            'date_end' => utc_db_datetime($startIso . ' +' . ($durH * 60 + $durM) . ' minutes'),
            'duration_hours' => $durH,
            'duration_minutes' => $durM,
            'parent_type' => 'Accounts',
            'parent_id' => $accounts[$cd['acc']]->id,
            'assigned_user_id' => $assignedUser,
            'description' => $cd['desc'],
        ],
        isset($cd['date']) ? (str_starts_with($cd['date'], '-') ? utc_db_datetime($cd['date']) : $cd['date']) : $startDate
    );

    if (isset($contacts[$cd['acc']])) {
        link_beans($callBean, 'contacts', $contacts[$cd['acc']]->id);
    }
    if ($callBean->load_relationship('accounts')) {
        $callBean->accounts->add($accounts[$cd['acc']]->id);
    }
}

// ---------------------------------------------------------------------------
// 7. Meetings (30 Meetings: 5 Planned Dashboard + 25 Held)
// ---------------------------------------------------------------------------
$meetStatusPlanned = must_key('meeting_status_dom', 'Planned');
$meetStatusHeld = must_key('meeting_status_dom', 'Held');

$meetingsData = json_decode(<<<'JSON'
[
  {
    "name": "[PLANNED] Negosiasi Perpanjangan Kontrak 2027 Majapahit",
    "acc": "majapahit",
    "status": "Planned",
    "start": "+3 days 14:00:00",
    "dur_h": 1,
    "dur_m": 30,
    "assigned": "citra",
    "desc": "Pertemuan tahunan evaluasi kontrak pasokan biji kopi 150 kg/bln dan garansi mesin dengan F&B Director Grand Majapahit."
  },
  {
    "name": "[PLANNED] Uji Coba Mesin Kopi Kontainer Sidoarjo",
    "acc": "kopi_kontainer",
    "status": "Planned",
    "start": "+4 days 10:00:00",
    "dur_h": 2,
    "dur_m": 0,
    "assigned": "bima",
    "desc": "Live demo ekstraksi espresso dan frothing susu di showroom Surabaya bersama Bapak Wahyu Pratama."
  },
  {
    "name": "[PLANNED] Presentasi Fasilitas Coffee Pantry Intiland",
    "acc": "intiland_tower",
    "status": "Planned",
    "start": "+6 days 11:00:00",
    "dur_h": 1,
    "dur_m": 0,
    "assigned": "citra",
    "desc": "Presentasi proposal pengadaan mesin bean-to-cup touch screen di hadapan direksi manajemen gedung Intiland Tower."
  },
  {
    "name": "[PLANNED] Final Review Addendum Titik Temu 2027",
    "acc": "titik_temu",
    "status": "Planned",
    "start": "+7 days 14:30:00",
    "dur_h": 1,
    "dur_m": 30,
    "assigned": "citra",
    "desc": "Penandatanganan kontrak pasokan tahunan multi-outlet 2027 dan komitmen alokasi panen Ijen Natural."
  },
  {
    "name": "[PLANNED] Rapat Evaluasi Kinerja Penjualan Q4 2026",
    "acc": "majapahit",
    "status": "Planned",
    "start": "+9 days 09:30:00",
    "dur_h": 2,
    "dur_m": 0,
    "assigned": "1",
    "desc": "Rapat koordinasi internal sales executive & customer success mereview pipeline 10 tahun dan target 2027."
  },
  {
    "name": "[2016] Commissioning Mesin Aurelia Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "start": "2016-05-25 13:00:00",
    "assigned": "citra",
    "desc": "Instalasi mesin Aurelia Wave pertama kalibrasi water filter 140 ppm dan sertifikasi 6 barista hotel."
  },
  {
    "name": "[2016] Opening Briefing Kopi Titik Temu Darmo",
    "acc": "titik_temu",
    "status": "Held",
    "start": "2016-10-12 14:00:00",
    "assigned": "citra",
    "desc": "Pelatihan barista pemula dan kalibrasi gilingan grinder kedai pertama Titik Temu."
  },
  {
    "name": "[2016] Uji Seduh Eksekutif Surabaya Golf Club",
    "acc": "surabaya_club",
    "status": "Held",
    "start": "2016-12-08 15:30:00",
    "assigned": "citra",
    "desc": "Uji seduh kopi espresso blend bersama dewan pengawas club house golf."
  },
  {
    "name": "[2017] Review Kontrak Tahunan Grand Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "start": "2017-06-10 14:00:00",
    "assigned": "citra",
    "desc": "Evaluasi kepuasan tamu terhadap Majapahit Blend dan perpanjangan pasokan tahun kedua."
  },
  {
    "name": "[2017] Cupping Session Panen Perdana Senja Roastery",
    "acc": "senja",
    "status": "Held",
    "start": "2017-09-15 10:00:00",
    "assigned": "citra",
    "desc": "Blind cupping 5 lot specialty Arabika Ijen dan Robusta Dampit bersama tim roastery."
  },
  {
    "name": "[2018] Penandatanganan Kontrak Petrokimia Gresik",
    "acc": "petrokimia",
    "status": "Held",
    "start": "2018-05-15 10:30:00",
    "assigned": "citra",
    "desc": "Seremoni serah terima mesin otomatis dan kontrak supply lounge VIP direksi BUMN."
  },
  {
    "name": "[2018] Workshop Barista Diklat Semen Indonesia",
    "acc": "semen_indonesia",
    "status": "Held",
    "start": "2018-08-20 09:00:00",
    "assigned": "bima",
    "desc": "Pelatihan staf pantry operasional dan standar sanitasi mesin bean-to-cup."
  },
  {
    "name": "[2018] Design Review Mesin Custom Java Heritage",
    "acc": "java_heritage",
    "status": "Held",
    "start": "2018-11-28 14:00:00",
    "assigned": "citra",
    "desc": "Penyelarasan aksen kuningan mesin espresso dengan arsitektur interior cigar lounge."
  },
  {
    "name": "[2019] Technical Audit Mesin Batu Highland Resort",
    "acc": "batu_highland",
    "status": "Held",
    "start": "2019-11-25 11:00:00",
    "assigned": "citra",
    "desc": "Audit performa steam boiler penggantian filter softener sebelum musim libur akhir tahun."
  },
  {
    "name": "[2019] Barista Training Kopi Jalanan Malang",
    "acc": "kopi_jalanan",
    "status": "Held",
    "start": "2019-04-10 13:00:00",
    "assigned": "bima",
    "desc": "Training dasar kalibrasi espresso dan milk frothing untuk 4 barista mahasiswa."
  },
  {
    "name": "[2019] Kunjungan Kebun Kopi Mitra Ijen Banyuwangi",
    "acc": "banyuwangi_resort",
    "status": "Held",
    "start": "2019-12-10 09:00:00",
    "assigned": "citra",
    "desc": "Field trip ke kebun kopi lereng Ijen bersama manajemen resort."
  },
  {
    "name": "[2020] QBR Virtual Masa Pandemi Grand Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "start": "2020-07-15 14:00:00",
    "assigned": "citra",
    "desc": "Evaluasi strategi delivery biji kopi kemasan ritel untuk tamu hotel selama masa PPKM."
  },
  {
    "name": "[2020] Commissioning Mesin Steril RS Mitra Sidoarjo",
    "acc": "rs_mitra",
    "status": "Held",
    "start": "2020-05-12 10:00:00",
    "assigned": "bima",
    "desc": "Instalasi dan verifikasi K3 mesin kopi touchless untuk dokter spesialis."
  },
  {
    "name": "[2021] Review Ekspansi Cabang 2 Titik Temu",
    "acc": "titik_temu",
    "status": "Held",
    "start": "2021-11-15 15:30:00",
    "assigned": "citra",
    "desc": "Penandatanganan kontrak mesin kedua dan alokasi kuota biji kopi house blend."
  },
  {
    "name": "[2021] Onboarding Kopi Sudut Kota Malang",
    "acc": "sudut_kota",
    "status": "Held",
    "start": "2021-04-15 13:30:00",
    "assigned": "bima",
    "desc": "Review operasional bulan pertama cafe & training maintenance harian mesin."
  },
  {
    "name": "[2021] Setup Mesin Banquet Bumi Surabaya Resort",
    "acc": "bumi_surabaya",
    "status": "Held",
    "start": "2021-08-20 10:30:00",
    "assigned": "citra",
    "desc": "Instalasi mesin espresso banquet hall perjamuan diplomatik."
  },
  {
    "name": "[2022] Review 6 Tahun Kemitraan Grand Majapahit",
    "acc": "majapahit",
    "status": "Held",
    "start": "2022-06-25 14:00:00",
    "assigned": "citra",
    "desc": "Pemberian piagam penghargaan loyalitas 6 tahun mitra dan addendum kontrak pasokan."
  },
  {
    "name": "[2022] Cupping Panen Ijen Anaerob Senja Roastery",
    "acc": "senja",
    "status": "Held",
    "start": "2022-10-18 10:00:00",
    "assigned": "citra",
    "desc": "Blind cupping lot specialty Ijen Anaerob score 87.5 bersama tim roastery."
  },
  {
    "name": "[2022] Commissioning Mesin Sky Bar Bromo Terrace",
    "acc": "bromo_terrace",
    "status": "Held",
    "start": "2022-10-15 11:00:00",
    "assigned": "citra",
    "desc": "Pengujian mesin espresso elevasi tinggi 1.800 mdpl di Bromo."
  },
  {
    "name": "[2023] QBR & Onboarding Kafe Aksara Rungkut",
    "acc": "aksara",
    "status": "Held",
    "start": "2023-02-20 14:00:00",
    "assigned": "bima",
    "desc": "Review operasional bulan pertama co-working cafe & training maintenance harian."
  },
  {
    "name": "[2023] Evaluasi Pasokan Kopi Petrokimia Sentosa",
    "acc": "petrokimia",
    "status": "Held",
    "start": "2023-12-05 10:30:00",
    "assigned": "citra",
    "desc": "Review konsumsi biji kopi lounge direksi dan perpanjangan kontrak tahun 2024."
  },
  {
    "name": "[2023] Kemitraan Riset Otomasi Seduh ITS Tech Cafe",
    "acc": "its_research",
    "status": "Held",
    "start": "2023-10-05 14:00:00",
    "assigned": "bima",
    "desc": "Diskusi implementasi algoritma seduh pour-over robotik bersama dosen ITS."
  },
  {
    "name": "[2024] Audit Kualitas Pasokan Kopi Seminyak Bali",
    "acc": "aroma_bali",
    "status": "Held",
    "start": "2024-10-15 15:00:00",
    "assigned": "citra",
    "desc": "Uji organoleptik batch specialty anaerob yang tiba di outlet Bali."
  },
  {
    "name": "[2024] Evaluasi Pengadaan Mesin RS Mitra Sidoarjo",
    "acc": "rs_mitra",
    "status": "Held",
    "start": "2024-08-10 10:30:00",
    "assigned": "bima",
    "desc": "Review konsumsi biji kopi dokter lounge dan penyesuaian jadwal delivery mingguan."
  },
  {
    "name": "[2025] Demo Mesin Komersial D Java Bistro Madiun",
    "acc": "djava_bistro",
    "status": "Held",
    "start": "2025-08-20 13:00:00",
    "assigned": "bima",
    "desc": "Uji seduh mesin Appia Life di showroom Surabaya bersama manajemen D Java Bistro."
  }
]
JSON
, true);

foreach ($meetingsData as $md) {
    $startIso = str_starts_with($md['start'], '+') || str_starts_with($md['start'], '-')
        ? $md['start']
        : $md['start'];
    $startDate = utc_db_datetime($startIso);
    $durH = isset($md['dur_h']) ? (int)$md['dur_h'] : 1;
    $durM = isset($md['dur_m']) ? (int)$md['dur_m'] : 0;
    $assignedUser = isset($userMap[$md['assigned']]) ? $userMap[$md['assigned']] : '1';

    $meetBean = find_or_create(
        'Meetings',
        ['name' => $md['name']],
        [
            'name' => $md['name'],
            'status' => $md['status'] === 'Planned' ? $meetStatusPlanned : $meetStatusHeld,
            'date_start' => $startDate,
            'date_end' => utc_db_datetime($startIso . ' +' . ($durH * 60 + $durM) . ' minutes'),
            'duration_hours' => $durH,
            'duration_minutes' => $durM,
            'parent_type' => 'Accounts',
            'parent_id' => $accounts[$md['acc']]->id,
            'assigned_user_id' => $assignedUser,
            'description' => $md['desc'],
        ],
        $startDate
    );

    if (isset($contacts[$md['acc']])) {
        link_beans($meetBean, 'contacts', $contacts[$md['acc']]->id);
    }
    if ($meetBean->load_relationship('accounts')) {
        $meetBean->accounts->add($accounts[$md['acc']]->id);
    }
}

// ---------------------------------------------------------------------------
// 8. Tasks (24 Tasks: 6 Open for Dashboard + 18 Completed)
// ---------------------------------------------------------------------------
$taskStatNotStarted = must_key('task_status_dom', 'Not Started');
$taskStatInProgress = must_key('task_status_dom', 'In Progress');
$taskStatCompleted = must_key('task_status_dom', 'Completed');
$taskPrioHigh = must_key('task_priority_dom', 'High');
$taskPrioMed = must_key('task_priority_dom', 'Medium');
$taskPrioLow = must_key('task_priority_dom', 'Low');

$taskStatMap = [
    'Not Started' => $taskStatNotStarted,
    'In Progress' => $taskStatInProgress,
    'Completed' => $taskStatCompleted,
];
$taskPrioMap = [
    'High' => $taskPrioHigh,
    'Medium' => $taskPrioMed,
    'Low' => $taskPrioLow,
];

$tasksData = json_decode(<<<'JSON'
[
  {
    "name": "Kirim Proposal Addendum Kontrak 2027 Majapahit",
    "acc": "majapahit",
    "status": "In Progress",
    "priority": "High",
    "due": "+5 days 17:00:00",
    "start": "-2 days 09:00:00",
    "assigned": "citra",
    "desc": "Finalisasi draft addendum kontrak pasokan tahunan 150 kg/bln dan garansi mesin Aurelia Wave."
  },
  {
    "name": "Siapkan Unit Mesin Demo Kopi Kontainer Sda",
    "acc": "kopi_kontainer",
    "status": "Not Started",
    "priority": "High",
    "due": "+3 days 12:00:00",
    "start": "+1 days 09:00:00",
    "assigned": "bima",
    "desc": "Kalibrasi mesin 1-Group dan grinder Eureka di showroom untuk uji seduh Bapak Wahyu."
  },
  {
    "name": "Survey Kelistrikan Ruang Pantry Intiland Tower",
    "acc": "intiland_tower",
    "status": "In Progress",
    "priority": "Medium",
    "due": "+7 days 15:00:00",
    "start": "-1 days 10:00:00",
    "assigned": "citra",
    "desc": "Kordinasi dengan teknisi gedung Intiland memastikan daya listrik 3.500 watt tersedia di pantry."
  },
  {
    "name": "Kirim Sample Biji Kopi Blend Komunitas Blitar",
    "acc": "blitar_hub",
    "status": "In Progress",
    "priority": "Medium",
    "due": "+4 days 17:00:00",
    "start": "-2 days 14:00:00",
    "assigned": "bima",
    "desc": "Kirim 5 varian sampel green bean specialty (Ijen, Dampit, Kawi) melalui kargo darat."
  },
  {
    "name": "Laporan Penjualan & Pipeline CRM 10 Tahun",
    "acc": "majapahit",
    "status": "In Progress",
    "priority": "High",
    "due": "+6 days 18:00:00",
    "start": "-3 days 08:30:00",
    "assigned": "1",
    "desc": "Penyusunan ringkasan performa penjualan CRM 2016-2026 untuk bahan rapat dewan direksi."
  },
  {
    "name": "Follow Up Penawaran Grinder Kopi Sudut Malang",
    "acc": "sudut_kota",
    "status": "Not Started",
    "priority": "Medium",
    "due": "+8 days 16:00:00",
    "start": "+2 days 10:00:00",
    "assigned": "bima",
    "desc": "Konfirmasi keputusan Clara Shinta mengenai skema cicilan 2 unit grinder on-demand."
  },
  {
    "name": "Setup Database CRM Perdana 2016",
    "acc": "majapahit",
    "status": "Completed",
    "priority": "High",
    "due": "2016-04-15 17:00:00",
    "start": "2016-04-10 09:00:00",
    "assigned": "1",
    "desc": "Inisialisasi modul akun hotel dan konfigurasi mata uang IDR rupiah."
  },
  {
    "name": "Verifikasi Grounding Listrik Grand Majapahit",
    "acc": "majapahit",
    "status": "Completed",
    "priority": "High",
    "due": "2016-05-18 16:00:00",
    "start": "2016-05-15 10:00:00",
    "assigned": "citra",
    "desc": "Pengecekan voltase netral-grounding < 1.0 Volt untuk mesin Aurelia Wave."
  },
  {
    "name": "Kirim Paket Pelatihan Barista Titik Temu",
    "acc": "titik_temu",
    "status": "Completed",
    "priority": "Medium",
    "due": "2016-10-10 15:00:00",
    "start": "2016-10-05 09:00:00",
    "assigned": "citra",
    "desc": "Modul SOP kalibrasi espresso dan panduan maintenance mesin harian."
  },
  {
    "name": "Uji Sampel Sangrai Green Bean Senja 2017",
    "acc": "senja",
    "status": "Completed",
    "priority": "Medium",
    "due": "2017-03-10 17:00:00",
    "start": "2017-03-05 11:00:00",
    "assigned": "citra",
    "desc": "Cupping evaluasi 3 profil sangrai berbeda Arabika Ijen Anaerobic."
  },
  {
    "name": "Pemasangan Water Softener Batu Highland",
    "acc": "batu_highland",
    "status": "Completed",
    "priority": "High",
    "due": "2017-11-05 16:00:00",
    "start": "2017-11-01 10:00:00",
    "assigned": "citra",
    "desc": "Instalasi tabung filter resin kation menurunkan hardness air pegunungan."
  },
  {
    "name": "Pengiriman Mesin Otomatis Petrokimia Gresik",
    "acc": "petrokimia",
    "status": "Completed",
    "priority": "High",
    "due": "2018-05-10 17:00:00",
    "start": "2018-05-08 09:00:00",
    "assigned": "citra",
    "desc": "Serah terima unit mesin bean-to-cup ke ruang sekretariat direksi."
  },
  {
    "name": "Sertifikasi 8 Staf Diklat Semen Indonesia",
    "acc": "semen_indonesia",
    "status": "Completed",
    "priority": "Medium",
    "due": "2018-08-25 15:00:00",
    "start": "2018-08-22 09:00:00",
    "assigned": "bima",
    "desc": "Ujian kompetensi operasional mesin kopi otomatis bagi tim fasilitas."
  },
  {
    "name": "Inspeksi Finishing Kuningan Java Heritage",
    "acc": "java_heritage",
    "status": "Completed",
    "priority": "High",
    "due": "2018-11-20 16:00:00",
    "start": "2018-11-18 13:00:00",
    "assigned": "citra",
    "desc": "Quality control kilau plat kuningan custom mesin 3-Group."
  },
  {
    "name": "Instalasi Mesin Kopi Jalan Suhat Malang",
    "acc": "kopi_jalanan",
    "status": "Completed",
    "priority": "Medium",
    "due": "2019-04-02 17:00:00",
    "start": "2019-03-30 10:00:00",
    "assigned": "bima",
    "desc": "Instalasi kelistrikan 3.000 Watt dan pompa air galon kedai kopi Suhat."
  },
  {
    "name": "Penggantian Filter Resin RS Mitra Sejahtera",
    "acc": "rs_mitra",
    "status": "Completed",
    "priority": "High",
    "due": "2020-05-08 15:00:00",
    "start": "2020-05-05 09:00:00",
    "assigned": "bima",
    "desc": "Sanitasi jalur air dan penggantian filter antibakteri mesin pantry dokter."
  },
  {
    "name": "Kalibrasi Mesin Drive-Thru MERR Surabaya",
    "acc": "kopi_darurat",
    "status": "Completed",
    "priority": "High",
    "due": "2020-12-18 16:00:00",
    "start": "2020-12-15 11:00:00",
    "assigned": "bima",
    "desc": "Penyetelan volume shot espresso 25 detik konsisten untuk ritme cepat drive-thru."
  },
  {
    "name": "PMR Tahunan 2021 Grand Majapahit Hotel",
    "acc": "majapahit",
    "status": "Completed",
    "priority": "High",
    "due": "2021-06-28 17:00:00",
    "start": "2021-06-25 09:00:00",
    "assigned": "citra",
    "desc": "Pembersihan boiler descaling dan penggantian safety valve berkala."
  },
  {
    "name": "Pemasangan Mesin Cabang 2 Titik Temu",
    "acc": "titik_temu",
    "status": "Completed",
    "priority": "Medium",
    "due": "2021-11-25 16:00:00",
    "start": "2021-11-20 10:00:00",
    "assigned": "citra",
    "desc": "Commissioning mesin espresso cabang Surabaya Barat tepat waktu."
  },
  {
    "name": "Pemasangan Mesin Bromo Terrace Probolinggo",
    "acc": "bromo_terrace",
    "status": "Completed",
    "priority": "High",
    "due": "2022-10-20 17:00:00",
    "start": "2022-10-18 09:00:00",
    "assigned": "citra",
    "desc": "Instalasi dan uji pemanasan boiler pada kondisi udara dingin pegunungan."
  },
  {
    "name": "Kirim Konsinyasi Cold Brew Kafe Aksara",
    "acc": "aksara",
    "status": "Completed",
    "priority": "Low",
    "due": "2023-02-15 15:00:00",
    "start": "2023-02-12 11:00:00",
    "assigned": "bima",
    "desc": "Pengiriman 100 botol cold brew konsinyasi co-working space MERR."
  },
  {
    "name": "Pengiriman Kargo Kopi Bali 120 Kg",
    "acc": "aroma_bali",
    "status": "Completed",
    "priority": "Medium",
    "due": "2024-09-22 16:00:00",
    "start": "2024-09-20 09:00:00",
    "assigned": "citra",
    "desc": "Packing vakum nitrogen valve pengiriman specialty ke Seminyak Bali."
  },
  {
    "name": "Instalasi Mesin D Java Bistro Madiun",
    "acc": "djava_bistro",
    "status": "Completed",
    "priority": "Medium",
    "due": "2025-09-10 17:00:00",
    "start": "2025-09-08 10:00:00",
    "assigned": "bima",
    "desc": "Serah terima mesin Appia Life Compact dan pelatihan 3 barista Madiun."
  },
  {
    "name": "PMR Tahunan 2025 Grand Majapahit Hotel",
    "acc": "majapahit",
    "status": "Completed",
    "priority": "High",
    "due": "2025-11-12 17:00:00",
    "start": "2025-11-10 09:00:00",
    "assigned": "citra",
    "desc": "Pengecekan menyeluruh 3 unit mesin espresso Grand Majapahit (Resto, Lounge, Banquet)."
  }
]
JSON
, true);

foreach ($tasksData as $td) {
    $dueIso = str_starts_with($td['due'], '+') || str_starts_with($td['due'], '-')
        ? $td['due']
        : $td['due'];
    $startIso = str_starts_with($td['start'], '+') || str_starts_with($td['start'], '-')
        ? $td['start']
        : $td['start'];
    $assignedUser = isset($userMap[$td['assigned']]) ? $userMap[$td['assigned']] : '1';

    $taskBean = find_or_create(
        'Tasks',
        ['name' => $td['name']],
        [
            'name' => $td['name'],
            'status' => $taskStatMap[$td['status']],
            'priority' => $taskPrioMap[$td['priority']],
            'date_due' => utc_db_datetime($dueIso),
            'date_start' => utc_db_datetime($startIso),
            'parent_type' => 'Accounts',
            'parent_id' => $accounts[$td['acc']]->id,
            'contact_id' => isset($contacts[$td['acc']]) ? $contacts[$td['acc']]->id : '',
            'assigned_user_id' => $assignedUser,
            'description' => $td['desc'],
        ],
        utc_db_datetime($startIso)
    );
}

// ---------------------------------------------------------------------------
// 9. Cases (24 Cases: 4 Active in 2026 + 20 Closed Historical)
// ---------------------------------------------------------------------------
$caseStatNew = must_key('case_status_dom', 'Open_New');
$caseStatPending = must_key('case_status_dom', 'Open_Pending Input');
$caseStatClosed = must_key('case_status_dom', 'Closed_Closed');
$caseP1 = must_key('case_priority_dom', 'P1');
$caseP2 = must_key('case_priority_dom', 'P2');
$caseP3 = must_key('case_priority_dom', 'P3');

$caseStatMap = [
    'Open_New' => $caseStatNew,
    'Open_Pending Input' => $caseStatPending,
    'Closed_Closed' => $caseStatClosed,
];
$casePrioMap = [
    'P1' => $caseP1,
    'P2' => $caseP2,
    'P3' => $caseP3,
];

$casesData = json_decode(<<<'JSON'
[
  {
    "name": "[URGENT] Tekanan Steam Wand Turun",
    "acc": "majapahit",
    "status": "Open_New",
    "priority": "P1",
    "assigned": "citra",
    "date": "2026-11-09 08:30:00",
    "desc": "Tekanan steam boiler turun dari 1.2 bar ke 0.5 bar saat jam sarapan tamu hotel. Barista kesulitan frothing susu latte."
  },
  {
    "name": "Jadwal Kalibrasi Mesin Kuartal 1",
    "acc": "senja",
    "status": "Open_Pending Input",
    "priority": "P2",
    "assigned": "citra",
    "date": "2026-11-08 10:00:00",
    "desc": "Permintaan kalibrasi rutin tekanan pompa rotary 9 bar dan pengecekan elemen pemanas."
  },
  {
    "name": "Penggantian Filter Air Kopi Sudut Malang",
    "acc": "sudut_kota",
    "status": "Open_New",
    "priority": "P2",
    "assigned": "bima",
    "date": "2026-11-07 14:15:00",
    "desc": "Nilai TDS air keluar dari filter softening naik menjadi 190 ppm. Diperlukan regenerasi resin kation."
  },
  {
    "name": "Inspeksi Pompa Air Maspion Square Executive",
    "acc": "maspion_tower",
    "status": "Open_Pending Input",
    "priority": "P3",
    "assigned": "1",
    "desc": "Suara pompa pengisian boiler terdengar mendengung saat pengisian otomatis. Teknisi dijadwalkan inspeksi."
  },
  {
    "name": "[2016] Kalibrasi Awal Boiler Mesin Aurelia",
    "acc": "majapahit",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2016-06-20 14:00:00",
    "desc": "Penyesuaian tekanan pressure stat boiler dari 1.0 bar ke 1.25 bar untuk stabilitas steam milk saat sarapan hotel."
  },
  {
    "name": "[2016] Rembesan Air Seal Portafilter Titik Temu",
    "acc": "titik_temu",
    "status": "Closed_Closed",
    "priority": "P3",
    "assigned": "citra",
    "date": "2016-11-15 11:30:00",
    "desc": "Penggantian gasket 8.5mm silikon tahan panas. Kebocoran terselesaikan sempurna."
  },
  {
    "name": "[2017] Penurunan Tekanan Pompa Senja Roastery",
    "acc": "senja",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2017-05-18 10:15:00",
    "desc": "Bypass valve pompa rotary dibersihkan dari partikel pasir halus kotoran pipa air."
  },
  {
    "name": "[2017] Suhu Air Drop Saat Banquet Majapahit",
    "acc": "majapahit",
    "status": "Closed_Closed",
    "priority": "P1",
    "assigned": "citra",
    "date": "2017-08-22 16:45:00",
    "desc": "Elemen pemanas 4.500 Watt diganti unit cadangan darurat dalam waktu 3 jam."
  },
  {
    "name": "[2018] Kerak Kapur Boiler Batu Highland",
    "acc": "batu_highland",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2018-04-10 13:00:00",
    "desc": "Descaling total sirkulasi asam sitrat food grade untuk menghilangkan endapan kalsium air pegunungan."
  },
  {
    "name": "[2018] Clogging Saluran Susu Petrokimia Lounge",
    "acc": "petrokimia",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2018-09-12 11:00:00",
    "desc": "Pembersihan kerak susu pada modul auto-frother mesin bean-to-cup."
  },
  {
    "name": "[2019] Burr Grinder Macet Kopi Jalanan Malang",
    "acc": "kopi_jalanan",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "bima",
    "date": "2019-06-20 15:30:00",
    "desc": "Benda asing kerikil kecil pada biji kopi pihak ketiga dibersihkan dan kalibrasi ulang pisau gilingan."
  },
  {
    "name": "[2019] Tombol Volumetrik Error Suhat Roasters",
    "acc": "suhat_roaster",
    "status": "Closed_Closed",
    "priority": "P3",
    "assigned": "citra",
    "date": "2019-08-14 10:00:00",
    "desc": "Penggantian touchpad keypad volumetrik membran yang aus akibat pemakaian intensif."
  },
  {
    "name": "[2020] Sensor Suhu E1 Mesin RS Mitra Sejahtera",
    "acc": "rs_mitra",
    "status": "Closed_Closed",
    "priority": "P1",
    "assigned": "bima",
    "date": "2020-06-18 09:30:00",
    "desc": "Kabel probe NTC termokopel kendor diperbaiki dan isolasi termal diperkuat."
  },
  {
    "name": "[2020] Solenoid Valve Macet Kopi Drive-Thru",
    "acc": "kopi_darurat",
    "status": "Closed_Closed",
    "priority": "P1",
    "assigned": "bima",
    "date": "2020-12-28 14:15:00",
    "desc": "Penggantian coil 3-way solenoid valve grouphead 2 dalam SLA 2 jam."
  },
  {
    "name": "[2021] Kebocoran Kran Air Panas Titik Temu 2",
    "acc": "titik_temu",
    "status": "Closed_Closed",
    "priority": "P3",
    "assigned": "citra",
    "date": "2021-12-10 11:00:00",
    "desc": "Penggantian teflon seal kran air panas Americano."
  },
  {
    "name": "[2021] Kalibrasi Pompa Ekstraksi Bumi Surabaya",
    "acc": "bumi_surabaya",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2021-10-05 13:45:00",
    "desc": "Penyesuaian tekanan ekstraksi tepat 9 bar stabil saat jamuan makan malam."
  },
  {
    "name": "[2022] Error Level Air Boiler Senja Roastery",
    "acc": "senja",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2022-04-14 10:20:00",
    "desc": "Probe pendeteksi level air boiler dibersihkan dari kerak tipis."
  },
  {
    "name": "[2022] Gasket Portafilter Aus Bromo Terrace",
    "acc": "bromo_terrace",
    "status": "Closed_Closed",
    "priority": "P3",
    "assigned": "citra",
    "date": "2022-11-18 14:00:00",
    "desc": "Penggantian rutin karet gasket silikon tahan suhu beku lereng Bromo."
  },
  {
    "name": "[2023] Touchpad Mesin Custom Java Heritage",
    "acc": "java_heritage",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2023-04-20 15:30:00",
    "desc": "Kalibrasi sensitivitas tombol capacitive touch switch mesin custom brass."
  },
  {
    "name": "[2023] Water Softener Saturation Batu Highland",
    "acc": "batu_highland",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2023-11-18 10:00:00",
    "desc": "Regenerasi garam industri pekat untuk mengembalikan kapasitas resin softener."
  },
  {
    "name": "[2024] Clogging Burr Grinder Kopi Sudut Malang",
    "acc": "sudut_kota",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "bima",
    "date": "2024-07-09 13:45:00",
    "desc": "Pembersihan flat burr dari penumpukan minyak kopi sangrai dark roast."
  },
  {
    "name": "[2024] Sanitasi Jalur Susu Otomatis RS Mitra",
    "acc": "rs_mitra",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "bima",
    "date": "2024-06-18 15:20:00",
    "desc": "Desinfeksi sirkulasi cairan sanitizer susu dan lulus uji swab lab rumah sakit."
  },
  {
    "name": "[2025] Penggantian Vacuum Valve Grand Majapahit",
    "acc": "majapahit",
    "status": "Closed_Closed",
    "priority": "P2",
    "assigned": "citra",
    "date": "2025-05-14 11:00:00",
    "desc": "Anti-vacuum valve boiler executive lounge diganti untuk mencegah cairan tersedot balik."
  },
  {
    "name": "[2025] Kalibrasi Suhu Mesin D Java Bistro Madiun",
    "acc": "djava_bistro",
    "status": "Closed_Closed",
    "priority": "P3",
    "assigned": "bima",
    "date": "2025-10-22 14:00:00",
    "desc": "Pemeriksaan termokopel digital memastikan konsistensi suhu brew 92.5 C."
  }
]
JSON
, true);

foreach ($casesData as $cd) {
    $assignedUser = isset($userMap[$cd['assigned']]) ? $userMap[$cd['assigned']] : '1';
    $caseBean = find_or_create(
        'Cases',
        ['name' => $cd['name']],
        [
            'name' => $cd['name'],
            'status' => $caseStatMap[$cd['status']],
            'priority' => $casePrioMap[$cd['priority']],
            'account_id' => $accounts[$cd['acc']]->id,
            'account_name' => $accounts[$cd['acc']]->name,
            'assigned_user_id' => $assignedUser,
            'description' => $cd['desc'],
        ],
        $cd['date']
    );

    if (isset($contacts[$cd['acc']])) {
        link_beans($caseBean, 'contacts', $contacts[$cd['acc']]->id);
    }
    if ($caseBean->load_relationship('accounts')) {
        $caseBean->accounts->add($accounts[$cd['acc']]->id);
    }
}

// ---------------------------------------------------------------------------
// 10. Notes (78 Notes across 10-Year Timeline: 2016-2026)
// ---------------------------------------------------------------------------
$notesData = json_decode(<<<'JSON'
[
  {
    "name": "Piagam Mitra Loyalitas 10 Tahun: Grand Majapahit Hotel Surabaya",
    "acc": "majapahit",
    "date": "2026-04-12 09:00:00",
    "desc": "STATUS KLIEN PLATINUM VIP (10 TAHUN LOYAL 2016-2026)\nGrand Majapahit Hotel adalah mitra B2B pertama PT Sumber Kopi Nusantara. Diberikan status prioritas teknisi 24/7 dan complimentary cupping workshop tahunan."
  },
  {
    "name": "Piagam Mitra Loyalitas 10 Tahun: Kopi Titik Temu Surabaya",
    "acc": "titik_temu",
    "date": "2026-09-05 09:00:00",
    "desc": "STATUS KLIEN PLATINUM VIP (10 TAHUN LOYAL 2016-2026)\nKopi Titik Temu tumbuh bersama sejak kedai pertama 2016 hingga multi-outlet di Jawa Timur. Prioritas alokasi panen pertama micro-lot specialty Ijen."
  },
  {
    "name": "Piagam Mitra Loyalitas 10 Tahun: Surabaya Golf Club Dining",
    "acc": "surabaya_club",
    "date": "2026-11-01 09:00:00",
    "desc": "STATUS KLIEN PLATINUM VIP (10 TAHUN LOYAL 2016-2026)\nKemitraan berkesinambungan pengadaan kopi eksklusif ruang jamuan dewan pembina dan atlet golf."
  },
  {
    "name": "Piagam Mitra Loyalitas 5 Tahun: Kopi Sudut Kota Malang",
    "acc": "sudut_kota",
    "date": "2026-03-08 09:00:00",
    "desc": "STATUS KLIEN GOLD (5 TAHUN LOYAL 2021-2026)\nKemitraan 5 tahun cafe mahasiswa legendaris jalan Ijen Malang. Fasilitas diskon spare part 15%."
  },
  {
    "name": "Piagam Mitra Loyalitas 5 Tahun: Bumi Surabaya City Resort",
    "acc": "bumi_surabaya",
    "date": "2026-06-15 09:00:00",
    "desc": "STATUS KLIEN GOLD (5 TAHUN LOYAL 2021-2026)\nMitra perjamuan hotel bintang 5 pusat kota Surabaya. Pengadaan kopi jamuan resmi konvensi."
  },
  {
    "name": "BAST-2016-001: Serah Terima Mesin Aurelia Wave Grand Majapahit",
    "acc": "majapahit",
    "date": "2016-05-25 15:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Aurelia Wave 2G\nSerial: NS-AW2G-2016-0012\nStatus: Lulus uji kelistrikan grounding 0.8V dan filter air 140 ppm."
  },
  {
    "name": "BAST-2016-002: Mesin Espresso Victoria Arduino Titik Temu",
    "acc": "titik_temu",
    "date": "2016-10-15 14:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Victoria Arduino Eagle One 2G\nSerial: VA-E1-2016-0045\nPelatihan barista pembukaan kedai pertama selesai dilaksanakan."
  },
  {
    "name": "BAST-2016-003: Mesin Komersial Surabaya Golf Clubhouse",
    "acc": "surabaya_club",
    "date": "2016-12-10 16:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Appia Life 2G\nSerial: NS-AL-2016-0098\nInstalasi pantry VIP dining selesai."
  },
  {
    "name": "BAST-2017-004: Instalasi Mesin Appia Life Senja Roastery",
    "acc": "senja",
    "date": "2017-03-12 16:30:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Appia Life 2G\nSerial: NS-AL-2017-0144\nTekanan boiler stabil 1.15 bar."
  },
  {
    "name": "BAST-2017-005: Paket Mesin Resto Batu Highland Resort",
    "acc": "batu_highland",
    "date": "2017-11-20 11:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: 2x Appia Life 2G + Water Softener 50L\nSerial: NS-AL-2017-0211/0212\nInstalasi restoran dan banquet hall selesai."
  },
  {
    "name": "BAST-2018-006: Mesin Super Otomatis Petrokimia Sentosa",
    "acc": "petrokimia",
    "date": "2018-05-18 14:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Schaerer Coffee Soul Bean-to-Cup\nSerial: SC-CS-2018-0033\nLounge direksi PT Petrokimia Sentosa."
  },
  {
    "name": "BAST-2018-007: Unit Otomatis Diklat Semen Indonesia",
    "acc": "semen_indonesia",
    "date": "2018-08-28 10:30:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: 2x WMF 1500S+ Bean-to-Cup\nSerial: WMF-1500-2018-0112/0113\nFasilitas coffee corner peserta pelatihan BUMN."
  },
  {
    "name": "BAST-2018-008: Mesin Custom Brass Java Heritage Club",
    "acc": "java_heritage",
    "date": "2018-12-08 16:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Sanremo Cafe Racer 3-Group Custom Brass\nSerial: SR-CR-2018-0007\nInstalasi private bar lounge selesai."
  },
  {
    "name": "BAST-2019-009: Mesin Espresso Kedai Kopi Jalanan Malang",
    "acc": "kopi_jalanan",
    "date": "2019-04-05 15:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Appia Life 2G Compact\nSerial: NS-AL-2019-0301\nKedai mahasiswa jalan Soekarno Hatta."
  },
  {
    "name": "BAST-2019-010: Peralatan Cupping Lab Suhat Roasters",
    "acc": "suhat_roaster",
    "date": "2019-06-18 11:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Ikawa Sample Roaster Pro V3 + Acaia Scales\nSerial: IKW-PRO-2019-0044\nLaboratorium evaluasi specialty coffee."
  },
  {
    "name": "BAST-2019-011: Mesin Komersial Ijen View Resort Banyuwangi",
    "acc": "banyuwangi_resort",
    "date": "2019-12-20 14:30:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Aurelia Wave 2G\nSerial: NS-AW-2019-0455\nRestoran utama pemandangan Gunung Ijen."
  },
  {
    "name": "BAST-2020-012: Mesin Touchless RS Mitra Sejahtera Sidoarjo",
    "acc": "rs_mitra",
    "date": "2020-05-12 10:30:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Franke A600 FoamMaster Touchless\nSerial: FR-A600-2020-0089\nFasilitas pantry higienis dokter spesialis."
  },
  {
    "name": "BAST-2020-013: Mesin Kopi Dokter Spesialis Siloam Hospital",
    "acc": "siloam_surabaya",
    "date": "2020-07-22 14:00:00",
    "desc": "Unit: WMF 1100S Touchless\nSerial: WMF-1100-2020-0044\nInstalasi ruang dokter lantai 3 Siloam Surabaya."
  },
  {
    "name": "BAST-2020-014: Mesin High Volume Kopi Drive-Thru Merdeka",
    "acc": "kopi_darurat",
    "date": "2020-12-18 16:00:00",
    "desc": "Unit: Nuova Simonelli Aurelia Wave 3G\nSerial: NS-AW3G-2020-0512\nKapasitas seduh tinggi 400 cup/hari jalur drive-thru."
  },
  {
    "name": "BAST-2021-015: Mesin Aurelia Wave Unit Kedua Grand Majapahit",
    "acc": "majapahit",
    "date": "2021-07-20 15:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Aurelia Wave 2G\nSerial: NS-AW2G-2021-0881\nPenambahan unit restoran lantai 2."
  },
  {
    "name": "BAST-2021-016: Mesin Espresso Cabang 2 Titik Temu Surabaya Barat",
    "acc": "titik_temu",
    "date": "2021-11-22 14:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Victoria Arduino Eagle One 2G\nSerial: VA-E1-2021-104\nOutlet cabang baru Surabaya Barat."
  },
  {
    "name": "BAST-2022-017: Mesin Sky Bar Bromo Terrace Hotel",
    "acc": "bromo_terrace",
    "date": "2022-10-25 15:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Appia Life 2G Heavy Duty\nSerial: NS-AL-2022-0612\nInstalasi sky bar ketinggian 1.800 mdpl."
  },
  {
    "name": "BAST-2023-018: Mesin Kafe Aksara Co-Working Rungkut",
    "acc": "aksara",
    "date": "2023-03-01 14:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Appia Life 1-Group\nSerial: NS-AL1G-2023-0711\nCo-working space mahasiswa Surabaya Timur."
  },
  {
    "name": "BAST-2024-019: Mesin Espresso Kopi Luwak Seminyak Bali",
    "acc": "aroma_bali",
    "date": "2024-03-01 11:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: La Marzocco Linea Classic S 2G\nSerial: LM-LC-2024-0099\nEspresso bar turis internasional Bali."
  },
  {
    "name": "BAST-2025-020: Mesin D Java Bistro & Bakery Madiun",
    "acc": "djava_bistro",
    "date": "2025-09-12 14:00:00",
    "desc": "BERITA ACARA SERAH TERIMA\nUnit: Nuova Simonelli Appia Life Compact 2G\nSerial: NS-ALC-2025-0899\nBistro & bakery modern Madiun."
  },
  {
    "name": "PMR Tahunan 2017 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2017-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nDescaling boiler, ganti safety valve, ganti gasket grouphead."
  },
  {
    "name": "PMR Tahunan 2018 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2018-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nOverhaul solenoid valve, kalibrasi sensor level air, uji pompa 9 bar."
  },
  {
    "name": "PMR Tahunan 2019 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2019-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nFlushing heat exchanger, penggantian shower screen IMS, uji kestabilan suhu PID."
  },
  {
    "name": "PMR Tahunan 2020 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2020-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nPerawatan menyeluruh saat okupansi rendah, proteksi komponen elektronik."
  },
  {
    "name": "PMR Tahunan 2021 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2021-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nPemeriksaan unit 1 & unit 2, penggantian seal uap anti-vacuum."
  },
  {
    "name": "PMR Tahunan 2022 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2022-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nDescaling boiler ganda, inspeksi kelistrikan kontaktor pemanas."
  },
  {
    "name": "PMR Tahunan 2023 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2023-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nKalibrasi water level probe, flushing volumetrik flowmeter."
  },
  {
    "name": "PMR Tahunan 2024 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2024-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nOverhaul pompa rotary, penggantian shower screen, pembersihan nozzle steam."
  },
  {
    "name": "PMR Tahunan 2025 Grand Majapahit Hotel",
    "acc": "majapahit",
    "date": "2025-06-30 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nInspeksi 3 unit mesin espresso (Resto, Lounge, Banquet). Seluruh komponen prima."
  },
  {
    "name": "PMR Berkala 2017 Kopi Titik Temu Darmo",
    "acc": "titik_temu",
    "date": "2017-10-20 11:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nPenggantian gasket silikon dan shower screen grouphead."
  },
  {
    "name": "PMR Tahunan 2019 Kopi Titik Temu Darmo",
    "acc": "titik_temu",
    "date": "2019-10-20 11:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nDescaling boiler uap dan kalibrasi gilingan flat burr grinder."
  },
  {
    "name": "PMR Tahunan 2021 Kopi Titik Temu Multi-Outlet",
    "acc": "titik_temu",
    "date": "2021-11-10 11:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nInspeksi mesin cabang 1 Darmo dan cabang 2 Surabaya Barat."
  },
  {
    "name": "PMR Tahunan 2023 Kopi Titik Temu Multi-Outlet",
    "acc": "titik_temu",
    "date": "2023-11-10 11:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nPemeriksaan 3 outlet, penggantian seal steam wand dan kalibrasi flowmeter."
  },
  {
    "name": "PMR Tahunan 2025 Kopi Titik Temu Multi-Outlet",
    "acc": "titik_temu",
    "date": "2025-11-10 11:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nOverhaul berkala seluruh unit Victoria Arduino, performa ekstraksi 100% konsisten."
  },
  {
    "name": "PMR Tahunan 2018 Senja Roastery Gubeng",
    "acc": "senja",
    "date": "2018-03-20 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nPengecekan elemen pemanas dan kalibrasi termometer analog."
  },
  {
    "name": "PMR Tahunan 2021 Senja Roastery Gubeng",
    "acc": "senja",
    "date": "2021-03-20 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nDescaling boiler dan overhaul steam tap lever."
  },
  {
    "name": "PMR Tahunan 2024 Senja Roastery Gubeng",
    "acc": "senja",
    "date": "2024-03-20 10:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nPenggantian vacuum valve dan uji tekanan pompa 9 bar."
  },
  {
    "name": "PMR Tahunan 2025 Batu Highland Resort",
    "acc": "batu_highland",
    "date": "2025-10-15 11:00:00",
    "desc": "PREVENTIVE MAINTENANCE REPORT\nOverhaul water softener pegunungan dan sanitasi boiler banquet hall."
  },
  {
    "name": "Laporan Cupping Panen Perdana Arabika Ijen 2016",
    "acc": "majapahit",
    "date": "2016-08-15 11:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 84.5 (Dark Chocolate, Roasted Almond, Sweet Molasses). Majapahit Signature Espresso."
  },
  {
    "name": "Laporan Cupping Specialty Harvest 2017 Senja Roastery",
    "acc": "senja",
    "date": "2017-09-18 10:30:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 85.0 (Brown Sugar, Red Cherry, Lime Zest). Disetujui alokasi 1.5 ton."
  },
  {
    "name": "Laporan Cupping Java Dampit Robusta Fine 2018",
    "acc": "rawon_kalkun",
    "date": "2018-07-20 14:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 82.5 (Bold Body, Cacao Nibs, Nutty, Low Acidity). Ideal untuk es kopi susu tradisional."
  },
  {
    "name": "Laporan Cupping Micro-Lot Ijen Honey 2019",
    "acc": "suhat_roaster",
    "date": "2019-08-12 11:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 86.0 (Peach, Floral Jasmine, Honeycomb). Kuota roastery Suhat Malang disepakati."
  },
  {
    "name": "Laporan Cupping Robusta Dampit Monsooned 2020",
    "acc": "kopi_darurat",
    "date": "2020-09-15 15:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 83.0 (Dark Cocoa, Toast, Heavy Crema). Stabil untuk susu UHT rasio tinggi."
  },
  {
    "name": "Laporan Cupping Majapahit Blend Harvest 2021",
    "acc": "majapahit",
    "date": "2021-08-15 11:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 85.0 (Milk Chocolate, Orange Peel, Caramel). Konsumsi kuartal 4 disetujui F&B Director."
  },
  {
    "name": "Laporan Cupping Ijen Anaerobic Natural 2022",
    "acc": "senja",
    "date": "2022-10-14 11:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 87.25 (Winey, Blackberry, Jasmine, Brown Sugar). Alokasi panen 2 ton dikontrak."
  },
  {
    "name": "Laporan Cupping Argopuro Natural Anaerob 2022",
    "acc": "kediri_roastery",
    "date": "2022-11-25 14:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 86.5 (Mango, Dried Apricot, Dark Rum). Batch perdana Kediri Roastery Hub."
  },
  {
    "name": "Laporan Cupping Specialty Ijen Harvest 2023",
    "acc": "titik_temu",
    "date": "2023-09-10 10:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 86.75 (Strawberry, Black Tea, Caramelized Sugar). Alokasi house blend 3 cabang."
  },
  {
    "name": "Laporan Cupping Dampit Highland Robusta 2023",
    "acc": "heritage_club",
    "date": "2023-10-15 15:30:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 83.5 (Spicy Clove, Dark Chocolate, Velvety Finish). Komponen signature blend lounge."
  },
  {
    "name": "Laporan Cupping Bali Espresso Bar Supply 2024",
    "acc": "aroma_bali",
    "date": "2024-09-28 11:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 86.0 (Tamarind, Sweet Toffee, Citrus). Pengiriman batch pertama ke Seminyak."
  },
  {
    "name": "Laporan Cupping Kopi Pacet Welirang 2024",
    "acc": "mojokerto_cafe",
    "date": "2024-11-25 14:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 83.0 (Nutty, Palm Sugar, Full Body). Karakter lokal kopi Majapahit."
  },
  {
    "name": "Laporan Cupping Panen Raya Jawa Timur 2025",
    "acc": "senja",
    "date": "2025-10-22 10:30:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 87.0 (Red Apple, Caramel, Milk Chocolate). Perpanjangan suplai green bean 2026 disepakati."
  },
  {
    "name": "Laporan Cupping Majapahit Blend Harvest 2025",
    "acc": "majapahit",
    "date": "2025-11-05 11:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 85.5 (Hazelnut, Dried Plum, Dark Cocoa). Alokasi 180 kg/bulan tahun 2026."
  },
  {
    "name": "Laporan Cupping Kolonial Blend Tugu Hotel 2025",
    "acc": "tugu_hotel",
    "date": "2025-08-10 14:00:00",
    "desc": "EVALUASI CUPPING TAHUNAN\nScore: 84.75 (Spiced Molasses, Cedar, Dark Chocolate). Blend khusus tamu VIP hotel butik."
  },
  {
    "name": "Addendum Kontrak Pasokan Majapahit 2017",
    "acc": "majapahit",
    "date": "2017-06-12 14:00:00",
    "desc": "Perpanjangan kontrak suplai biji kopi blend tahun ke-2 diskon volume 3%."
  },
  {
    "name": "Addendum Kontrak Pasokan Titik Temu 2017",
    "acc": "titik_temu",
    "date": "2017-10-15 15:00:00",
    "desc": "Peningkatan kuota pengiriman menjadi 100 kg/bln seiring lonjakan traffic kedai."
  },
  {
    "name": "Perjanjian SLA Maintenance Darurat Petrokimia 2018",
    "acc": "petrokimia",
    "date": "2018-05-20 11:00:00",
    "desc": "Komitmen tanggap darurat teknisi maksimal 1x24 jam untuk ruang tamu direksi BUMN."
  },
  {
    "name": "Addendum Kontrak Pasokan Majapahit 2019",
    "acc": "majapahit",
    "date": "2019-06-15 14:00:00",
    "desc": "Penyesuaian kuota 130 kg/bulan dan penyediaan teknisi standby saat acara KTT regional."
  },
  {
    "name": "Relaksasi Tagihan Pasokan Kopi Pandemi 2020",
    "acc": "majapahit",
    "date": "2020-04-20 10:00:00",
    "desc": "Restrukturisasi termin pembayaran 60 hari mendukung ketahanan operasional hotel bintang 5."
  },
  {
    "name": "Addendum Kontrak Pasokan RS Mitra Sejahtera 2020",
    "acc": "rs_mitra",
    "date": "2020-05-20 11:30:00",
    "desc": "Penambahan kuota biji kopi 40 kg/bulan untuk 3 posko tenaga medis penanganan darurat."
  },
  {
    "name": "Addendum Multi-Outlet Kontrak Titik Temu 2021",
    "acc": "titik_temu",
    "date": "2021-11-25 15:00:00",
    "desc": "Konsolidasi penagihan bulanan untuk cabang Darmo dan Surabaya Barat."
  },
  {
    "name": "Addendum Pasokan Rutin Senja Roastery 2022",
    "acc": "senja",
    "date": "2022-03-15 14:00:00",
    "desc": "Komitmen alokasi green bean specialty 200 kg/bulan dengan harga indeks panen stabil."
  },
  {
    "name": "Addendum Kontrak Pasokan Korporasi Petrokimia 2023",
    "acc": "petrokimia",
    "date": "2023-12-10 14:00:00",
    "desc": "Pembaruan kontrak tahunan pasokan lounge direksi dengan garansi spare part penuh."
  },
  {
    "name": "Addendum Kontrak Pasokan Ciputra Golf Club 2023",
    "acc": "ciputra_golf",
    "date": "2023-08-05 11:00:00",
    "desc": "Penyediaan booth kopi bergerak saat turnamen golf internasional."
  },
  {
    "name": "Addendum Pasokan Biji Kopi Bali 2024",
    "acc": "aroma_bali",
    "date": "2024-10-05 16:00:00",
    "desc": "Jadwal ekspedisi laut tetap setiap tanggal 5 dan 20 setiap bulannya."
  },
  {
    "name": "Addendum Multi-Outlet Titik Temu 2025",
    "acc": "titik_temu",
    "date": "2025-11-15 15:00:00",
    "desc": "Pembaruan kontrak pasokan 4 outlet (Darmo, Barat, Sidoarjo, MERR) diskon 8%."
  },
  {
    "name": "Addendum Perpanjangan Kontrak Majapahit 2025",
    "acc": "majapahit",
    "date": "2025-11-20 14:00:00",
    "desc": "Kontrak pasokan 3 unit mesin espresso dan jaminan ketersediaan barista trainer."
  },
  {
    "name": "Notulensi Kunjungan Negosiasi Addendum Titik Temu 2026",
    "acc": "titik_temu",
    "date": "2026-11-08 16:00:00",
    "desc": "Pertemuan dengan Danang Prasetyo. Disepakati addendum 2027 kuota 160 kg/bulan."
  },
  {
    "name": "Spesifikasi Teknis Pengadaan Grinder Kopi Sudut 2026",
    "acc": "sudut_kota",
    "date": "2026-10-20 14:30:00",
    "desc": "Proposal grinder Eureka Olympus 75E High Speed single origin bar Clara Shinta."
  },
  {
    "name": "Profil Uji Coba Kopi Kedai Kontainer Sidoarjo 2026",
    "acc": "kopi_kontainer",
    "date": "2026-02-18 13:00:00",
    "desc": "Uji rasa formula Robusta 65% : Arabika 35% sangat disukai target anak muda Sidoarjo."
  },
  {
    "name": "Catatan Kebutuhan Pantry Eksekutif Intiland Tower 2026",
    "acc": "intiland_tower",
    "date": "2026-01-28 11:00:00",
    "desc": "Rencana penempatan mesin bean-to-cup touch screen di pantry eksekutif lantai 18."
  },
  {
    "name": "Riwayat Servis Darurat: Steam Wand Grand Majapahit 2026",
    "acc": "majapahit",
    "date": "2026-11-09 11:30:00",
    "desc": "Teknisi darurat dikirim ke lokasi hotel. Dilakukan penggantian anti-vacuum valve."
  },
  {
    "name": "Catatan Peluang Ekspansi Kemitraan Komunitas Blitar 2026",
    "acc": "blitar_hub",
    "date": "2026-03-02 14:00:00",
    "desc": "Potensi kemitraan micro-roasting menghubungkan 15 kedai kopi independen Blitar."
  },
  {
    "name": "Catatan Teknis Jalur Air Kios Royal Plaza 2026",
    "acc": "royal_plaza",
    "date": "2026-04-18 10:30:00",
    "desc": "Konfirmasi penempatan pompa galon otomatis di bawah meja counter kios."
  }
]
JSON
, true);

foreach ($notesData as $nd) {
    $noteBean = find_or_create(
        'Notes',
        ['name' => $nd['name']],
        [
            'name' => $nd['name'],
            'parent_type' => 'Accounts',
            'parent_id' => $accounts[$nd['acc']]->id,
            'description' => $nd['desc'],
        ],
        $nd['date']
    );

    if ($noteBean->load_relationship('accounts')) {
        $noteBean->accounts->add($accounts[$nd['acc']]->id);
    }
    if (isset($contacts[$nd['acc']]) && $contacts[$nd['acc']]->load_relationship('notes')) {
        $contacts[$nd['acc']]->notes->add($noteBean->id);
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

echo "\n=== Seeding Summary (10-Year B2B Pure CRM) ===\n";
foreach ($stats as $mod => $cnts) {
    echo sprintf("  %-15s : Created=%-3d  Skipped/Updated=%-3d\n", $mod, $cnts['created'], $cnts['skipped']);
}
echo "=== PT Sumber Kopi Nusantara 10-Year CRM Seeder Completed Successfully ===\n";
