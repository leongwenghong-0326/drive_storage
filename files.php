<?php
/**
 * AetherVault - File Explorer & Catalog Management
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$currentFolderId = !empty($_GET['folder']) ? (int)$_GET['folder'] : null;
$viewMode = $_GET['view'] ?? ($_SESSION['vault_view_mode'] ?? 'grid');
if (in_array($viewMode, ['grid', 'list'])) {
    $_SESSION['vault_view_mode'] = $viewMode;
} else {
    $viewMode = 'grid';
}

// Current folder validation and info
$currentFolder = null;
if ($currentFolderId !== null) {
    $stmtCur = $pdo->prepare("SELECT * FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
    $stmtCur->execute([$currentFolderId, $userId]);
    $currentFolder = $stmtCur->fetch();

    if (!$currentFolder) {
        set_flash('error', 'Folder not found or has been moved to trash.');
        redirect('files.php');
    }
}

// Breadcrumb generation
$breadcrumbs = StorageEngine::getBreadcrumbs($currentFolderId, $userId);

// Fetch subfolders
$stmtFolders = $pdo->prepare("
    SELECT f.*, 
        (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND is_deleted = 0) AS files_count,
        (SELECT COUNT(*) FROM folders WHERE parent_id = f.id AND is_deleted = 0) AS subfolders_count
    FROM folders f
    WHERE f.user_id = ? AND (f.parent_id <=> ?) AND f.is_deleted = 0
    ORDER BY f.name ASC
");
$stmtFolders->execute([$userId, $currentFolderId]);
$folders = $stmtFolders->fetchAll();

// Fetch files in this folder
$stmtFiles = $pdo->prepare("
    SELECT * FROM files
    WHERE user_id = ? AND (folder_id <=> ?) AND is_deleted = 0
    ORDER BY updated_at DESC
");
$stmtFiles->execute([$userId, $currentFolderId]);
$files = $stmtFiles->fetchAll();

$pageTitle = $currentFolder ? $currentFolder['name'] : 'My Storage';

require_once INCLUDES_DIR . '/header.php';
?>

<!-- Action Bar & Breadcrumbs -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb-custom">
                <li>
                    <a href="files.php"><i class="fa-solid fa-house-chimney me-1"></i> My Storage</a>
                </li>
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <li><i class="fa-solid fa-chevron-right text-muted small mx-1"></i></li>
                    <?php if ($index === count($breadcrumbs) - 1): ?>
                        <li class="active"><?= e($crumb['name']) ?></li>
                    <?php else: ?>
                        <li><a href="files.php?folder=<?= $crumb['id'] ?>"><?= e($crumb['name']) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
        <h1 class="page-header-title d-flex align-items-center gap-2">
            <?php if ($currentFolder): ?>
                <i class="fa-solid fa-folder-open text-primary"></i> <?= e($currentFolder['name']) ?>
            <?php else: ?>
                <i class="fa-solid fa-hard-drive text-primary"></i> My Storage
            <?php endif; ?>
        </h1>
    </div>

    <!-- Toolbar Buttons -->
    <div class="d-flex align-items-center gap-2">
        <!-- View Toggle (Grid / List) -->
        <div class="btn-group border rounded" role="group">
            <a href="?folder=<?= $currentFolderId ?>&view=grid" class="btn btn-sm <?= $viewMode === 'grid' ? 'btn-primary' : 'btn-light' ?>" title="Grid View">
                <i class="fa-solid fa-grip"></i>
            </a>
            <a href="?folder=<?= $currentFolderId ?>&view=list" class="btn btn-sm <?= $viewMode === 'list' ? 'btn-primary' : 'btn-light' ?>" title="List View">
                <i class="fa-solid fa-list"></i>
            </a>
        </div>

        <?php if ($currentFolder): ?>
            <!-- Download Folder as ZIP -->
            <a href="download.php?folder_id=<?= $currentFolder['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Download folder as ZIP archive">
                <i class="fa-solid fa-file-zipper me-1"></i> Download ZIP
            </a>
            <!-- Share Folder -->
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openShareModal('folder', <?= $currentFolder['id'] ?>, '<?= e(addslashes($currentFolder['name'])) ?>')">
                <i class="fa-solid fa-share-nodes me-1"></i> Share
            </button>
        <?php endif; ?>

        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newFolderModal">
            <i class="fa-solid fa-folder-plus me-1"></i> New Folder
        </button>

        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('fileUploadInput').click()">
            <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload
        </button>
    </div>
</div>

<!-- Drag and Drop Dropzone -->
<div class="dropzone-area mb-4" id="uploadDropzone" onclick="document.getElementById('fileUploadInput').click()">
    <i class="fa-solid fa-cloud-arrow-up text-primary fa-2x mb-2"></i>
    <div class="fw-semibold text-dark">Drag and drop files here to upload, or <span class="text-primary">browse files</span></div>
    <div class="small text-muted mt-1">Maximum single file upload: <?= (int)get_setting('max_upload_size_mb', 100) ?> MB</div>
</div>

<input type="file" id="fileUploadInput" class="d-none" multiple>
<input type="hidden" id="uploadFolderId" value="<?= (int)$currentFolderId ?>">

<!-- Upload Progress Indicator -->
<div id="uploadProgressContainer" class="d-none mb-4">
    <div class="card border-0 shadow-sm p-3 bg-white" style="border-radius: var(--radius-md);">
        <div class="d-flex justify-content-between small fw-semibold mb-1">
            <span class="text-primary"><i class="fa-solid fa-circle-notch fa-spin me-2"></i> Uploading files to cloud storage...</span>
            <span id="uploadProgressBarText"></span>
        </div>
        <div class="progress" style="height: 8px;">
            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%;">0%</div>
        </div>
    </div>
</div>

<!-- Folders Section -->
<?php if (!empty($folders)): ?>
    <div class="mb-4">
        <h6 class="text-muted small fw-bold text-uppercase tracking-wider mb-3">Folders (<?= count($folders) ?>)</h6>
        <div class="row g-3">
            <?php foreach ($folders as $f): ?>
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="folder-card position-relative">
                        <div class="d-flex align-items-center flex-grow-1 text-truncate" onclick="window.location.href='files.php?folder=<?= $f['id'] ?>'">
                            <i class="fa-solid fa-folder folder-icon" style="color: <?= e($f['color'] ?: '#4f46e5') ?>;"></i>
                            <div class="text-truncate">
                                <div class="fw-semibold text-dark text-truncate" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></div>
                                <div class="small text-muted"><?= $f['files_count'] ?> files</div>
                            </div>
                        </div>

                        <!-- Folder Actions (Star & Dropdown Menu) -->
                        <div class="d-flex align-items-center ms-2 flex-shrink-0" onclick="event.stopPropagation();">
                            <button type="button" class="btn-star <?= $f['is_starred'] ? 'starred' : '' ?> me-1" data-type="folder" data-id="<?= $f['id'] ?>" onclick="toggleStar('folder', <?= $f['id'] ?>, this)" title="Star / Unstar">
                                <i class="<?= $f['is_starred'] ? 'fa-solid' : 'fa-regular' ?> fa-star"></i>
                            </button>
                            <div class="dropdown">
                                <button class="btn btn-link btn-sm text-muted p-1" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fa-solid fa-ellipsis-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 small">
                                    <li><a class="dropdown-item" href="files.php?folder=<?= $f['id'] ?>"><i class="fa-regular fa-folder-open me-2 text-primary"></i> Open</a></li>
                                    <li><a class="dropdown-item" href="download.php?folder_id=<?= $f['id'] ?>"><i class="fa-solid fa-file-zipper me-2 text-success"></i> Download ZIP</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="openShareModal('folder', <?= $f['id'] ?>, '<?= e(addslashes($f['name'])) ?>')"><i class="fa-solid fa-share-nodes me-2 text-info"></i> Share Link</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="openRenameModal('folder', <?= $f['id'] ?>, '<?= e(addslashes($f['name'])) ?>')"><i class="fa-solid fa-pen-to-square me-2 text-muted"></i> Rename</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="openMoveModal('folder', <?= $f['id'] ?>, '<?= e(addslashes($f['name'])) ?>')"><i class="fa-solid fa-arrows-up-down-left-right me-2 text-muted"></i> Move</a></li>
                                    <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="moveToTrash('folder', <?= $f['id'] ?>, '<?= e(addslashes($f['name'])) ?>')"><i class="fa-solid fa-trash-can me-2"></i> Move to Trash</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Files Section -->
<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-muted small fw-bold text-uppercase tracking-wider m-0">Files (<?= count($files) ?>)</h6>
    </div>

    <?php if (empty($files) && empty($folders)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fa-solid fa-folder-plus"></i>
            </div>
            <h5 class="fw-bold text-dark">This folder is empty</h5>
            <p class="text-muted small mb-3">Upload files or create subfolders to organize your assets.</p>
            <button type="button" class="btn btn-primary px-4" onclick="document.getElementById('fileUploadInput').click()">
                <i class="fa-solid fa-cloud-arrow-up me-2"></i> Upload File
            </button>
        </div>
    <?php elseif (empty($files)): ?>
        <div class="p-4 text-center bg-white rounded border text-muted small">
            No files in this folder yet. Drag and drop files above to upload.
        </div>
    <?php else: ?>

        <?php if ($viewMode === 'grid'): ?>
            <!-- Grid View -->
            <div class="row g-3">
                <?php foreach ($files as $file): 
                    $meta = get_file_icon_meta($file['extension'], $file['mime_type']);
                ?>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                        <div class="file-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <button type="button" class="btn-star <?= $file['is_starred'] ? 'starred' : '' ?>" data-type="file" data-id="<?= $file['id'] ?>" onclick="toggleStar('file', <?= $file['id'] ?>, this)" title="Star / Unstar">
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
                                        <li><a class="dropdown-item" href="javascript:void(0)" onclick="openShareModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-share-nodes me-2 text-warning"></i> Share Link</a></li>
                                        <li><a class="dropdown-item" href="versions.php?id=<?= $file['id'] ?>"><i class="fa-solid fa-code-branch me-2 text-secondary"></i> Versions (<?= $file['version_count'] ?>)</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="javascript:void(0)" onclick="openRenameModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-pen-to-square me-2 text-muted"></i> Rename</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0)" onclick="openMoveModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-arrows-up-down-left-right me-2 text-muted"></i> Move</a></li>
                                        <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="moveToTrash('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')"><i class="fa-solid fa-trash-can me-2"></i> Move to Trash</a></li>
                                    </ul>
                                </div>
                            </div>

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

                            <div class="file-card-meta d-flex justify-content-between mt-auto pt-2">
                                <span><?= format_bytes($file['file_size']) ?></span>
                                <span><?= date('M d, Y', strtotime($file['updated_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- List View (Table) -->
            <div class="file-table">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width: 40px;"></th>
                                <th>Name</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Versions</th>
                                <th>Last Modified</th>
                                <th class="text-end" style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $file): 
                                $meta = get_file_icon_meta($file['extension'], $file['mime_type']);
                            ?>
                                <tr>
                                    <td>
                                        <button type="button" class="btn-star <?= $file['is_starred'] ? 'starred' : '' ?>" data-type="file" data-id="<?= $file['id'] ?>" onclick="toggleStar('file', <?= $file['id'] ?>, this)">
                                            <i class="<?= $file['is_starred'] ? 'fa-solid' : 'fa-regular' ?> fa-star"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fa-solid <?= $meta['icon'] ?> fs-5 me-3" style="color: <?= $meta['color'] ?>; width: 24px; text-align: center;"></i>
                                            <a href="preview.php?id=<?= $file['id'] ?>" class="text-dark fw-semibold text-decoration-none text-truncate" style="max-width: 320px;" title="<?= e($file['original_name']) ?>">
                                                <?= e($file['original_name']) ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td><span class="text-muted small"><?= format_bytes($file['file_size']) ?></span></td>
                                    <td><span class="badge bg-light text-secondary border">.<?= e(strtoupper($file['extension'])) ?></span></td>
                                    <td>
                                        <a href="versions.php?id=<?= $file['id'] ?>" class="badge bg-secondary-subtle text-secondary text-decoration-none">
                                            v<?= $file['version_count'] ?>
                                        </a>
                                    </td>
                                    <td><span class="text-muted small"><?= date('M d, Y h:i A', strtotime($file['updated_at'])) ?></span></td>
                                    <td class="text-end">
                                        <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-light btn-sm" title="Download">
                                            <i class="fa-solid fa-download text-success"></i>
                                        </a>
                                        <button type="button" class="btn btn-light btn-sm" onclick="openShareModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')" title="Share">
                                            <i class="fa-solid fa-share-nodes text-primary"></i>
                                        </button>
                                        <button type="button" class="btn btn-light btn-sm text-danger" onclick="moveToTrash('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')" title="Move to Trash">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
