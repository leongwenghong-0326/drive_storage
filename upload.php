<?php
/**
 * AetherVault - File Upload Controller
 * Handles drag-and-drop, single, and multiple file uploads with strict security
 */

require_once __DIR__ . '/config/config.php';
require_login();
Csrf::requireValid();

$user = auth_user();
$userId = (int)$user['id'];
$folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;

if (empty($_FILES['files']) && empty($_FILES['file'])) {
    json_response(['success' => false, 'message' => 'No files were provided for upload.'], 400);
}

// Normalize files array to handle both single and multiple file inputs
$fileArray = $_FILES['files'] ?? $_FILES['file'];
$files = [];

if (is_array($fileArray['name'])) {
    $count = count($fileArray['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($fileArray['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name'     => $fileArray['name'][$i],
            'type'     => $fileArray['type'][$i],
            'tmp_name' => $fileArray['tmp_name'][$i],
            'error'    => $fileArray['error'][$i],
            'size'     => $fileArray['size'][$i],
        ];
    }
} else {
    if ($fileArray['error'] !== UPLOAD_ERR_NO_FILE) {
        $files[] = $fileArray;
    }
}

if (empty($files)) {
    json_response(['success' => false, 'message' => 'No valid files selected for upload.'], 400);
}

$uploadedCount = 0;
$errors = [];

foreach ($files as $file) {
    $validation = StorageEngine::validateUpload($file, $userId);
    
    if (!$validation['valid']) {
        $errors[] = "{$file['name']}: {$validation['error']}";
        continue;
    }

    $storeResult = StorageEngine::storeFile($validation, $userId, $folderId);
    
    if ($storeResult['success']) {
        $uploadedCount++;
    } else {
        $errors[] = "{$file['name']}: {$storeResult['message']}";
    }
}

if ($uploadedCount > 0) {
    $msg = "Successfully uploaded {$uploadedCount} file(s).";
    if (!empty($errors)) {
        $msg .= " Some files had issues: " . implode('; ', $errors);
    }
    json_response([
        'success' => true,
        'uploaded_count' => $uploadedCount,
        'message' => $msg
    ]);
} else {
    json_response([
        'success' => false,
        'message' => 'Upload failed. ' . implode('; ', $errors)
    ], 400);
}
