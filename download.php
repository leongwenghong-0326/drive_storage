<?php
/**
 * AetherVault - Secure Stream Download Handler
 * Prevents unauthorized access, path traversal, and delivers files directly via stream
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();

// 1. Check for folder ZIP download request
if (!empty($_GET['folder_id'])) {
    $folderId = (int)$_GET['folder_id'];
    $stmtF = $pdo->prepare("SELECT name FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
    $stmtF->execute([$folderId, $userId]);
    $folderName = $stmtF->fetchColumn();

    if (!$folderName) {
        http_response_code(404);
        die('Folder not found or access denied.');
    }

    $zipPath = StorageEngine::createFolderZip($folderId, $userId);
    if (!$zipPath || !file_exists($zipPath)) {
        http_response_code(500);
        die('Failed to package folder as ZIP archive.');
    }

    $downloadName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $folderName) . '.zip';

    log_activity($userId, 'Download', 'Folder', $folderId, "Downloaded folder archive '{$downloadName}'");

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// 2. File Download Request
$fileId = (int)($_GET['id'] ?? 0);
$versionNumber = !empty($_GET['version']) ? (int)$_GET['version'] : null;

if (!$fileId) {
    http_response_code(400);
    die('Invalid file requested.');
}

// Check base file ownership
$stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
$stmt->execute([$fileId, $userId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('Requested file does not exist or access is forbidden.');
}

$physicalRelativePath = $file['file_path'];
$downloadFilename = $file['original_name'];
$mimeType = $file['mime_type'];
$fileSize = $file['file_size'];

// If specific historical version is requested
if ($versionNumber !== null && $versionNumber < (int)$file['version_count']) {
    $stmtVer = $pdo->prepare("SELECT * FROM file_versions WHERE file_id = ? AND version_number = ? AND user_id = ?");
    $stmtVer->execute([$fileId, $versionNumber, $userId]);
    $versionRecord = $stmtVer->fetch();

    if ($versionRecord) {
        $physicalRelativePath = $versionRecord['file_path'];
        $fileSize = $versionRecord['file_size'];
        $mimeType = $versionRecord['mime_type'];
        $downloadFilename = pathinfo($file['original_name'], PATHINFO_FILENAME) . "_v{$versionNumber}." . $file['extension'];
    }
}

// Resolve real filesystem path and verify it stays inside APP_ROOT/uploads
$fullPath = realpath(APP_ROOT . '/' . $physicalRelativePath);
$uploadsRoot = realpath(UPLOADS_DIR);

if ($fullPath === false || $uploadsRoot === false || !str_starts_with($fullPath, $uploadsRoot)) {
    http_response_code(403);
    die('Security verification failed. Invalid file path detected.');
}

if (!is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    die('Physical file missing on server.');
}

// Log download event
log_activity($userId, 'Download', 'File', $fileId, "Downloaded '{$downloadFilename}'");

// Clear any buffered output
if (ob_get_level()) {
    ob_end_clean();
}

// Stream download headers
header('Content-Description: File Transfer');
header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . addslashes($downloadFilename) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($fullPath));

// Output file in chunks to handle large files efficiently
$handle = fopen($fullPath, 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);
exit;
