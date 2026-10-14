<?php
/**
 * AetherVault - File Version History & Revision Control
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$fileId = (int)($_GET['id'] ?? 0);

if (!$fileId) {
    redirect('files.php');
}

$pdo = Database::getConnection();

// Fetch target file
$stmtFile = $pdo->prepare("
    SELECT f.*, fo.name AS folder_name
    FROM files f
    LEFT JOIN folders fo ON f.folder_id = fo.id
    WHERE f.id = ? AND f.user_id = ? AND f.is_deleted = 0
");
$stmtFile->execute([$fileId, $userId]);
$file = $stmtFile->fetch();

if (!$file) {
    set_flash('error', 'File not found or access denied.');
    redirect('files.php');
}

$error = '';

// Handle New Revision Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'upload_revision') {
    Csrf::requireValid();

    if (empty($_FILES['revision_file']) || $_FILES['revision_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select a revision file to upload.';
    } else {
        $val = StorageEngine::validateUpload($_FILES['revision_file'], $userId);
        if (!$val['valid']) {
            $error = $val['error'];
        } else {
            $revResult = StorageEngine::storeRevision($fileId, $val, $userId);
            if ($revResult['success']) {
                set_flash('success', $revResult['message']);
                redirect("versions.php?id={$fileId}");
            } else {
                $error = $revResult['message'];
            }
        }
    }
}

// Handle Rollback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'rollback_version') {
    Csrf::requireValid();

    $versionId = (int)($_POST['version_id'] ?? 0);
    $rollResult = StorageEngine::rollbackVersion($fileId, $versionId, $userId);

    if ($rollResult['success']) {
        set_flash('success', $rollResult['message']);
    } else {
        set_flash('error', $rollResult['message']);
    }
    redirect("versions.php?id={$fileId}");
}

// Fetch historical versions
$stmtVersions = $pdo->prepare("
    SELECT * FROM file_versions 
    WHERE file_id = ? AND user_id = ?
    ORDER BY version_number DESC
");
$stmtVersions->execute([$fileId, $userId]);
$versions = $stmtVersions->fetchAll();

$pageTitle = 'Version History: ' . $file['original_name'];
require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="files.php<?= $file['folder_id'] ? '?folder=' . $file['folder_id'] : '' ?>" class="btn btn-outline-secondary btn-sm rounded-circle" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="page-header-title text-truncate" style="max-width: 650px;">Version History: <?= e($file['original_name']) ?></h1>
            <p class="page-header-subtitle">Track modifications, inspect historical archives, upload revisions, and roll back content</p>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 small py-2 px-3 mb-4"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Active Current Version Card -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark m-0 d-flex align-items-center">
                    <i class="fa-solid fa-file-shield text-success me-2"></i> Active Version (Current)
                </h5>
                <span class="badge bg-success-subtle text-success px-3 py-1 fw-bold">v<?= $file['version_count'] ?> Current</span>
            </div>

            <div class="p-3 bg-light rounded border mb-3">
                <div class="fw-semibold text-dark text-break mb-1"><?= e($file['original_name']) ?></div>
                <div class="small text-muted mb-2">
                    <span>Size: <?= format_bytes($file['file_size']) ?></span> &bull; 
                    <span>Type: <?= e($file['mime_type']) ?></span>
                </div>
                <div class="small text-muted">Last Updated: <?= date('M d, Y h:i A', strtotime($file['updated_at'])) ?></div>
            </div>

            <div class="d-flex gap-2">
                <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="fa-solid fa-download me-1"></i> Download Current
                </a>
                <a href="preview.php?id=<?= $file['id'] ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-regular fa-eye me-1"></i> Preview
                </a>
            </div>
        </div>

        <!-- Upload New Revision Card -->
        <div class="card border-0 shadow-sm p-4" style="border-radius: var(--radius-lg);">
            <h5 class="fw-bold text-dark mb-3 d-flex align-items-center">
                <i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i> Upload New Revision
            </h5>
            <p class="text-muted small mb-3">
                Uploading a new version will automatically archive the current version to history and advance the revision counter to v<?= $file['version_count'] + 1 ?>.
            </p>

            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action_type" value="upload_revision">

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Select Revision File</label>
                    <input type="file" name="revision_file" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-gradient w-100">
                    <i class="fa-solid fa-arrow-up-from-bracket me-1"></i> Publish Version v<?= $file['version_count'] + 1 ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Historical Archive Chain -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm p-4" style="border-radius: var(--radius-lg);">
            <h5 class="fw-bold text-dark mb-3 d-flex align-items-center">
                <i class="fa-solid fa-timeline text-primary me-2"></i> Historical Version Chain (<?= count($versions) ?>)
            </h5>

            <?php if (empty($versions)): ?>
                <div class="p-4 text-center text-muted small bg-light rounded border">
                    <i class="fa-solid fa-history fa-2x mb-2 text-secondary"></i>
                    <div>No previous versions on record yet. When you upload new revisions, older copies will be preserved here.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Archived Date</th>
                                <th>Size</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($versions as $ver): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1 fw-bold">
                                            v<?= $ver['version_number'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="small text-dark fw-medium"><?= date('M d, Y h:i A', strtotime($ver['created_at'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="small text-muted"><?= format_bytes($ver['file_size']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <!-- Download Historical Version -->
                                            <a href="download.php?id=<?= $file['id'] ?>&version=<?= $ver['version_number'] ?>" class="btn btn-light btn-sm" title="Download Version v<?= $ver['version_number'] ?>">
                                                <i class="fa-solid fa-download text-success"></i>
                                            </a>

                                            <!-- Rollback to this Version -->
                                            <form method="POST" action="" onsubmit="return confirm('Rollback active file content to version v<?= $ver['version_number'] ?>? A new version snapshot will be created.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action_type" value="rollback_version">
                                                <input type="hidden" name="version_id" value="<?= $ver['id'] ?>">
                                                <button type="submit" class="btn btn-outline-warning btn-sm" title="Rollback to this version">
                                                    <i class="fa-solid fa-rotate-left me-1"></i> Rollback
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
