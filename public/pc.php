<?php
/**
 * Performance Contract (PC) Module
 * Ministry of Health — State Department for Medical Services (PP-SDOMS)
 * Native PHP 8.1+ & MySQL (No Node.js)
 */
require_once __DIR__ . '/../config/db.php';
require_auth();

$page_title = 'Performance Contract — State Department for Medical Services';
$active_tab = 'pc';

// Fetch KPIs
$kpis = [];
$category_summary = [];
$total_weight = 0.0;
$weighted_achievement = 0.0;
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM performance_kpis ORDER BY id ASC");
        $kpis = $stmt->fetchAll();
    } catch (Exception $e) {}
}
foreach ($kpis as $kpi) {
    $category = trim((string)($kpi['category'] ?? '')) ?: 'Uncategorized';
    $weight = (float)($kpi['sub_kpi_weight'] ?? 0);
    $achievement = (float)($kpi['current_achievement'] ?? 0);
    if (!isset($category_summary[$category])) $category_summary[$category] = ['weight' => 0.0, 'achievement_total' => 0.0, 'count' => 0];
    $category_summary[$category]['weight'] += $weight;
    $category_summary[$category]['achievement_total'] += $achievement;
    $category_summary[$category]['count']++;
    $total_weight += $weight;
    $weighted_achievement += $achievement * $weight;
}
$composite_achievement = $total_weight > 0 ? $weighted_achievement / $total_weight : 0.0;

require_once __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; flex-direction: column; gap: 20px;">

    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                🛡️ Performance Contract (PC) Management
            </h2>
            <span style="font-size: 0.8rem; color: #64748b;">FY 2025/2026 &bull; Ministerial Indicator Weights &amp; Composite Achievement Evaluation</span>
        </div>

        <div style="display: flex; gap: 8px;">
            <button onclick="window.print();" style="background: white; border: 1px solid #cbd5e1; color: #334155; padding: 6px 14px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                🖨️ Print Evaluation Summary
            </button>
        </div>
    </div>

    <!-- Category Weights Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
        <?php foreach ($category_summary as $category => $summary): $category_average = $summary['count'] ? $summary['achievement_total'] / $summary['count'] : 0; ?>
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                <div style="font-size: 0.72rem; font-weight: 800; color: #64748b; text-transform: uppercase;"><?= htmlspecialchars($category) ?></div>
                <div style="font-size: 1.4rem; font-weight: 800; color: #0d47a1; margin-top: 4px;">Weight: <?= number_format($summary['weight'], 1) ?>%</div>
                <div style="font-size: 0.75rem; color: #10b981; font-weight: 700; margin-top: 2px;">Average achievement: <?= number_format($category_average, 1) ?>%</div>
            </div>
        <?php endforeach; ?>
        <?php if (!$category_summary): ?><div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;color:#64748b;">No performance indicators are registered.</div><?php endif; ?>
    </div>

    <!-- Performance Contract Table -->
    <div style="background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
        <div style="background: #0d47a1; color: white; padding: 12px 18px; font-size: 0.85rem; font-weight: 700; display: flex; justify-content: space-between;">
            <span>Performance Contract Key Performance Indicators (Recorded Weight: <?= number_format($total_weight, 1) ?>%)</span>
            <span>Weighted Composite Achievement: <?= number_format($composite_achievement, 1) ?>%</span>
        </div>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; color: #475569; font-weight: 700; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; font-size: 0.72rem;">
                        <th style="padding: 12px 16px;">Code</th>
                        <th style="padding: 12px 16px;">Performance Indicator &amp; Delivery Unit</th>
                        <th style="padding: 12px 16px; text-align: center;">Weight</th>
                        <th style="padding: 12px 16px;">Baseline</th>
                        <th style="padding: 12px 16px;">Target</th>
                        <th style="padding: 12px 16px;">Actual</th>
                        <th style="padding: 12px 16px; text-align: center;">Achievement</th>
                        <th style="padding: 12px 16px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$kpis): ?><tr><td colspan="8" style="padding:24px;text-align:center;color:#64748b;">No performance indicators are available in the database.</td></tr><?php endif; ?>
                    <?php foreach ($kpis as $k): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-weight: 800; color: #0d47a1;">
                                [<?= htmlspecialchars($k['code']) ?>]
                            </td>
                            <td style="padding: 14px 16px;">
                                <div style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($k['kpi_name']) ?></div>
                                <div style="font-size: 0.72rem; color: #64748b;"><?= htmlspecialchars($k['delivery_unit'] ?? $k['category']) ?></div>
                            </td>
                            <td style="padding: 14px 16px; text-align: center; font-weight: 700; color: #b45309; font-family: 'JetBrains Mono', monospace;">
                                <?= $k['sub_kpi_weight'] ?>%
                            </td>
                            <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: #64748b;">
                                <?= htmlspecialchars($k['target_baseline']) ?>
                            </td>
                            <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; font-weight: 700; color: #0284c7;">
                                <?= htmlspecialchars($k['target_2025_26']) ?>
                            </td>
                            <td style="padding: 14px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; font-weight: 700; color: #0f172a;">
                                <?= htmlspecialchars($k['actual_2025_26']) ?>
                            </td>
                            <td style="padding: 14px 16px; text-align: center;">
                                <span style="background: #ecfdf5; color: #065f46; font-weight: 800; padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-family: 'JetBrains Mono', monospace;">
                                    <?= $k['current_achievement'] ?>%
                                </span>
                            </td>
                            <td style="padding: 14px 16px;">
                                <span style="background: #10b981; color: white; padding: 3px 8px; border-radius: 10px; font-size: 0.68rem; font-weight: 700;">
                                    <?= htmlspecialchars($k['status'] ?? 'Achieved') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
