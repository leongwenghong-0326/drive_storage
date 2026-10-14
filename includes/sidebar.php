<?php
/**
 * AetherVault - Application Sidebar Navigation
 */

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$isAdminSection = ($currentDir === 'admin');
?>
<aside class="app-sidebar">
    <!-- Brand Title -->
    <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-brand">
        <div class="brand-icon-box">
            <i class="fa-solid fa-cloud-arrow-up"></i>
        </div>
        <span>AetherVault</span>
    </a>

    <!-- Navigation Menu Items -->
    <div class="sidebar-nav">
        <div class="sidebar-section-title">Workspace</div>
        
        <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link-custom <?= (!$isAdminSection && $currentScript === 'dashboard.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>

        <a href="<?= BASE_URL ?>/files.php" class="nav-link-custom <?= (!$isAdminSection && in_array($currentScript, ['files.php', 'folders.php'])) ? 'active' : '' ?>">
            <i class="fa-solid fa-folder-open"></i>
            <span>My Storage</span>
        </a>

        <a href="<?= BASE_URL ?>/recent.php" class="nav-link-custom <?= (!$isAdminSection && $currentScript === 'recent.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>Recent</span>
        </a>

        <a href="<?= BASE_URL ?>/starred.php" class="nav-link-custom <?= (!$isAdminSection && $currentScript === 'starred.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-star text-warning"></i>
            <span>Starred</span>
        </a>

        <a href="<?= BASE_URL ?>/tags.php" class="nav-link-custom <?= (!$isAdminSection && $currentScript === 'tags.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-tags text-info"></i>
            <span>Tags & Labels</span>
        </a>

        <a href="<?= BASE_URL ?>/trash.php" class="nav-link-custom <?= (!$isAdminSection && $currentScript === 'trash.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-trash-can text-danger"></i>
            <span>Trash Bin</span>
        </a>

        <?php if (auth_is_admin()): ?>
            <div class="sidebar-section-title mt-3">Administration</div>
            
            <a href="<?= BASE_URL ?>/admin/index.php" class="nav-link-custom <?= ($isAdminSection && $currentScript === 'index.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-line text-primary"></i>
                <span>Admin Overview</span>
            </a>

            <a href="<?= BASE_URL ?>/admin/users.php" class="nav-link-custom <?= ($isAdminSection && in_array($currentScript, ['users.php', 'user_create.php', 'user_edit.php'])) ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i>
                <span>User Management</span>
            </a>

            <a href="<?= BASE_URL ?>/admin/files.php" class="nav-link-custom <?= ($isAdminSection && $currentScript === 'files.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-server"></i>
                <span>System Files</span>
            </a>

            <a href="<?= BASE_URL ?>/admin/logs.php" class="nav-link-custom <?= ($isAdminSection && $currentScript === 'logs.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-shield-halved text-success"></i>
                <span>Audit Logs</span>
            </a>

            <a href="<?= BASE_URL ?>/admin/settings.php" class="nav-link-custom <?= ($isAdminSection && $currentScript === 'settings.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-gear"></i>
                <span>Configuration</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- Storage Usage Widget -->
    <div class="sidebar-storage-widget">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold text-white">Storage Usage</span>
            <span class="small text-muted"><?= $storagePercentText ?></span>
        </div>
        <div class="storage-progress">
            <div class="storage-progress-bar" style="width: <?= $storageProgressWidth ?>%;"></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2" style="font-size: 0.76rem;">
            <span class="text-secondary"><?= $storageUsedText ?></span>
            <span class="text-muted">of <?= $storageQuotaText ?></span>
        </div>
    </div>
</aside>
