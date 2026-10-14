<?php
/**
 * AetherVault - Global Header & Topbar
 */

if (!defined('AETHER_VAULT_INIT')) {
    require_once dirname(__DIR__) . '/config/config.php';
}

$currentUser = auth_user();
$pageTitle = $pageTitle ?? 'Cloud Storage';
$appName = get_setting('app_name', 'AetherVault Cloud');

// Storage statistics for user
$quotaBytes = $currentUser['storage_quota'] ?? 10737418240;
$usedBytes = $currentUser['storage_used'] ?? 0;
$rawStoragePercent = $quotaBytes > 0 ? ($usedBytes / $quotaBytes) * 100 : 0;
$storagePercent = min(100, round($rawStoragePercent));
$storagePercentText = ($usedBytes > 0 && $rawStoragePercent < 1) ? '< 1%' : "{$storagePercent}%";
$storageProgressWidth = ($usedBytes > 0 && $storagePercent < 2) ? 2 : $storagePercent;
$storageUsedText = format_bytes($usedBytes);
$storageQuotaText = format_bytes($quotaBytes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title><?= e($pageTitle) ?> — <?= e($appName) ?></title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom Design System CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<div class="app-wrapper">
    <!-- Include Responsive Sidebar -->
    <?php require_once INCLUDES_DIR . '/sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="app-main">
        <!-- Sticky Top Navigation Bar -->
        <header class="app-topbar">
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <!-- Search Input Form -->
                <form action="<?= BASE_URL ?>/search.php" method="GET" class="topbar-search">
                    <i class="fa-solid fa-magnifying-glass topbar-search-icon"></i>
                    <input type="text" name="q" placeholder="Search files, folders or tags..." value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
                </form>
            </div>

            <!-- User Menu & Actions -->
            <div class="d-flex align-items-center gap-3">
                <!-- User Profile Dropdown -->
                <div class="dropdown">
                    <div class="d-flex align-items-center gap-2 cursor-pointer" data-bs-toggle="dropdown" aria-expanded="false" role="button">
                        <div class="topbar-user-avatar">
                            <?= strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <div class="d-none d-md-block text-start">
                            <div class="fw-semibold small leading-tight text-dark"><?= e($currentUser['full_name'] ?? 'User') ?></div>
                            <div class="text-muted" style="font-size: 0.75rem;"><?= ucfirst($currentUser['role'] ?? 'user') ?></div>
                        </div>
                        <i class="fa-solid fa-chevron-down text-muted small ms-1 d-none d-md-inline"></i>
                    </div>

                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 py-2 mt-2" style="border-radius: var(--radius-md); min-width: 220px;">
                        <li class="px-3 py-2 border-bottom mb-1">
                            <div class="fw-bold text-dark"><?= e($currentUser['full_name'] ?? '') ?></div>
                            <div class="small text-muted text-truncate"><?= e($currentUser['email'] ?? '') ?></div>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="<?= BASE_URL ?>/profile.php">
                                <i class="fa-solid fa-user me-2 text-muted"></i> My Profile
                            </a>
                        </li>
                        <?php if (auth_is_admin()): ?>
                            <li>
                                <a class="dropdown-item py-2 text-primary" href="<?= BASE_URL ?>/admin/index.php">
                                    <i class="fa-solid fa-shield-halved me-2"></i> Admin Panel
                                </a>
                            </li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                            <a class="dropdown-item py-2 text-danger" href="<?= BASE_URL ?>/logout.php">
                                <i class="fa-solid fa-right-from-bracket me-2"></i> Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Dynamic Content Body -->
        <div class="content-body">
            <!-- Flash Notification Render -->
            <?php 
            $flashMessages = get_flash();
            if (!empty($flashMessages)): 
                foreach ($flashMessages as $type => $messages):
                    $alertClass = $type === 'success' ? 'alert-success' : ($type === 'error' ? 'alert-danger' : 'alert-info');
                    $iconClass = $type === 'success' ? 'fa-circle-check' : ($type === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-info');
                    foreach ($messages as $msg):
            ?>
                <div class="alert <?= $alertClass ?> alert-dismissible fade show border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
                    <i class="fa-solid <?= $iconClass ?> me-2 fs-5"></i>
                    <div><?= e($msg) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php 
                    endforeach;
                endforeach;
            endif; 
            ?>
