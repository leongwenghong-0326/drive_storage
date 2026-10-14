<?php
/**
 * AetherVault - User Dashboard
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();
$pageTitle = 'Dashboard Overview';

// Recalculate storage
$used = refresh_user_storage($userId);
$quota = (int)$user['storage_quota'];
$remaining = max(0, $quota - $used);
$rawPercent = $quota > 0 ? ($used / $quota) * 100 : 0;
$percent = min(100, round($rawPercent, 1));
$percentText = ($used > 0 && $percent < 0.1) ? '< 0.1%' : "{$percent}%";
$barWidth = ($used > 0 && $percent < 1) ? max(1, $percent) : $percent;

// Query metric counts
$stmtFilesCount = $pdo->prepare("SELECT COUNT(*) FROM files WHERE user_id = ? AND is_deleted = 0");
$stmtFilesCount->execute([$userId]);
$totalFiles = (int)$stmtFilesCount->fetchColumn();

$stmtFoldersCount = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE user_id = ? AND is_deleted = 0");
$stmtFoldersCount->execute([$userId]);
$totalFolders = (int)$stmtFoldersCount->fetchColumn();

$stmtStarredCount = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM files WHERE user_id = ? AND is_starred = 1 AND is_deleted = 0) +
        (SELECT COUNT(*) FROM folders WHERE user_id = ? AND is_starred = 1 AND is_deleted = 0)
");
$stmtStarredCount->execute([$userId, $userId]);
$totalStarred = (int)$stmtStarredCount->fetchColumn();

// Fetch 6 most recently uploaded / modified files
$stmtRecent = $pdo->prepare("
    SELECT f.*, fo.name AS folder_name 
    FROM files f
    LEFT JOIN folders fo ON f.folder_id = fo.id
    WHERE f.user_id = ? AND f.is_deleted = 0
    ORDER BY f.updated_at DESC
    LIMIT 6
");
$stmtRecent->execute([$userId]);
$recentFiles = $stmtRecent->fetchAll();

// Fetch starred items preview (up to 4)
$stmtStarredFiles = $pdo->prepare("
    SELECT id, original_name, file_size, extension, mime_type, updated_at, 'file' AS item_type
    FROM files WHERE user_id = ? AND is_starred = 1 AND is_deleted = 0
    ORDER BY updated_at DESC LIMIT 4
");
$stmtStarredFiles->execute([$userId]);
$starredItems = $stmtStarredFiles->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<!-- Welcome Banner -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="page-header-title">Hello, <?= e($user['full_name']) ?>! 👋</h1>
        <p class="page-header-subtitle">Welcome back to your personal cloud workspace. Here is your storage summary.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" onclick="document.getElementById('fileUploadInput').click()">
            <i class="fa-solid fa-cloud-arrow-up me-2"></i> Upload File
        </button>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newFolderModal">
            <i class="fa-solid fa-folder-plus me-1"></i> New Folder
        </button>
    </div>
</div>

<!-- Hidden File Input for Dashboard Quick Upload -->
<input type="file" id="fileUploadInput" class="d-none" multiple>
<input type="hidden" id="uploadFolderId" value="">

<!-- Upload Progress Bar (hidden by default) -->
<div id="uploadProgressContainer" class="d-none mb-4">
    <div class="card border-0 shadow-sm p-3 bg-white" style="border-radius: var(--radius-md);">
        <div class="d-flex justify-content-between small fw-semibold mb-1">
            <span class="text-primary"><i class="fa-solid fa-circle-notch fa-spin me-2"></i> Uploading files to root directory...</span>
            <span id="uploadProgressBarText"></span>
        </div>
        <div class="progress" style="height: 8px;">
            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%;">0%</div>
        </div>
    </div>
</div>

<!-- Storage & Metrics Row -->
<div class="row g-4 mb-4">
    <!-- Storage Quota Widget Card -->
    <div class="col-xl-6 col-lg-12">
        <div class="stat-card h-100 d-flex flex-column justify-content-between">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase tracking-wider">Cloud Storage Overview</span>
                    <h3 class="fw-bold text-dark mt-1 mb-0"><?= format_bytes($used) ?> <span class="fs-6 fw-normal text-muted">/ <?= format_bytes($quota) ?></span></h3>
                </div>
                <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                    <i class="fa-solid fa-hard-drive"></i>
                </div>
            </div>

            <div>
                <div class="d-flex justify-content-between small text-muted mb-2">
                    <span><?= $percentText ?> capacity used</span>
                    <span class="text-success fw-medium"><?= format_bytes($remaining) ?> free space left</span>
                </div>
                <div class="progress" style="height: 10px; border-radius: 999px;">
                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $barWidth ?>%;" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>

            <div class="d-flex justify-content-between pt-3 mt-3 border-top small text-muted">
                <span><i class="fa-solid fa-shield-halved text-success me-1"></i> Data Isolated & Encrypted</span>
                <a href="<?= BASE_URL ?>/profile.php" class="text-primary text-decoration-none fw-semibold">Manage Profile &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Total Files Card -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="stat-card h-100">
            <div class="stat-icon-wrapper bg-info-subtle text-info mb-3">
                <i class="fa-solid fa-file"></i>
            </div>
            <span class="text-muted small fw-semibold text-uppercase">Total Files</span>
            <h3 class="fw-bold text-dark mt-1 mb-1"><?= number_format($totalFiles) ?></h3>
            <span class="small text-muted">In cloud storage</span>
        </div>
    </div>

    <!-- Total Folders Card -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="stat-card h-100">
            <div class="stat-icon-wrapper bg-warning-subtle text-warning mb-3">
                <i class="fa-solid fa-folder"></i>
            </div>
            <span class="text-muted small fw-semibold text-uppercase">Folders</span>
            <h3 class="fw-bold text-dark mt-1 mb-1"><?= number_format($totalFolders) ?></h3>
            <span class="small text-muted">Organized catalogs</span>
        </div>
    </div>

    <!-- Starred Items Card -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="stat-card h-100">
            <div class="stat-icon-wrapper bg-danger-subtle text-danger mb-3">
                <i class="fa-solid fa-star"></i>
            </div>
            <span class="text-muted small fw-semibold text-uppercase">Starred</span>
            <h3 class="fw-bold text-dark mt-1 mb-1"><?= number_format($totalStarred) ?></h3>
            <span class="small text-muted">Priority bookmarks</span>
        </div>
    </div>
</div>

<!-- Quick Shortcuts Bar -->
<div class="card border-0 shadow-sm p-3 mb-4" style="border-radius: var(--radius-lg);">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="fw-bold text-dark small text-uppercase tracking-wider px-2">Quick Navigation:</span>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>/files.php" class="btn btn-light btn-sm px-3 border">
                <i class="fa-solid fa-folder-open text-primary me-1"></i> Browse Files
            </a>
            <a href="<?= BASE_URL ?>/recent.php" class="btn btn-light btn-sm px-3 border">
                <i class="fa-solid fa-clock-rotate-left text-info me-1"></i> Recent Files
            </a>
            <a href="<?= BASE_URL ?>/starred.php" class="btn btn-light btn-sm px-3 border">
                <i class="fa-solid fa-star text-warning me-1"></i> Starred Items
            </a>
            <a href="<?= BASE_URL ?>/tags.php" class="btn btn-light btn-sm px-3 border">
                <i class="fa-solid fa-tags text-success me-1"></i> Tags & Labels
            </a>
            <a href="<?= BASE_URL ?>/trash.php" class="btn btn-light btn-sm px-3 border">
                <i class="fa-solid fa-trash-can text-danger me-1"></i> Recycle Bin
            </a>
        </div>
    </div>
</div>

<!-- Recent Files Section -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold text-dark m-0 d-flex align-items-center">
        <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Recently Modified Files
    </h5>
    <a href="<?= BASE_URL ?>/recent.php" class="btn btn-link btn-sm text-decoration-none fw-semibold">View All &rarr;</a>
</div>

<?php if (empty($recentFiles)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <i class="fa-solid fa-cloud-arrow-up"></i>
        </div>
        <h5 class="fw-bold text-dark">No files uploaded yet</h5>
        <p class="text-muted small mb-3">Start uploading your documents, spreadsheets, images, or archives.</p>
        <button type="button" class="btn btn-primary px-4" onclick="document.getElementById('fileUploadInput').click()">
            <i class="fa-solid fa-cloud-arrow-up me-2"></i> Upload Your First File
        </button>
    </div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ($recentFiles as $file): 
            $meta = get_file_icon_meta($file['extension'], $file['mime_type']);
        ?>
            <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                <div class="file-card">
                    <!-- Top Star & Action Icons -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <button type="button" class="btn-star <?= $file['is_starred'] ? 'starred' : '' ?>" onclick="toggleStar('file', <?= $file['id'] ?>, this)" title="Star / Unstar">
                            <i class="<?= $file['is_starred'] ? 'fa-solid' : 'fa-regular' ?> fa-star"></i>
                        </button>
                        
                        <div class="dropdown">
                            <button class="btn btn-link btn-sm text-muted p-0" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-ellipsis-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 small">
                                <li><a class="dropdown-item" href="preview.php?id=<?= $file['id'] ?>"><i class="fa-regular fa-eye me-2 text-primary"></i> Preview</a></li>
                                <li><a class="dropdown-item" href="download.php?id=<?= $file['id'] ?>"><i class="fa-solid fa-download me-2 text-success"></i> Download</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="openFileDetails(<?= $file['id'] ?>)"><i class="fa-solid fa-circle-info me-2 text-info"></i> Details</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="openShareModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-share-nodes me-2 text-warning"></i> Share</a></li>
                                <li><a class="dropdown-item" href="versions.php?id=<?= $file['id'] ?>"><i class="fa-solid fa-code-branch me-2 text-secondary"></i> Versions (<?= $file['version_count'] ?>)</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="openRenameModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-pen-to-square me-2 text-muted"></i> Rename</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="openMoveModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-arrows-up-down-left-right me-2 text-muted"></i> Move</a></li>
                                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="moveToTrash('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-trash-can me-2"></i> Move to Trash</a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- File Thumbnail / Icon -->
                    <a href="preview.php?id=<?= $file['id'] ?>" class="text-decoration-none">
                        <div class="file-thumbnail">
                            <?php if ($meta['type'] === 'image'): ?>
                                <img src="preview.php?id=<?= $file['id'] ?>&stream=1" alt="<?= e($file['original_name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fa-solid <?= $meta['icon'] ?>" style="color: <?= $meta['color'] ?>;"></i>
                            <?php endif; ?>
                        </div>
                        <div class="file-card-title text-dark" title="<?= e($file['original_name']) ?>">
                            <?= e($file['original_name']) ?>
                        </div>
                    </a>

                    <!-- File Metadata -->
                    <div class="file-card-meta d-flex justify-content-between mt-auto pt-2">
                        <span><?= format_bytes($file['file_size']) ?></span>
                        <span><?= date('M d', strtotime($file['updated_at'])) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
