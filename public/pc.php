<?php
/**
 * Performance Contract (PC) Module
 * Ministry of Health — State Department for Medical Services (PP-SDOMS)
 * Native PHP 8.1+ & MySQL (No Node.js)
 *
 * Tables and quarterly Gantt charts driven by Year + Quarter filters.
 * The Performance Gantt chart is left as is and always displays Q1, Q2, Q3, Q4.
 */
require_once __DIR__ . '/../database/db.php';
require_auth();

$page_title = 'Performance Contract — State Department for Medical Services';
$active_tab = 'pc';

$fiscal_years = ['2025/26', '2026/27', '2027/28', '2028/29', '2029/30'];
$quarters = ['Q1' => 'Q1 (Jul - Sep)', 'Q2' => 'Q2 (Oct - Dec)', 'Q3' => 'Q3 (Jan - Mar)', 'Q4' => 'Q4 (Apr - Jun)'];

// Fiscal-year start calendar year: FY 2025/26 begins in July 2025.
$fiscal_year_start = static function (string $fy): int {
    return (int)substr($fy, 0, 4);
};
// Kenyan fiscal quarters: Q1 = Jul-Sep, Q2 = Oct-Dec, Q3 = Jan-Mar, Q4 = Apr-Jun.
$quarter_months = static function (string $fy): array {
    $start = (int)substr($fy, 0, 4);
    return [
        'Q1' => [sprintf('%04d-07', $start), sprintf('%04d-08', $start), sprintf('%04d-09', $start)],
        'Q2' => [sprintf('%04d-10', $start), sprintf('%04d-11', $start), sprintf('%04d-12', $start)],
        'Q3' => [sprintf('%04d-01', $start + 1), sprintf('%04d-02', $start + 1), sprintf('%04d-03', $start + 1)],
        'Q4' => [sprintf('%04d-04', $start + 1), sprintf('%04d-05', $start + 1), sprintf('%04d-06', $start + 1)],
    ];
};

// ---- Filters -----------------------------------------------------------------
$filter_year = (string)($_GET['year'] ?? '2025/26');
if (!in_array($filter_year, $fiscal_years, true)) $filter_year = '2025/26';
$filter_quarter = strtoupper(trim((string)($_GET['quarter'] ?? 'all')));
if ($filter_quarter !== 'all' && !isset($quarters[$filter_quarter])) $filter_quarter = 'all';
$filter_category = trim((string)($_GET['category'] ?? ''));

$keep_filter_qs = static function () use ($filter_year, $filter_quarter, $filter_category): string {
    return http_build_query(array_filter([
        'year' => $filter_year,
        'quarter' => $filter_quarter !== 'all' ? $filter_quarter : null,
        'category' => $filter_category !== '' ? $filter_category : null,
    ], static fn($v) => $v !== null && $v !== ''));
};

// ---- Data --------------------------------------------------------------------
$kpis = [];
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM performance_kpis ORDER BY id ASC");
        $kpis = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Per-category summary (source of the key statistics links).
$category_summary = [];
$total_weight = 0.0;
$weighted_achievement = 0.0;
foreach ($kpis as $kpi) {
    $category = trim((string)($kpi['category'] ?? '')) ?: 'Uncategorized';
    $weight = (float)($kpi['sub_kpi_weight'] ?? 0);
    $achievement = (float)($kpi['current_achievement'] ?? 0);
    if (!isset($category_summary[$category])) {
        $category_summary[$category] = ['weight' => 0.0, 'achievement_total' => 0.0, 'count' => 0];
    }
    $category_summary[$category]['weight'] += $weight;
    $category_summary[$category]['achievement_total'] += $achievement;
    $category_summary[$category]['count']++;
    $total_weight += $weight;
    $weighted_achievement += $achievement * $weight;
}
$composite_achievement = $total_weight > 0 ? $weighted_achievement / $total_weight : 0.0;

// KPI rows, filtered by the statistics link (category) when one is active.
$visible_kpis = $kpis;
if ($filter_category !== '') {
    $visible_kpis = array_values(array_filter($kpis, static function ($kpi) use ($filter_category) {
        $category = trim((string)($kpi['category'] ?? '')) ?: 'Uncategorized';
        return strcasecmp($category, $filter_category) === 0;
    }));
}

// Target / actual columns for the selected fiscal year.
$target_column = 'target_' . str_replace('/', '_', $filter_year);
$has_target_column = false;
foreach ($kpis as $kpi) {
    if (array_key_exists($target_column, $kpi)) { $has_target_column = true; break; }
}

// ---- Quarterly progress from monthly_progress ---------------------------------
$monthly_records = [];
$available_months = [];
if ($db_connected && $pdo) {
    try {
        $available_months = $pdo->query("SELECT DISTINCT `year_month` FROM monthly_progress ORDER BY `year_month` ASC")->fetchAll(PDO::FETCH_COLUMN);
        if ($available_months) {
            $placeholders = implode(',', array_fill(0, count($available_months), '?'));
            $stmt = $pdo->prepare("SELECT project_id, `year_month`, kpi_code, kpi_name, weight, progress_pct, notes
                FROM monthly_progress WHERE `year_month` IN ($placeholders) ORDER BY `year_month`, kpi_code");
            $stmt->execute($available_months);
            $monthly_records = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $monthly_records = [];
    }
}

$fy_months = $quarter_months($filter_year);
// Which quarters have data; defaults the filter to "all" when nothing recorded.
$quarters_with_data = [];
foreach ($monthly_records as $record) {
    foreach ($quarters as $q_key => $q_label) {
        if (in_array((string)$record['year_month'], $fy_months[$q_key], true)) {
            $quarters_with_data[$q_key] = true;
        }
    }
}

// Aggregate progress per quarter (and per KPI category) for the selected year.
$quarter_totals = array_fill_keys(array_keys($quarters), ['sum' => 0.0, 'count' => 0]);
$quarter_category = [];
$quarter_rows = [];
foreach ($monthly_records as $record) {
    $record_month = (string)$record['year_month'];
    foreach ($quarters as $q_key => $q_label) {
        if (!in_array($record_month, $fy_months[$q_key], true)) continue;
        $pct = max(0.0, (float)$record['progress_pct']);
        $category = trim((string)($record['kpi_code'] ?? '')) ?: '—';
        $category = strtoupper(substr($category, 0, 1));
        $quarter_totals[$q_key]['sum'] += $pct;
        $quarter_totals[$q_key]['count']++;
        $quarter_category[$q_key][$category] = ($quarter_category[$q_key][$category] ?? 0.0) + $pct;
        $quarter_rows[$q_key][] = $record;
        break;
    }
}

// Quarters shown in the progress tables: honour the quarter filter.
$shown_quarters = $filter_quarter === 'all' ? array_keys($quarters) : [$filter_quarter];
$max_quarter_value = 1.0;

require_once __DIR__ . '/header.php';
?>

<div style="display: flex; flex-direction: column; gap: 20px;">

    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                🛡️ Performance Contract (PC) Management
            </h2>
            <span style="font-size: 0.8rem; color: #64748b;">Ministerial Indicator Weights &bull; Quarterly Performance Evaluation &bull; Amounts in KES</span>
        </div>

        <div style="display: flex; gap: 8px;">
            <button onclick="window.print();" style="background: white; border: 1px solid #cbd5e1; color: #334155; padding: 6px 14px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                🖨️ Print Evaluation Summary
            </button>
        </div>
    </div>

    <!-- Year + Quarter Filters -->
    <div style="background: white; padding: 16px 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <form method="GET" action="/modules/pc.php" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px;">
            <div>
                <label for="pcYear" style="display:block;font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Year</label>
                <select id="pcYear" name="year" style="min-width:140px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font-size:.8rem;">
                    <?php foreach ($fiscal_years as $fy): ?>
                        <option value="<?= htmlspecialchars($fy) ?>" <?= $fy === $filter_year ? 'selected' : '' ?>>FY <?= htmlspecialchars($fy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pcQuarter" style="display:block;font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Quarter</label>
                <select id="pcQuarter" name="quarter" style="min-width:180px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font-size:.8rem;">
                    <option value="all" <?= $filter_quarter === 'all' ? 'selected' : '' ?>>All Quarters</option>
                    <?php foreach ($quarters as $q_key => $q_label): ?>
                        <option value="<?= htmlspecialchars($q_key) ?>" <?= $q_key === $filter_quarter ? 'selected' : '' ?>><?= htmlspecialchars($q_label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" style="background:#0d47a1;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;">
                Apply Filters
            </button>
            <a href="/modules/pc.php" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:7px 14px;border-radius:6px;font-size:.78rem;font-weight:700;text-decoration:none;text-align:center;">
                Reset
            </a>
        </form>
    </div>

    <?php if ($filter_category !== ''): ?>
    <div style="background:#dbeafe;border:1px solid #93c5fd;border-radius:8px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <span style="font-size:.78rem;font-weight:800;color:#1e40af;">Filtered by indicator category: <?= htmlspecialchars($filter_category) ?></span>
        <a href="/modules/pc.php?<?= htmlspecialchars($keep_filter_qs()) ?>" style="font-size:.72rem;font-weight:800;color:#b91c1c;text-decoration:none;">Clear category filter &times;</a>
    </div>
    <?php endif; ?>

    <!-- Key Statistics: each card is a link that displays its information -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
        <?php foreach ($category_summary as $category => $summary):
            $category_average = $summary['count'] ? $summary['achievement_total'] / $summary['count'] : 0;
            $card_href = '/modules/pc.php?' . http_build_query(array_filter([
                'year' => $filter_year,
                'quarter' => $filter_quarter !== 'all' ? $filter_quarter : null,
                'category' => $category,
            ], static fn($v) => $v !== null && $v !== ''));
            $is_selected = $filter_category !== '' && strcasecmp($filter_category, $category) === 0;
        ?>
            <a href="<?= htmlspecialchars($card_href) ?>"
               aria-label="Show details for <?= htmlspecialchars($category) ?>"
               <?= $is_selected ? 'aria-current="true"' : '' ?>
               style="background: white; border: <?= $is_selected ? '2px' : '1px' ?> solid <?= $is_selected ? '#0d47a1' : '#e2e8f0' ?>; border-radius: 8px; padding: 16px; text-decoration: none; display: block; transition: box-shadow .2s, transform .2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                <span style="display:block;font-size: 0.72rem; font-weight: 800; color: #64748b; text-transform: uppercase;"><?= htmlspecialchars($category) ?></span>
                <span style="display:block;font-size: 1.4rem; font-weight: 800; color: #0d47a1; margin-top: 4px;">Weight: <?= number_format($summary['weight'], 1) ?>%</span>
                <span style="display:block;font-size: 0.75rem; color: #10b981; font-weight: 700; margin-top: 2px;">Average achievement: <?= number_format($category_average, 1) ?>%</span>
                <span style="display:block;font-size: 0.68rem; color: #94a3b8; font-weight: 600; margin-top: 8px;"><?= $summary['count'] ?> indicator<?= $summary['count'] === 1 ? '' : 's' ?> &bull; View details &rarr;</span>
            </a>
        <?php endforeach; ?>
        <a href="/modules/pc.php?<?= htmlspecialchars($keep_filter_qs()) ?>"
           style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:16px;text-decoration:none;display:block;transition:background .2s;">
            <span style="display:block;font-size: 0.72rem; font-weight: 800; color: #64748b; text-transform: uppercase;">Weighted Composite Achievement</span>
            <span style="display:block;font-size: 1.4rem; font-weight: 800; color: #065f46; margin-top: 4px;"><?= number_format($composite_achievement, 1) ?>%</span>
            <span style="display:block;font-size: 0.75rem; color: #64748b; font-weight: 700; margin-top: 2px;">Total recorded weight: <?= number_format($total_weight, 1) ?>%</span>
            <span style="display:block;font-size: 0.68rem; color: #94a3b8; font-weight: 600; margin-top: 8px;">Show all indicators &rarr;</span>
        </a>
        <?php if (!$kpis): ?><div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;color:#64748b;">No performance indicators are registered.</div><?php endif; ?>
    </div>

    <!-- Performance Contract KPI Table (all recorded fields) -->
    <div style="background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
        <div style="background: #0d47a1; color: white; padding: 12px 18px; font-size: 0.85rem; font-weight: 700; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <span>Performance Contract KPIs &bull; FY <?= htmlspecialchars($filter_year) ?><?= $filter_quarter !== 'all' ? ' &bull; ' . htmlspecialchars($quarters[$filter_quarter]) : ' &bull; All Quarters' ?></span>
            <span>Weighted Composite Achievement: <?= number_format($composite_achievement, 1) ?>%</span>
        </div>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; color: #475569; font-weight: 700; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; font-size: 0.7rem;">
                        <th style="padding: 12px 16px;">Code</th>
                        <th style="padding: 12px 16px;">Performance Indicator</th>
                        <th style="padding: 12px 16px;">Category</th>
                        <th style="padding: 12px 16px;">Delivery Unit</th>
                        <th style="padding: 12px 16px;">Parent KPI</th>
                        <th style="padding: 12px 16px; text-align: center;">Weight</th>
                        <th style="padding: 12px 16px;">Baseline</th>
                        <?php if ($has_target_column): ?><th style="padding: 12px 16px;">Target FY <?= htmlspecialchars($filter_year) ?></th><?php endif; ?>
                        <?php foreach ($fiscal_years as $fy): if ($fy === $filter_year) continue; ?>
                            <th style="padding: 12px 16px;">Target FY <?= htmlspecialchars($fy) ?></th>
                        <?php endforeach; ?>
                        <th style="padding: 12px 16px;">Actual</th>
                        <th style="padding: 12px 16px; text-align: center;">Achievement</th>
                        <th style="padding: 12px 16px;">Status</th>
                        <th style="padding: 12px 16px;">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$visible_kpis): ?>
                        <tr><td colspan="15" style="padding:24px;text-align:center;color:#64748b;">No performance indicators match the selected filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($visible_kpis as $k): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-weight: 800; color: #0d47a1; white-space: nowrap;">
                                [<?= htmlspecialchars($k['code']) ?>]
                            </td>
                            <td style="padding: 14px 16px; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($k['kpi_name']) ?></td>
                            <td style="padding: 14px 16px; color: #475569;"><?= htmlspecialchars($k['category'] ?? '—') ?></td>
                            <td style="padding: 14px 16px; color: #475569;"><?= htmlspecialchars($k['delivery_unit'] ?? '—') ?></td>
                            <td style="padding: 14px 16px; color: #64748b; font-size: .74rem;"><?= htmlspecialchars($k['parent_kpi_name'] ?? '—') ?></td>
                            <td style="padding: 14px 16px; text-align: center; font-weight: 700; color: #b45309; font-family: 'JetBrains Mono', monospace;">
                                <?= htmlspecialchars((string)$k['sub_kpi_weight']) ?>%
                            </td>
                            <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: #64748b;">
                                <?= htmlspecialchars((string)($k['target_baseline'] ?? '—')) ?>
                            </td>
                            <?php if ($has_target_column): ?>
                                <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; font-weight: 800; color: #0284c7; background: #eff6ff;">
                                    <?= htmlspecialchars((string)($k[$target_column] ?? '—')) ?>
                                </td>
                            <?php endif; ?>
                            <?php foreach ($fiscal_years as $fy): if ($fy === $filter_year) continue;
                                $col = 'target_' . str_replace('/', '_', $fy);
                            ?>
                                <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: #94a3b8;">
                                    <?= htmlspecialchars((string)($k[$col] ?? '—')) ?>
                                </td>
                            <?php endforeach; ?>
                            <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; font-weight: 700; color: #0f172a;">
                                <?= htmlspecialchars((string)($k['actual_2025_26'] ?? '—')) ?>
                            </td>
                            <td style="padding: 14px 16px; text-align: center;">
                                <span style="background: #ecfdf5; color: #065f46; font-weight: 800; padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-family: 'JetBrains Mono', monospace;">
                                    <?= htmlspecialchars((string)$k['current_achievement']) ?>%
                                </span>
                            </td>
                            <td style="padding: 14px 16px;">
                                <span style="background: #10b981; color: white; padding: 3px 8px; border-radius: 10px; font-size: 0.68rem; font-weight: 700;">
                                    <?= htmlspecialchars($k['status'] ?? 'Achieved') ?>
                                </span>
                            </td>
                            <td style="padding: 14px 16px; color: #64748b; font-size: .72rem; max-width: 260px;"><?= htmlspecialchars((string)($k['remarks'] ?? '')) ?: '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Quarterly Progress Gantt (respects the Year + Quarter filters) -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <div>
                <h3 style="font-size:1rem;font-weight:800;color:#0f172a;margin:0;">Quarterly Progress Gantt &bull; FY <?= htmlspecialchars($filter_year) ?></h3>
                <span style="font-size:.74rem;color:#64748b;">Total progress contribution recorded in monthly submissions, grouped per quarter.</span>
            </div>
            <span style="font-size:.72rem;font-weight:800;color:#0d47a1;background:#dbeafe;padding:4px 10px;border-radius:6px;">
                <?= $filter_quarter === 'all' ? 'Q1, Q2, Q3, Q4' : htmlspecialchars($quarters[$filter_quarter]) ?>
            </span>
        </div>
        <?php if (!$monthly_records): ?>
            <div style="padding:20px;text-align:center;color:#64748b;background:#f8fafc;border-radius:8px;">
                No monthly progress submissions are recorded, so quarterly totals cannot be computed for FY <?= htmlspecialchars($filter_year) ?>. The Performance Gantt chart below still displays all four quarters.
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px;font-size:.78rem;">
                <?php
                $max_quarter_value = 1.0;
                foreach ($shown_quarters as $q_key) { $max_quarter_value = max($max_quarter_value, $quarter_totals[$q_key]['sum']); }
                $max_quarter_value = max($max_quarter_value, 1.0);
                foreach ($shown_quarters as $q_key):
                    $total = $quarter_totals[$q_key]['sum'];
                    $width = min(100, ($total / $max_quarter_value) * 100);
                ?>
                    <div style="display:grid;grid-template-columns:70px 1fr 170px;align-items:center;gap:12px;">
                        <strong style="font-family:'JetBrains Mono',monospace;color:#334155;"><?= htmlspecialchars($q_key) ?></strong>
                        <div style="background:#f1f5f9;height:22px;border-radius:5px;overflow:hidden;border:1px solid #e2e8f0;">
                            <div style="background:#0d47a1;height:100%;width:<?= $width ?>%;transition:width .3s;"></div>
                        </div>
                        <div style="text-align:right;font-family:'JetBrains Mono',monospace;font-size:.72rem;">
                            <span style="color:#0f172a;font-weight:800;"><?= number_format($total, 1) ?>%</span>
                            <span style="color:#64748b;margin-left:6px;"><?= $quarter_totals[$q_key]['count'] ?> entr<?= $quarter_totals[$q_key]['count'] === 1 ? 'y' : 'ies' ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Performance Gantt Chart: left as is, always displays the 4 quarters -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <div>
                <h3 style="font-size:1rem;font-weight:800;color:#0f172a;margin:0;">Performance Gantt Chart</h3>
                <span style="font-size:.74rem;color:#64748b;">Category achievement across the four quarters of FY <?= htmlspecialchars($filter_year) ?>.</span>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:220px repeat(4,1fr);gap:8px;align-items:center;font-size:.74rem;">
            <span></span>
            <?php foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q_head): ?>
                <strong style="text-align:center;font-family:'JetBrains Mono',monospace;color:#475569;"><?= htmlspecialchars($q_head) ?></strong>
            <?php endforeach; ?>
            <?php foreach ($category_summary as $category => $summary):
                $category_average = $summary['count'] ? $summary['achievement_total'] / $summary['count'] : 0;
            ?>
                <span style="font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></span>
                <?php foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q_key):
                    $cat_key = strtoupper(substr(trim((string)$category), 0, 1));
                    $value = $quarter_category[$q_key][$cat_key] ?? 0.0;
                    $bar_width = min(100, ($value / $max_quarter_value) * 100);
                ?>
                    <div style="background:#f1f5f9;height:20px;border-radius:4px;overflow:hidden;border:1px solid #e2e8f0;" title="<?= htmlspecialchars($q_key) ?>: <?= number_format($value, 1) ?>%">
                        <?php if ($value > 0): ?><div style="background:#10b981;height:100%;width:<?= $bar_width ?>%;"></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
