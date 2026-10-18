<?php
/**
 * Projects & Programmes Module
 * Ministry of Health — State Department for Medical Services (PP-SDOMS)
 * Native PHP 8.1+ & MySQL (No Node.js)
 */
require_once __DIR__ . '/../database/db.php';
require_auth();

$page_title = 'Projects & Programmes — State Department for Medical Services';
$active_tab = 'projects';

$flash_msg = '';
$view_detail_id = (int)($_GET['id'] ?? 0);
$is_detail_view = ($view_detail_id > 0);

// Add Project Form Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit()) {
    $token = $_POST['csrf_token'] ?? '';
    if (verify_csrf_token($token)) {
        $sub_prog = trim($_POST['sub_programme'] ?? '');
        $project_code = trim($_POST['project_code'] ?? '');
        $project_type = trim($_POST['project_type'] ?? 'Project');
            $prog_title = trim($_POST['programme_title'] ?? '');
            $cat = $_POST['category'] ?? 'Directorate';
            $agency = trim($_POST['agency_name'] ?? '');
            $tier = $_POST['priority_tier'] ?? 'Tier 1 - High Priority';
            $total_budget = (float)($_POST['total_budget'] ?? 0);
            $status = $_POST['status'] ?? 'In Progress';
            $start_date = $_POST['start_date'] ?? date('Y-m-d');
            $end_date = $_POST['end_date'] ?? date('Y-m-d', strtotime('+1 year'));
            $desc = trim($_POST['description'] ?? '');

        if (!empty($sub_prog)) {
            if ($db_connected && $pdo) {
                try {
                        $stmt = $pdo->prepare("
                            INSERT INTO projects (programme_title, sub_programme, project_code, project_type, category, directorate_name, saga_name, delivery_unit, priority_tier, total_budget, status, start_date, end_date, description)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $prog_title,
                            $sub_prog,
                            $project_code !== '' ? $project_code : null,
                            $project_type,
                            $cat,
                            $cat === 'Directorate' ? $agency : null,
                            $cat === 'Saga' ? $agency : null,
                            $agency,
                            $tier,
                            $total_budget,
                            $status,
                            $start_date,
                            $end_date,
                            $desc
                        ]);
                        log_audit_trail('CREATE', 'Projects', $pdo->lastInsertId(), "Created project: $sub_prog");
                        $flash_msg = 'Project successfully added to portfolio.';
                    } catch (Exception $e) {
                        $flash_msg = 'Database error: ' . $e->getMessage();
                    }
                } else {
                    $flash_msg = 'Database connection unavailable; project was not saved.';
                }
            }
    }
}

// Multi-Filter parameters
$filter_status = $_GET['status'] ?? 'All';
$filter_saga = $_GET['saga'] ?? 'All';
$filter_dir = $_GET['dir'] ?? 'All';
$filter_div = $_GET['div'] ?? 'All';
$filter_du = $_GET['du'] ?? 'All';
$filter_tier = $_GET['tier'] ?? 'All';
$search_q = trim($_GET['q'] ?? '');
$gantt_year = (int)($_GET['year'] ?? 0);
$gantt_month = trim((string)($_GET['month'] ?? ''));
if ($gantt_month !== 'all' && (!ctype_digit($gantt_month) || (int)$gantt_month < 1 || (int)$gantt_month > 12)) $gantt_month = '0';
$gantt_year_options = range(2022, 2027);
if (!in_array($gantt_year, $gantt_year_options, true)) $gantt_year = 0;
$gantt_directorate = trim($_GET['gantt_directorate'] ?? 'All');
$gantt_saga = trim($_GET['gantt_saga'] ?? 'All');
$monthly_gantt = [];
$available_months = [];
$gantt_directorates = [];
$gantt_divisions = [];
$gantt_sagas = [];
$filter_directorates = [];
$filter_divisions = [];
$filter_sagas = [];
$saga_filter_aliases = [];
$filter_delivery_units = [];
$filter_tiers = [];
$detail_monthly_records = [];
$annual_key_output_records = [];
$annual_key_output_groups = [];
$detail_annual_key_outputs = [];
$portfolio_progress_records = [];
$project_key_output_records = [];
$project_total_count = null;
$project_load_error = false;
$registered_directorates = [];
$registered_sagas = [];

// Load the canonical SAGA names and their legacy spellings before applying
// the portfolio filter so older project rows still match the registered list.
if ($db_connected && $pdo) {
    try { $registered_directorates = $pdo->query("SELECT name FROM directorates ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $registered_directorates = []; }
    try {
        $registered_sagas = $pdo->query("SELECT name, code FROM sagas ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($registered_sagas as $registered_saga) {
            $name = (string)$registered_saga['name'];
            $aliases = [$name, (string)$registered_saga['code']];
            if (preg_match('/^(.*?)\\s*\\(([^()]*)\\)\\s*$/u', $name, $match)) {
                $aliases[] = trim($match[1]);
                $aliases[] = trim($match[2]);
            }
            $saga_filter_aliases[$name] = array_values(array_unique(array_filter($aliases, static fn($v) => $v !== '')));
        }
    } catch (Exception $e) { $registered_sagas = []; }
}

$projects = [];
if ($db_connected && $pdo) {
    try {
        $project_total_count = (int)$pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        $sql = "SELECT * FROM projects WHERE 1=1";
        $params = [];
        if ($filter_status !== 'All') {
            $sql .= " AND (status = ? OR LOWER(status) = LOWER(?))";
            $params[] = $filter_status;
            $params[] = $filter_status;
        }
        if ($filter_saga !== 'All') {
            $aliases = $saga_filter_aliases[$filter_saga] ?? [$filter_saga];
            $sql .= " AND saga_name IN (" . implode(',', array_fill(0, count($aliases), '?')) . ")";
            array_push($params, ...$aliases);
        }
        if ($filter_dir !== 'All') {
            $sql .= " AND directorate_name = ?";
            $params[] = $filter_dir;
        }
        if ($filter_div !== 'All') {
            $sql .= " AND division_name = ?";
            $params[] = $filter_div;
        }
        if ($filter_du !== 'All') {
            $sql .= " AND delivery_unit = ?";
            $params[] = $filter_du;
        }
        if ($filter_tier !== 'All') {
            $sql .= " AND priority_tier = ?";
            $params[] = $filter_tier;
        }
        if ($search_q !== '') {
            $sql .= " AND (sub_programme LIKE ? OR programme_title LIKE ? OR description LIKE ? OR project_code LIKE ?)";
            $params[] = "%$search_q%";
            $params[] = "%$search_q%";
            $params[] = "%$search_q%";
            $params[] = "%$search_q%";
        }
        $sql .= " ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $projects = $stmt->fetchAll();
    } catch (Exception $e) { $project_load_error = true; }
}

// Single Project Detail Selection
$current_project = null;
if ($is_detail_view && $db_connected && $pdo) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$view_detail_id]);
        $current_project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $current_project = null;
    }
}

// Load Gantt filter choices independently from monthly progress. The dropdowns
// must remain populated even when monthly_progress has no rows or is unavailable.
if ($db_connected && $pdo) {
    try {
        $project_directorates = $pdo->query("SELECT DISTINCT directorate_name FROM projects WHERE directorate_name IS NOT NULL AND TRIM(directorate_name) <> '' ORDER BY directorate_name")->fetchAll(PDO::FETCH_COLUMN);
        $gantt_directorates = array_values(array_unique(array_merge($registered_directorates, $project_directorates)));
        natcasesort($gantt_directorates); $gantt_directorates = array_values($gantt_directorates);
    } catch (Exception $e) { $gantt_directorates = []; }
    // Use the official organogram hierarchy for the menus, then merge existing
    // project assignments so legacy records remain available in the filters.
    try {
        $gantt_divisions = $pdo->query("SELECT dir.name AS directorate_name, div.name AS division_name
            FROM divisions div INNER JOIN directorates dir ON dir.id = div.directorate_id
            ORDER BY dir.name, div.name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $gantt_divisions = []; }
    try {
        $project_divisions = $pdo->query("SELECT DISTINCT directorate_name, division_name FROM projects
            WHERE directorate_name IS NOT NULL AND TRIM(directorate_name) <> ''
              AND division_name IS NOT NULL AND TRIM(division_name) <> ''
            ORDER BY directorate_name, division_name")->fetchAll(PDO::FETCH_ASSOC);
        $division_pairs = [];
        foreach (array_merge($gantt_divisions, $project_divisions) as $division) {
            $pair_key = strtolower(trim((string)$division['directorate_name']) . "\0" . trim((string)$division['division_name']));
            if (!isset($division_pairs[$pair_key])) $division_pairs[$pair_key] = $division;
        }
        $gantt_divisions = array_values($division_pairs);
        usort($gantt_divisions, static function($a, $b) {
            return strnatcasecmp($a['directorate_name'], $b['directorate_name']) ?: strnatcasecmp($a['division_name'], $b['division_name']);
        });
    } catch (Exception $e) { /* Keep the official division list if legacy rows cannot be loaded. */ }
    try {
        $project_sagas = $pdo->query("SELECT DISTINCT saga_name FROM projects WHERE saga_name IS NOT NULL AND TRIM(saga_name) <> '' ORDER BY saga_name")->fetchAll(PDO::FETCH_COLUMN);
        $gantt_sagas = array_column($registered_sagas, 'name');
        $known_saga_bases = array_map(static function($name) { return strtolower(trim(preg_replace('/\\s*\\([^()]*\\)\\s*$/u', '', (string)$name))); }, $gantt_sagas);
        foreach ($project_sagas as $project_saga) {
            $base = strtolower(trim(preg_replace('/\\s*\\([^()]*\\)\\s*$/u', '', (string)$project_saga)));
            if (!in_array($base, $known_saga_bases, true)) $gantt_sagas[] = $project_saga;
        }
        natcasesort($gantt_sagas); $gantt_sagas = array_values(array_unique($gantt_sagas));
        foreach ($registered_sagas as $registered_saga) {
            $name = (string)$registered_saga['name'];
            $aliases = [$name, (string)$registered_saga['code']];
            if (preg_match('/^(.*?)\\s*\\(([^()]*)\\)\\s*$/u', $name, $match)) {
                $aliases[] = trim($match[1]);
                $aliases[] = trim($match[2]);
            }
            $saga_filter_aliases[$name] = array_values(array_unique(array_filter($aliases, static fn($v) => $v !== '')));
        }
    } catch (Exception $e) { $gantt_sagas = []; }
    $filter_directorates = $gantt_directorates;
    $filter_divisions = array_values(array_unique(array_column($gantt_divisions, 'division_name')));
    $filter_sagas = $gantt_sagas;
    try { $filter_delivery_units = $pdo->query("SELECT DISTINCT delivery_unit FROM projects WHERE delivery_unit IS NOT NULL AND TRIM(delivery_unit) <> '' ORDER BY delivery_unit")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $filter_delivery_units = []; }
    try { $filter_tiers = $pdo->query("SELECT DISTINCT priority_tier FROM projects WHERE priority_tier IS NOT NULL AND TRIM(priority_tier) <> '' ORDER BY priority_tier")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $filter_tiers = []; }
}

// Load monthly progress when available, but always build the chart rows from
// the same database-backed project list used by the table below.
$monthly_progress_by_project = [];
if ($db_connected && $pdo) {
    try {
        $available_months = $pdo->query("SELECT DISTINCT `year_month` FROM monthly_progress ORDER BY `year_month` DESC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $available_months = []; }
}
if ($gantt_year < 1 && !empty($available_months)) {
    $latest_year = (int)substr($available_months[0], 0, 4);
    $gantt_year = in_array($latest_year, $gantt_year_options, true) ? $latest_year : min(max((int)date('Y'), 2022), 2027);
}
if ($gantt_year < 1) $gantt_year = min(max((int)date('Y'), 2022), 2027);
if ($gantt_month === '0' && !empty($available_months)) {
    $latest_month = $available_months[0];
    if ((int)substr($latest_month, 0, 4) === $gantt_year) $gantt_month = (string)(int)substr($latest_month, 5, 2);
}
if ($gantt_month === '0') $gantt_month = (string)(int)date('n');

if ($db_connected && $pdo) {
    try {
        $progress_sql = "SELECT project_id, AVG(monthly_total) AS monthly_progress FROM (
            SELECT project_id, `year_month`, SUM(progress_pct) AS monthly_total
            FROM monthly_progress WHERE `year_month` ";
        $progress_params = [];
        if ($gantt_month === 'all') {
            $progress_sql .= "LIKE ?";
            $progress_params[] = sprintf('%04d-%%', $gantt_year);
        } else {
            $progress_sql .= "= ?";
            $progress_params[] = sprintf('%04d-%02d', $gantt_year, (int)$gantt_month);
        }
        $progress_sql .= " GROUP BY project_id, `year_month`) AS monthly_totals GROUP BY project_id";
        $stmt = $pdo->prepare($progress_sql);
        $stmt->execute($progress_params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $monthly_progress_by_project[(int)$record['project_id']] = (float)$record['monthly_progress'];
        }
    } catch (Exception $e) { $monthly_progress_by_project = []; }

    try {
        $output_sql = "SELECT project_id, `year_month`, kpi_code, kpi_name, AVG(progress_pct) AS progress_pct, MAX(weight) AS weight
            FROM monthly_progress WHERE `year_month` ";
        $output_params = [];
        if ($gantt_month === 'all') {
            $output_sql .= "LIKE ?";
            $output_params[] = sprintf('%04d-%%', $gantt_year);
        } else {
            $output_sql .= "= ?";
            $output_params[] = sprintf('%04d-%02d', $gantt_year, (int)$gantt_month);
        }
        $output_sql .= " GROUP BY project_id, `year_month`, kpi_code, kpi_name ORDER BY kpi_code, kpi_name";
        $stmt = $pdo->prepare($output_sql);
        $stmt->execute($output_params);
        $portfolio_progress_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $portfolio_progress_records = []; }

    if ($is_detail_view) {
        try {
            $stmt = $pdo->prepare("SELECT `year_month`, kpi_code, kpi_name, weight, progress_pct, notes FROM monthly_progress WHERE project_id = ? ORDER BY `year_month`, kpi_code");
            $stmt->execute([$view_detail_id]);
            $detail_monthly_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $detail_monthly_records = []; }
    }
}

if ($db_connected && $pdo && !$is_detail_view && !empty($projects)) {
    try {
        $projectIds = array_map('intval', array_column($projects, 'id'));
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $outputSql = "SELECT project_id, `year_month`, kpi_code, kpi_name, progress_pct, weight, notes
            FROM monthly_progress WHERE project_id IN ($placeholders) AND `year_month` ";
        $outputParams = $projectIds;
        if ($gantt_month === 'all') {
            $outputSql .= "LIKE ?";
            $outputParams[] = sprintf('%04d-%%', $gantt_year);
        } else {
            $outputSql .= "= ?";
            $outputParams[] = sprintf('%04d-%02d', $gantt_year, (int)$gantt_month);
        }
        $outputSql .= " ORDER BY project_id, kpi_name, `year_month`, kpi_code";
        $stmt = $pdo->prepare($outputSql);
        $stmt->execute($outputParams);
        $project_key_output_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $project_key_output_records = []; }
}

// Annual KNH key-output results live in their own project-output dataset.
// They are not Performance Contract KPIs or monthly_progress submissions.
if ($db_connected && $pdo) {
    try {
        $annual_key_output_records = $pdo->query("SELECT scope_type, scope_name, output_code, output_title,
                kpi_code, kpi_name, unit, direction, fiscal_year, target_value,
                actual_value, target_type, source_note
            FROM project_key_output_results
            ORDER BY scope_type, scope_name, output_code, kpi_code, fiscal_year")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Older installations can run the migration and import without
        // blocking the monthly project views.
        $annual_key_output_records = [];
    }
}

foreach ($annual_key_output_records as $annual_record) {
    $scope_key = $annual_record['scope_type'].'|'.$annual_record['scope_name'];
    $output_key = $annual_record['output_code'].'|'.$annual_record['output_title'];
    $kpi_code = $annual_record['kpi_code'];
    if (!isset($annual_key_output_groups[$scope_key][$output_key][$kpi_code])) {
        $annual_key_output_groups[$scope_key][$output_key][$kpi_code] = [
            'output_code' => $annual_record['output_code'],
            'output_title' => $annual_record['output_title'],
            'kpi_code' => $kpi_code,
            'kpi_name' => $annual_record['kpi_name'],
            'unit' => $annual_record['unit'],
            'direction' => $annual_record['direction'],
            'years' => [],
            'achievement_pct' => null,
        ];
    }
    $annual_key_output_groups[$scope_key][$output_key][$kpi_code]['years'][$annual_record['fiscal_year']] = [
        'target_value' => $annual_record['target_value'],
        'actual_value' => $annual_record['actual_value'],
        'target_type' => $annual_record['target_type'],
    ];
    if ($annual_record['fiscal_year'] === '2025/26' && $annual_record['actual_value'] !== null && (float)$annual_record['target_value'] > 0) {
        $target = (float)$annual_record['target_value'];
        $actual = (float)$annual_record['actual_value'];
        $achievement = $annual_record['direction'] === 'lower'
            ? ($actual <= 0 ? 100.0 : ($target / $actual) * 100)
            : ($actual / $target) * 100;
        $annual_key_output_groups[$scope_key][$output_key][$kpi_code]['achievement_pct'] = round($achievement, 1);
    }
}

if ($current_project) {
    $detail_scope_keys = [
        'SAGA|'.(string)($current_project['saga_name'] ?? ''),
        'Delivery Unit|'.(string)($current_project['delivery_unit'] ?? ''),
        'Project|'.(string)($current_project['project_code'] ?? ''),
    ];
    foreach ($detail_scope_keys as $scope_key) {
        if (isset($annual_key_output_groups[$scope_key])) {
            $detail_annual_key_outputs = $annual_key_output_groups[$scope_key];
            break;
        }
    }
    // Match legacy project SAGA names such as "Kenyatta National Hospital"
    // with registered annual results labeled "Kenyatta National Hospital (KNH)".
    if (!$detail_annual_key_outputs && !empty($current_project['saga_name'])) {
        $project_saga_base = strtolower(trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', (string)$current_project['saga_name'])));
        foreach ($annual_key_output_groups as $scope_key => $output_groups) {
            if (strpos($scope_key, 'SAGA|') !== 0) continue;
            $scope_name = substr($scope_key, 5);
            $scope_saga_base = strtolower(trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', $scope_name)));
            if ($scope_saga_base === $project_saga_base) {
                $detail_annual_key_outputs = $output_groups;
                break;
            }
        }
    }
}

$project_key_output_groups = [];
foreach ($project_key_output_records as $output_record) {
    $project_key_output_groups[(int)$output_record['project_id']][$output_record['kpi_code'].'|'.$output_record['kpi_name']][] = $output_record;
}
$knh_annual_output_groups = $annual_key_output_groups['SAGA|Kenyatta National Hospital'] ?? [];
$portfolio_has_knh_context = false;
foreach ($projects as $project) {
    if (strcasecmp(trim((string)($project['saga_name'] ?? '')), 'Kenyatta National Hospital') === 0) {
        $portfolio_has_knh_context = true;
        break;
    }
}
$key_output_tab_count = array_sum(array_map('count', $project_key_output_groups));
if ($portfolio_has_knh_context) $key_output_tab_count += array_sum(array_map('count', $knh_annual_output_groups));
$knh_output_chart_payloads = [];

foreach ($projects as $project) {
    $project_id = (int)$project['id'];
    $project_division = (string)($project['division_name'] ?? '');
    if ($gantt_directorate !== 'All') {
        if (strpos($gantt_directorate, 'division:') === 0) {
            $division_filter = explode('|', substr($gantt_directorate, 9), 2);
            if (($project['directorate_name'] ?? '') !== ($division_filter[0] ?? '') || $project_division !== ($division_filter[1] ?? '')) continue;
        } elseif (($project['directorate_name'] ?? '') !== substr($gantt_directorate, 12)) {
            continue;
        }
    }
    if ($gantt_saga !== 'All' && !in_array((string)($project['saga_name'] ?? ''), $saga_filter_aliases[$gantt_saga] ?? [$gantt_saga], true)) continue;

    $has_monthly_progress = array_key_exists($project_id, $monthly_progress_by_project);
    if ($has_monthly_progress) {
        $project_progress = $monthly_progress_by_project[$project_id];
        $progress_source = $gantt_month === 'all' ? 'Monthly data for selected year' : 'Monthly data for selected month';
    } elseif (isset($project['overall_progress_pct']) && $project['overall_progress_pct'] !== null) {
        $project_progress = (float)$project['overall_progress_pct'];
        $progress_source = 'Project record';
    } else {
        $project_progress = null;
        $progress_status = trim((string)($project['progress_status'] ?? ''));
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $progress_status, $matches)) {
            $project_progress = (float)$matches[1];
        } elseif ((float)($project['milestones_total'] ?? 0) > 0) {
            $project_progress = 100 * (float)($project['milestones_completed'] ?? 0) / (float)$project['milestones_total'];
        } elseif ((float)($project['total_budget'] ?? 0) > 0) {
            $project_progress = 100 * (float)($project['funds_disbursed'] ?? 0) / (float)$project['total_budget'];
        } else {
            $project_progress = 0.0;
        }
        $progress_source = 'Project record';
    }
    $project['monthly_progress'] = $project_progress;
    $project['progress_source'] = $progress_source;
    $project['color'] = $project['color'] ?? '#1976d2';
    $monthly_gantt[] = $project;
}
$detail_progress_pct = null;
if ($current_project) {
    if (isset($current_project['overall_progress_pct']) && $current_project['overall_progress_pct'] !== null) $detail_progress_pct = (float)$current_project['overall_progress_pct'];
    elseif (preg_match('/^\s*(\d+(?:\.\d+)?)\s*%?\s*$/', (string)($current_project['progress_status'] ?? ''), $detailProgressMatch)) $detail_progress_pct = (float)$detailProgressMatch[1];
    if ($detail_progress_pct !== null) $detail_progress_pct = max(0, min(100, $detail_progress_pct));
}
if ($gantt_year < 1) $gantt_year = min(max((int)date('Y'), 2022), 2027);
if ($gantt_month === '0') $gantt_month = (string)(int)date('n');

require_once __DIR__ . '/header.php';
?>

<div style="display: flex; flex-direction: column; gap: 24px;">

<?php if ($is_detail_view && $current_project): ?>
    <!-- ================= SINGLE PROJECT DETAIL VIEW ================= -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <!-- Back Navigation & Action Bar -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <a href="/modules/projects.php" style="background: white; border: 1px solid #cbd5e1; color: #1e293b; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                &larr; Back to Projects Portfolio
            </a>

            <a href="/modules/monthly_progress.php" style="background:#087f5b;color:#fff;text-decoration:none;padding:7px 14px;border-radius:6px;font-size:.8rem;font-weight:700;">Manage Project Key Output Progress</a>
        </div>

        <!-- Project Header and Status Badge -->
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h1 style="font-size: 1.4rem; font-weight: 800; color: #0f172a; margin: 0 0 10px;">
                <?= htmlspecialchars($current_project['sub_programme']) ?>
            </h1>

            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 16px;">
                <span style="background: #16a34a; color: white; padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 800;">
                    <?= htmlspecialchars($current_project['status']) ?>
                </span>
                <span style="background: #dbeafe; color: #1d4ed8; padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 800;">
                    <?= htmlspecialchars($current_project['priority_tier'] ?? 'Tier 1 - High Priority') ?>
                </span>
            </div>

            <!-- VIEW DETAILS Toggle Button -->
            <button onclick="var d=document.getElementById('projectDetailsGrid'); d.style.display=(d.style.display==='none'?'block':'none');" style="background: #1976d2; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <span>VIEW DETAILS &darr;</span>
            </button>

            <!-- Collapsible Project Metadata Grid -->
            <div id="projectDetailsGrid" style="display: none; margin-top: 18px; padding: 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; font-size: 0.8rem;">
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">PROGRAMME TITLE</div>
                        <div style="font-weight: 800; color: #1d4ed8; margin-top: 4px;"><?= htmlspecialchars($current_project['programme_title']) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">CATEGORY</div>
                        <div style="font-weight: 800; color: #1d4ed8; margin-top: 4px;"><?= htmlspecialchars($current_project['category']) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">DELIVERY UNIT</div>
                        <div style="font-weight: 800; color: #1d4ed8; margin-top: 4px;"><?= htmlspecialchars($current_project['delivery_unit'] ?? 'Ministry of Health') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">TOTAL BUDGET</div>
                        <div style="font-weight: 800; color: #1d4ed8; font-family: 'JetBrains Mono', monospace; margin-top: 4px;"><?= format_kes($current_project['total_budget']) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">START DATE</div>
                        <div style="font-weight: 800; color: #1d4ed8; margin-top: 4px;"><?= htmlspecialchars($current_project['start_date']) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">END DATE</div>
                        <div style="font-weight: 800; color: #1d4ed8; margin-top: 4px;"><?= htmlspecialchars($current_project['end_date']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Detail Grid -->
        <div style="display: grid; grid-template-columns: 1fr 1.4fr; gap: 20px;">
            <!-- Left Column: Action & Remarks -->
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <h3 style="font-size: 0.85rem; font-weight: 800; color: #0f172a; margin: 0 0 10px;">Action:</h3>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; font-size: 0.8rem; color: #334155; line-height: 1.6; min-height: 120px;">
                        <?= nl2br(htmlspecialchars($current_project['description'])) ?>
                    </div>
                    <div style="margin-top: 14px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem; font-weight: 800;">
                            <span>Overall Project Progress: <?= $detail_progress_pct === null ? 'Not recorded' : number_format($detail_progress_pct, 1).'%' ?></span>
                        </div>
                        <div style="background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden; margin-top: 6px;">
                            <?php if ($detail_progress_pct !== null): ?><div style="background: #334155; height: 100%; width: <?= $detail_progress_pct ?>%;"></div><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <h3 style="font-size: 0.85rem; font-weight: 800; color: #0f172a; margin: 0 0 10px;">Remarks:</h3>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; font-size: 0.8rem; color: #334155; line-height: 1.6;">
                        Structural fitouts and medical gas telemetry testing proceeding according to revised quarterly milestone timelines.
                    </div>
                </div>
            </div>

            <!-- Right Column: Financial Bar Graph & Monthly Progress Statistics Horizontal Gantt Chart -->
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <?php
                $finance_total = isset($current_project['total_budget']) ? (float)$current_project['total_budget'] : null;
                $finance_utilized = isset($current_project['funds_utilized']) ? (float)$current_project['funds_utilized'] : null;
                $finance_gok = isset($current_project['gok_financing']) ? (float)$current_project['gok_financing'] : null;
                $finance_foreign = isset($current_project['foreign_financing']) ? (float)$current_project['foreign_financing'] : null;
                $finance_outstanding = isset($current_project['funds_outstanding']) && $current_project['funds_outstanding'] !== null
                    ? (float)$current_project['funds_outstanding']
                    : (($finance_total !== null && $finance_utilized !== null) ? max(0, $finance_total - $finance_utilized) : null);
                $finance_chart_data = [
                    'Total Budget' => $finance_total,
                    'GOK Financing' => $finance_gok,
                    'Foreign Financing' => $finance_foreign,
                    'Funds Utilized' => $finance_utilized,
                    'Funds Outstanding' => $finance_outstanding,
                ];
                $finance_has_data = count(array_filter($finance_chart_data, static fn($value) => $value !== null)) > 0;
                ?>
                <!-- Project Finance Graph -->
                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:8px;">
                        <div>
                            <h3 style="font-size:.9rem;font-weight:800;color:#0f172a;margin:0;">Financial Data (Amount in Millions)</h3>
                            <span style="font-size:.7rem;color:#64748b;">Project financial values from the database.</span>
                        </div>
                        <span style="font-size:.68rem;font-weight:700;color:#475569;background:#f1f5f9;border-radius:5px;padding:4px 7px;white-space:nowrap;">KES millions</span>
                    </div>
                    <?php if ($finance_has_data): ?><div style="height:280px;position:relative;"><canvas id="projectFinanceChart" aria-label="Project financial data in KES millions"></canvas></div><?php else: ?><div style="padding:24px;text-align:center;color:#64748b;background:#f8fafc;border-radius:6px;">No financial records are available for this project.</div><?php endif; ?>
                    <script src="/assets/js/chart.min.js"></script>
                    <script>
                    (() => {
                        const canvas = document.getElementById('projectFinanceChart');
                        if (!canvas || typeof Chart === 'undefined') return;
                        const labels = <?= json_encode(array_keys($finance_chart_data), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
                        const values = <?= json_encode(array_values($finance_chart_data), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>.map(v => v === null ? null : Number(v) / 1000000);
                        const colors = ['#245789','#4f8fc2','#72b6d8','#70ad5a','#ed8b24'];
                        const valueLabels = {
                            id: 'projectFinanceValueLabels',
                            afterDatasetsDraw(chart) {
                                const {ctx} = chart;
                                ctx.save();
                                ctx.font = '700 12px Arial';
                                ctx.fillStyle = '#111827';
                                ctx.textAlign = 'center';
                                chart.getDatasetMeta(0).data.forEach((bar, index) => {
                                    if (values[index] === null) return;
                                    ctx.fillText(`${values[index].toLocaleString(undefined,{maximumFractionDigits:1})}M`, bar.x, bar.y - 7);
                                });
                                ctx.restore();
                            }
                        };
                        new Chart(canvas, {
                            type: 'bar',
                            data: {labels, datasets: [{label: 'KES millions', data: values, backgroundColor: colors, borderColor: colors, borderWidth: 1, borderRadius: 2, maxBarThickness: 58}]},
                            plugins: [valueLabels],
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: {legend: {display: false}, tooltip: {callbacks: {label: c => `KES ${Number(c.raw).toLocaleString(undefined,{maximumFractionDigits:2})}M`}}},
                                scales: {
                                    y: {beginAtZero: true, suggestedMax: Math.max(1, Math.ceil(Math.max(...values.filter(v => v !== null)) / 50) * 50), title: {display: true, text: 'Amount in KES millions'}, ticks: {callback: v => `${v}M`}, grid: {color: '#d1d5db'}},
                                    x: {grid: {display: false}, ticks: {maxRotation: 0, minRotation: 0}}
                                }
                            }
                        });
                    })();
                    </script>
                </div>

                <!-- Monthly Progress Statistics Horizontal Gantt Chart (Replaced Overall Statistics) -->
                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div>
                            <h3 style="font-size: 0.85rem; font-weight: 800; color: #0f172a; margin: 0;">
                                Project Key Output Progress (Horizontal Gantt Chart)
                            </h3>
                            <span style="font-size: 0.72rem; color: #64748b;">Monthly progress records linked to this project. Performance Contract KPIs are managed separately.</span>
                        </div>
                        <span style="font-size: 0.75rem; font-weight: 800; color: #0d47a1; background: #dbeafe; padding: 3px 8px; border-radius: 4px;">
                            <?= 'Cumulative: '.($detail_monthly_records ? htmlspecialchars($current_project['progress_status'] ?? 'Not recorded') : 'Not recorded') ?>
                        </span>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.75rem;">
                        <?php
                        $detail_months = [];
                        $category_codes = ['A','B','C','D','E','F'];
                        $month_groups = [];
                        foreach ($detail_monthly_records as $record) {
                            $month_key = $record['year_month'];
                            $code = strtoupper(substr($record['kpi_code'] ?? '', 0, 1));
                            if (!in_array($code, $category_codes, true)) continue;
                            if (!isset($month_groups[$month_key])) $month_groups[$month_key] = array_fill_keys($category_codes, 0.0);
                            $month_groups[$month_key][$code] += (float)$record['progress_pct'];
                        }
                        ksort($month_groups);
                        $previous_total = 0.0;
                        foreach ($month_groups as $month_key => $contributions) {
                            $total = array_sum($contributions);
                            $detail_months[] = ['m'=>date('M Y', strtotime($month_key.'-01')), 'a'=>$contributions['A'], 'b'=>$contributions['B'], 'c'=>$contributions['C'], 'd'=>$contributions['D'], 'e'=>$contributions['E'], 'f'=>$contributions['F'], 'monthly_gain'=>number_format($total-$previous_total,1), 'cumul'=>number_format($total,1)];
                            $previous_total = $total;
                        }
                        $detail_months = array_reverse($detail_months);
                        if (!$detail_months) echo '<div style="padding:16px;text-align:center;color:#64748b;background:#f8fafc;border-radius:6px;">No monthly progress submissions are recorded for this project. Annual KNH targets and FY 2025/26 actuals are separate and appear in the Annual Key Outputs section when available.</div>';
                        foreach ($detail_months as $dm):
                        ?>
                            <div style="display: grid; grid-template-columns: 80px 1fr 140px; align-items: center; gap: 10px;">
                                <strong style="font-family: 'JetBrains Mono', monospace; color: #334155;"><?= $dm['m'] ?></strong>
                                <div style="background: #f1f5f9; height: 18px; border-radius: 4px; overflow: hidden; display: flex; border: 1px solid #e2e8f0;">
                                    <div style="background: #1976d2; width: <?= ($dm['a']/100)*100 ?>%;" title="A: <?= $dm['a'] ?>%"></div>
                                    <div style="background: #ea580c; width: <?= ($dm['b']/100)*100 ?>%;" title="B: <?= $dm['b'] ?>%"></div>
                                    <div style="background: #16a34a; width: <?= ($dm['c']/100)*100 ?>%;" title="C: <?= $dm['c'] ?>%"></div>
                                    <div style="background: #7c3aed; width: <?= ($dm['d']/100)*100 ?>%;" title="D: <?= $dm['d'] ?>%"></div>
                                    <div style="background: #db2777; width: <?= ($dm['e']/100)*100 ?>%;" title="E: <?= $dm['e'] ?>%"></div>
                                    <div style="background: #64748b; width: <?= ($dm['f']/100)*100 ?>%;" title="F: <?= $dm['f'] ?>%"></div>
                                </div>
                                <div style="text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 0.72rem;">
                                    <span style="color: #10b981; font-weight: 700;"><?= $dm['monthly_gain'] ?></span>
                                    <span style="color: #0f172a; font-weight: 800; margin-left: 6px;">(Cumul: <?= $dm['cumul'] ?>)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display: flex; gap: 14px; margin-top: 12px; font-size: 0.7rem; color: #64748b; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                        <span><span style="display:inline-block;width:8px;height:8px;background:#1976d2;border-radius:2px;"></span> A - Financial Stewardship</span>
                        <span><span style="display:inline-block;width:8px;height:8px;background:#ea580c;border-radius:2px;"></span> B - Service Delivery</span>
                        <span><span style="display:inline-block;width:8px;height:8px;background:#16a34a;border-radius:2px;"></span> C - Core Mandate</span>
                        <span><span style="display:inline-block;width:8px;height:8px;background:#7c3aed;border-radius:2px;"></span> D - Presidential Directives</span>
                        <span><span style="display:inline-block;width:8px;height:8px;background:#db2777;border-radius:2px;"></span> E - Affirmative Procurement</span>
                        <span><span style="display:inline-block;width:8px;height:8px;background:#64748b;border-radius:2px;"></span> F - Cross-Cutting</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($detail_annual_key_outputs): ?>
        <section class="knh-annual-output-card">
            <div class="knh-annual-output-heading">
                <div>
                    <h3>KNH Key Outputs: Annual Targets and Actuals</h3>
                    <p>Hospital-wide SAGA results from the supplied workbook. Actuals are available for FY 2025/26; later years contain targets only.</p>
                </div>
                <span class="knh-output-scope">Kenyatta National Hospital</span>
            </div>
            <?php
            $annual_chart_rows = [];
            foreach ($detail_annual_key_outputs as $outputGroup) {
                foreach ($outputGroup as $kpi) {
                    if ($kpi['achievement_pct'] === null) continue;
                    $fy_actual = $kpi['years']['2025/26']['actual_value'] ?? null;
                    $fy_target = $kpi['years']['2025/26']['target_value'] ?? null;
                    $annual_chart_rows[] = [
                        'label' => $kpi['kpi_name'],
                        'output' => $kpi['output_title'],
                        'output_code' => $kpi['output_code'],
                        'achievement' => $kpi['achievement_pct'],
                        'actual' => $fy_actual,
                        'target' => $fy_target,
                        'unit' => $kpi['unit'],
                    ];
                }
            }
            ?>
            <?php if ($annual_chart_rows): ?>
                <div class="knh-annual-chart-wrap" style="height:<?= min(720, max(300, count($annual_chart_rows) * 38 + 90)) ?>px;">
                    <canvas id="knhAnnualKeyOutputChart" aria-label="KNH key-output actual achievement against FY 2025/26 targets"></canvas>
                </div>
                <script src="/assets/js/chart.min.js"></script>
                <script>
                (() => {
                    const rows = <?= json_encode($annual_chart_rows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
                    const colors = {'KNH-01':'#1d65b5','KNH-02':'#13845c','KNH-03':'#778397'};
                    const canvas = document.getElementById('knhAnnualKeyOutputChart');
                    if (!canvas || typeof Chart === 'undefined') return;
                    new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: rows.map(row => row.label),
                            datasets: [{
                                label: 'Achievement vs target',
                                data: rows.map(row => row.achievement),
                                backgroundColor: rows.map(row => colors[row.output_code] || '#64748b'),
                                borderRadius: 5,
                                maxBarThickness: 24
                            }]
                        },
                        options: {
                            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                            plugins: {
                                legend: {display: false},
                                tooltip: {callbacks: {label: item => {
                                    const row = rows[item.dataIndex];
                                    return `Actual: ${row.actual} ${row.unit} / Target: ${row.target} ${row.unit} (${Number(row.achievement).toFixed(1)}%)`;
                                }}}
                            },
                            scales: {
                                x: {beginAtZero: true, suggestedMax: Math.max(100, Math.ceil(Math.max(...rows.map(row => row.achievement)) / 20) * 20), title: {display: true, text: 'Achievement against FY 2025/26 target (%)'}, grid: {color: '#e2e8f0'}},
                                y: {grid: {display: false}, ticks: {font: {size: 11}}}
                            }
                        }
                    });
                })();
                </script>
            <?php endif; ?>
            <div class="knh-annual-table-wrap">
                <table class="knh-annual-table">
                    <thead><tr><th>Key Output</th><th>KPI</th><th>Unit</th><th>FY 2025/26 Target</th><th>FY 2025/26 Actual</th><th>Achievement</th><th>FY 2026/27 Baseline</th><th>FY 2027/28 Target</th><th>FY 2028/29 Target</th><th>FY 2029/30 Target</th></tr></thead>
                    <tbody>
                    <?php foreach ($detail_annual_key_outputs as $outputGroup): foreach ($outputGroup as $kpi):
                        $years = $kpi['years'];
                        $format_output_value = static function ($value) { return $value === null ? '—' : rtrim(rtrim(number_format((float)$value, 2, '.', ','), '0'), '.'); };
                    ?>
                        <tr>
                            <td><span class="knh-output-name"><?= htmlspecialchars($kpi['output_title']) ?></span></td>
                            <td><?= htmlspecialchars($kpi['kpi_name']) ?></td>
                            <td><?= htmlspecialchars($kpi['unit']) ?></td>
                            <td><?= $format_output_value($years['2025/26']['target_value'] ?? null) ?></td>
                            <td><?= $format_output_value($years['2025/26']['actual_value'] ?? null) ?></td>
                            <td><?= $kpi['achievement_pct'] === null ? '—' : number_format($kpi['achievement_pct'], 1).'%' ?></td>
                            <td><?= $format_output_value($years['2026/27']['target_value'] ?? null) ?></td>
                            <td><?= $format_output_value($years['2027/28']['target_value'] ?? null) ?></td>
                            <td><?= $format_output_value($years['2028/29']['target_value'] ?? null) ?></td>
                            <td><?= $format_output_value($years['2029/30']['target_value'] ?? null) ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- Project-specific Monthly Progress and Key Outputs -->
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 14px; margin-bottom: 16px;">
                <div>
                    <h3 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0;">
                        Monthly Project Progress &amp; Key Output Tracking
                    </h3>
                    <span style="font-size: 0.75rem; color: #64748b;">Project-linked monthly outputs, recorded independently from Performance Contract KPIs.</span>
                </div>
                <a href="/modules/monthly_progress.php" style="background:#087f5b;color:#fff;text-decoration:none;padding:8px 14px;border-radius:6px;font-size:.78rem;font-weight:700;">Add Monthly Output Progress</a>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin:4px 0 10px;">
                <div style="font-size:.72rem;color:#64748b;">Tracking actual vs target progress across monthly reporting cycles.</div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <label for="detailKpiFilter" style="font-size:.72rem;font-weight:700;color:#64748b;">Filter Key Output</label>
                    <select id="detailKpiFilter" style="min-width:220px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font-size:.78rem;">
                        <option value="All">All Project Key Outputs (Composite)</option>
                        <option value="A">A — Financial Stewardship (10%)</option>
                        <option value="B">B — Service Delivery (15%)</option>
                        <option value="C">C — Core Mandate (65%)</option>
                        <option value="D">D — Implementation of Presidential Directives (2%)</option>
                        <option value="E">E — Affirmative Action in Procurement (4%)</option>
                        <option value="F">F — Cross - Cutting (4%)</option>
                    </select>
                    <a href="/modules/monthly_progress.php" style="background:#087f5b;color:#fff;text-decoration:none;padding:8px 12px;border-radius:6px;font-size:.76rem;font-weight:700;">+ Add Monthly Progress</a>
                </div>
            </div>
            <div style="height:340px; margin: 8px 0 20px;">
                <canvas id="detailMonthlyProgressChart" aria-label="Monthly progress by KPI category"></canvas>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:.7rem;color:#64748b;border-top:1px solid #f1f5f9;padding-top:10px;">
                <span>A Financial Stewardship — 10%</span><span>B Service Delivery — 15%</span><span>C Core Mandate — 65%</span>
                <span>D Implementation of Presidential Directives — 2%</span><span>E Affirmative Action in Procurement — 4%</span><span>F Cross - Cutting — 4%</span><strong>Total Weight — 100%</strong>
            </div>
            <script src="/assets/js/chart.min.js"></script>
            <script>
            (() => {
                const rows = <?= json_encode($detail_monthly_records, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
                const categories = [
                    {code:'A',name:'Financial Stewardship',weight:10,color:'#1976d2'},
                    {code:'B',name:'Service Delivery',weight:15,color:'#ea580c'},
                    {code:'C',name:'Core Mandate',weight:65,color:'#16a34a'},
                    {code:'D',name:'Implementation of Presidential Directives',weight:2,color:'#7c3aed'},
                    {code:'E',name:'Affirmative Action in Procurement',weight:4,color:'#db2777'},
                    {code:'F',name:'Cross - Cutting',weight:4,color:'#64748b'}
                ];
                const months = [...new Set(rows.map(r => r.year_month))].sort();
                const projectStart = <?= json_encode($current_project['start_date'] ?? null) ?>;
                const projectEnd = <?= json_encode($current_project['end_date'] ?? null) ?>;
                const ctx = document.getElementById('detailMonthlyProgressChart');
                if (!ctx || typeof Chart === 'undefined') return;
                if (!months.length) {
                    ctx.parentElement.innerHTML = '<div style="padding:24px;text-align:center;color:#64748b;background:#f8fafc;border-radius:8px;">No monthly KPI submissions are recorded for this project. The KNH workbook provides annual targets and FY 2025/26 actuals, not monthly values; see the Annual Key Outputs section when available.</div>';
                    return;
                }
                const achievedByMonth = {};
                rows.forEach(r => {
                    const code = String(r.kpi_code || '').trim().charAt(0).toUpperCase();
                    const key = `${r.year_month}|${code}`;
                    const value = Math.max(0, Math.min(100, Number(r.progress_pct) || 0));
                    // progress_pct is the weighted contribution for the KPI category.
                    // Average duplicates so a repeated category cannot inflate a month.
                    achievedByMonth[key] = achievedByMonth[key]
                        ? {sum: achievedByMonth[key].sum + value, count: achievedByMonth[key].count + 1}
                        : {sum: value, count: 1};
                });
                const categoryContribution = (month, code) => {
                    const entry = achievedByMonth[`${month}|${code}`];
                    return entry ? entry.sum / entry.count : 0;
                };
                const select = document.getElementById('detailKpiFilter');
                let chartInstance = null;
                const monthLabel = m => { const [y,mo]=m.split('-').map(Number); return new Date(y,mo-1,1).toLocaleString('en',{month:'short',year:'numeric'}); };
                const targetForMonth = m => {
                    if (!projectStart || !projectEnd) return 100;
                    const [y,mo]=m.split('-').map(Number), at=Date.UTC(y,mo,0), start=Date.parse(projectStart), end=Date.parse(projectEnd);
                    return end<=start ? 100 : Math.max(0,Math.min(100,((at-start)/(end-start))*100));
                };
                const draw = () => {
                    if (chartInstance) chartInstance.destroy();
                    const selected = select.value;
                    const selectedCategory = categories.find(c => c.code === selected);
                    const visibleCategories = selected === 'All' ? categories : categories.filter(c => c.code === selected);
                    const datasets = visibleCategories.map(c => ({
                        type: 'bar',
                        label: `${c.code} ${c.name}${selected === 'All' ? ` (${c.weight}%)` : ''}`,
                        data: months.map(m => {
                            const contribution = categoryContribution(m,c.code);
                            return selected === 'All' ? contribution : contribution / c.weight * 100;
                        }),
                        backgroundColor: c.color,
                        borderColor: c.color,
                        borderWidth: 1,
                        borderRadius: 2,
                        stack: 'monthly-progress',
                        maxBarThickness: 42,
                        order: 2
                    }));
                    const achieved = months.map(m => selected === 'All'
                        ? categories.reduce((sum,c) => sum + categoryContribution(m,c.code),0)
                        : (selectedCategory ? categoryContribution(m,selected) / selectedCategory.weight * 100 : 0));
                    datasets.push(
                        {type:'line',label:'Achieved Progress %',data:achieved,borderColor:'#0d47a1',backgroundColor:'#0d47a1',borderWidth:3,pointBackgroundColor:'#0d47a1',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:4,fill:false,tension:.25,order:0},
                        {type:'line',label:'Target %',data:months.map(targetForMonth),borderColor:'#64748b',backgroundColor:'#64748b',borderDash:[6,4],borderWidth:2,pointRadius:2,pointStyle:'rectRot',fill:false,tension:0,order:0}
                    );
                    chartInstance = new Chart(ctx, {type:'bar',data:{labels:months.map(monthLabel),datasets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',labels:{font:{weight:'600'},usePointStyle:true}},tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${Number(c.raw).toFixed(1)}%`}}},scales:{y:{min:0,max:100,stacked:true,ticks:{callback:v=>v+'%'},grid:{color:'#dbe4ef'}},x:{stacked:true,grid:{display:false},ticks:{font:{weight:'600'}}}}}});
                };
                select.addEventListener('change',draw);
                draw();
            })();
            </script>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;text-align:left;">
                    <thead><tr style="background:#f8fafc;color:#475569;border-bottom:2px solid #e2e8f0;text-transform:uppercase;font-size:.7rem;">
                        <th style="padding:10px 14px;">Reporting Month</th><th style="padding:10px 14px;">Key Output Code</th><th style="padding:10px 14px;">Project Key Output</th><th style="padding:10px 14px;text-align:center;">Weight</th><th style="padding:10px 14px;text-align:center;">Progress Contribution</th><th style="padding:10px 14px;">Notes</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$detail_monthly_records): ?>
                        <tr><td colspan="6" style="padding:22px;text-align:center;color:#64748b;">No project key output progress has been recorded. Performance Contract KPIs are not used in this project table.</td></tr>
                    <?php else: foreach ($detail_monthly_records as $record): ?>
                        <tr class="status-row-default" style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px 14px;"><?= htmlspecialchars(date('M Y', strtotime($record['year_month'].'-01'))) ?></td>
                            <td style="padding:10px 14px;font-family:monospace;"><?= htmlspecialchars($record['kpi_code']) ?></td>
                            <td style="padding:10px 14px;"><strong><?= htmlspecialchars($record['kpi_name']) ?></strong></td>
                            <td style="padding:10px 14px;text-align:center;"><?= number_format((float)$record['weight'], 2) ?>%</td>
                            <td style="padding:10px 14px;text-align:center;font-weight:800;"><?= number_format((float)$record['progress_pct'], 2) ?>%</td>
                            <td style="padding:10px 14px;color:#64748b;"><?= htmlspecialchars($record['notes'] ?? '') ?: '—' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ================= MAIN PROJECTS PORTFOLIO VIEW ================= -->

    <div class="project-browse-grid">
        <details class="project-browse-card">
            <summary><span class="project-browse-icon project-browse-blue">▣</span><span><strong>Directorates</strong><small><?= count($gantt_directorates) ?> available</small></span><b aria-hidden="true">⌄</b></summary>
            <div class="project-browse-menu">
                <?php if (!$gantt_directorates): ?><span class="project-browse-empty">No directorates are registered.</span><?php endif; ?>
                <?php foreach ($gantt_directorates as $directorate): ?>
                    <?php $directorate_divisions = array_values(array_filter($gantt_divisions, static fn($division) => $division['directorate_name'] === $directorate)); ?>
                    <details class="project-browse-group">
                        <summary class="project-browse-link project-browse-directorate"><?= htmlspecialchars($directorate) ?><span aria-hidden="true">›</span></summary>
                        <div class="project-browse-submenu">
                            <a class="project-browse-link project-browse-all" href="/modules/projects.php?dir=<?= urlencode($directorate) ?>">View all projects in this directorate →</a>
                            <?php foreach ($directorate_divisions as $division): ?>
                                <a class="project-browse-link project-browse-division" href="/modules/projects.php?dir=<?= urlencode($directorate) ?>&amp;div=<?= urlencode($division['division_name']) ?>"><?= htmlspecialchars($division['division_name']) ?></a>
                            <?php endforeach; ?>
                            <?php if (!$directorate_divisions): ?><span class="project-browse-empty">No divisions are registered under this directorate.</span><?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </details>
        <details class="project-browse-card">
            <summary><span class="project-browse-icon project-browse-green">⬡</span><span><strong>SAGAs</strong><small><?= count($gantt_sagas) ?> available</small></span><b aria-hidden="true">⌄</b></summary>
            <div class="project-browse-menu">
                <?php if (!$gantt_sagas): ?><span class="project-browse-empty">No SAGAs are registered.</span><?php endif; ?>
                <?php foreach ($gantt_sagas as $saga): ?><a class="project-browse-link" href="/modules/projects.php?saga=<?= urlencode($saga) ?>"><?= htmlspecialchars($saga) ?></a><?php endforeach; ?>
            </div>
        </details>
    </div>

    <!-- Top Status Filter Pills (Do not remove top filters) -->
    <div style="background: white; padding: 14px 20px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span style="font-size: 0.75rem; font-weight: 800; color: #475569; margin-right: 4px;">Status Filter:</span>
            <?php
            $statuses = ['All', 'In Progress', 'On Hold', 'Completed', 'Planning', 'Delayed', 'Overdue', 'Cancelled'];
            foreach ($statuses as $st):
                $isActive = (strtolower($filter_status) === strtolower($st));
                $status_colors = ['All'=>['#0d47a1','#ffffff'],'In Progress'=>['#d1fae5','#047857'],'On Hold'=>['#fef3c7','#92400e'],'Completed'=>['#dbeafe','#1d4ed8'],'Planning'=>['#ede9fe','#6d28d9'],'Delayed'=>['#ffedd5','#c2410c'],'Overdue'=>['#fee2e2','#b91c1c'],'Cancelled'=>['#e2e8f0','#475569']];
                $status_color = $status_colors[$st] ?? ['#f1f5f9','#334155'];
            ?>
                <a href="/modules/projects.php?status=<?= urlencode($st) ?>&saga=<?= urlencode($filter_saga) ?>&dir=<?= urlencode($filter_dir) ?>&div=<?= urlencode($filter_div) ?>&du=<?= urlencode($filter_du) ?>&tier=<?= urlencode($filter_tier) ?>&q=<?= urlencode($search_q) ?>&year=<?= $gantt_year ?>&month=<?= $gantt_month ?>&gantt_directorate=<?= urlencode($gantt_directorate) ?>&gantt_saga=<?= urlencode($gantt_saga) ?>" 
                   aria-label="Filter projects by <?= htmlspecialchars($st) ?>" <?= $isActive ? 'aria-current="true"' : '' ?>
                   style="padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-decoration: none; transition: all 0.2s; background: <?= $status_color[0] ?>; color: <?= $status_color[1] ?>; border:<?= $isActive ? '2px' : '1px' ?> solid <?= $status_color[1] ?>; box-shadow:<?= $isActive ? '0 0 0 2px '.$status_color[0] : 'none' ?>;">
                    <?= $st ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div>
            <a href="/modules/projects.php" style="background: #e2e8f0; color: #1e293b; padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 800; text-decoration: none;">
                &#x21bb; Reset Filters
            </a>
        </div>
    </div>

    <!-- Retain Search and Filters (All Directorates, Sagas, Divisions, Delivery Unit, Priority Tier) -->
    <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <form method="GET" action="/modules/projects.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; align-items: flex-end;">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <input type="hidden" name="gantt_directorate" value="<?= htmlspecialchars($gantt_directorate) ?>">
            <input type="hidden" name="gantt_saga" value="<?= htmlspecialchars($gantt_saga) ?>">

            <div><label style="display:block;font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Year</label><select name="year" style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;"><?php foreach ($gantt_year_options as $fy): ?><option value="<?= $fy ?>" <?= $fy === $gantt_year ? 'selected' : '' ?>><?= $fy ?></option><?php endforeach; ?></select></div>
            <div><label style="display:block;font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Month</label><select name="month" style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;"><option value="all" <?= $gantt_month === 'all' ? 'selected' : '' ?>>All Months</option><?php foreach (range(1, 12) as $fm): ?><option value="<?= $fm ?>" <?= (string)$fm === $gantt_month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $fm, 1)) ?></option><?php endforeach; ?></select></div>

            <!-- SAGA Filter -->
            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Filter by SAGA</label>
                <select name="saga" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.78rem;">
                    <option value="All" <?= $filter_saga === 'All' ? 'selected' : '' ?>>All SAGAs</option>
                    <?php foreach ($filter_sagas as $saga): ?><option value="<?= htmlspecialchars($saga) ?>" <?= $filter_saga === $saga ? 'selected' : '' ?>><?= htmlspecialchars($saga) ?></option><?php endforeach; ?>
                </select>
            </div>

            <!-- Directorate Filter -->
            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Filter by Directorate</label>
                <select name="dir" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.78rem;">
                    <option value="All" <?= $filter_dir === 'All' ? 'selected' : '' ?>>All Directorates</option>
                    <?php foreach ($filter_directorates as $directorate): ?><option value="<?= htmlspecialchars($directorate) ?>" <?= $filter_dir === $directorate ? 'selected' : '' ?>><?= htmlspecialchars($directorate) ?></option><?php endforeach; ?>
                </select>
            </div>

            <!-- Division Filter -->
            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Filter by Division</label>
                <select name="div" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.78rem;">
                    <option value="All" <?= $filter_div === 'All' ? 'selected' : '' ?>>All Divisions</option>
                    <?php foreach ($filter_divisions as $division): ?><option value="<?= htmlspecialchars($division) ?>" <?= $filter_div === $division ? 'selected' : '' ?>><?= htmlspecialchars($division) ?></option><?php endforeach; ?>
                </select>
            </div>

            <!-- Delivery Unit Filter -->
            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Filter by Delivery Unit</label>
                <select name="du" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.78rem;">
                    <option value="All" <?= $filter_du === 'All' ? 'selected' : '' ?>>All Delivery Units</option>
                    <?php foreach ($filter_delivery_units as $delivery_unit): ?><option value="<?= htmlspecialchars($delivery_unit) ?>" <?= $filter_du === $delivery_unit ? 'selected' : '' ?>><?= htmlspecialchars($delivery_unit) ?></option><?php endforeach; ?>
                </select>
            </div>

            <!-- Priority Tier Filter -->
            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Priority Tier</label>
                <select name="tier" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.78rem;">
                    <option value="All" <?= $filter_tier === 'All' ? 'selected' : '' ?>>All Priority Tiers</option>
                    <?php foreach ($filter_tiers as $tier): ?><option value="<?= htmlspecialchars($tier) ?>" <?= $filter_tier === $tier ? 'selected' : '' ?>><?= htmlspecialchars($tier) ?></option><?php endforeach; ?>
                </select>
            </div>

            <!-- Search Projects -->
            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Search Projects</label>
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Search title, keyword..." style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.78rem;">
            </div>

            <!-- Filter Buttons -->
            <div style="display: flex; gap: 8px;">
                <button type="submit" style="background: #0d47a1; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 700; font-size: 0.78rem; cursor: pointer;">
                    Apply Filters
                </button>
                <a href="/modules/projects.php" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; padding: 7px 12px; border-radius: 6px; font-weight: 700; font-size: 0.78rem; text-decoration: none; text-align: center;">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="projects-view-tabs" role="tablist" aria-label="Projects and key outputs">
        <button type="button" class="projects-view-tab active" id="allProjectsTab" role="tab" aria-selected="true" aria-controls="allProjectsPanel">All Projects <span><?= count($projects) ?></span></button>
        <button type="button" class="projects-view-tab" id="projectOutputsTab" role="tab" aria-selected="false" aria-controls="projectOutputsPanel">Key Outputs <span><?= $key_output_tab_count ?></span></button>
    </div>

    <!-- Projects Table -->
    <div id="allProjectsPanel" role="tabpanel" aria-labelledby="allProjectsTab" style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="background: #0d47a1; color: white; padding: 14px 20px; font-size: 0.85rem; font-weight: 800; display: flex; justify-content: space-between; align-items: center;">
            <span>Priority Projects &amp; Programmes (<?= count($projects) ?> Projects)</span>
            <button onclick="document.getElementById('addProjectModal').style.display='flex';" style="background: #10b981; color: white; border: none; padding: 6px 14px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                + Add Project
            </button>
        </div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; color: #475569; font-weight: 700; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; font-size: 0.7rem;">
                        <th style="padding: 12px 16px;">Title &amp; Delivery Unit</th>
                        <th style="padding: 12px 16px;">Type</th>
                        <th style="padding: 12px 16px;">Category &amp; Tier</th>
                        <th style="padding: 12px 16px;">Status</th>
                        <th style="padding: 12px 16px;">Duration</th>
                        <th style="padding: 12px 16px; text-align: right;">Total Budget</th>
                        <th style="padding: 12px 16px; width: 140px;">Progress</th>
                        <th style="padding: 12px 16px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects)): ?>
                        <tr>
                            <td colspan="8" style="padding: 30px; text-align: center; color: #64748b;">
                                <?php if (!$db_connected): ?>The projects database connection is unavailable.
                                <?php elseif ($project_load_error): ?>Project records could not be loaded. Check the live database schema and application connection.
                                <?php elseif ($project_total_count === 0): ?>No project rows are present in the database. Import <code>seed_knh_ten_sample_projects.sql</code> to add the KNH sample projects.
                                <?php else: ?>No projects match the selected filters. Use Reset Filters to show all database records.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects as $p):
                            $statusKey = strtolower(trim((string)($p['status'] ?? '')));
                            $rowStatusClass = ['in progress'=>'status-in-progress','on hold'=>'status-on-hold','completed'=>'status-completed','planning'=>'status-planning','delayed'=>'status-delayed','overdue'=>'status-overdue','cancelled'=>'status-cancelled'][$statusKey] ?? 'status-default';
                            $progressValue = null;
                            if (isset($monthly_progress_by_project[(int)$p['id']])) $progressValue = (float)$monthly_progress_by_project[(int)$p['id']];
                            elseif (isset($p['overall_progress_pct']) && $p['overall_progress_pct'] !== null) $progressValue = (float)$p['overall_progress_pct'];
                            elseif (preg_match('/^\s*(\d+(?:\.\d+)?)\s*%?\s*$/', (string)($p['progress_status'] ?? ''), $progressMatch)) $progressValue = (float)$progressMatch[1];
                            else $progressValue = 0.0;
                            $progressValue = max(0, min(100, $progressValue));
                            $durationLabel = '—';
                            $durationStart = !empty($p['start_date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$p['start_date']) : false;
                            $durationEnd = !empty($p['end_date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$p['end_date']) : false;
                            if ($durationStart && $durationEnd && $durationEnd >= $durationStart) {
                                $duration = $durationStart->diff($durationEnd);
                                $durationParts = [];
                                if ($duration->y) $durationParts[] = $duration->y . ' ' . ($duration->y === 1 ? 'year' : 'years');
                                if ($duration->m) $durationParts[] = $duration->m . ' ' . ($duration->m === 1 ? 'month' : 'months');
                                if (!$durationParts && $duration->d) $durationParts[] = $duration->d . ' ' . ($duration->d === 1 ? 'day' : 'days');
                                $durationLabel = $durationParts ? implode(' ', $durationParts) : 'Under 1 month';
                            }
                            $statusStyle = ['in progress'=>['#047857','#ffffff'],'on hold'=>['#92400e','#ffffff'],'completed'=>['#1d4ed8','#ffffff'],'planning'=>['#6d28d9','#ffffff'],'delayed'=>['#c2410c','#ffffff'],'overdue'=>['#b91c1c','#ffffff'],'cancelled'=>['#475569','#ffffff']][$statusKey] ?? ['#64748b','#ffffff'];
                        ?>
                            <tr class="project-status-row <?= $rowStatusClass ?>">
                                <td style="padding: 12px 16px;">
                                    <strong style="color: #0f172a; font-size: 0.82rem;"><?= htmlspecialchars($p['sub_programme']) ?></strong>
                                    <?php if (!empty($p['project_code'])): ?><div style="font-size:.68rem;color:#64748b;margin-top:2px;">Code: <?= htmlspecialchars($p['project_code']) ?></div><?php endif; ?>
                                    <div style="font-size: 0.72rem; color: #64748b; margin-top: 2px;">
                                        <?= htmlspecialchars($p['delivery_unit'] ?? $p['saga_name'] ?? $p['directorate_name'] ?? 'Ministry of Health') ?>
                                    </div>
                                </td>
                                <td style="padding:12px 16px;"><span style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;padding:3px 7px;border-radius:4px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars($p['project_type'] ?? 'Project') ?></span></td>
                                <td style="padding: 12px 16px;">
                                    <span style="background: #dbeafe; color: #1e40af; font-weight: 800; font-size: 0.68rem; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($p['category']) ?></span>
                                    <div style="font-size: 0.7rem; color: #475569; margin-top: 3px; font-weight: 600;">
                                        <?= htmlspecialchars($p['priority_tier'] ?? 'Tier 1 - High') ?>
                                    </div>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <span style="background: <?= $statusStyle[0] ?>; color: <?= $statusStyle[1] ?>; padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 800;">
                                        <?= htmlspecialchars($p['status']) ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 16px; white-space: nowrap; font-weight: 700; color: #334155;">
                                    <?= htmlspecialchars($durationLabel) ?>
                                </td>
                                <td style="padding: 12px 16px; text-align: right; font-family: 'JetBrains Mono', monospace; font-weight: 700; color: #0d47a1;">
                                    <?= format_kes($p['total_budget']) ?>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <div style="font-size: 0.75rem; font-weight: 800; color: #334155; margin-bottom: 4px;"><?= number_format($progressValue, 1) ?>%</div>
                                    <div style="background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">
                                        <div style="background: <?= htmlspecialchars($p['color'] ?? '#0d47a1') ?>; height: 100%; width: <?= $progressValue ?>%;"></div>
                                    </div>
                                </td>
                                <td style="padding: 12px 16px; text-align: center;">
                                    <a href="/modules/projects.php?id=<?= $p['id'] ?>" style="background: #dbeafe; color: #1e40af; text-decoration: none; padding: 5px 12px; border-radius: 6px; font-size: 0.72rem; font-weight: 800; display: inline-block;">
                                        View Detail &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <section id="projectOutputsPanel" class="project-outputs-panel" role="tabpanel" aria-labelledby="projectOutputsTab" hidden>
        <?php if (!$projects): ?><div class="project-output-empty">No projects match the selected filters.</div><?php endif; ?>
        <?php foreach ($projects as $project): $outputGroups = $project_key_output_groups[(int)$project['id']] ?? []; ?>
            <article class="project-output-project">
                <header><a href="/modules/projects.php?id=<?= (int)$project['id'] ?>"><?= htmlspecialchars($project['sub_programme']) ?></a><span><?= htmlspecialchars($project['delivery_unit'] ?? $project['saga_name'] ?? $project['directorate_name'] ?? 'Ministry of Health') ?></span></header>
                <?php if (!$outputGroups): ?><p class="project-output-empty">No monthly key-output submissions are recorded for <?= $gantt_month === 'all' ? 'the selected year' : 'the selected month' ?>.</p>
                <?php else: foreach ($outputGroups as $outputRows):
                    $outputLabel = $outputRows[0]['kpi_name'] ?: $outputRows[0]['kpi_code'];
                    $outputCode = $outputRows[0]['kpi_code'];
                    $outputLatest = end($outputRows);
                    $outputColor = ['A'=>'#4778c4','B'=>'#ed8734','C'=>'#6eae49','D'=>'#f0c400','E'=>'#8458b6','F'=>'#9b9b9b'][strtoupper(substr((string)$outputCode,0,1))] ?? '#64748b';
                ?>
                    <details class="project-output-detail">
                        <summary><span class="project-output-code" style="background:<?= $outputColor ?>;">[<?= htmlspecialchars($outputCode) ?>]</span><strong><?= htmlspecialchars($outputLabel) ?></strong><span class="project-output-latest"><?= number_format((float)$outputLatest['progress_pct'],1) ?>% latest recorded</span></summary>
                        <div class="project-output-detail-content">
                            <div class="project-output-gantt" aria-label="Monthly progress chart for <?= htmlspecialchars($outputLabel) ?>">
                                <?php foreach ($outputRows as $outputRow): $outputPercent=max(0,min(100,(float)$outputRow['progress_pct'])); ?>
                                    <div class="project-output-month"><span><?= htmlspecialchars(date('M Y', strtotime($outputRow['year_month'].'-01'))) ?></span><div class="project-output-track"><i style="width:<?= $outputPercent ?>%;background:<?= $outputColor ?>;"></i></div><b><?= number_format($outputPercent,1) ?>%</b></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="project-output-table-wrap"><table class="project-output-table"><thead><tr><th>Month</th><th>Weight</th><th>Progress contribution</th><th>Notes</th></tr></thead><tbody>
                                <?php foreach ($outputRows as $outputRow): ?><tr><td><?= htmlspecialchars(date('M Y', strtotime($outputRow['year_month'].'-01'))) ?></td><td><?= number_format((float)$outputRow['weight'],2) ?>%</td><td><?= number_format((float)$outputRow['progress_pct'],2) ?>%</td><td><?= htmlspecialchars($outputRow['notes'] ?? '') ?: '—' ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </details>
                <?php endforeach; endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if ($portfolio_has_knh_context && $knh_annual_output_groups): ?>
            <article class="project-output-project knh-annual-portfolio-output">
                <header><strong>Kenyatta National Hospital Key Outputs</strong><span>SAGA annual targets and actuals from the supplied workbook</span></header>
                <p class="knh-annual-note">FY 2025/26 includes reported actuals. The remaining years show targets only because no later actuals were supplied.</p>
                <?php foreach ($knh_annual_output_groups as $outputGroup):
                    $outputMeta = reset($outputGroup);
                    $outputColor = ['KNH-01'=>'#1d65b5','KNH-02'=>'#13845c','KNH-03'=>'#778397'][$outputMeta['output_code']] ?? '#64748b';
                    $chartId = 'knh-output-chart-' . strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $outputMeta['output_code']));
                    $chartPeriods = [
                        ['2025/26', 'target_value'], ['2025/26', 'actual_value'], ['2026/27', 'target_value'],
                        ['2027/28', 'target_value'], ['2028/29', 'target_value'], ['2029/30', 'target_value'],
                    ];
                    $chartLabels = ['Target 2025/26', 'Actual Achievement 2025/26', 'Target (Baseline) 2026/27', 'Target 2027/28', 'Target 2028/29', 'Target 2029/30'];
                    $excelSeriesColors = ['#4472C4', '#ED7D31', '#A5A5A5', '#FFC000', '#5B9BD5', '#70AD47'];
                    $chartDatasets = [];
                    foreach (array_values($outputGroup) as $seriesIndex => $kpiSeries) {
                        $seriesValues = [];
                        foreach ($chartPeriods as [$fiscalYear, $valueType]) {
                            $seriesValues[] = $kpiSeries['years'][$fiscalYear][$valueType] ?? null;
                        }
                        $chartDatasets[] = [
                            'label' => $kpiSeries['kpi_name'],
                            'data' => $seriesValues,
                            'backgroundColor' => $excelSeriesColors[$seriesIndex % count($excelSeriesColors)],
                            'borderColor' => '#ffffff',
                            'borderWidth' => 1,
                            'borderSkipped' => false,
                        ];
                    }
                    $knh_output_chart_payloads[$chartId] = [
                        'labels' => $chartLabels,
                        'datasets' => $chartDatasets,
                        'stacked' => in_array($outputMeta['output_code'], ['KNH-01', 'KNH-03'], true),
                    ];
                ?>
                    <details class="project-output-detail">
                        <summary><span class="project-output-code" style="background:<?= $outputColor ?>;"><?= htmlspecialchars($outputMeta['output_code']) ?></span><strong><?= htmlspecialchars($outputMeta['output_title']) ?></strong><span class="project-output-latest"><?= count($outputGroup) ?> indicators</span></summary>
                        <div class="project-output-detail-content">
                            <div style="height:300px;position:relative;margin:4px 0 18px;">
                                <canvas id="<?= htmlspecialchars($chartId) ?>" aria-label="<?= htmlspecialchars($outputMeta['output_title']) ?> values from FY 2025/26 through FY 2029/30"></canvas>
                            </div>
                            <div class="project-output-table-wrap"><table class="project-output-table"><thead><tr><th>Key Performance Indicators</th><th>Target 2025/26</th><th>Actual Achievement 2025/26</th><th>Target (Baseline) 2026/27</th><th>Target 2027/28</th><th>Target 2028/29</th><th>Target 2029/30</th></tr></thead><tbody>
                                <?php foreach ($outputGroup as $kpi): $years = $kpi['years']; $fmt = static function ($value) { return $value === null ? '—' : rtrim(rtrim(number_format((float)$value,2,'.',','),'0'),'.'); }; ?>
                                    <tr><td><?= htmlspecialchars($kpi['kpi_name']) ?></td><td><?= $fmt($years['2025/26']['target_value'] ?? null) ?></td><td><?= $fmt($years['2025/26']['actual_value'] ?? null) ?></td><td><?= $fmt($years['2026/27']['target_value'] ?? null) ?></td><td><?= $fmt($years['2027/28']['target_value'] ?? null) ?></td><td><?= $fmt($years['2028/29']['target_value'] ?? null) ?></td><td><?= $fmt($years['2029/30']['target_value'] ?? null) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </article>
        <?php endif; ?>
    </section>
    <script>
    (() => {
        const knhCharts = <?= json_encode($knh_output_chart_payloads, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
        const chartInstances = new Map();
        document.querySelectorAll('.knh-annual-portfolio-output .project-output-detail').forEach(detail => {
            detail.addEventListener('toggle', () => {
                if (!detail.open || typeof Chart === 'undefined') return;
                const canvas = detail.querySelector('canvas[id]');
                const chart = canvas && knhCharts[canvas.id];
                if (!canvas || !chart || chartInstances.has(canvas.id)) return;
                const stacked = chart.stacked;
                chartInstances.set(canvas.id, new Chart(canvas, {
                    type: 'bar',
                    data: {labels: chart.labels, datasets: chart.datasets},
                    options: {
                        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: {display: chart.datasets.length > 1, position: 'bottom', labels: {usePointStyle: true, boxWidth: 10, font: {size: 11}}},
                            tooltip: {callbacks: {label: item => `${item.dataset.label}: ${Number(item.raw ?? 0).toLocaleString()}`}}
                        },
                        scales: {
                            x: {beginAtZero: true, stacked, title: {display: true, text: 'Annual target / actual'}, ticks: {precision: 0}, grid: {color: '#e2e8f0'}},
                            y: {stacked, grid: {display: false}, ticks: {font: {size: 11}}}
                        }
                    }
                }));
            });
        });
        const projectsTab = document.getElementById('allProjectsTab');
        const outputsTab = document.getElementById('projectOutputsTab');
        const projectsPanel = document.getElementById('allProjectsPanel');
        const outputsPanel = document.getElementById('projectOutputsPanel');
        if (!projectsTab || !outputsTab || !projectsPanel || !outputsPanel) return;
        const showPanel = showOutputs => {
            projectsPanel.hidden = showOutputs;
            outputsPanel.hidden = !showOutputs;
            projectsTab.classList.toggle('active', !showOutputs);
            outputsTab.classList.toggle('active', showOutputs);
            projectsTab.setAttribute('aria-selected', String(!showOutputs));
            outputsTab.setAttribute('aria-selected', String(showOutputs));
        };
        projectsTab.addEventListener('click', () => showPanel(false));
        outputsTab.addEventListener('click', () => showPanel(true));
    })();
    </script>


    <!-- Monthly Progress Module Section & Gantt Chart of Overall Project Progress -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; gap: 18px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; border-bottom:1px solid #e2e8f0; padding-bottom:14px;">
            <div><h3 style="font-size:1.05rem;font-weight:800;color:#0f172a;margin:0;">Overall Progress by Month — Contribution of Each Key Output</h3><span style="font-size:.78rem;color:#64748b;">Project names are shown on the vertical axis. Bars show total progress, split by project key output.</span></div>
            <a href="/modules/monthly_progress.php" style="background:#0d47a1;color:white;text-decoration:none;font-size:.78rem;font-weight:700;padding:7px 14px;border-radius:6px;">Manage Monthly Progress &rarr;</a>
        </div>
        <form method="GET" action="/modules/projects.php" style="display:flex;flex-wrap:wrap;align-items:end;gap:12px;">
            <?php foreach (['status'=>$filter_status,'saga'=>$filter_saga,'dir'=>$filter_dir,'div'=>$filter_div,'du'=>$filter_du,'tier'=>$filter_tier,'q'=>$search_q] as $key=>$value): ?><input type="hidden" name="<?= $key ?>" value="<?= htmlspecialchars($value) ?>"><?php endforeach; ?>
            <?php if ($is_detail_view): ?><input type="hidden" name="id" value="<?= $view_detail_id ?>"><?php endif; ?>
            <label style="font-size:.75rem;font-weight:700;color:#475569;">Year <select name="year" style="display:block;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;">
                <?php foreach ($gantt_year_options as $year): ?><option value="<?= $year ?>" <?= $year === $gantt_year ? 'selected' : '' ?>><?= $year ?></option><?php endforeach; ?>
            </select></label>
            <label style="font-size:.75rem;font-weight:700;color:#475569;">Month <select name="month" style="display:block;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;">
                <option value="all" <?= $gantt_month === 'all' ? 'selected' : '' ?>>All Months</option>
                <?php foreach (range(1, 12) as $month): ?><option value="<?= $month ?>" <?= (string)$month === $gantt_month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $month, 1)) ?></option><?php endforeach; ?>
            </select></label>
            <label style="font-size:.75rem;font-weight:700;color:#475569;">Directorate / Division <select name="gantt_directorate" style="display:block;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;min-width:220px;">
                <option value="All" <?= $gantt_directorate === 'All' ? 'selected' : '' ?>>All Directorates</option>
                <?php foreach ($gantt_directorates as $directorate): ?>
                    <optgroup label="<?= htmlspecialchars($directorate) ?>">
                        <option value="directorate:<?= htmlspecialchars($directorate) ?>" <?= $gantt_directorate === 'directorate:' . $directorate ? 'selected' : '' ?>><?= htmlspecialchars($directorate) ?></option>
                        <?php foreach ($gantt_divisions as $division): if ($division['directorate_name'] !== $directorate) continue; $division_value = 'division:' . $directorate . '|' . $division['division_name']; ?>
                            <option value="<?= htmlspecialchars($division_value) ?>" <?= $gantt_directorate === $division_value ? 'selected' : '' ?>>&nbsp;&nbsp;↳ <?= htmlspecialchars($division['division_name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select></label>
            <label style="font-size:.75rem;font-weight:700;color:#475569;">Saga <select name="gantt_saga" style="display:block;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;min-width:200px;">
                <option value="All" <?= $gantt_saga === 'All' ? 'selected' : '' ?>>All Sagas</option>
                <?php foreach ($gantt_sagas as $saga): ?><option value="<?= htmlspecialchars($saga) ?>" <?= $gantt_saga === $saga ? 'selected' : '' ?>><?= htmlspecialchars($saga) ?></option><?php endforeach; ?>
            </select></label>
            <button type="submit" style="background:#0d47a1;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-weight:700;cursor:pointer;">View Progress</button>
        </form>
        <?php
        $visible_project_ids = array_map('intval', array_column($projects, 'id'));
        $context_gantt = array_values(array_filter($monthly_gantt, function($row) use ($visible_project_ids) { return in_array((int)$row['id'], $visible_project_ids, true); }));
        $chart_records = array_values(array_filter($portfolio_progress_records, function($row) use ($context_gantt) {
            return in_array((int)$row['project_id'], array_map('intval', array_column($context_gantt, 'id')), true);
        }));
        ?>
        <?php if (!$context_gantt): ?><div style="padding:20px;text-align:center;color:#64748b;background:#f8fafc;border-radius:8px;">
            <?php if (!$db_connected): ?>The projects database connection is unavailable.
            <?php elseif ($project_load_error): ?>The project records could not be loaded. Check that the live projects table matches the application schema.
            <?php elseif ($project_total_count === 0): ?>There are no project rows in the database. Import the project workbook SQL file to populate the portfolio.
            <?php else: ?>No project records match the selected filters. Reset the project and Gantt filters to show all database records.
            <?php endif; ?>
        </div><?php else: ?>
            <div class="project-output-gantt-wrap" style="height:<?= min(1000, max(320, count($context_gantt) * 42 + 125)) ?>px;">
                <canvas id="projectOutputGantt" aria-label="Project progress by key output"></canvas>
            </div>
            <?php $chart_project_data = array_map(static function($row) { return ['id'=>(int)$row['id'], 'title'=>$row['sub_programme'], 'progress'=>(float)$row['monthly_progress'], 'source'=>$row['progress_source']]; }, $context_gantt); ?>
            <script src="/assets/js/chart.min.js"></script>
            <script>
            (() => {
                const projects = <?= json_encode($chart_project_data, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
                const records = <?= json_encode($chart_records, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
                const colors = {A:'#4778c4',B:'#ed8734',C:'#6eae49',D:'#f0c400',E:'#8458b6',F:'#9b9b9b'};
                const categoryRows = new Map();
                records.forEach(row => {
                    const name = String(row.kpi_name || row.kpi_code || 'Unlabelled key output');
                    const code = String(row.kpi_code || '').trim().charAt(0).toUpperCase();
                    if (!categoryRows.has(name)) categoryRows.set(name, {name, code, values: new Map()});
                    const output = categoryRows.get(name);
                    const id = Number(row.project_id);
                    const point = output.values.get(id) || {sum:0,count:0};
                    point.sum += Math.max(0, Number(row.progress_pct) || 0);
                    point.count += 1;
                    output.values.set(id, point);
                });
                const normalized = projects.map(project => {
                    const values = [...categoryRows.values()].map(output => {
                        const point = output.values.get(project.id);
                        return point ? point.sum / point.count : 0;
                    });
                    const total = values.reduce((sum,value) => sum + value, 0);
                    const factor = total > 100 ? 100 / total : 1;
                    return values.map(value => value * factor);
                });
                const hasMonthlyOutput = project => [...categoryRows.values()].some(output => output.values.has(project.id));
                const datasets = [...categoryRows.values()].map((output, index) => ({
                    label: output.code ? `${output.code} — ${output.name}` : output.name,
                    data: projects.map((project, projectIndex) => hasMonthlyOutput(project) ? normalized[projectIndex][index] : 0),
                    backgroundColor: colors[output.code] || '#64748b',
                    borderColor: '#ffffff', borderWidth: 1, borderSkipped: false, stack: 'project-progress'
                }));
                datasets.push({
                    label: 'Overall project progress',
                    data: projects.map(project => hasMonthlyOutput(project) ? 0 : Math.max(0, Math.min(100, Number(project.progress) || 0))),
                    backgroundColor: '#94a3b8', borderColor: '#ffffff', borderWidth: 1, borderSkipped: false, stack: 'project-progress'
                });
                const canvas = document.getElementById('projectOutputGantt');
                if (!canvas || typeof Chart === 'undefined') return;
                new Chart(canvas, {
                    type:'bar',
                    data:{labels:projects.map(project=>project.title),datasets},
                    options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,interaction:{mode:'nearest',axis:'y',intersect:true},plugins:{legend:{position:'bottom',labels:{usePointStyle:true,boxWidth:10,font:{size:11,weight:'600'}}},tooltip:{callbacks:{label:item=>`${item.dataset.label}: ${Number(item.raw).toFixed(1)}%`}}},scales:{x:{min:0,max:100,stacked:true,title:{display:true,text:'Total project progress (%)'},ticks:{callback:value=>`${value}%`},grid:{color:'#e2e8f0'}},y:{stacked:true,ticks:{font:{size:11,weight:'600'}},grid:{display:false}}}}
                });
            })();
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>

<!-- Modal: Add Project -->
<div id="addProjectModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 12px; max-width: 600px; width: 100%; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a;">Register Project to Portfolio</h3>
            <button onclick="document.getElementById('addProjectModal').style.display='none';" style="background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #64748b;">&times;</button>
        </div>

        <form method="POST" action="/modules/projects.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
            <input type="hidden" name="action_type" value="add_project">

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Sub-Programme / Project Name</label>
                <input type="text" name="sub_programme" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;" placeholder="e.g. Modern Burns ICU Center">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Strategic Programme Title</label>
                <input type="text" name="programme_title" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;" placeholder="e.g. Specialized Hospital Infrastructure">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><label style="display:block;font-size:.75rem;font-weight:700;margin-bottom:4px;">Project Code</label><input type="text" name="project_code" maxlength="50" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;"></div>
                <div><label style="display:block;font-size:.75rem;font-weight:700;margin-bottom:4px;">Type</label><select name="project_type" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;"><option>Project</option><option>Programme</option></select></div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Category</label>
                    <select name="category" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;">
                        <option value="Saga">SAGA (Referral Hospital)</option>
                        <option value="Directorate">Directorate</option>
                        <option value="Division">Division</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Agency / Directorate / SAGA Name</label>
                    <input type="text" name="agency_name" list="projectAgencyOptions" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;" placeholder="Select or enter an agency, directorate, or division">
                    <datalist id="projectAgencyOptions">
                        <?php foreach ($gantt_sagas as $agencyOption): ?><option value="<?= htmlspecialchars($agencyOption) ?>"></option><?php endforeach; ?>
                        <?php foreach ($gantt_directorates as $agencyOption): ?><option value="<?= htmlspecialchars($agencyOption) ?>"></option><?php endforeach; ?>
                        <?php foreach ($filter_divisions as $agencyOption): ?><option value="<?= htmlspecialchars($agencyOption) ?>"></option><?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Priority Tier</label>
                    <select name="priority_tier" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;">
                        <option value="Tier 1 - High Priority">Tier 1 - High Priority</option>
                        <option value="Tier 2 - Medium Priority">Tier 2 - Medium Priority</option>
                        <option value="Tier 3 - Standard Priority">Tier 3 - Standard Priority</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Total Budget (KES)</label>
                    <input type="number" step="100000" name="total_budget" value="1500000000" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Status</label>
                    <select name="status" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;">
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="On Hold">On Hold</option>
                        <option value="Delayed">Delayed</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px;">Start Date</label>
                    <input type="date" name="start_date" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px;">
                <button type="button" onclick="document.getElementById('addProjectModal').style.display='none';" style="padding: 8px 16px; border: 1px solid #cbd5e1; background: white; border-radius: 6px; font-weight: 600; font-size: 0.8rem; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 8px 18px; background: #0d47a1; color: white; border: none; border-radius: 6px; font-weight: 700; font-size: 0.8rem; cursor: pointer;">Save Project</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
