<?php
/**
 * AetherVault - File and Folder Search System
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$query = trim($_GET['q'] ?? '');
$pageTitle = !empty($query) ? 'Search: ' . $query : 'Search Files';

$matchingFiles = [];
$matchingFolders = [];

if (!empty($query)) {
    $searchWildcard = '%' . $query . '%';

    // 1. Search folders
    $stmtFolders = $pdo->prepare("
        SELECT f.*, 
            (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND is_deleted = 0) AS files_count
        FROM folders f
        WHERE f.user_id = ? AND f.is_deleted = 0 AND f.name LIKE ?
        ORDER BY f.name ASC
    ");
    $stmtFolders->execute([$userId, $searchWildcard]);
    $matchingFolders = $stmtFolders->fetchAll();

    // 2. Search files
    $stmtFiles = $pdo->prepare("
        SELECT f.*, fo.name AS folder_name
        FROM files f
        LEFT JOIN folders fo ON f.folder_id = fo.id
        WHERE f.user_id = ? AND f.is_deleted = 0 AND (f.original_name LIKE ? OR f.extension LIKE ?)
        ORDER BY f.updated_at DESC
    ");
    $stmtFiles->execute([$userId, $searchWildcard, $searchWildcard]);
    $matchingFiles = $stmtFiles->fetchAll();

    log_activity($userId, 'Search', 'Query', null, "Searched for keyword: '{$query}'");
}

require_once INCLUDES_DIR . '/header.php';
?>

<div class="mb-4">
    <h1 class="page-header-title">Search Storage</h1>
    <p class="page-header-subtitle">Find documents, assets, and folders across your personal drive</p>
</div>

<!-- Search Input Card -->
<div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
    <form action="search.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-10">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" name="q" class="form-control border-start-0" placeholder="Type filename, extension, or folder name..." value="<?= e($query) ?>" autofocus required>
            </div>
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-primary">Search Drive</button>
        </div>
    </form>
</div>

<?php if (empty($query)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <h5 class="fw-bold text-dark">Enter search terms above</h5>
        <p class="text-muted small">Search by keyword, filename, or file extension (e.g. "project", "pdf", "report").</p>
    </div>
<?php else: ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-dark m-0">
            Search Results for "<span class="text-primary"><?= e($query) ?></span>"
        </h5>
        <span class="badge bg-light text-secondary border"><?= count($matchingFolders) ?> folders, <?= count($matchingFiles) ?> files</span>
    </div>

    <!-- Matching Folders -->
    <?php if (!empty($matchingFolders)): ?>
        <div class="mb-4">
            <h6 class="text-muted small fw-bold text-uppercase tracking-wider mb-2">Matching Folders</h6>
            <div class="row g-3">
                <?php foreach ($matchingFolders as $f): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <div class="folder-card position-relative">
                            <div class="d-flex align-items-center flex-grow-1 text-truncate" onclick="window.location.href='files.php?folder=<?= $f['id'] ?>'">
                                <i class="fa-solid fa-folder folder-icon" style="color: <?= e($f['color'] ?: '#4f46e5') ?>;"></i>
                                <div class="text-truncate">
                                    <div class="fw-semibold text-dark text-truncate"><?= e($f['name']) ?></div>
                                    <div class="small text-muted"><?= $f['files_count'] ?> files</div>
                                </div>
                            </div>
                            <div class="ms-2 flex-shrink-0" onclick="event.stopPropagation();">
                                <button type="button" class="btn-star <?= $f['is_starred'] ? 'starred' : '' ?>" data-type="folder" data-id="<?= $f['id'] ?>" onclick="toggleStar('folder', <?= $f['id'] ?>, this)" title="Star / Unstar">
                                    <i class="<?= $f['is_starred'] ? 'fa-solid' : 'fa-regular' ?> fa-star"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Matching Files -->
    <?php if (!empty($matchingFiles)): ?>
        <div class="file-table mb-4">
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
                        <?php foreach ($matchingFiles as $file): 
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
                                        <a href="preview.php?id=<?= $file['id'] ?>" class="text-dark fw-semibold text-decoration-none text-truncate" style="max-width: 300px;">
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
    <?php endif; ?>

    <?php if (empty($matchingFolders) && empty($matchingFiles)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fa-solid fa-box-open"></i>
            </div>
            <h5 class="fw-bold text-dark">No matching results found</h5>
            <p class="text-muted small">We could not find any files or folders matching "<?= e($query) ?>". Try different keywords.</p>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
