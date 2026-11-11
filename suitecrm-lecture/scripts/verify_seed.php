<?php
/**
 * Verification Script for PT Sumber Kopi Nusantara SuiteCRM 8 B2B 10-Year Seed Data
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

echo "=== Verifying PT Sumber Kopi Nusantara 10-Year B2B CRM Lifecycle Data ===\n";

// ---------------------------------------------------------------------------
// 1. Users
// ---------------------------------------------------------------------------
$cntUsers = sql_scalar("SELECT COUNT(*) FROM users WHERE deleted = 0 AND user_name IN ('bima', 'citra')");
check_assert($cntUsers === 2, "Seeded Users count (bima, citra) == 2", "actual={$cntUsers}");

// ---------------------------------------------------------------------------
// 2. Campaigns (9 across 2016-2026)
// ---------------------------------------------------------------------------
$cntCampaigns = sql_scalar("SELECT COUNT(*) FROM campaigns WHERE deleted = 0");
check_assert($cntCampaigns >= 9, "Campaigns count across 10-Year timeline >= 9", "actual={$cntCampaigns}");

// ---------------------------------------------------------------------------
// 3. Accounts (45)
// ---------------------------------------------------------------------------
$cntAccounts = sql_scalar("SELECT COUNT(*) FROM accounts WHERE deleted = 0");
check_assert($cntAccounts >= 45, "Seeded Accounts count >= 45 (3x volume)", "actual={$cntAccounts}");

// ---------------------------------------------------------------------------
// 4. Contacts (45)
// ---------------------------------------------------------------------------
$cntContacts = sql_scalar("SELECT COUNT(*) FROM contacts WHERE deleted = 0");
check_assert($cntContacts >= 45, "Seeded Contacts count >= 45 (3x volume)", "actual={$cntContacts}");

// ---------------------------------------------------------------------------
// 5. Leads (18)
// ---------------------------------------------------------------------------
$cntLeads = sql_scalar("SELECT COUNT(*) FROM leads WHERE deleted = 0");
check_assert($cntLeads >= 18, "Leads count >= 18", "actual={$cntLeads}");

// ---------------------------------------------------------------------------
// 6. Opportunities (54)
// ---------------------------------------------------------------------------
$cntOpps = sql_scalar("SELECT COUNT(*) FROM opportunities WHERE deleted = 0");
check_assert($cntOpps >= 54, "Seeded Opportunities count >= 54 (3x volume across 10 years)", "actual={$cntOpps}");

// ---------------------------------------------------------------------------
// 7. Calls (54: 1 Overdue Planned + 8 Planned Dashboard + 45 Held Historical)
// ---------------------------------------------------------------------------
$cntCalls = sql_scalar("SELECT COUNT(*) FROM calls WHERE deleted = 0");
check_assert($cntCalls >= 54, "Calls count >= 54 (1 overdue + planned dashboard + historical)", "actual={$cntCalls}");

// ---------------------------------------------------------------------------
// 8. Meetings (30: 5 Planned Dashboard + 25 Held Historical)
// ---------------------------------------------------------------------------
$cntMeetings = sql_scalar("SELECT COUNT(*) FROM meetings WHERE deleted = 0");
check_assert($cntMeetings >= 30, "Meetings count >= 30 (planned dashboard + historical)", "actual={$cntMeetings}");

// ---------------------------------------------------------------------------
// 9. Tasks (24: 6 Open for Dashboard + 18 Completed Historical)
// ---------------------------------------------------------------------------
$cntTasks = sql_scalar("SELECT COUNT(*) FROM tasks WHERE deleted = 0");
check_assert($cntTasks >= 24, "Tasks count >= 24 (6 open for dashboard + completed)", "actual={$cntTasks}");

// ---------------------------------------------------------------------------
// 10. Cases (24: 4 Active in 2026 + 20 Closed Historical)
// ---------------------------------------------------------------------------
$cntCases = sql_scalar("SELECT COUNT(*) FROM cases WHERE deleted = 0");
check_assert($cntCases >= 24, "Cases count >= 24 (4 active for dashboard + 20 closed)", "actual={$cntCases}");

// ---------------------------------------------------------------------------
// 11. Notes (78: Full 10-Year Timeline: BAST, PMRs, Cupping, Milestones)
// ---------------------------------------------------------------------------
$cntNotes = sql_scalar("SELECT COUNT(*) FROM notes WHERE deleted = 0");
check_assert($cntNotes >= 78, "Notes count >= 78 (full 10-year timeline BAST & PMR)", "actual={$cntNotes}");

// ---------------------------------------------------------------------------
// 12. Relationship Integrity: accounts_opportunities
// ---------------------------------------------------------------------------
$unlinkedOpps = sql_scalar("
    SELECT COUNT(*)
    FROM opportunities o
    LEFT JOIN accounts_opportunities ao
        ON ao.opportunity_id = o.id AND ao.deleted = 0
    LEFT JOIN accounts a
        ON a.id = ao.account_id AND a.deleted = 0
    WHERE o.deleted = 0 AND a.id IS NULL
");
check_assert(
    $unlinkedOpps === 0,
    "Every seeded Opportunity is linked to an Account in accounts_opportunities",
    "unlinked={$unlinkedOpps}"
);

// ---------------------------------------------------------------------------
// 13. Relationship Integrity: accounts_contacts
// ---------------------------------------------------------------------------
$unlinkedContacts = sql_scalar("
    SELECT COUNT(*)
    FROM contacts c
    LEFT JOIN accounts_contacts ac
        ON ac.contact_id = c.id AND ac.deleted = 0
    LEFT JOIN accounts a
        ON a.id = ac.account_id AND a.deleted = 0
    WHERE c.deleted = 0 AND a.id IS NULL
");
check_assert(
    $unlinkedContacts === 0,
    "Every seeded Contact is linked to an Account in accounts_contacts",
    "unlinked={$unlinkedContacts}"
);

// ---------------------------------------------------------------------------
// 14. Relationship Integrity: cases -> accounts
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
// 15. Relationship Integrity: tasks -> accounts
// ---------------------------------------------------------------------------
$unlinkedTasks = sql_scalar("
    SELECT COUNT(*)
    FROM tasks t
    LEFT JOIN accounts a ON a.id = t.parent_id AND a.deleted = 0 AND t.parent_type = 'Accounts'
    WHERE t.deleted = 0 AND a.id IS NULL
");
check_assert(
    $unlinkedTasks === 0,
    "All Tasks are linked to a valid Account",
    "unlinked={$unlinkedTasks}"
);

// ---------------------------------------------------------------------------
// 16. Campaign -> Lead attribution
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
// 17. Win / Loss Analytics Readiness
// ---------------------------------------------------------------------------
$cntWon = sql_scalar("SELECT COUNT(*) FROM opportunities WHERE deleted = 0 AND sales_stage = 'Closed Won'");
$cntLost = sql_scalar("SELECT COUNT(*) FROM opportunities WHERE deleted = 0 AND sales_stage = 'Closed Lost'");
check_assert($cntWon >= 30, "At least 30 Opportunities in Closed Won (10-year repeat business proof)", "won={$cntWon}");
check_assert($cntLost >= 8, "At least 8 Opportunities in Closed Lost (Realistic sales loss analytics)", "lost={$cntLost}");

// ---------------------------------------------------------------------------
// 18. Probability Consistency across Sales Stages
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
    "SELECT name, sales_stage, probability FROM opportunities WHERE deleted = 0"
);
$probMismatches = [];
foreach ($oppRows as $row) {
    $stage = $row['sales_stage'] ?? '';
    $prob = (int)round((float)($row['probability'] ?? -1));
    if (isset($expectedProbMap[$stage]) && $expectedProbMap[$stage] !== $prob) {
        $probMismatches[] = "{$row['name']} ({$stage} => {$prob})";
    }
}
check_assert(
    count($probMismatches) === 0,
    "All seeded Opportunities have probability consistent with sales_stage",
    count($probMismatches) === 0 ? "matched" : implode('; ', array_slice($probMismatches, 0, 5))
);

// ---------------------------------------------------------------------------
// 19. Overdue Planned Call assigned to admin (id=1)
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
    $overdueAdminCalls >= 1,
    "At least 1 Planned Call is overdue (date_start < UTC now) and assigned to admin",
    "actual={$overdueAdminCalls}, utc_now={$utcNow}"
);

// ---------------------------------------------------------------------------
// 20. Dashboard Active Pipeline & Activities (Non-Empty Dashboard Guarantee!)
// ---------------------------------------------------------------------------
$activePlannedCalls = sql_scalar("SELECT COUNT(*) FROM calls WHERE deleted = 0 AND status = 'Planned'");
$activePlannedMeetings = sql_scalar("SELECT COUNT(*) FROM meetings WHERE deleted = 0 AND status = 'Planned'");
$activeTasks = sql_scalar("SELECT COUNT(*) FROM tasks WHERE deleted = 0 AND status IN ('Not Started', 'In Progress')");
$activeCases = sql_scalar("SELECT COUNT(*) FROM cases WHERE deleted = 0 AND status LIKE 'Open%'");
$activeOpps = sql_scalar("SELECT COUNT(*) FROM opportunities WHERE deleted = 0 AND sales_stage NOT IN ('Closed Won', 'Closed Lost')");

check_assert(
    $activePlannedCalls >= 5 && $activePlannedMeetings >= 3 && $activeTasks >= 4 && $activeCases >= 3 && $activeOpps >= 4,
    "Dashboard populated with active records (My Calls, My Meetings, My Tasks, My Cases, Open Opps)",
    "calls={$activePlannedCalls}, meetings={$activePlannedMeetings}, tasks={$activeTasks}, cases={$activeCases}, opps={$activeOpps}"
);

// ---------------------------------------------------------------------------
// 21. 360-degree view on Grand Majapahit Hotel
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
// 22. 10-Year Timeline Span Verification (2016 through 2026)
// ---------------------------------------------------------------------------
$oppYears = sql_rows("
    SELECT DISTINCT SUBSTRING(date_closed, 1, 4) AS yr
    FROM opportunities
    WHERE deleted = 0
    ORDER BY yr ASC
");
$distinctYears = array_filter(array_column($oppYears, 'yr'));
$requiredYears = ['2016', '2017', '2018', '2019', '2020', '2021', '2022', '2023', '2024', '2025', '2026'];
$missingYears = array_diff($requiredYears, $distinctYears);
check_assert(
    count($missingYears) === 0,
    "Opportunities span all 10 years (2016 through 2026)",
    "years=" . implode(',', $distinctYears)
);

// ---------------------------------------------------------------------------
// 23. 10-Year Loyal Veteran Account Verification (Grand Majapahit & Titik Temu)
// ---------------------------------------------------------------------------
$majapahitNotes = sql_scalar("
    SELECT COUNT(*)
    FROM notes n
    INNER JOIN accounts a ON a.id = n.parent_id AND a.deleted = 0
    WHERE n.deleted = 0 AND a.name = 'Grand Majapahit Hotel Surabaya'
");
check_assert(
    $majapahitNotes >= 10,
    "Grand Majapahit Hotel has multi-year historical notes & PMR reports (10-year client)",
    "notes_count={$majapahitNotes}"
);

// ---------------------------------------------------------------------------
// 24. Dropdown key validity against $app_list_strings
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
    ['table' => 'tasks', 'col' => 'status', 'dom' => 'task_status_dom', 'allow_empty' => false],
    ['table' => 'tasks', 'col' => 'priority', 'dom' => 'task_priority_dom', 'allow_empty' => false],
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
    count($invalidDropdownValues) === 0 ? "all valid" : implode('; ', array_slice($invalidDropdownValues, 0, 5))
);

// ---------------------------------------------------------------------------
// 25. Currency Pure IDR (Rp) Verification (No USD $)
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

echo "\nVERIFY RESULT: ALL PASS (25/25 PASS)\n";
exit(0);
