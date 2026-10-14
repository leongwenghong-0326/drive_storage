<?php
/**
 * AetherVault - Platform File Audit & System Monitor
 */

require_once dirname(__DIR__) . '/config/config.php';
require_admin();

$currentUser = auth_user();
$pdo = Database::getConnection();
$pageTitle = 'Platform Files Monitor';

$ownerFilter = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

// Handle Administrative Permanent Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'admin_delete_file') {
    Csrf::requireValid();

    $fileId = (int)($_POST['file_id'] ?? 0);
    $stmtTarget = $pdo->prepare("SELECT user_id, original_name FROM files WHERE id = ?");
    $stmtTarget->execute([$fileId]);
    $targetFile = $stmtTarget->fetch();

    if ($targetFile) {
        StorageEngine::permanentlyDeleteFile($fileId, (int)$targetFile['user_id']);
        log_activity($currentUser['id'], 'Permanent Delete', 'File', $fileId, "Admin purged file '{$targetFile['original_name']}'");
        set_flash('success', "File '{$targetFile['original_name']}' was purged by administrator.");
    }
    redirect('admin/files.php');
}

// Build query
$where = [];
$params = [];

if ($ownerFilter) {
    $where[] = "f.user_id = ?";
    $params[] = $ownerFilter;
}

if ($statusFilter === 'active') {
    $where[] = "f.is_deleted = 0";
} elseif ($statusFilter === 'deleted') {
    $where[] = "f.is_deleted = 1";
}

if (!empty($searchQuery)) {
    $where[] = "(f.original_name LIKE ? OR f.extension LIKE ?)";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
}

$whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

$stmtFiles = $pdo->prepare("
    SELECT f.*, u.username, u.full_name AS owner_name, fo.name AS folder_name
    FROM files f
    JOIN users u ON f.user_id = u.id
    LEFT JOIN folders fo ON f.folder_id = fo.id
    {$whereSql}
    ORDER BY f.created_at DESC
    LIMIT 100
");
$stmtFiles->execute($params);
$filesList = $stmtFiles->fetchAll();

// Fetch all users for filter select
$usersForFilter = $pdo->query("SELECT id, username, full_name FROM users ORDER BY username ASC")->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-server text-primary"></i> Platform Files & Data Audit
        </h1>
        <p class="page-header-subtitle">Inspect physical assets across all storage partitions with administrator oversight</p>
    </div>
</div>

<!-- Filter Control Bar -->
<div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
    <form action="files.php" method="GET" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Search File Name / Extension</label>
            <input type="text" name="search" class="form-control" placeholder="Filename or extension..." value="<?= e($searchQuery) ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label small fw-semibold">Filter by Tenant / Owner</label>
            <select name="user_id" class="form-select">
                <option value="">All Users</option>
                <?php foreach ($usersForFilter as $uf): ?>
                    <option value="<?= $uf['id'] ?>" <?= ($ownerFilter === (int)$uf['id']) ? 'selected' : '' ?>>
                        <?= e($uf['full_name']) ?> (@<?= e($uf['username']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label small fw-semibold">Status</label>
            <select name="status" class="form-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="deleted" <?= $statusFilter === 'deleted' ? 'selected' : '' ?>>In Recycle Bin</option>
            </select>
        </div>

        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-primary">Filter Records</button>
        </div>
    </form>
</div>

<!-- File Table -->
<div class="file-table mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Tenant Owner</th>
                    <th>Location</th>
                    <th>Size</th>
                    <th>Status</th>
                    <th>Upload Date</th>
                    <th class="text-end" style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filesList)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No files matched the selected filter criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filesList as $f): 
                        $meta = get_file_icon_meta($f['extension'], $f['mime_type']);
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <i class="fa-solid <?= $meta['icon'] ?> fs-5 me-2" style="color: <?= $meta['color'] ?>; width: 22px; text-align: center;"></i>
                                    <div>
                                        <div class="fw-semibold text-dark text-truncate" style="max-width: 280px;" title="<?= e($f['original_name']) ?>">
                                            <?= e($f['original_name']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.72rem;"><?= e($f['mime_type']) ?> &bull; v<?= $f['version_count'] ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="fw-semibold text-dark"><?= e($f['owner_name']) ?></span>
                                <div class="text-muted small">@<?= e($f['username']) ?></div>
                            </td>
                            <td><span class="text-muted small">📁 <?= e($f['folder_name'] ?: 'Root') ?></span></td>
                            <td><span class="small fw-semibold"><?= format_bytes($f['file_size']) ?></span></td>
                            <td>
                                <?php if ($f['is_deleted']): ?>
                                    <span class="badge bg-danger-subtle text-danger">In Trash</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="text-muted small"><?= date('M d, Y H:i', strtotime($f['created_at'])) ?></span></td>
                            <td class="text-end">
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Purge file \'<?= e(addslashes($f['original_name'])) ?>\' from storage?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action_type" value="admin_delete_file">
                                    <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                                    <button type="submit" class="btn btn-light btn-sm text-danger" title="Purge Physical File">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
