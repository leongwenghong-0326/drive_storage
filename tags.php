<?php
/**
 * AetherVault - Tag & Label Management System
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();
$pageTitle = 'Tags & Labels';

$selectedTagId = !empty($_GET['tag']) ? (int)$_GET['tag'] : null;

// Handle New Tag Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'create_tag') {
    Csrf::requireValid();

    $name = trim($_POST['tag_name'] ?? '');
    $color = $_POST['tag_color'] ?? '#6366f1';

    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO tags (user_id, name, color, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE color = VALUES(color)
            ");
            $stmt->execute([$userId, $name, $color]);
            set_flash('success', "Tag '{$name}' created successfully.");
        } catch (Exception $e) {
            set_flash('error', 'Failed to create tag.');
        }
    }
    redirect('tags.php');
}

// Handle Tag Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'delete_tag') {
    Csrf::requireValid();

    $tagId = (int)($_POST['tag_id'] ?? 0);
    $del = $pdo->prepare("DELETE FROM tags WHERE id = ? AND user_id = ?");
    $del->execute([$tagId, $userId]);
    set_flash('info', 'Tag removed.');
    redirect('tags.php');
}

// Handle Tag Assignment to File
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'attach_tag') {
    Csrf::requireValid();

    $fileId = (int)($_POST['file_id'] ?? 0);
    $tagId = (int)($_POST['tag_id'] ?? 0);

    // Verify file and tag belong to user
    $chkF = $pdo->prepare("SELECT id FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
    $chkF->execute([$fileId, $userId]);
    $chkT = $pdo->prepare("SELECT id FROM tags WHERE id = ? AND user_id = ?");
    $chkT->execute([$tagId, $userId]);

    if ($chkF->fetch() && $chkT->fetch()) {
        $ins = $pdo->prepare("INSERT IGNORE INTO file_tags (file_id, tag_id, created_at) VALUES (?, ?, NOW())");
        $ins->execute([$fileId, $tagId]);
        set_flash('success', 'Tag attached to file.');
    }
    redirect('tags.php' . ($selectedTagId ? '?tag=' . $selectedTagId : ''));
}

// Handle Detaching Tag from File
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'detach_tag') {
    Csrf::requireValid();

    $fileId = (int)($_POST['file_id'] ?? 0);
    $tagId = (int)($_POST['tag_id'] ?? 0);

    $del = $pdo->prepare("
        DELETE ft FROM file_tags ft
        JOIN files f ON ft.file_id = f.id
        WHERE ft.file_id = ? AND ft.tag_id = ? AND f.user_id = ?
    ");
    $del->execute([$fileId, $tagId, $userId]);
    set_flash('info', 'Tag detached from file.');
    redirect('tags.php' . ($selectedTagId ? '?tag=' . $selectedTagId : ''));
}

// Fetch all user tags
$stmtTags = $pdo->prepare("
    SELECT t.*, COUNT(ft.file_id) AS file_count
    FROM tags t
    LEFT JOIN file_tags ft ON t.id = ft.tag_id
    WHERE t.user_id = ?
    GROUP BY t.id
    ORDER BY t.name ASC
");
$stmtTags->execute([$userId]);
$tags = $stmtTags->fetchAll();

// Fetch tagged files if a tag is selected
$taggedFiles = [];
$currentTag = null;
if ($selectedTagId) {
    foreach ($tags as $t) {
        if ((int)$t['id'] === $selectedTagId) {
            $currentTag = $t;
            break;
        }
    }

    if ($currentTag) {
        $stmtFiles = $pdo->prepare("
            SELECT f.*, fo.name AS folder_name
            FROM files f
            JOIN file_tags ft ON f.id = ft.file_id
            LEFT JOIN folders fo ON f.folder_id = fo.id
            WHERE ft.tag_id = ? AND f.user_id = ? AND f.is_deleted = 0
            ORDER BY f.updated_at DESC
        ");
        $stmtFiles->execute([$selectedTagId, $userId]);
        $taggedFiles = $stmtFiles->fetchAll();
    }
}

// Fetch user files for manual tag attachment dropdown
$stmtAllFiles = $pdo->prepare("SELECT id, original_name FROM files WHERE user_id = ? AND is_deleted = 0 ORDER BY original_name ASC LIMIT 100");
$stmtAllFiles->execute([$userId]);
$userFilesList = $stmtAllFiles->fetchAll();

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-tags text-primary"></i> Tags & Labels
        </h1>
        <p class="page-header-subtitle">Categorize, organize, and filter files across all folders using custom color-coded tags</p>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Tag Management -->
    <div class="col-lg-4">
        <!-- Create Tag Card -->
        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
            <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                <i class="fa-solid fa-plus-circle text-primary me-2"></i> Create New Tag
            </h5>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action_type" value="create_tag">

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Tag Name</label>
                    <input type="text" name="tag_name" id="tagNameInput" class="form-control" placeholder="e.g. Finance, Contract, Urgent" required autocomplete="off" oninput="updateTagPreview()">
                </div>

                <!-- Live Tag Preview -->
                <div class="mb-3 p-2 bg-light rounded border d-flex justify-content-between align-items-center">
                    <span class="small text-muted fw-medium">Live Preview:</span>
                    <span id="tagPreviewBadge" class="badge shadow-sm" style="background-color: #4f46e5; font-size: 0.82rem; padding: 6px 12px;">🏷️ New Tag</span>
                </div>

                <!-- Modern Color Selector (Presets + Custom Color Picker) -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small fw-semibold m-0">Choose Color</label>
                        <span class="text-muted small" id="colorHexText">#4f46e5</span>
                    </div>

                    <input type="hidden" name="tag_color" id="selectedTagColor" value="#4f46e5">

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <!-- Preset Color Circles -->
                        <?php 
                        $presetColors = [
                            '#4f46e5' => 'Indigo',
                            '#10b981' => 'Emerald',
                            '#f59e0b' => 'Amber',
                            '#ef4444' => 'Red',
                            '#8b5cf6' => 'Purple',
                            '#06b6d4' => 'Cyan',
                            '#ec4899' => 'Pink',
                            '#0284c7' => 'Sky',
                            '#64748b' => 'Slate'
                        ];
                        foreach ($presetColors as $hex => $cName):
                        ?>
                            <button type="button" class="color-swatch-btn <?= $hex === '#4f46e5' ? 'active' : '' ?>" style="background-color: <?= $hex ?>;" title="<?= $cName ?>" onclick="selectTagColor('<?= $hex ?>', this)">
                                <i class="fa-solid fa-check check-icon text-white"></i>
                            </button>
                        <?php endforeach; ?>

                        <!-- Custom Native Color Picker -->
                        <div class="d-flex align-items-center gap-1 ms-1">
                            <input type="color" id="customColorPicker" class="form-control form-control-color border-0 p-0 shadow-sm" value="#4f46e5" title="Pick any custom color" style="width: 32px; height: 32px; border-radius: 50%; cursor: pointer;" onchange="selectTagColor(this.value, null)">
                            <span class="text-muted small" style="font-size: 0.72rem;">Custom</span>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="fa-solid fa-plus me-1"></i> Add Tag
                </button>
            </form>
        </div>

        <!-- Available Tags List -->
        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: var(--radius-lg);">
            <h5 class="fw-bold mb-3 text-dark">My Tags (<?= count($tags) ?>)</h5>
            
            <?php if (empty($tags)): ?>
                <p class="text-muted small">No tags created yet. Use the form above to add your first tag.</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($tags as $t): ?>
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                            <a href="tags.php?tag=<?= $t['id'] ?>" class="text-decoration-none d-flex align-items-center gap-2 <?= ($selectedTagId === (int)$t['id']) ? 'fw-bold text-primary' : 'text-dark' ?>">
                                <span class="badge" style="background-color: <?= e($t['color']) ?>; width: 12px; height: 12px; border-radius: 50%; padding: 0;">&nbsp;</span>
                                <span><?= e($t['name']) ?></span>
                                <span class="badge bg-light text-muted border small"><?= $t['file_count'] ?></span>
                            </a>
                            <form method="POST" action="" onsubmit="return confirm('Delete this tag? It will be removed from all attached files.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action_type" value="delete_tag">
                                <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Delete Tag">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attach Tag to File Form -->
        <?php if (!empty($tags) && !empty($userFilesList)): ?>
            <div class="card border-0 shadow-sm p-4" style="border-radius: var(--radius-lg);">
                <h6 class="fw-bold mb-3 text-dark">Attach Tag to File</h6>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action_type" value="attach_tag">

                    <div class="mb-2">
                        <label class="form-label small text-muted">Select File</label>
                        <select name="file_id" class="form-select form-select-sm" required>
                            <?php foreach ($userFilesList as $uf): ?>
                                <option value="<?= $uf['id'] ?>"><?= e($uf['original_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">Select Tag</label>
                        <select name="tag_id" class="form-select form-select-sm" required>
                            <?php foreach ($tags as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= ($selectedTagId === (int)$t['id']) ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Attach Tag</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Tagged Files Explorer -->
    <div class="col-lg-8">
        <?php if ($currentTag): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark m-0 d-flex align-items-center gap-2">
                    Files tagged with 
                    <span class="badge" style="background-color: <?= e($currentTag['color']) ?>;"><?= e($currentTag['name']) ?></span>
                </h5>
                <a href="tags.php" class="btn btn-sm btn-link text-decoration-none">Clear Filter</a>
            </div>

            <?php if (empty($taggedFiles)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fa-solid fa-tags"></i>
                    </div>
                    <h5 class="fw-bold text-dark">No files attached to this tag</h5>
                    <p class="text-muted small">Attach this tag to files using the panel on the left or the file options menu.</p>
                </div>
            <?php else: ?>
                <div class="file-table">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>File Name</th>
                                    <th>Folder</th>
                                    <th>Size</th>
                                    <th>Modified</th>
                                    <th class="text-end" style="width: 140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($taggedFiles as $file): 
                                    $meta = get_file_icon_meta($file['extension'], $file['mime_type']);
                                ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="fa-solid <?= $meta['icon'] ?> fs-5 me-3" style="color: <?= $meta['color'] ?>; width: 24px; text-align: center;"></i>
                                                <a href="preview.php?id=<?= $file['id'] ?>" class="text-dark fw-semibold text-decoration-none text-truncate" style="max-width: 280px;" title="<?= e($file['original_name']) ?>">
                                                    <?= e($file['original_name']) ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td><span class="text-muted small">📁 <?= e($file['folder_name'] ?: 'Root / My Storage') ?></span></td>
                                        <td><span class="text-muted small"><?= format_bytes($file['file_size']) ?></span></td>
                                        <td><span class="text-muted small"><?= date('M d, Y', strtotime($file['updated_at'])) ?></span></td>
                                        <td class="text-end">
                                            <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-light btn-sm" title="Download">
                                                <i class="fa-solid fa-download text-success"></i>
                                            </a>
                                            <a href="preview.php?id=<?= $file['id'] ?>" class="btn btn-light btn-sm" title="Preview">
                                                <i class="fa-regular fa-eye text-primary"></i>
                                            </a>
                                            <!-- Detach Tag Form -->
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Remove tag from this file?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action_type" value="detach_tag">
                                                <input type="hidden" name="file_id" value="<?= $file['id'] ?>">
                                                <input type="hidden" name="tag_id" value="<?= $currentTag['id'] ?>">
                                                <button type="submit" class="btn btn-light btn-sm text-danger" title="Detach Tag">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fa-solid fa-filter"></i>
                </div>
                <h5 class="fw-bold text-dark">Select a tag to filter files</h5>
                <p class="text-muted small">Click any tag on the left menu to view all associated files across your storage drive.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function selectTagColor(hex, btnEl) {
    document.getElementById('selectedTagColor').value = hex;
    document.getElementById('colorHexText').innerText = hex;
    document.getElementById('customColorPicker').value = hex;
    
    // Update live badge background
    document.getElementById('tagPreviewBadge').style.backgroundColor = hex;

    // Toggle active state on preset swatch buttons
    document.querySelectorAll('.color-swatch-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    if (btnEl) {
        btnEl.classList.add('active');
    }
}

function updateTagPreview() {
    const input = document.getElementById('tagNameInput');
    const badge = document.getElementById('tagPreviewBadge');
    const name = input ? input.value.trim() : '';
    if (badge) {
        badge.innerText = '🏷️ ' + (name || 'New Tag');
    }
}
</script>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
