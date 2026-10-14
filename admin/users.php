<?php
/**
 * AetherVault - Admin User Management
 */

require_once dirname(__DIR__) . '/config/config.php';
require_admin();

$currentUser = auth_user();
$pdo = Database::getConnection();
$pageTitle = 'User Management';

$search = trim($_GET['search'] ?? '');

// Handle Quick Toggle Status (Activate / Deactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'toggle_status') {
    Csrf::requireValid();

    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId === (int)$currentUser['id']) {
        set_flash('error', 'Security rule: You cannot deactivate your own active administrator account.');
    } else {
        $stmtU = $pdo->prepare("SELECT username, status, role FROM users WHERE id = ?");
        $stmtU->execute([$targetUserId]);
        $target = $stmtU->fetch();

        if ($target) {
            $newStatus = ($target['status'] === 'active') ? 'inactive' : 'active';
            $up = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
            $up->execute([$newStatus, $targetUserId]);

            log_activity($currentUser['id'], 'Edit User', 'User', $targetUserId, "Changed status of user '{$target['username']}' to {$newStatus}");
            set_flash('success', "User '{$target['username']}' is now {$newStatus}.");
        }
    }
    redirect('admin/users.php' . ($search ? '?search=' . urlencode($search) : ''));
}

// Handle Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'delete_user') {
    Csrf::requireValid();

    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId === (int)$currentUser['id']) {
        set_flash('error', 'Security rule: You cannot delete your own logged-in account.');
    } else {
        // Prevent deleting the only administrator
        $stmtAdminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $adminCount = (int)$stmtAdminCount->fetchColumn();

        $stmtTarget = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
        $stmtTarget->execute([$targetUserId]);
        $target = $stmtTarget->fetch();

        if ($target && $target['role'] === 'admin' && $adminCount <= 1) {
            set_flash('error', 'Cannot delete the only existing administrator on the platform.');
        } elseif ($target) {
            // Delete physical files belonging to this user
            $stmtFiles = $pdo->prepare("SELECT id FROM files WHERE user_id = ?");
            $stmtFiles->execute([$targetUserId]);
            $userFiles = $stmtFiles->fetchAll();
            foreach ($userFiles as $f) {
                StorageEngine::permanentlyDeleteFile((int)$f['id'], $targetUserId);
            }

            // Delete user record (foreign keys cascade will clear folders, tags, logs, shares)
            $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $del->execute([$targetUserId]);

            log_activity($currentUser['id'], 'Delete User', 'User', $targetUserId, "Deleted user account '{$target['username']}'");
            set_flash('success', "User '{$target['username']}' was deleted successfully.");
        }
    }
    redirect('admin/users.php');
}

// Fetch users with search
if (!empty($search)) {
    $searchWildcard = '%' . $search . '%';
    $stmtUsers = $pdo->prepare("
        SELECT * FROM users 
        WHERE username LIKE ? OR email LIKE ? OR full_name LIKE ?
        ORDER BY created_at DESC
    ");
    $stmtUsers->execute([$searchWildcard, $searchWildcard, $searchWildcard]);
} else {
    $stmtUsers = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
}
$usersList = $stmtUsers->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-users text-primary"></i> Tenant & User Directory
        </h1>
        <p class="page-header-subtitle">Manage user accounts, assign storage limits, and control platform access</p>
    </div>
    <a href="user_create.php" class="btn btn-primary btn-sm px-3">
        <i class="fa-solid fa-user-plus me-1"></i> Provision New User
    </a>
</div>

<!-- Search Filter Bar -->
<div class="card border-0 shadow-sm p-3 mb-4" style="border-radius: var(--radius-lg);">
    <form action="users.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-10">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name, username, or email..." value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-primary">Filter Users</button>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="file-table mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Storage Usage</th>
                    <th>Registered</th>
                    <th class="text-end" style="width: 180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($usersList)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No user accounts found matching your query.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($usersList as $u): 
                        $quota = (int)$u['storage_quota'];
                        $used = (int)$u['storage_used'];
                        $percent = $quota > 0 ? min(100, round(($used / $quota) * 100)) : 0;
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="topbar-user-avatar me-3" style="width: 38px; height: 38px;">
                                        <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold text-dark"><?= e($u['full_name']) ?></div>
                                        <div class="text-muted small">@<?= e($u['username']) ?> &bull; <?= e($u['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-secondary border"><?= ucfirst($u['role']) ?></span>
                            </td>
                            <td>
                                <?php if ($u['status'] === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?= format_bytes($used) ?> / <?= format_bytes($quota) ?></div>
                                <div class="progress mt-1" style="height: 5px; width: 140px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $percent ?>%;"></div>
                                </div>
                            </td>
                            <td><span class="text-muted small"><?= date('M d, Y', strtotime($u['created_at'])) ?></span></td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <!-- Edit Link -->
                                    <a href="user_edit.php?id=<?= $u['id'] ?>" class="btn btn-light btn-sm text-primary" title="Edit User">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>

                                    <!-- Activate / Deactivate Toggle -->
                                    <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                                        <form method="POST" action="" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action_type" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-light btn-sm <?= $u['status'] === 'active' ? 'text-warning' : 'text-success' ?>" title="<?= $u['status'] === 'active' ? 'Deactivate User' : 'Activate User' ?>">
                                                <i class="fa-solid <?= $u['status'] === 'active' ? 'fa-ban' : 'fa-circle-check' ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Delete User -->
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('WARNING: Are you sure you want to delete user \'<?= e(addslashes($u['username'])) ?>\' and all associated files? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action_type" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-light btn-sm text-danger" title="Delete User">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
