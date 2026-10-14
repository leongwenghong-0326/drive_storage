<?php
/**
 * AetherVault - Public & Protected Share Access Gateway
 */

require_once __DIR__ . '/config/config.php';

$token = trim($_GET['token'] ?? '');
$appName = get_setting('app_name', 'AetherVault Cloud');
$pdo = Database::getConnection();

if (empty($token)) {
    http_response_code(400);
    die('<!DOCTYPE html><html><head><title>Invalid Share Link</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light d-flex align-items-center min-vh-100 justify-content-center"><div class="card p-4 text-center shadow-sm" style="max-width: 440px;"><h4>Invalid Share Link</h4><p class="text-muted">No share token was provided in the URL.</p></div></body></html>');
}

// 1. Fetch Share Record
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name AS owner_name
    FROM shares s
    JOIN users u ON s.user_id = u.id
    WHERE s.share_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$share = $stmt->fetch();

// 2. Validate existence & active status
if (!$share || !$share['is_active']) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><title>Share Link Inactive</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light d-flex align-items-center min-vh-100 justify-content-center"><div class="card p-4 text-center shadow-sm" style="max-width: 460px;"><h4 class="text-danger mb-2">Link Expired or Inactive</h4><p class="text-muted">This share link is no longer active or has been revoked by the owner.</p></div></body></html>');
}

// 3. Check expiration
if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time()) {
    http_response_code(410);
    die('<!DOCTYPE html><html><head><title>Share Link Expired</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light d-flex align-items-center min-vh-100 justify-content-center"><div class="card p-4 text-center shadow-sm" style="max-width: 460px;"><h4 class="text-warning mb-2">Share Link Expired</h4><p class="text-muted">The access period for this shared item has expired.</p></div></body></html>');
}

// 4. Password Protection Check
$sessionUnlockKey = 'share_unlocked_' . $share['id'];
$isUnlocked = empty($share['password_hash']) || (!empty($_SESSION[$sessionUnlockKey]));
$passwordError = '';

if (!$isUnlocked && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_password'])) {
    if (password_verify($_POST['share_password'], $share['password_hash'])) {
        $_SESSION[$sessionUnlockKey] = true;
        $isUnlocked = true;
    } else {
        $passwordError = 'Incorrect access password. Please try again.';
    }
}

// If password protected and not unlocked yet, render unlock screen
if (!$isUnlocked) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Protected Shared Item — <?= e($appName) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0f172a; color: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
            .unlock-card { background: #1e293b; border: 1px solid #334155; border-radius: 1.25rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); max-width: 440px; width: 100%; padding: 36px 30px; }
            .form-control { background: #0f172a; border-color: #334155; color: #fff; border-radius: 0.6rem; padding: 0.75rem 1rem; }
            .form-control:focus { background: #0f172a; border-color: #6366f1; color: #fff; box-shadow: 0 0 0 3px rgba(99,102,241,0.25); }
        </style>
    </head>
    <body>
        <div class="unlock-card text-center">
            <div class="mb-3 text-warning">
                <i class="fa-solid fa-lock fa-3x"></i>
            </div>
            <h4 class="fw-bold mb-2">Protected Resource</h4>
            <p class="text-secondary small mb-4">This shared resource requires an access password configured by <strong><?= e($share['owner_name']) ?></strong>.</p>

            <?php if ($passwordError): ?>
                <div class="alert alert-danger border-0 small py-2 px-3 mb-3 text-start"><?= e($passwordError) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <input type="password" name="share_password" class="form-control text-center" placeholder="Enter password to unlock" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border: none;">
                    <i class="fa-solid fa-key me-2"></i> Unlock Resource
                </a>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 5. Increment View Count
$upView = $pdo->prepare("UPDATE shares SET view_count = view_count + 1 WHERE id = ?");
$upView->execute([$share['id']]);

// 6. Handle Public Download Request
if (!empty($_GET['download'])) {
    if ($share['resource_type'] === 'file') {
        $stmtF = $pdo->prepare("SELECT * FROM files WHERE id = ? AND is_deleted = 0");
        $stmtF->execute([$share['resource_id']]);
        $targetFile = $stmtF->fetch();

        if (!$targetFile) {
            http_response_code(404);
            die('Shared file has been removed.');
        }

        $fullPath = realpath(APP_ROOT . '/' . $targetFile['file_path']);
        $uploadsRoot = realpath(UPLOADS_DIR);

        if ($fullPath === false || $uploadsRoot === false || !str_starts_with($fullPath, $uploadsRoot) || !is_file($fullPath)) {
            http_response_code(404);
            die('Physical file unavailable.');
        }

        if (ob_get_level()) ob_end_clean();

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($targetFile['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . addslashes($targetFile['original_name']) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;

    } elseif ($share['resource_type'] === 'folder') {
        $zipPath = StorageEngine::createFolderZip((int)$share['resource_id'], (int)$share['user_id']);
        if (!$zipPath || !file_exists($zipPath)) {
            http_response_code(500);
            die('Failed to package shared folder.');
        }

        $stmtFolder = $pdo->prepare("SELECT name FROM folders WHERE id = ?");
        $stmtFolder->execute([$share['resource_id']]);
        $folderName = $stmtFolder->fetchColumn() ?: 'Shared_Folder';
        $downloadName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $folderName) . '.zip';

        if (ob_get_level()) ob_end_clean();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }
}

// 7. Render Shared File or Folder View
$resourceType = $share['resource_type'];
$resourceData = null;
$folderFiles = [];

if ($resourceType === 'file') {
    $stmtFile = $pdo->prepare("SELECT * FROM files WHERE id = ? AND is_deleted = 0");
    $stmtFile->execute([$share['resource_id']]);
    $resourceData = $stmtFile->fetch();

    if (!$resourceData) {
        http_response_code(404);
        die('The shared file has been deleted by its owner.');
    }
} else {
    $stmtFolder = $pdo->prepare("SELECT * FROM folders WHERE id = ? AND is_deleted = 0");
    $stmtFolder->execute([$share['resource_id']]);
    $resourceData = $stmtFolder->fetch();

    if (!$resourceData) {
        http_response_code(404);
        die('The shared folder has been deleted by its owner.');
    }

    // Fetch files inside shared folder
    $stmtFF = $pdo->prepare("SELECT * FROM files WHERE folder_id = ? AND is_deleted = 0 ORDER BY original_name ASC");
    $stmtFF->execute([$share['resource_id']]);
    $folderFiles = $stmtFF->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared: <?= e($resourceType === 'file' ? $resourceData['original_name'] : $resourceData['name']) ?> — <?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0f172a; color: #f8fafc; min-height: 100vh; }
        .public-nav { border-bottom: 1px solid #1e293b; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(10px); }
        .share-container { max-width: 860px; margin: 40px auto; }
        .share-card { background: #1e293b; border: 1px solid #334155; border-radius: 1.25rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .btn-gradient { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border: none; color: white; font-weight: 600; }
        .btn-gradient:hover { color: white; opacity: 0.95; box-shadow: 0 10px 20px -3px rgba(79, 70, 229, 0.4); }
    </style>
</head>
<body>

<header class="public-nav py-3 px-4">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <div class="bg-primary text-white rounded p-1 px-2 fw-bold"><i class="fa-solid fa-cloud-arrow-up"></i></div>
            <span class="fw-bold tracking-tight text-white"><?= e($appName) ?></span>
        </div>
        <span class="badge bg-secondary-subtle text-secondary px-3 py-2">
            <i class="fa-solid fa-share-nodes me-1"></i> Public Access Gateway
        </span>
    </div>
</header>

<main class="container share-container px-3">
    <div class="share-card p-4 p-md-5 mb-4">
        <!-- Resource Header -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4 pb-4 border-bottom border-secondary">
            <div class="d-flex align-items-center gap-3">
                <div class="p-3 bg-dark rounded-circle border border-secondary text-primary fs-3" style="width: 64px; height: 64px; display: flex; align-items: center; justify-content: center;">
                    <?php if ($resourceType === 'file'): ?>
                        <?php $meta = get_file_icon_meta($resourceData['extension'], $resourceData['mime_type']); ?>
                        <i class="fa-solid <?= $meta['icon'] ?>" style="color: <?= $meta['color'] ?>;"></i>
                    <?php else: ?>
                        <i class="fa-solid fa-folder text-warning"></i>
                    <?php endif; ?>
                </div>

                <div>
                    <h3 class="fw-bold text-white mb-1 text-break">
                        <?= e($resourceType === 'file' ? $resourceData['original_name'] : $resourceData['name']) ?>
                    </h3>
                    <div class="small text-secondary">
                        <span>Shared by <strong class="text-light"><?= e($share['owner_name']) ?></strong></span> &bull; 
                        <span>Views: <?= number_format($share['view_count']) ?></span>
                        <?php if ($share['expires_at']): ?>
                            &bull; <span>Expires: <?= date('M d, Y', strtotime($share['expires_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Download Button -->
            <a href="public_share.php?token=<?= e($token) ?>&download=1" class="btn btn-gradient btn-lg px-4 py-2">
                <i class="fa-solid fa-download me-2"></i> 
                <?= $resourceType === 'file' ? 'Download File' : 'Download Folder (ZIP)' ?>
            </a>
        </div>

        <?php if ($resourceType === 'file'): ?>
            <!-- File Info Breakdown -->
            <div class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="p-3 bg-dark rounded border border-secondary">
                        <span class="small text-secondary d-block">File Size</span>
                        <strong class="text-white"><?= format_bytes($resourceData['file_size']) ?></strong>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-3 bg-dark rounded border border-secondary">
                        <span class="small text-secondary d-block">MIME Type</span>
                        <strong class="text-white text-truncate d-block" title="<?= e($resourceData['mime_type']) ?>"><?= e($resourceData['mime_type']) ?></strong>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-3 bg-dark rounded border border-secondary">
                        <span class="small text-secondary d-block">Last Modified</span>
                        <strong class="text-white"><?= date('M d, Y', strtotime($resourceData['updated_at'])) ?></strong>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Shared Folder File Listing -->
            <h5 class="fw-bold text-white mb-3">Folder Contents (<?= count($folderFiles) ?> files)</h5>
            <?php if (empty($folderFiles)): ?>
                <div class="p-4 text-center text-secondary bg-dark rounded border border-secondary">
                    This shared folder does not contain any files.
                </div>
            <?php else: ?>
                <div class="table-responsive rounded border border-secondary bg-dark">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th class="text-end">Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($folderFiles as $ff): 
                                $meta = get_file_icon_meta($ff['extension'], $ff['mime_type']);
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fa-solid <?= $meta['icon'] ?> me-2" style="color: <?= $meta['color'] ?>;"></i>
                                            <span class="fw-semibold text-white"><?= e($ff['original_name']) ?></span>
                                        </div>
                                    </td>
                                    <td><span class="small text-secondary"><?= format_bytes($ff['file_size']) ?></span></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary">.<?= e(strtoupper($ff['extension'])) ?></span></td>
                                    <td class="text-end">
                                        <a href="public_share.php?token=<?= e($token) ?>&download=1" class="btn btn-sm btn-outline-light">
                                            <i class="fa-solid fa-download"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
