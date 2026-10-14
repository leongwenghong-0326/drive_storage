<?php
/**
 * AetherVault - User Profile & Password Management
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$pageTitle = 'Account Profile';
$pdo = Database::getConnection();

$profileError = '';
$profileSuccess = '';
$passwordError = '';
$passwordSuccess = '';

// Handle Profile Information Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'update_profile') {
    Csrf::requireValid();

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($fullName) || empty($email)) {
        $profileError = 'Full name and email address are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileError = 'Please enter a valid email address.';
    } else {
        // Check if email is used by another user
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $user['id']]);
        if ($chk->fetch()) {
            $profileError = 'This email address is already in use by another account.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$fullName, $email, $user['id']]);

            log_activity($user['id'], 'Update Profile', 'User', $user['id'], 'Updated user profile information');
            set_flash('success', 'Profile details updated successfully.');
            redirect('profile.php');
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'change_password') {
    Csrf::requireValid();

    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    // Fetch stored hash
    $stmtUser = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmtUser->execute([$user['id']]);
    $storedHash = $stmtUser->fetchColumn();

    if (!password_verify($currentPass, $storedHash)) {
        $passwordError = 'The current password you entered is incorrect.';
    } elseif (strlen($newPass) < 8) {
        $passwordError = 'New password must be at least 8 characters in length.';
    } elseif ($newPass !== $confirmPass) {
        $passwordError = 'The new password and confirmation password do not match.';
    } else {
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$newHash, $user['id']]);

        log_activity($user['id'], 'Change Password', 'User', $user['id'], 'Changed account password');
        set_flash('success', 'Your password has been changed successfully.');
        redirect('profile.php');
    }
}

// Refresh and fetch storage calculation
refresh_user_storage($user['id']);
$quota = (int)$user['storage_quota'];
$used = (int)$user['storage_used'];
$remaining = max(0, $quota - $used);
$percent = $quota > 0 ? min(100, round(($used / $quota) * 100, 1)) : 0;

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title">My Account Profile</h1>
        <p class="page-header-subtitle">Manage personal information, security credentials, and storage status</p>
    </div>
</div>

<div class="row g-4">
    <!-- Storage & Account Summary Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
            <div class="text-center mb-4">
                <div class="topbar-user-avatar mx-auto mb-3" style="width: 72px; height: 72px; font-size: 1.8rem;">
                    <?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <h5 class="fw-bold mb-1 text-dark"><?= e($user['full_name']) ?></h5>
                <span class="badge bg-primary-subtle text-primary px-3 py-1"><?= ucfirst($user['role']) ?> Account</span>
            </div>

            <div class="border-top pt-3">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Storage Quota</span>
                    <strong><?= format_bytes($quota) ?></strong>
                </div>
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Used Space</span>
                    <strong class="text-dark"><?= format_bytes($used) ?></strong>
                </div>
                <div class="d-flex justify-content-between small text-muted mb-2">
                    <span>Remaining Space</span>
                    <strong class="text-success"><?= format_bytes($remaining) ?></strong>
                </div>

                <div class="progress" style="height: 8px; border-radius: 999px;">
                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $percent ?>%;" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="text-end small text-muted mt-1"><?= $percent ?>% capacity utilized</div>
            </div>

            <div class="border-top mt-3 pt-3 small text-muted">
                <div class="mb-1"><strong>Username:</strong> <?= e($user['username']) ?></div>
                <div class="mb-1"><strong>Registered:</strong> <?= date('M d, Y', strtotime($user['created_at'])) ?></div>
            </div>
        </div>
    </div>

    <!-- Edit Profile & Password Columns -->
    <div class="col-lg-8">
        <!-- Edit Profile Form -->
        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
            <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                <i class="fa-solid fa-user-pen text-primary me-2"></i> Edit Account Details
            </h5>

            <?php if ($profileError): ?>
                <div class="alert alert-danger border-0 small py-2 px-3 mb-3"><?= e($profileError) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action_type" value="update_profile">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Username</label>
                        <input type="text" class="form-control bg-light" value="<?= e($user['username']) ?>" readonly disabled>
                        <div class="form-text text-muted">Usernames cannot be changed once provisioned.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Role</label>
                        <input type="text" class="form-control bg-light" value="<?= ucfirst($user['role']) ?>" readonly disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-primary px-4">Save Profile Changes</button>
                </div>
            </form>
        </div>

        <!-- Change Password Form -->
        <div class="card border-0 shadow-sm p-4" style="border-radius: var(--radius-lg);">
            <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                <i class="fa-solid fa-key text-primary me-2"></i> Update Password
            </h5>

            <?php if ($passwordError): ?>
                <div class="alert alert-danger border-0 small py-2 px-3 mb-3"><?= e($passwordError) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action_type" value="change_password">

                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Current Password</label>
                        <input type="password" name="current_password" class="form-control" placeholder="Enter current password" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Minimum 8 characters" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-type new password" required>
                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-outline-primary px-4">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
