<?php
/**
 * Verification Script for PT Sumber Kopi Nusantara SuiteCRM 8 B2B 5-Year Seed Data
 * Exits 0 ONLY if every assertion passes; exits 1 otherwise.
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

$root = getenv('SUITECRM_ROOT') ?: '/bitnami/suitecrm';
if (!is_dir($root . '/public/legacy')) {
    fwrite(STDERR, "FAIL: Invalid SUITECRM_ROOT or missing public/legacy: {$root}\n");
    exit(1);
}
chdir($root . '/public/legacy');
require_once 'include/entryPoint.php';

global $current_user, $app_list_strings, $timedate, $current_language, $sugar_config, $db;

$current_user = BeanFactory::getBean('Users', '1');
if (empty($current_user->id)) {
    echo "FAIL: Admin user id=1 not found\n";
    exit(1);
}

if (empty($current_language)) {
    $current_language = $sugar_config['default_language'] ?? 'en_us';
}
if (empty($app_list_strings) || !is_array($app_list_strings)) {
    $app_list_strings = return_app_list_strings_language($current_language);
}

$failures = 0;

function check_assert(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    $suffix = $detail !== '' ? " ({$detail})" : '';
    if ($condition) {
        echo "PASS: {$label}{$suffix}\n";
    } else {
        echo "FAIL: {$label}{$suffix}\n";
        $failures++;
    }
}

function sql_scalar(string $sql): int
{
    global $db;
    $res = $db->query($sql);
    if (!$res) {
        return -1;
    }
    $row = $db->fetchByAssoc($res);
    if (!$row) {
        return 0;
    }
    return (int)reset($row);
}

function sql_rows(string $sql): array
{
    global $db;
    $rows = [];
    $res = $db->query($sql);
    if (!$res) {
        return $rows;
    }
    while ($r = $db->fetchByAssoc($res, false)) {
        $rows[] = $r;
    }
    return $rows;
}

echo "=== Verifying PT Sumber Kopi Nusantara 5-Year CRM Lifecycle Data ===\n";

// ---------------------------------------------------------------------------
// 1. Users
// ---------------------------------------------------------------------------
$cntUsers = sql_scalar("SELECT COUNT(*) FROM users WHERE deleted = 0 AND user_name IN ('bima', 'citra')");
check_assert($cntUsers === 2, "Seeded Users count (bima, citra) == 2", "actual={$cntUsers}");

// ---------------------------------------------------------------------------
// 2. Campaigns (3 across 2022, 2024, 2026)
// ---------------------------------------------------------------------------
$expectedCampaigns = [
    'Pameran F&B Expo 2026 Surabaya',
    'East Java Coffee Festival 2024',
    'Surabaya Hospitality Fair 2022',
];
$quotedCamps = array_map(fn($n) => "'" . $db->quote($n) . "'", $expectedCampaigns);
$cntCampaigns = sql_scalar("SELECT COUNT(*) FROM campaigns WHERE deleted = 0 AND name IN (" . implode(',', $quotedCamps) . ")");
check_assert($cntCampaigns === 3, "Campaigns count across 5-Year timeline == 3", "actual={$cntCampaigns}");

// ---------------------------------------------------------------------------
// 3. Accounts (15)
// ---------------------------------------------------------------------------
$expectedAccounts = [
    'Grand Majapahit Hotel Surabaya',
    'Kopi Titik Temu Surabaya',
    'Senja Roastery & Eatery',
    'Batu Highland Resort & Convention',
    'PT Petrokimia Sentosa Mess & Lounge',
    'Kopi Sudut Kota Malang',
    'Surabaya Heritage Club & Lounge',
    'Kafe Aksara & Co-Working',
    'Rumah Sakit Mitra Sejahtera Cafeteria',
    'Aroma Kopi Luwak & Espresso Bar Bali',
    'D Java Bistro & Bakery Madiun',
    'Cakrawala University Student Lounge',
    'Kopi Kontainer Sidoarjo',
    'Gedung Perkantoran Intiland Tower Cafe',
    'Blitar Coffee Community Hub',
];
$quotedAccs = array_map(fn($n) => "'" . $db->quote($n) . "'", $expectedAccounts);
$cntAccounts = sql_scalar(
    "SELECT COUNT(*) FROM accounts WHERE deleted = 0 AND name IN (" . implode(',', $quotedAccs) . ")"
);
check_assert($cntAccounts === 15, "Seeded Accounts count == 15", "actual={$cntAccounts}");

// ---------------------------------------------------------------------------
// 4. Contacts (15)
// ---------------------------------------------------------------------------
$expectedContacts = [
    ['first_name' => 'Antonius', 'last_name' => 'Wibowo'],
    ['first_name' => 'Danang', 'last_name' => 'Prasetyo'],
    ['first_name' => 'Hendra', 'last_name' => 'Wijaya'],
    ['first_name' => 'Guntur', 'last_name' => 'Wicaksono'],
    ['first_name' => 'Bambang', 'last_name' => 'Soedirman'],
    ['first_name' => 'Clara', 'last_name' => 'Shinta'],
    ['first_name' => 'Ferry', 'last_name' => 'Gunawan'],
    ['first_name' => 'Nadia', 'last_name' => 'Kusuma'],
    ['first_name' => 'Ratna', 'last_name' => 'Sari'],
    ['first_name' => 'Made', 'last_name' => 'Suryawan'],
    ['first_name' => 'Rudi', 'last_name' => 'Hartono'],
    ['first_name' => 'Siti', 'last_name' => 'Rahmawati'],
    ['first_name' => 'Wahyu', 'last_name' => 'Pratama'],
    ['first_name' => 'Kevin', 'last_name' => 'Tanujaya'],
    ['first_name' => 'Agus', 'last_name' => 'Santoso'],
];
$quotedCons = array_map(
    fn($c) => "(first_name = '" . $db->quote($c['first_name']) . "' AND last_name = '" . $db->quote($c['last_name']) . "')",
    $expectedContacts
);
$cntContacts = sql_scalar(
    "SELECT COUNT(*) FROM contacts WHERE deleted = 0 AND (" . implode(' OR ', $quotedCons) . ")"
);
check_assert($cntContacts === 15, "Seeded Contacts count == 15", "actual={$cntContacts}");

// ---------------------------------------------------------------------------
// 5. Leads (6)
// ---------------------------------------------------------------------------
$cntLeads = sql_scalar("SELECT COUNT(*) FROM leads WHERE deleted = 0");
check_assert($cntLeads >= 6, "Leads count >= 6", "actual={$cntLeads}");

// ---------------------------------------------------------------------------
// 6. Opportunities (18)
// ---------------------------------------------------------------------------
$expectedOpps = [
    '[2021] Mesin Aurelia Majapahit',
    '[2021] Paket Mesin Titik Temu',
    '[2022] Mesin Appia Senja Roastery',
    '[2022] Pengadaan Mesin Batu Highland',
    '[2022] Mesin Espresso Resto Cabang',
    '[2023] Mesin Cabang 2 Titik Temu',
    '[2023] Mesin 3G Surabaya Heritage',
    '[2023] Kontrak Biji Petrokimia 2023',
    '[2024] Paket Mesin Kafe Aksara',
    '[2024] Mesin Kopi RS Mitra Sejahtera',
    '[2024] Tender Espresso Stasiun',
    '[2025] Mesin Aurelia Wave Majapahit',
    '[2025] Paket Mesin D Java Bistro',
    '[2026] Grinder Single Origin Malang',
    '[2026] Kontrak Biji Espresso Titik Temu',
    '[NEW BIZ] Paket Espresso Kopi Kontainer',
    '[RETENTION] Reaktivasi Kontrak Majapahit',
    '[LOST] Pengadaan Mesin Kopi Kampus C',
];
$quotedOpps = array_map(fn($n) => "'" . $db->quote($n) . "'", $expectedOpps);
$cntOpps = sql_scalar(
    "SELECT COUNT(*) FROM opportunities WHERE deleted = 0 AND name IN (" . implode(',', $quotedOpps) . ")"
);
check_assert($cntOpps === 18, "Seeded Opportunities count == 18", "actual={$cntOpps}");

// ---------------------------------------------------------------------------
// 7. Calls (18: 1 Overdue Planned + 17 Held Historical)
// ---------------------------------------------------------------------------
$cntCalls = sql_scalar("SELECT COUNT(*) FROM calls WHERE deleted = 0");
check_assert($cntCalls >= 18, "Calls count >= 18 (1 overdue + 17 historical)", "actual={$cntCalls}");

// ---------------------------------------------------------------------------
// 8. Meetings (10 Held Historical)
// ---------------------------------------------------------------------------
$cntMeetings = sql_scalar("SELECT COUNT(*) FROM meetings WHERE deleted = 0");
check_assert($cntMeetings >= 10, "Meetings count >= 10 (historical across 5 years)", "actual={$cntMeetings}");

// ---------------------------------------------------------------------------
// 9. Cases (8: 6 Closed Historical + 2 Active 2026)
// ---------------------------------------------------------------------------
$cntCases = sql_scalar("SELECT COUNT(*) FROM cases WHERE deleted = 0");
check_assert($cntCases >= 8, "Cases count >= 8 (6 historical closed + 2 active)", "actual={$cntCases}");

// ---------------------------------------------------------------------------
// 10. Notes (26: Full 5-Year Timeline)
// ---------------------------------------------------------------------------
$cntNotes = sql_scalar("SELECT COUNT(*) FROM notes WHERE deleted = 0");
check_assert($cntNotes >= 26, "Notes (5-Year BAST, PMR, Cupping & Contract Timeline) count >= 26", "actual={$cntNotes}");

// ---------------------------------------------------------------------------
// 11. Relationship Integrity: accounts_opportunities & accounts_contacts
// ---------------------------------------------------------------------------
$unlinkedOpps = sql_scalar("
    SELECT COUNT(*)
    FROM opportunities o
    LEFT JOIN accounts_opportunities ao
        ON ao.opportunity_id = o.id AND ao.deleted = 0
    LEFT JOIN accounts a
        ON a.id = ao.account_id AND a.deleted = 0
    WHERE o.deleted = 0 AND o.name IN (" . implode(',', $quotedOpps) . ") AND a.id IS NULL
");
check_assert(
    $cntOpps === 18 && $unlinkedOpps === 0,
    "Every seeded Opportunity is linked to an Account in accounts_opportunities",
    "unlinked={$unlinkedOpps}"
);

$unlinkedContacts = sql_scalar("
    SELECT COUNT(*)
    FROM contacts c
    LEFT JOIN accounts_contacts ac
        ON ac.contact_id = c.id AND ac.deleted = 0
    LEFT JOIN accounts a
        ON a.id = ac.account_id AND a.deleted = 0
    WHERE c.deleted = 0 AND (" . implode(' OR ', $quotedCons) . ") AND a.id IS NULL
");
check_assert(
    $cntContacts === 15 && $unlinkedContacts === 0,
    "Every seeded Contact is linked to an Account in accounts_contacts",
    "unlinked={$unlinkedContacts}"
);

// ---------------------------------------------------------------------------
// 12. Campaign -> Lead attribution
// ---------------------------------------------------------------------------
$campaignLeads = sql_scalar("
    SELECT COUNT(*)
    FROM leads l
    INNER JOIN campaigns c ON c.id = l.campaign_id AND c.deleted = 0
    WHERE l.deleted = 0 AND c.name = 'Pameran F&B Expo 2026 Surabaya'
");
check_assert(
    $campaignLeads >= 1,
    "At least 1 Lead has campaign_id = Pameran F&B Expo 2026 Surabaya",
    "matched={$campaignLeads}"
);

// ---------------------------------------------------------------------------
// 13. Win / Loss Analytics Readiness
// ---------------------------------------------------------------------------
$cntWon = sql_scalar("SELECT COUNT(*) FROM opportunities WHERE deleted = 0 AND sales_stage = 'Closed Won'");
$cntLost = sql_scalar("SELECT COUNT(*) FROM opportunities WHERE deleted = 0 AND sales_stage = 'Closed Lost'");
check_assert($cntWon >= 5, "At least 5 Opportunities in Closed Won (Repeat business proof)", "won={$cntWon}");
check_assert($cntLost >= 2, "At least 2 Opportunities in Closed Lost (Realistic sales loss analytics)", "lost={$cntLost}");

// ---------------------------------------------------------------------------
// 14. Probability Consistency across Sales Stages
// ---------------------------------------------------------------------------
$expectedProbMap = [
    'Prospecting' => 10,
    'Qualification' => 20,
    'Proposal/Price Quote' => 65,
    'Negotiation/Review' => 80,
    'Closed Won' => 100,
    'Closed Lost' => 0,
];
$oppRows = sql_rows(
    "SELECT name, sales_stage, probability FROM opportunities WHERE deleted = 0 AND name IN (" . implode(',', $quotedOpps) . ")"
);
$probMismatches = [];
foreach ($oppRows as $row) {
    $stage = $row['sales_stage'] ?? '';
    $prob = (int)round((float)($row['probability'] ?? -1));
    if (!isset($expectedProbMap[$stage]) || $expectedProbMap[$stage] !== $prob) {
        $probMismatches[] = "{$row['name']} ({$stage} => {$prob})";
    }
}
check_assert(
    count($oppRows) === 18 && count($probMismatches) === 0,
    "All seeded Opportunities have probability consistent with sales_stage",
    count($probMismatches) === 0 ? "18/18 matched" : implode('; ', $probMismatches)
);

// ---------------------------------------------------------------------------
// 15. Overdue Planned Call assigned to admin (id=1)
// ---------------------------------------------------------------------------
$utcNow = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$overdueAdminCalls = sql_scalar("
    SELECT COUNT(*)
    FROM calls
    WHERE deleted = 0
      AND status = 'Planned'
      AND date_start < '{$utcNow}'
      AND assigned_user_id = '1'
");
check_assert(
    $overdueAdminCalls === 1,
    "Exactly 1 Planned Call is overdue (date_start < UTC now) and assigned to admin",
    "actual={$overdueAdminCalls}, utc_now={$utcNow}"
);

// ---------------------------------------------------------------------------
// 16. Cases -> Account link
// ---------------------------------------------------------------------------
$unlinkedCases = sql_scalar("
    SELECT COUNT(*)
    FROM cases cs
    LEFT JOIN accounts a ON a.id = cs.account_id AND a.deleted = 0
    WHERE cs.deleted = 0 AND a.id IS NULL
");
check_assert(
    $unlinkedCases === 0,
    "All Cases are linked to a valid Account",
    "unlinked={$unlinkedCases}"
);

// ---------------------------------------------------------------------------
// 17. 360-degree view on Grand Majapahit Hotel (Case, Opps, Calls, Meetings, Notes)
// ---------------------------------------------------------------------------
$majapahit360Opps = sql_scalar("
    SELECT COUNT(*)
    FROM cases cs
    INNER JOIN accounts a ON a.id = cs.account_id AND a.deleted = 0
    INNER JOIN accounts_opportunities ao ON ao.account_id = a.id AND ao.deleted = 0
    INNER JOIN opportunities o ON o.id = ao.opportunity_id AND o.deleted = 0
    WHERE cs.deleted = 0 AND cs.name = '[URGENT] Tekanan Steam Wand Turun'
");
check_assert(
    $majapahit360Opps >= 2,
    "Account of Case '[URGENT] Tekanan Steam Wand Turun' (Grand Majapahit) has Opportunities (360 view)",
    "linked_opportunities={$majapahit360Opps}"
);

// ---------------------------------------------------------------------------
// 18. 5-Year Timeline Span Verification (2021, 2022, 2023, 2024, 2025, 2026)
// ---------------------------------------------------------------------------
$oppYears = sql_rows("
    SELECT DISTINCT SUBSTRING(date_closed, 1, 4) AS yr
    FROM opportunities
    WHERE deleted = 0 AND name IN (" . implode(',', $quotedOpps) . ")
    ORDER BY yr ASC
");
$distinctYears = array_filter(array_column($oppYears, 'yr'));
$hasAllYears = in_array('2021', $distinctYears, true)
    && in_array('2022', $distinctYears, true)
    && in_array('2023', $distinctYears, true)
    && in_array('2024', $distinctYears, true)
    && in_array('2025', $distinctYears, true)
    && in_array('2026', $distinctYears, true);
check_assert(
    $hasAllYears,
    "Opportunities span all 5 years (2021, 2022, 2023, 2024, 2025, 2026)",
    "years=" . implode(',', $distinctYears)
);

// ---------------------------------------------------------------------------
// 19. Loyal Veteran Account Verification (Grand Majapahit Hotel & Titik Temu)
// ---------------------------------------------------------------------------
$majapahitNotes = sql_scalar("
    SELECT COUNT(*)
    FROM notes n
    INNER JOIN accounts a ON a.id = n.parent_id AND a.deleted = 0
    WHERE n.deleted = 0 AND a.name = 'Grand Majapahit Hotel Surabaya'
");
check_assert(
    $majapahitNotes >= 5,
    "Grand Majapahit Hotel has multi-year historical notes & PMR reports (5-year client)",
    "notes_count={$majapahitNotes}"
);

// ---------------------------------------------------------------------------
// 20. Dropdown key validity against $app_list_strings
// ---------------------------------------------------------------------------
$dropdownChecks = [
    ['table' => 'accounts', 'col' => 'account_type', 'dom' => 'account_type_dom', 'allow_empty' => false],
    ['table' => 'accounts', 'col' => 'industry', 'dom' => 'industry_dom', 'allow_empty' => true],
    ['table' => 'leads', 'col' => 'lead_source', 'dom' => 'lead_source_dom', 'allow_empty' => true],
    ['table' => 'leads', 'col' => 'status', 'dom' => 'lead_status_dom', 'allow_empty' => false],
    ['table' => 'opportunities', 'col' => 'sales_stage', 'dom' => 'sales_stage_dom', 'allow_empty' => false],
    ['table' => 'opportunities', 'col' => 'lead_source', 'dom' => 'lead_source_dom', 'allow_empty' => true],
    ['table' => 'cases', 'col' => 'status', 'dom' => 'case_status_dom', 'allow_empty' => false],
    ['table' => 'cases', 'col' => 'priority', 'dom' => 'case_priority_dom', 'allow_empty' => false],
    ['table' => 'calls', 'col' => 'status', 'dom' => 'call_status_dom', 'allow_empty' => false],
    ['table' => 'meetings', 'col' => 'status', 'dom' => 'meeting_status_dom', 'allow_empty' => false],
    ['table' => 'campaigns', 'col' => 'status', 'dom' => 'campaign_status_dom', 'allow_empty' => false],
    ['table' => 'campaigns', 'col' => 'campaign_type', 'dom' => 'campaign_type_dom', 'allow_empty' => false],
];

$invalidDropdownValues = [];
foreach ($dropdownChecks as $chk) {
    $dom = $chk['dom'];
    if (!isset($app_list_strings[$dom]) || !is_array($app_list_strings[$dom])) {
        $invalidDropdownValues[] = "missing_dom:{$dom}";
        continue;
    }
    $rows = sql_rows("SELECT {$chk['col']} AS val FROM {$chk['table']} WHERE deleted = 0");
    foreach ($rows as $r) {
        $val = (string)($r['val'] ?? '');
        if ($val === '' && $chk['allow_empty']) {
            continue;
        }
        if (!array_key_exists($val, $app_list_strings[$dom])) {
            $invalidDropdownValues[] = "{$chk['table']}.{$chk['col']}='{$val}' not in {$dom}";
        }
    }
}
check_assert(
    count($invalidDropdownValues) === 0,
    "All seeded dropdown values exist in \$app_list_strings",
    count($invalidDropdownValues) === 0 ? "all valid" : implode('; ', $invalidDropdownValues)
);

// ---------------------------------------------------------------------------
// 21. Currency Pure IDR (Rp) Verification (No USD $)
// ---------------------------------------------------------------------------
$idrCurId = sql_rows("SELECT id, symbol FROM currencies WHERE iso4217 = 'IDR' AND deleted = 0");
$hasIdr = !empty($idrCurId) && ($idrCurId[0]['symbol'] ?? '') === 'Rp';
$nonIdrOpps = sql_scalar("
    SELECT COUNT(*)
    FROM opportunities
    WHERE deleted = 0
      AND (currency_id != '{$idrCurId[0]['id']}' OR currency_id IS NULL OR currency_id = '-99')
");
$cfgIso = $sugar_config['default_currency_iso4217'] ?? '';
$cfgSym = $sugar_config['default_currency_symbol'] ?? '';

check_assert(
    $hasIdr && $nonIdrOpps === 0 && $cfgIso === 'IDR' && $cfgSym === 'Rp',
    "Pure IDR Currency (Rp) active across System Config & all Opportunities (zero USD)",
    "config={$cfgIso}/{$cfgSym}, non_idr_opps={$nonIdrOpps}"
);

if ($failures > 0) {
    echo "\nVERIFY RESULT: FAILED ({$failures} assertion(s) failed)\n";
    exit(1);
}

echo "\nVERIFY RESULT: ALL PASS (21/21 PASS)\n";
exit(0);
