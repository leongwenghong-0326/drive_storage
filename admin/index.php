<?php
/**
 * AetherVault - Master Administrator Dashboard
 */

require_once dirname(__DIR__) . '/config/config.php';
require_admin();

$user = auth_user();
$pdo = Database::getConnection();
$pageTitle = 'Administrator Dashboard';

// Metrics Calculation
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$inactiveUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn();

$totalFiles = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = 0")->fetchColumn();
$totalStorageUsed = (int)$pdo->query("SELECT COALESCE(SUM(storage_used), 0) FROM users")->fetchColumn();
$totalStorageAssigned = (int)$pdo->query("SELECT COALESCE(SUM(storage_quota), 0) FROM users")->fetchColumn();

$storagePercent = $totalStorageAssigned > 0 ? min(100, round(($totalStorageUsed / $totalStorageAssigned) * 100, 1)) : 0;

// Fetch Recent Users
$recentUsers = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();

// Fetch Recent Uploads Across Platform
$recentUploads = $pdo->query("
    SELECT f.*, u.username, u.full_name AS owner_name
    FROM files f
    JOIN users u ON f.user_id = u.id
    WHERE f.is_deleted = 0
    ORDER BY f.created_at DESC
    LIMIT 6
")->fetchAll();

// Fetch Recent Audit Logs
$recentLogs = $pdo->query("
    SELECT l.*, u.username
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 8
")->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-chart-line text-primary"></i> Master Administrator Control Center
        </h1>
        <p class="page-header-subtitle">Platform health metrics, quota consumption, tenant monitoring, and security audit</p>
    </div>
    <div class="d-flex gap-2">
        <a href="user_create.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-user-plus me-1"></i> Provision User
        </a>
        <a href="settings.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-sliders me-1"></i> System Config
        </a>
    </div>
</div>

<!-- Admin Stat Cards Row -->
<div class="row g-4 mb-4">
    <!-- Total Users -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small fw-semibold text-uppercase">Total Accounts</span>
                <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <h3 class="fw-bold text-dark mb-1"><?= number_format($totalUsers) ?></h3>
            <div class="small text-muted">
                <span class="text-success fw-semibold"><?= $activeUsers ?> active</span> &bull; 
                <span class="text-secondary"><?= $inactiveUsers ?> disabled</span>
            </div>
        </div>
    </div>

    <!-- Total Storage Consumed -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small fw-semibold text-uppercase">Cluster Storage Used</span>
                <div class="stat-icon-wrapper bg-success-subtle text-success">
                    <i class="fa-solid fa-database"></i>
                </div>
            </div>
            <h3 class="fw-bold text-dark mb-1"><?= format_bytes($totalStorageUsed) ?></h3>
            <div class="small text-muted">Allocated: <?= format_bytes($totalStorageAssigned) ?> (<?= $storagePercent ?>%)</div>
        </div>
    </div>

    <!-- Total System Files -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small fw-semibold text-uppercase">Platform Files</span>
                <div class="stat-icon-wrapper bg-info-subtle text-info">
                    <i class="fa-solid fa-file-shield"></i>
                </div>
            </div>
            <h3 class="fw-bold text-dark mb-1"><?= number_format($totalFiles) ?></h3>
            <div class="small text-muted">Across all user drives</div>
        </div>
    </div>

    <!-- Storage Utilization Bar Card -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small fw-semibold text-uppercase">Quota Saturation</span>
                <div class="stat-icon-wrapper bg-warning-subtle text-warning">
                    <i class="fa-solid fa-gauge-high"></i>
                </div>
            </div>
            <h3 class="fw-bold text-dark mb-1"><?= $storagePercent ?>%</h3>
            <div class="progress mt-2" style="height: 6px;">
                <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $storagePercent ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Left Column: Recent Users & Global Files -->
    <div class="col-lg-7">
        <!-- Recent Users Card -->
        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark m-0">Recent User Accounts</h5>
                <a href="users.php" class="btn btn-sm btn-link text-decoration-none">View All Users &rarr;</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Storage Used</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUsers as $ru): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($ru['full_name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= e($ru['email']) ?></div>
                                </td>
                                <td><span class="badge bg-light text-secondary border"><?= ucfirst($ru['role']) ?></span></td>
                                <td>
                                    <?php if ($ru['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= format_bytes($ru['storage_used']) ?> / <?= format_bytes($ru['storage_quota']) ?></td>
                                <td><?= date('M d, Y', strtotime($ru['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Global Recent Uploads -->
        <div class="card border-0 shadow-sm p-4" style="border-radius: var(--radius-lg);">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark m-0">Platform Recent Uploads</h5>
                <a href="files.php" class="btn btn-sm btn-link text-decoration-none">All Files &rarr;</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Owner</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUploads as $rf): 
                            $meta = get_file_icon_meta($rf['extension'], $rf['mime_type']);
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="fa-solid <?= $meta['icon'] ?> me-2" style="color: <?= $meta['color'] ?>;"></i>
                                        <span class="text-dark fw-semibold text-truncate" style="max-width: 220px;" title="<?= e($rf['original_name']) ?>">
                                            <?= e($rf['original_name']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td><span class="text-muted"><?= e($rf['owner_name']) ?></span></td>
                                <td><?= format_bytes($rf['file_size']) ?></td>
                                <td><?= date('M d, H:i', strtotime($rf['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Column: Audit Event Feed -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm p-4 h-100" style="border-radius: var(--radius-lg);">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark m-0">Live Audit & Security Events</h5>
                <a href="logs.php" class="btn btn-sm btn-link text-decoration-none">Full Logs &rarr;</a>
            </div>

            <div class="list-group list-group-flush small">
                <?php foreach ($recentLogs as $log): ?>
                    <div class="list-group-item px-0 py-2 border-bottom">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="badge bg-primary-subtle text-primary"><?= e($log['action']) ?></span>
                            <span class="text-muted" style="font-size: 0.72rem;"><?= date('M d, H:i:s', strtotime($log['created_at'])) ?></span>
                        </div>
                        <div class="text-dark fw-medium text-break"><?= e($log['description']) ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;">
                            User: <strong><?= e($log['username'] ?? 'System / Guest') ?></strong> &bull; IP: <?= e($log['ip_address']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
