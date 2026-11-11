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
" + json.dumps(campaigns, indent=2) + "
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
// 3. Accounts (45) & Contacts (45) - 10-Year Portfolio (2016-2026)
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
" + json.dumps(accounts, indent=2) + "
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
" + json.dumps(leads, indent=2) + "
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
" + json.dumps(opps, indent=2) + "
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
// 6. Calls (54 Calls: 1 Overdue Planned admin + 8 Planned Dashboard + 45 Held)
// ---------------------------------------------------------------------------
$callStatusPlanned = must_key('call_status_dom', 'Planned');
$callStatusHeld = must_key('call_status_dom', 'Held');
$callDirOutbound = must_key('call_direction_dom', 'Outbound');
$callDirInbound = must_key('call_direction_dom', 'Inbound');

$callsData = json_decode(<<<'JSON'
" + json.dumps(calls, indent=2) + "
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
" + json.dumps(meetings, indent=2) + "
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
" + json.dumps(tasks, indent=2) + "
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
" + json.dumps(cases, indent=2) + "
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
" + json.dumps(notes, indent=2) + "
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
