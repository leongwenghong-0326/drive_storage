<?php
/**
 * AetherVault - System Audit & Security Activity Logs
 */

require_once dirname(__DIR__) . '/config/config.php';
require_admin();

$currentUser = auth_user();
$pdo = Database::getConnection();
$pageTitle = 'Security & Activity Audit Logs';

$actionFilter = trim($_GET['action'] ?? '');
$userFilter = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;

// Build query
$where = [];
$params = [];

if (!empty($actionFilter)) {
    $where[] = "l.action = ?";
    $params[] = $actionFilter;
}

if ($userFilter) {
    $where[] = "l.user_id = ?";
    $params[] = $userFilter;
}

$whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

$stmtLogs = $pdo->prepare("
    SELECT l.*, u.username, u.full_name
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.id
    {$whereSql}
    ORDER BY l.created_at DESC
    LIMIT 200
");
$stmtLogs->execute($params);
$logsList = $stmtLogs->fetchAll();

// Fetch distinct actions for filter dropdown
$distinctActions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch users for filter dropdown
$usersForFilter = $pdo->query("SELECT id, username, full_name FROM users ORDER BY username ASC")->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-shield-halved text-success"></i> Audit & Activity Security Logs
        </h1>
        <p class="page-header-subtitle">Real-time immutable tracking of authentication, storage actions, and administrative operations</p>
    </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
    <form action="logs.php" method="GET" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label small fw-semibold">Filter by Action Event</label>
            <select name="action" class="form-select">
                <option value="">All Action Types</option>
                <?php foreach ($distinctActions as $act): ?>
                    <option value="<?= e($act) ?>" <?= ($actionFilter === $act) ? 'selected' : '' ?>><?= e($act) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-5">
            <label class="form-label small fw-semibold">Filter by User Account</label>
            <select name="user_id" class="form-select">
                <option value="">All Users / Guests</option>
                <?php foreach ($usersForFilter as $uf): ?>
                    <option value="<?= $uf['id'] ?>" <?= ($userFilter === (int)$uf['id']) ? 'selected' : '' ?>>
                        <?= e($uf['full_name']) ?> (@<?= e($uf['username']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-primary">Filter Logs</button>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="file-table mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle small">
            <thead>
                <tr>
                    <th style="width: 170px;">Timestamp</th>
                    <th>Action</th>
                    <th>User / Operator</th>
                    <th>Target Resource</th>
                    <th>Event Description</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logsList)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No audit log records found matching your filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logsList as $log): ?>
                        <tr>
                            <td class="text-muted"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary"><?= e($log['action']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($log['username'])): ?>
                                    <span class="fw-semibold text-dark"><?= e($log['full_name']) ?></span>
                                    <span class="text-muted">(@<?= e($log['username']) ?>)</span>
                                <?php else: ?>
                                    <span class="text-muted fst-italic">Anonymous / System</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($log['resource_type'])): ?>
                                    <span class="badge bg-light text-secondary border"><?= e($log['resource_type']) ?> #<?= (int)$log['resource_id'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-dark fw-medium text-break" style="max-width: 340px;"><?= e($log['description']) ?></td>
                            <td><code><?= e($log['ip_address']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
