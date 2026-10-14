<?php
/**
 * AetherVault - Starred Items Catalog
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();
$pageTitle = 'Starred Items';

// Fetch starred folders
$stmtFolders = $pdo->prepare("
    SELECT f.*, 
        (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND is_deleted = 0) AS files_count
    FROM folders f
    WHERE f.user_id = ? AND f.is_starred = 1 AND f.is_deleted = 0
    ORDER BY f.name ASC
");
$stmtFolders->execute([$userId]);
$starredFolders = $stmtFolders->fetchAll();

// Fetch starred files
$stmtFiles = $pdo->prepare("
    SELECT f.*, fo.name AS folder_name
    FROM files f
    LEFT JOIN folders fo ON f.folder_id = fo.id
    WHERE f.user_id = ? AND f.is_starred = 1 AND f.is_deleted = 0
    ORDER BY f.updated_at DESC
");
$stmtFiles->execute([$userId]);
$starredFiles = $stmtFiles->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-star text-warning"></i> Starred Items
        </h1>
        <p class="page-header-subtitle">High priority files and folders you have marked for fast access</p>
    </div>
</div>

<?php if (empty($starredFolders) && empty($starredFiles)): ?>
    <div class="empty-state">
        <div class="empty-state-icon text-warning" style="background: rgba(245, 158, 11, 0.1);">
            <i class="fa-solid fa-star"></i>
        </div>
        <h5 class="fw-bold text-dark">No starred items yet</h5>
        <p class="text-muted small mb-3">Star important files and folders to keep them pinned here for quick retrieval.</p>
        <a href="files.php" class="btn btn-primary px-4">Browse My Files</a>
    </div>
<?php else: ?>

    <!-- Starred Folders -->
    <?php if (!empty($starredFolders)): ?>
        <div class="mb-4">
            <h6 class="text-muted small fw-bold text-uppercase tracking-wider mb-3">Starred Folders (<?= count($starredFolders) ?>)</h6>
            <div class="row g-3">
                <?php foreach ($starredFolders as $f): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <div class="folder-card position-relative">
                            <div class="d-flex align-items-center flex-grow-1 text-truncate" onclick="window.location.href='files.php?folder=<?= $f['id'] ?>'">
                                <i class="fa-solid fa-folder folder-icon" style="color: <?= e($f['color'] ?: '#4f46e5') ?>;"></i>
                                <div class="text-truncate">
                                    <div class="fw-semibold text-dark text-truncate"><?= e($f['name']) ?></div>
                                    <div class="small text-muted"><?= $f['files_count'] ?> files</div>
                                </div>
                            </div>
                            <button type="button" class="btn-star starred ms-2 flex-shrink-0" data-type="folder" data-id="<?= $f['id'] ?>" onclick="toggleStar('folder', <?= $f['id'] ?>, this); setTimeout(() => location.reload(), 400);" title="Unstar">
                                <i class="fa-solid fa-star"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Starred Files -->
    <?php if (!empty($starredFiles)): ?>
        <div class="mb-4">
            <h6 class="text-muted small fw-bold text-uppercase tracking-wider mb-3">Starred Files (<?= count($starredFiles) ?>)</h6>
            <div class="file-table">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width: 40px;"></th>
                                <th>Name</th>
                                <th>Folder</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Modified</th>
                                <th class="text-end" style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($starredFiles as $file): 
                                $meta = get_file_icon_meta($file['extension'], $file['mime_type']);
                            ?>
                                <tr>
                                    <td>
                                        <button type="button" class="btn-star starred" onclick="toggleStar('file', <?= $file['id'] ?>, this); setTimeout(() => location.reload(), 400);" title="Unstar">
                                            <i class="fa-solid fa-star"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fa-solid <?= $meta['icon'] ?> fs-5 me-3" style="color: <?= $meta['color'] ?>; width: 24px; text-align: center;"></i>
                                            <a href="preview.php?id=<?= $file['id'] ?>" class="text-dark fw-semibold text-decoration-none text-truncate" style="max-width: 300px;">
                                                <?= e($file['original_name']) ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td><span class="text-muted small">📁 <?= e($file['folder_name'] ?: 'Root / My Storage') ?></span></td>
                                    <td><span class="text-muted small"><?= format_bytes($file['file_size']) ?></span></td>
                                    <td><span class="badge bg-light text-secondary border">.<?= e(strtoupper($file['extension'])) ?></span></td>
                                    <td><span class="text-muted small"><?= date('M d, Y', strtotime($file['updated_at'])) ?></span></td>
                                    <td class="text-end">
                                        <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-light btn-sm" title="Download">
                                            <i class="fa-solid fa-download text-success"></i>
                                        </a>
                                        <a href="preview.php?id=<?= $file['id'] ?>" class="btn btn-light btn-sm" title="Preview">
                                            <i class="fa-regular fa-eye text-primary"></i>
                                        </a>
                                        <button type="button" class="btn btn-light btn-sm" onclick="openShareModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')" title="Share">
                                            <i class="fa-solid fa-share-nodes text-warning"></i>
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
