<?php
/**
 * AetherVault - Edit User & Quota Configuration
 */

require_once dirname(__DIR__) . '/config/config.php';
require_admin();

$currentUser = auth_user();
$pdo = Database::getConnection();
$userId = (int)($_GET['id'] ?? 0);

if (!$userId) {
    redirect('admin/users.php');
}

$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$targetUser = $stmtUser->fetch();

if (!$targetUser) {
    set_flash('error', 'User account not found.');
    redirect('admin/users.php');
}

$error = '';
$currentQuotaGb = round($targetUser['storage_quota'] / (1024 * 1024 * 1024), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : $targetUser['role'];
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : $targetUser['status'];
    $quotaGb = (float)($_POST['storage_quota_gb'] ?? $currentQuotaGb);
    $newPassword = $_POST['new_password'] ?? '';

    // Safety check: Cannot demote or deactivate self
    if ($userId === (int)$currentUser['id']) {
        $role = 'admin';
        $status = 'active';
    }

    if (empty($fullName) || empty($email)) {
        $error = 'Full name and email cannot be empty.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check uniqueness of email
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $userId]);
        if ($chk->fetch()) {
            $error = 'Email address already associated with another user.';
        } else {
            $quotaBytes = (int)round($quotaGb * 1024 * 1024 * 1024);

            if (!empty($newPassword)) {
                if (strlen($newPassword) < 8) {
                    $error = 'New password must be at least 8 characters.';
                } else {
                    $passHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmtUp = $pdo->prepare("
                        UPDATE users 
                        SET full_name = ?, email = ?, role = ?, status = ?, storage_quota = ?, password_hash = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmtUp->execute([$fullName, $email, $role, $status, $quotaBytes, $passHash, $userId]);
                    log_activity($currentUser['id'], 'Reset Password', 'User', $userId, "Reset password for user '{$targetUser['username']}'");
                }
            } else {
                $stmtUp = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, role = ?, status = ?, storage_quota = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUp->execute([$fullName, $email, $role, $status, $quotaBytes, $userId]);
            }

            if (!$error) {
                log_activity($currentUser['id'], 'Edit User', 'User', $userId, "Updated profile/quota for '{$targetUser['username']}' (Quota: {$quotaGb} GB)");
                set_flash('success', "Account '{$targetUser['username']}' updated successfully.");
                redirect('admin/users.php');
            }
        }
    }
}

$pageTitle = 'Edit User: ' . $targetUser['username'];
require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="users.php" class="btn btn-outline-secondary btn-sm rounded-circle" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="page-header-title">Edit User Account: <?= e($targetUser['username']) ?></h1>
            <p class="page-header-subtitle">Update identity, permissions, storage limitations, or reset password</p>
        </div>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm p-4 p-md-5" style="border-radius: var(--radius-lg);">
            <?php if ($error): ?>
                <div class="alert alert-danger border-0 small py-2 px-3 mb-4"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Username</label>
                        <input type="text" class="form-control bg-light" value="<?= e($targetUser['username']) ?>" readonly disabled>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= e($targetUser['full_name']) ?>" required>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= e($targetUser['email']) ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Role</label>
                        <select name="role" class="form-select" <?= ($userId === (int)$currentUser['id']) ? 'disabled' : '' ?>>
                            <option value="user" <?= $targetUser['role'] === 'user' ? 'selected' : '' ?>>Normal User</option>
                            <option value="admin" <?= $targetUser['role'] === 'admin' ? 'selected' : '' ?>>Platform Administrator</option>
                        </select>
                        <?php if ($userId === (int)$currentUser['id']): ?>
                            <input type="hidden" name="role" value="admin">
                            <div class="form-text text-muted">You cannot demote your own account.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Status</label>
                        <select name="status" class="form-select" <?= ($userId === (int)$currentUser['id']) ? 'disabled' : '' ?>>
                            <option value="active" <?= $targetUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $targetUser['status'] === 'inactive' ? 'selected' : '' ?>>Inactive / Suspended</option>
                        </select>
                        <?php if ($userId === (int)$currentUser['id']): ?>
                            <input type="hidden" name="status" value="active">
                            <div class="form-text text-muted">You cannot deactivate your own account.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Storage Quota (GB)</label>
                        <input type="number" step="0.5" name="storage_quota_gb" class="form-control" value="<?= e($currentQuotaGb) ?>" required>
                        <div class="form-text text-muted">Current usage: <?= format_bytes($targetUser['storage_used']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Reset Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep unchanged">
                        <div class="form-text text-muted">Only fill if resetting password</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <a href="users.php" class="btn btn-light px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-check me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
