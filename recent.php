<?php
/**
 * AetherVault - Recent Activity & Files View
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();
$pageTitle = 'Recent Files';

$stmtRecent = $pdo->prepare("
    SELECT f.*, fo.name AS folder_name
    FROM files f
    LEFT JOIN folders fo ON f.folder_id = fo.id
    WHERE f.user_id = ? AND f.is_deleted = 0
    ORDER BY f.updated_at DESC
    LIMIT 50
");
$stmtRecent->execute([$userId]);
$recentFiles = $stmtRecent->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title">Recent Files</h1>
        <p class="page-header-subtitle">Files accessed, modified, or uploaded recently in your cloud storage</p>
    </div>
</div>

<?php if (empty($recentFiles)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <h5 class="fw-bold text-dark">No recent activity</h5>
        <p class="text-muted small mb-3">Upload or edit files to see your activity timeline here.</p>
        <a href="files.php" class="btn btn-primary px-4">Go to My Storage</a>
    </div>
<?php else: ?>
    <div class="file-table">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Size</th>
                        <th>Type</th>
                        <th>Last Modified</th>
                        <th class="text-end" style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentFiles as $file): 
                        $meta = get_file_icon_meta($file['extension'], $file['mime_type']);
                    ?>
                        <tr>
                            <td>
                                <button type="button" class="btn-star <?= $file['is_starred'] ? 'starred' : '' ?>" onclick="toggleStar('file', <?= $file['id'] ?>, this)">
                                    <i class="<?= $file['is_starred'] ? 'fa-solid' : 'fa-regular' ?> fa-star"></i>
                                </button>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <i class="fa-solid <?= $meta['icon'] ?> fs-5 me-3" style="color: <?= $meta['color'] ?>; width: 24px; text-align: center;"></i>
                                    <a href="preview.php?id=<?= $file['id'] ?>" class="text-dark fw-semibold text-decoration-none text-truncate" style="max-width: 300px;" title="<?= e($file['original_name']) ?>">
                                        <?= e($file['original_name']) ?>
                                    </a>
                                </div>
                            </td>
                            <td>
                                <span class="text-muted small">
                                    📁 <?= e($file['folder_name'] ?: 'Root / My Storage') ?>
                                </span>
                            </td>
                            <td><span class="text-muted small"><?= format_bytes($file['file_size']) ?></span></td>
                            <td><span class="badge bg-light text-secondary border">.<?= e(strtoupper($file['extension'])) ?></span></td>
                            <td><span class="text-muted small"><?= date('M d, Y h:i A', strtotime($file['updated_at'])) ?></span></td>
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
<?php endif; ?>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
