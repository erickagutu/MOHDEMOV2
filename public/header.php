<?php
/**
 * Ministerial Application Header
 * Ministry of Health — State Department for Medical Services (PP-SDOMS)
 */
require_once __DIR__ . '/../database/db.php';
$current_user = get_current_user_data();
$active_tab = $active_tab ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(get_csrf_token()) ?>">
    <title><?= htmlspecialchars($page_title ?? 'State Department for Medical Services - PP-SDOMS') ?></title>
    
    <!-- Typography & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Vanilla CSS Stylesheets -->
    <link rel="stylesheet" href="/assets/css/stylelogin.css">
    <link rel="stylesheet" href="/assets/css/module-pages.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261015-diary">
    <?php foreach (($page_stylesheets ?? []) as $page_stylesheet): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($page_stylesheet) ?>">
    <?php endforeach; ?>
</head>
<body>

<!-- Top Ministerial Header -->
<header class="top-header">
    <div class="brand-container">
        <img src="/assets/images/kenya_coat_arms.png" alt="Coat of Arms of Kenya" class="coat-arms" style="height: 52px; width: auto;" onerror="this.src='/public/assets/images/kenya_coat_arms.png';">
        <div>
            <h1 style="font-size: 1.15rem; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; margin: 0; line-height: 1.2;">
                STATE DEPARTMENT FOR MEDICAL SERVICES
            </h1>
            <p style="font-size: 0.8rem; margin: 0; opacity: 0.88; font-weight: 500;">
                Performance Management Dashboard
            </p>
        </div>
    </div>

    <div style="display: flex; align-items: center; gap: 14px;">
        <div class="notification-control">
            <button id="notificationButton" type="button" class="notif-bell" aria-label="System notifications" aria-expanded="false" aria-controls="notificationPanel">
                <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                <span id="notificationBadge" class="notif-badge" hidden>0</span>
            </button>
            <section id="notificationPanel" class="notification-panel" hidden aria-label="Notifications">
                <div class="notification-panel-head">
                    <div><strong>Notifications</strong><span id="notificationSummary">Loading updates...</span></div>
                    <button id="notificationMarkAll" type="button" class="notification-mark-all">Mark all read</button>
                </div>
                <div id="notificationList" class="notification-list"></div>
            </section>
        </div>
        <!-- User Profile Pill -->
        <div style="background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.25); padding: 5px 12px; border-radius: 20px; display: flex; align-items: center; gap: 8px;">
            <div style="text-align: right; line-height: 1.2;">
                <div style="font-size: 0.75rem; font-weight: 700; color: #fff;"><?= htmlspecialchars($current_user['full_name']) ?></div>
                <div style="font-size: 0.65rem; color: #bae6fd;">[<?= htmlspecialchars($current_user['role']) ?>]</div>
            </div>
        </div>

        <a href="/logout.php" style="background: #00008B; color: #ffffff; padding: 6px 12px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-decoration: none; border: 1px solid #00008B;">
            Logout
        </a>
    </div>
</header>

<!-- Top Horizontal Navigation Bar -->
<nav class="main-nav" aria-label="Main navigation">
    <ul class="nav-links">
        <?php
        $nav_items = [
            'dashboard' => ['label' => 'DASHBOARD', 'url' => '/dashboard.php'],
            'flagships' => ['label' => 'PRIORITY FLAGSHIPS', 'url' => '/modules/flagships.php'],
            'events' => ['label' => 'ACTIVITIES AND EVENTS', 'url' => '/modules/events.php'],
            'actions' => ['label' => 'ACTION POINTS TRACKER', 'url' => '/modules/actions.php'],
            'meetings' => ['label' => 'MEETING MINUTES', 'url' => '/modules/meetings.php'],
            'projects' => ['label' => 'PROJECTS AND PROGRAMMES', 'url' => '/modules/projects.php'],
            'reports' => ['label' => 'REPORTS', 'url' => '/modules/reports.php'],
            'diary' => ['label' => 'DIARY', 'url' => '/modules/diary.php'],
            'pc' => ['label' => 'PERFORMANCE CONTRACT', 'url' => '/modules/pc.php'],
            'users' => ['label' => 'USERS', 'url' => '/modules/users.php'],
            'audit_logs' => ['label' => 'AUDIT LOGS', 'url' => '/modules/audit_logs.php'],
        ];
        $nav_icons = [
            'dashboard' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
            'flagships' => '<path d="M5 21V4m0 1h12l-2 4 2 4H5"/>',
            'events' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
            'actions' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2m-6 8 2 2 4-4"/>',
            'meetings' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M9 7h7M9 11h7"/>',
            'projects' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V4h8v3M9 11v6m6-6v6"/>',
            'reports' => '<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h7"/>',
            'diary' => '<path d="M6 4h12v17l-6-4-6 4z"/><circle cx="12" cy="9" r="2"/>',
            'pc' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6m-6 4h6m-6 4h4"/>',
            'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-1a6 6 0 0 1 12 0v1m2-12a3 3 0 0 1 0 6m1 2a5 5 0 0 1 3 4v1"/>',
            'audit_logs' => '<path d="M5 4h14v17H5zM8 8h8m-8 4h8m-8 4h5"/>',
        ];

        foreach ($nav_items as $key => $item):
            $is_current = ($active_tab === $key);
        ?>
            <li class="nav-item">
                <a href="<?= $item['url'] ?>" class="nav-link<?= $is_current ? ' active' : '' ?>" <?= $is_current ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $nav_icons[$key] ?? $nav_icons['dashboard'] ?></svg>
                    <?= $item['label'] ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- Core Values Marquee Bar -->
<div class="core-values-banner">
    <div class="core-values-marquee"><span>CORE VALUES: Integrity, Professionalism, Accountability, Ethics, Teamwork, Delivery, Impact.</span></div>
</div>

<main style="flex: 1; padding: 24px; max-width: 1400px; margin: 0 auto; width: 100%;">
