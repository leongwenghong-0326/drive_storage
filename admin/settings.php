<?php
/**
 * AetherVault - Master System Settings
 */

require_once dirname(__DIR__) . '/config/config.php';
require_admin();

$currentUser = auth_user();
$pdo = Database::getConnection();
$pageTitle = 'System Settings';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $appName = trim($_POST['app_name'] ?? '');
    $maxUploadMb = (int)($_POST['max_upload_size_mb'] ?? 100);
    $defaultQuotaGb = (float)($_POST['default_user_quota_gb'] ?? 10);
    $allowedExtensions = trim($_POST['allowed_file_extensions'] ?? '');
    $trashRetentionDays = (int)($_POST['trash_retention_days'] ?? 30);

    if (empty($appName)) {
        $error = 'Application Name is required.';
    } elseif ($maxUploadMb < 1 || $maxUploadMb > 5000) {
        $error = 'Max upload size must be between 1 MB and 5000 MB.';
    } elseif ($defaultQuotaGb < 0.1) {
        $error = 'Default user quota must be at least 0.1 GB.';
    } elseif ($trashRetentionDays < 1 || $trashRetentionDays > 365) {
        $error = 'Trash retention days must be between 1 and 365.';
    } else {
        // Sanitize extensions: strip dots, spaces, convert to lowercase
        $extTokens = array_filter(array_map(function($ext) {
            return strtolower(trim(ltrim($ext, '.')));
        }, explode(',', $allowedExtensions)));
        $cleanExts = implode(',', $extTokens);

        update_setting('app_name', $appName);
        update_setting('max_upload_size_mb', (string)$maxUploadMb);
        update_setting('default_user_quota_gb', (string)$defaultQuotaGb);
        update_setting('allowed_file_extensions', $cleanExts);
        update_setting('trash_retention_days', (string)$trashRetentionDays);

        log_activity($currentUser['id'], 'Update Settings', 'System', null, 'Updated platform configuration settings');
        set_flash('success', 'System configuration updated successfully.');
        redirect('admin/settings.php');
    }
}

// Current settings
$appNameVal = get_setting('app_name', 'AetherVault Cloud Storage');
$maxUploadMbVal = (int)get_setting('max_upload_size_mb', 100);
$defaultQuotaGbVal = (float)get_setting('default_user_quota_gb', 10);
$allowedExtensionsVal = get_setting('allowed_file_extensions', 'jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,tar,gz,7z,mp3,wav,ogg,mp4,webm,mkv,json,xml,md');
$trashRetentionDaysVal = (int)get_setting('trash_retention_days', 30);

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-gear text-primary"></i> System Settings & Configuration
        </h1>
        <p class="page-header-subtitle">Tune global storage quotas, upload file size boundaries, and retention policies</p>
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

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Platform Brand / Application Name</label>
                    <input type="text" name="app_name" class="form-control" value="<?= e($appNameVal) ?>" required>
                    <div class="form-text text-muted">Displayed in navigation headers, titles, and public share pages.</div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Maximum Upload Size per File (MB)</label>
                        <input type="number" name="max_upload_size_mb" class="form-control" value="<?= $maxUploadMbVal ?>" min="1" max="5000" required>
                        <div class="form-text text-muted">Ensure PHP's <code>upload_max_filesize</code> supports this.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Default User Storage Quota (GB)</label>
                        <input type="number" step="0.5" name="default_user_quota_gb" class="form-control" value="<?= $defaultQuotaGbVal ?>" min="0.1" required>
                        <div class="form-text text-muted">Assigned when new user accounts are provisioned.</div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Permitted File Extensions (Comma Separated)</label>
                    <textarea name="allowed_file_extensions" rows="3" class="form-control" required><?= e($allowedExtensionsVal) ?></textarea>
                    <div class="form-text text-muted">Executable scripts (.php, .exe, .sh, .py, etc.) are always strictly blocked regardless of this setting.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Trash Retention Period (Days)</label>
                    <input type="number" name="trash_retention_days" class="form-control" value="<?= $trashRetentionDaysVal ?>" min="1" max="365" required>
                    <div class="form-text text-muted">Soft-deleted items older than this duration can be permanently purged.</div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
