<?php
/**
 * AetherVault - File Preview Controller & Media Streaming
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$fileId = (int)($_GET['id'] ?? 0);
$stream = !empty($_GET['stream']);

if (!$fileId) {
    redirect('files.php');
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
$stmt->execute([$fileId, $userId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('File not found or access denied.');
}

$physicalRelativePath = $file['file_path'];
$fullPath = realpath(APP_ROOT . '/' . $physicalRelativePath);
$uploadsRoot = realpath(UPLOADS_DIR);

if ($fullPath === false || $uploadsRoot === false || !str_starts_with($fullPath, $uploadsRoot)) {
    http_response_code(403);
    die('Security policy violation: Invalid storage reference.');
}

// 1. Raw Stream Mode (for embedded <img>, <video>, <audio>, <iframe>)
if ($stream) {
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        http_response_code(404);
        exit;
    }

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: inline; filename="' . addslashes($file['original_name']) . '"');
    header('Cache-Control: private, max-age=3600');

    readfile($fullPath);
    exit;
}

// 2. Full Page Preview UI
$pageTitle = 'Preview: ' . $file['original_name'];
$ext = strtolower($file['extension']);
$iconMeta = get_file_icon_meta($ext, $file['mime_type']);

require_once INCLUDES_DIR . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm rounded-circle" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="page-header-title text-truncate" style="max-width: 650px;"><?= e($file['original_name']) ?></h1>
            <div class="page-header-subtitle">
                <span><?= format_bytes($file['file_size']) ?></span> &bull; 
                <span><?= e($file['mime_type']) ?></span> &bull; 
                <span>Modified <?= date('M d, Y h:i A', strtotime($file['updated_at'])) ?></span>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-primary btn-sm px-3">
            <i class="fa-solid fa-download me-1"></i> Download File
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm px-3" onclick="openShareModal('file', <?= $file['id'] ?>, '<?= e(addslashes($file['original_name'])) ?>')">
            <i class="fa-solid fa-share-nodes me-1"></i> Share
        </button>
    </div>
</div>

<!-- Preview Container Card -->
<div class="card border-0 shadow-sm" style="border-radius: var(--radius-lg); overflow: hidden; min-height: 520px; background-color: #0f172a;">
    <div class="card-body p-0 d-flex align-items-center justify-content-center position-relative">

        <?php if ($iconMeta['type'] === 'image'): ?>
            <!-- Image Preview -->
            <div class="p-4 text-center w-100">
                <img src="preview.php?id=<?= $file['id'] ?>&stream=1" alt="<?= e($file['original_name']) ?>" class="img-fluid rounded shadow" style="max-height: 75vh; object-fit: contain;">
            </div>

        <?php elseif ($iconMeta['type'] === 'pdf'): ?>
            <!-- PDF Viewer -->
            <iframe src="preview.php?id=<?= $file['id'] ?>&stream=1" style="width: 100%; height: 80vh; border: none;"></iframe>

        <?php elseif ($iconMeta['type'] === 'video'): ?>
            <!-- Video Player -->
            <div class="p-4 text-center w-100">
                <video controls autoplay class="rounded shadow w-100" style="max-height: 75vh; max-width: 900px;">
                    <source src="preview.php?id=<?= $file['id'] ?>&stream=1" type="<?= e($file['mime_type']) ?>">
                    Your browser does not support HTML5 video preview.
                </video>
            </div>

        <?php elseif ($iconMeta['type'] === 'audio'): ?>
            <!-- Audio Player -->
            <div class="p-5 text-center w-100 text-light">
                <div class="mb-4">
                    <i class="fa-solid fa-music fa-4x text-primary"></i>
                </div>
                <h5 class="fw-bold mb-3"><?= e($file['original_name']) ?></h5>
                <audio controls class="w-100" style="max-width: 500px;">
                    <source src="preview.php?id=<?= $file['id'] ?>&stream=1" type="<?= e($file['mime_type']) ?>">
                    Your browser does not support HTML5 audio playback.
                </audio>
            </div>

        <?php elseif ($iconMeta['type'] === 'text' && $file['file_size'] < 2097152): ?>
            <!-- Text/Code File Preview (up to 2MB) -->
            <?php 
                $textContent = file_get_contents($fullPath);
            ?>
            <div class="w-100 p-4" style="height: 75vh; overflow-y: auto;">
                <pre class="bg-dark text-light p-4 rounded border border-secondary" style="font-family: monospace; font-size: 0.88rem; line-height: 1.6; white-space: pre-wrap;"><?= e($textContent) ?></pre>
            </div>

        <?php else: ?>
            <!-- Unsupported / Generic File Fallback State -->
            <div class="text-center p-5 text-light" style="max-width: 480px;">
                <div class="mb-3 text-secondary">
                    <i class="fa-solid <?= $iconMeta['icon'] ?> fa-4x"></i>
                </div>
                <h4 class="fw-bold mb-2">Preview unavailable</h4>
                <p class="text-secondary small mb-4">
                    Direct browser preview is not supported for <code>.<?= e($ext) ?></code> files. You can safely download this file to open it in your local application.
                </p>
                <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-primary px-4 py-2">
                    <i class="fa-solid fa-download me-2"></i> Download File (<?= format_bytes($file['file_size']) ?>)
                </a>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once INCLUDES_DIR . '/footer.php'; ?>
