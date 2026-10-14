<?php
/**
 * AetherVault - Trash / Recycle Bin Management
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();
$pageTitle = 'Recycle Bin';

// Fetch deleted folders
$stmtFolders = $pdo->prepare("
    SELECT * FROM folders 
    WHERE user_id = ? AND is_deleted = 1
    ORDER BY deleted_at DESC
");
$stmtFolders->execute([$userId]);
$deletedFolders = $stmtFolders->fetchAll();

// Fetch deleted files
$stmtFiles = $pdo->prepare("
    SELECT f.*, fo.name AS original_folder_name
    FROM files f
    LEFT JOIN folders fo ON f.folder_id = fo.id
    WHERE f.user_id = ? AND f.is_deleted = 1
    ORDER BY f.deleted_at DESC
");
$stmtFiles->execute([$userId]);
$deletedFiles = $stmtFiles->fetchAll();

$retentionDays = (int)get_setting('trash_retention_days', 30);

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-trash-can text-danger"></i> Recycle Bin
        </h1>
        <p class="page-header-subtitle">
            Items in the trash will be automatically purged after <?= $retentionDays ?> days of retention
        </p>
    </div>

    <?php if (!empty($deletedFolders) || !empty($deletedFiles)): ?>
        <button type="button" class="btn btn-outline-danger btn-sm px-3" onclick="emptyTrash()">
            <i class="fa-solid fa-dumpster-fire me-1"></i> Empty Trash Bin
        </button>
    <?php endif; ?>
</div>

<?php if (empty($deletedFolders) && empty($deletedFiles)): ?>
    <div class="empty-state">
        <div class="empty-state-icon text-muted" style="background: rgba(148, 163, 184, 0.1);">
            <i class="fa-solid fa-trash-can"></i>
        </div>
        <h5 class="fw-bold text-dark">Your trash is empty</h5>
        <p class="text-muted small mb-3">Deleted files and folders will appear here until restored or permanently destroyed.</p>
        <a href="files.php" class="btn btn-primary px-4">Back to My Storage</a>
    </div>
<?php else: ?>

    <!-- Deleted Folders -->
    <?php if (!empty($deletedFolders)): ?>
        <div class="mb-4">
            <h6 class="text-muted small fw-bold text-uppercase tracking-wider mb-3">Deleted Folders (<?= count($deletedFolders) ?>)</h6>
            <div class="row g-3">
                <?php foreach ($deletedFolders as $f): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <div class="folder-card p-3 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center text-truncate me-2">
                                <i class="fa-solid fa-folder-minus text-secondary fs-4 me-3"></i>
                                <div class="text-truncate">
                                    <div class="fw-semibold text-dark text-truncate" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></div>
                                    <div class="small text-muted">Deleted <?= date('M d, Y', strtotime($f['deleted_at'])) ?></div>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-light btn-sm text-primary" onclick="restoreTrash('folder', <?= $f['id'] ?>)" title="Restore Folder">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </button>
                                <button type="button" class="btn btn-light btn-sm text-danger" onclick="permanentDelete('folder', <?= $f['id'] ?>, '<?= e(addslashes($f['name'])) ?>')" title="Permanently Erase">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Deleted Files -->
    <?php if (!empty($deletedFiles)): ?>
        <div class="mb-4">
            <h6 class="text-muted small fw-bold text-uppercase tracking-wider mb-3">Deleted Files (<?= count($deletedFiles) ?>)</h6>
            <div class="file-table">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Original Location</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Deleted Date</th>
                                <th class="text-end" style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deletedFiles as $file): 
                                $meta = get_file_icon_meta($file['extension'], $file['mime_type']);
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fa-solid <?= $meta['icon'] ?> fs-5 me-3 text-secondary" style="width: 24px; text-align: center;"></i>
                                            <span class="text-dark fw-semibold text-truncate" style="max-width: 320px;" title="<?= e($file['original_name']) ?>">
                                                <?= e($file['original_name']) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-muted small">
                                            📁 <?= e($file['original_folder_name'] ?: 'Root / My Storage') ?>
                                        </span>
                                    </td>
                                    <td><span class="text-muted small"><?= format_bytes($file['file_size']) ?></span></td>
                                    <td><span class="badge bg-light text-secondary border">.<?= e(strtoupper($file['extension'])) ?></span></td>
                                    <td><span class="text-muted small"><?= date('M d, Y h:i A', strtotime($file['deleted_at'])) ?></span></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-light btn-sm text-primary" onclick="restoreTrash('file', <?= $file['id'] ?>)" title="Restore File">
                                            <i class="fa-solid fa-rotate-left me-1"></i> Restore
                                        </button>
                                        <button type="button" class="btn btn-light btn-sm text-danger" onclick="permanentDelete('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')" title="Permanently Erase">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
