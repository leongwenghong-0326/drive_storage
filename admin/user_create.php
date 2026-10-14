<?php
/**
 * AetherVault - Provision New User Account
 */

require_once dirname(__DIR__) . '/config/config.php';
require_admin();

$currentUser = auth_user();
$pdo = Database::getConnection();
$pageTitle = 'Provision New User';

$error = '';
$defaultQuotaGb = (int)get_setting('default_user_quota_gb', 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $quotaGb = (float)($_POST['storage_quota_gb'] ?? $defaultQuotaGb);

    if (empty($fullName) || empty($username) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        // Check uniqueness of username and email
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $error = 'The chosen username or email is already registered in the system.';
        } else {
            $passHash = password_hash($password, PASSWORD_BCRYPT);
            $quotaBytes = (int)round($quotaGb * 1024 * 1024 * 1024);

            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, full_name, role, status, storage_quota, storage_used, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
            ");
            $stmt->execute([$username, $email, $passHash, $fullName, $role, $status, $quotaBytes]);
            $newUserId = (int)$pdo->lastInsertId();

            log_activity($currentUser['id'], 'Create User', 'User', $newUserId, "Created {$role} account '{$username}' with quota {$quotaGb} GB");
            set_flash('success', "User account '{$username}' provisioned successfully.");
            redirect('admin/users.php');
        }
    }
}

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="users.php" class="btn btn-outline-secondary btn-sm rounded-circle" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="page-header-title">Provision New User</h1>
            <p class="page-header-subtitle">Create authorized user credentials and assign storage limitations</p>
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
                        <label class="form-label small fw-semibold">Full Name</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Jane Doe" value="<?= e($_POST['full_name'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. janedoe" value="<?= e($_POST['username'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="jane@example.com" value="<?= e($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Account Role</label>
                        <select name="role" class="form-select">
                            <option value="user" selected>Normal User</option>
                            <option value="admin">Platform Administrator</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Initial Status</label>
                        <select name="status" class="form-select">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive / Suspended</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Storage Quota (GB)</label>
                        <input type="number" step="0.5" name="storage_quota_gb" class="form-control" value="<?= e($_POST['storage_quota_gb'] ?? $defaultQuotaGb) ?>" required>
                        <div class="form-text text-muted">Configurable drive limit in Gigabytes</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Initial Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Minimum 8 characters" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <a href="users.php" class="btn btn-light px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-user-plus me-1"></i> Create User Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
