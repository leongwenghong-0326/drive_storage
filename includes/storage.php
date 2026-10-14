<?php
/**
 * AetherVault - Storage Engine & File Security Operations
 */

class StorageEngine {
    /**
     * Dangerous executable extensions blocked unconditionally
     */
    private const DISALLOWED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh',
        'cgi', 'pl', 'py', 'vbs', 'msi', 'dll', 'com', 'scr', 'vbe', 'wsf', 'wsh',
        'bash', 'asp', 'aspx', 'jsp', 'jspx', 'cer', 'asa', 'htaccess', 'htpasswd'
    ];

    /**
     * Validate an uploaded file before saving
     */
    public static function validateUpload(array $fileInput, int $userId): array {
        if (!isset($fileInput['error']) || is_array($fileInput['error'])) {
            return ['valid' => false, 'error' => 'Invalid file parameter.'];
        }

        switch ($fileInput['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['valid' => false, 'error' => 'The uploaded file exceeds the maximum server file size limit.'];
            case UPLOAD_ERR_PARTIAL:
                return ['valid' => false, 'error' => 'The file was only partially uploaded.'];
            case UPLOAD_ERR_NO_FILE:
                return ['valid' => false, 'error' => 'No file was uploaded.'];
            case UPLOAD_ERR_NO_TMP_DIR:
                return ['valid' => false, 'error' => 'Missing a temporary upload directory on server.'];
            case UPLOAD_ERR_CANT_WRITE:
                return ['valid' => false, 'error' => 'Failed to write file to disk.'];
            default:
                return ['valid' => false, 'error' => 'Unknown upload error occurred.'];
        }

        $fileSize = (int)$fileInput['size'];
        $originalName = trim(basename($fileInput['name']));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (empty($extension)) {
            return ['valid' => false, 'error' => 'Files without an extension are not permitted.'];
        }

        // Strict blacklist check
        if (in_array($extension, self::DISALLOWED_EXTENSIONS, true)) {
            return ['valid' => false, 'error' => "Uploading .{$extension} executable or server script files is strictly forbidden."];
        }

        // System allowed extensions whitelist check
        $allowedSetting = (string)get_setting('allowed_file_extensions', '');
        if (!empty($allowedSetting)) {
            $allowedList = array_map('trim', explode(',', strtolower($allowedSetting)));
            if (!in_array($extension, $allowedList, true)) {
                return ['valid' => false, 'error' => "File extension .{$extension} is not allowed by system policy."];
            }
        }

        // System max upload size check
        $maxMb = (int)get_setting('max_upload_size_mb', 100);
        $maxBytes = $maxMb * 1024 * 1024;
        if ($fileSize > $maxBytes) {
            return ['valid' => false, 'error' => "File size exceeds the allowed limit of {$maxMb} MB."];
        }

        // Storage quota check
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT storage_quota, storage_used FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userQuota = $stmt->fetch();

        if (!$userQuota) {
            return ['valid' => false, 'error' => 'User account not found.'];
        }

        if (($userQuota['storage_used'] + $fileSize) > $userQuota['storage_quota']) {
            $avail = max(0, $userQuota['storage_quota'] - $userQuota['storage_used']);
            return ['valid' => false, 'error' => 'Storage quota exceeded. Available space: ' . format_bytes($avail)];
        }

        // Detect MIME type reliably using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($fileInput['tmp_name']) ?: ($fileInput['type'] ?? 'application/octet-stream');

        return [
            'valid' => true,
            'original_name' => $originalName,
            'extension' => $extension,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'tmp_name' => $fileInput['tmp_name']
        ];
    }

    /**
     * Store new file into system
     */
    public static function storeFile(array $validated, int $userId, ?int $folderId = null): array {
        // Ensure destination directory exists
        if (!is_dir(FILES_DIR)) {
            mkdir(FILES_DIR, 0755, true);
        }

        // Verify folder ownership if folderId is provided
        $pdo = Database::getConnection();
        if ($folderId !== null && $folderId > 0) {
            $chk = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $chk->execute([$folderId, $userId]);
            if (!$chk->fetch()) {
                $folderId = null; // fallback to root if invalid
            }
        } else {
            $folderId = null;
        }

        // Generate cryptographically unique stored filename
        $storedName = bin2hex(random_bytes(16)) . '_' . time() . '.' . $validated['extension'];
        $targetPath = FILES_DIR . '/' . $storedName;

        $fileMoved = false;
        if (is_uploaded_file($validated['tmp_name'])) {
            $fileMoved = move_uploaded_file($validated['tmp_name'], $targetPath);
        } else {
            $fileMoved = copy($validated['tmp_name'], $targetPath);
        }

        if (!$fileMoved) {
            return ['success' => false, 'message' => 'Failed to save physical file to storage directory.'];
        }

        // Insert database record
        $stmt = $pdo->prepare("
            INSERT INTO files (user_id, folder_id, original_name, stored_name, file_path, file_size, mime_type, extension, version_count, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([
            $userId,
            $folderId,
            $validated['original_name'],
            $storedName,
            'uploads/files/' . $storedName,
            $validated['file_size'],
            $validated['mime_type'],
            $validated['extension']
        ]);

        $fileId = (int)$pdo->lastInsertId();

        // Refresh user storage usage
        refresh_user_storage($userId);

        log_activity($userId, 'Upload', 'File', $fileId, "Uploaded '{$validated['original_name']}' (" . format_bytes($validated['file_size']) . ")");

        return [
            'success' => true,
            'file_id' => $fileId,
            'original_name' => $validated['original_name'],
            'file_size' => $validated['file_size'],
            'message' => 'File uploaded successfully.'
        ];
    }

    /**
     * Upload a new revision / version for an existing file
     */
    public static function storeRevision(int $fileId, array $validated, int $userId): array {
        $pdo = Database::getConnection();

        // Verify file ownership and existence
        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
        $stmt->execute([$fileId, $userId]);
        $currentFile = $stmt->fetch();

        if (!$currentFile) {
            return ['success' => false, 'message' => 'Target file not found or access denied.'];
        }

        if (!is_dir(VERSIONS_DIR)) {
            mkdir(VERSIONS_DIR, 0755, true);
        }

        // 1. Move old current file to versions directory
        $oldPhysicalPath = APP_ROOT . '/' . $currentFile['file_path'];
        $versionStoredName = 'v_' . $currentFile['version_count'] . '_' . $currentFile['stored_name'];
        $newVersionPhysicalPath = VERSIONS_DIR . '/' . $versionStoredName;

        if (file_exists($oldPhysicalPath)) {
            rename($oldPhysicalPath, $newVersionPhysicalPath);
        }

        // 2. Insert into file_versions table
        $stmtVer = $pdo->prepare("
            INSERT INTO file_versions (file_id, user_id, version_number, stored_name, file_path, file_size, mime_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtVer->execute([
            $currentFile['id'],
            $userId,
            $currentFile['version_count'],
            $versionStoredName,
            'uploads/versions/' . $versionStoredName,
            $currentFile['file_size'],
            $currentFile['mime_type'],
            $currentFile['updated_at']
        ]);

        // 3. Move new uploaded file to files directory
        $newStoredName = bin2hex(random_bytes(16)) . '_' . time() . '.' . $validated['extension'];
        $newTargetPath = FILES_DIR . '/' . $newStoredName;

        $revMoved = false;
        if (is_uploaded_file($validated['tmp_name'])) {
            $revMoved = move_uploaded_file($validated['tmp_name'], $newTargetPath);
        } else {
            $revMoved = copy($validated['tmp_name'], $newTargetPath);
        }

        if (!$revMoved) {
            return ['success' => false, 'message' => 'Failed to store new revision physical file.'];
        }

        // 4. Update file record
        $newVersionNumber = (int)$currentFile['version_count'] + 1;
        $stmtUp = $pdo->prepare("
            UPDATE files 
            SET stored_name = ?, file_path = ?, file_size = ?, mime_type = ?, extension = ?, version_count = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmtUp->execute([
            $newStoredName,
            'uploads/files/' . $newStoredName,
            $validated['file_size'],
            $validated['mime_type'],
            $validated['extension'],
            $newVersionNumber,
            $fileId,
            $userId
        ]);

        refresh_user_storage($userId);
        log_activity($userId, 'Upload Revision', 'File', $fileId, "Uploaded revision v{$newVersionNumber} for '{$currentFile['original_name']}'");

        return [
            'success' => true,
            'version_count' => $newVersionNumber,
            'message' => "Revision v{$newVersionNumber} uploaded successfully."
        ];
    }

    /**
     * Rollback a file to a specific historical version
     */
    public static function rollbackVersion(int $fileId, int $versionId, int $userId): array {
        $pdo = Database::getConnection();

        $stmtFile = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
        $stmtFile->execute([$fileId, $userId]);
        $currentFile = $stmtFile->fetch();

        $stmtVer = $pdo->prepare("SELECT * FROM file_versions WHERE id = ? AND file_id = ? AND user_id = ?");
        $stmtVer->execute([$versionId, $fileId, $userId]);
        $targetVer = $stmtVer->fetch();

        if (!$currentFile || !$targetVer) {
            return ['success' => false, 'message' => 'Version or file not found.'];
        }

        $targetPhysicalPath = APP_ROOT . '/' . $targetVer['file_path'];
        if (!file_exists($targetPhysicalPath)) {
            return ['success' => false, 'message' => 'Historical file content missing on disk.'];
        }

        // Save current file as a new archive before rolling back
        $currentPhysical = APP_ROOT . '/' . $currentFile['file_path'];
        $archiveStoredName = 'v_' . $currentFile['version_count'] . '_' . $currentFile['stored_name'];
        $archivePath = VERSIONS_DIR . '/' . $archiveStoredName;

        if (file_exists($currentPhysical)) {
            rename($currentPhysical, $archivePath);
        }

        $stmtInsertCur = $pdo->prepare("
            INSERT INTO file_versions (file_id, user_id, version_number, stored_name, file_path, file_size, mime_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsertCur->execute([
            $currentFile['id'],
            $userId,
            $currentFile['version_count'],
            $archiveStoredName,
            'uploads/versions/' . $archiveStoredName,
            $currentFile['file_size'],
            $currentFile['mime_type'],
            $currentFile['updated_at']
        ]);

        // Copy historical version back as the active file
        $restoredStoredName = bin2hex(random_bytes(16)) . '_' . time() . '.' . pathinfo($targetVer['stored_name'], PATHINFO_EXTENSION);
        $newActivePath = FILES_DIR . '/' . $restoredStoredName;
        copy($targetPhysicalPath, $newActivePath);

        $newVerNum = (int)$currentFile['version_count'] + 1;
        $stmtUp = $pdo->prepare("
            UPDATE files 
            SET stored_name = ?, file_path = ?, file_size = ?, mime_type = ?, version_count = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmtUp->execute([
            $restoredStoredName,
            'uploads/files/' . $restoredStoredName,
            $targetVer['file_size'],
            $targetVer['mime_type'],
            $newVerNum,
            $fileId,
            $userId
        ]);

        refresh_user_storage($userId);
        log_activity($userId, 'Rollback Version', 'File', $fileId, "Rolled back '{$currentFile['original_name']}' to content of version v{$targetVer['version_number']}");

        return ['success' => true, 'message' => "Successfully restored version v{$targetVer['version_number']} as active version v{$newVerNum}."];
    }

    /**
     * Permanently delete a file, its versions, and its physical files
     */
    public static function permanentlyDeleteFile(int $fileId, int $userId): bool {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
        $stmt->execute([$fileId, $userId]);
        $file = $stmt->fetch();

        if (!$file) {
            return false;
        }

        // Delete active physical file
        $activePath = APP_ROOT . '/' . $file['file_path'];
        if (file_exists($activePath) && is_file($activePath)) {
            @unlink($activePath);
        }

        // Delete all historical physical files
        $stmtVer = $pdo->prepare("SELECT file_path FROM file_versions WHERE file_id = ?");
        $stmtVer->execute([$fileId]);
        $versions = $stmtVer->fetchAll();
        foreach ($versions as $v) {
            $vPath = APP_ROOT . '/' . $v['file_path'];
            if (file_exists($vPath) && is_file($vPath)) {
                @unlink($vPath);
            }
        }

        // Database cascade will delete file_versions, file_tags, shares
        $del = $pdo->prepare("DELETE FROM files WHERE id = ? AND user_id = ?");
        $del->execute([$fileId, $userId]);

        refresh_user_storage($userId);
        log_activity($userId, 'Permanent Delete', 'File', $fileId, "Permanently deleted file '{$file['original_name']}'");

        return true;
    }

    /**
     * Get folder hierarchy breadcrumbs
     */
    public static function getBreadcrumbs(?int $folderId, int $userId): array {
        $breadcrumbs = [];
        if (!$folderId) {
            return $breadcrumbs;
        }

        $pdo = Database::getConnection();
        $currentId = $folderId;
        $safetyCounter = 0;

        while ($currentId !== null && $safetyCounter < 50) {
            $stmt = $pdo->prepare("SELECT id, name, parent_id FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $stmt->execute([$currentId, $userId]);
            $folder = $stmt->fetch();

            if (!$folder) {
                break;
            }

            array_unshift($breadcrumbs, $folder);
            $currentId = $folder['parent_id'];
            $safetyCounter++;
        }

        return $breadcrumbs;
    }

    /**
     * Check if a target folder is a descendant of source folder (prevent circular tree nesting)
     */
    public static function isDescendantFolder(int $sourceFolderId, int $targetFolderId, int $userId): bool {
        if ($sourceFolderId === $targetFolderId) {
            return true;
        }

        $pdo = Database::getConnection();
        $currentId = $targetFolderId;
        $safetyCounter = 0;

        while ($currentId !== null && $safetyCounter < 50) {
            $stmt = $pdo->prepare("SELECT parent_id FROM folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$currentId, $userId]);
            $parent = $stmt->fetchColumn();

            if ($parent === false || $parent === null) {
                return false;
            }

            if ((int)$parent === $sourceFolderId) {
                return true;
            }

            $currentId = (int)$parent;
            $safetyCounter++;
        }

        return false;
    }

    /**
     * Create ZIP archive of a folder for download
     */
    public static function createFolderZip(int $folderId, int $userId): ?string {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $pdo = Database::getConnection();
        $stmtFolder = $pdo->prepare("SELECT name FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
        $stmtFolder->execute([$folderId, $userId]);
        $folderName = $stmtFolder->fetchColumn();

        if (!$folderName) {
            return null;
        }

        $zip = new ZipArchive();
        $tempZipPath = sys_get_temp_dir() . '/vault_zip_' . bin2hex(random_bytes(8)) . '.zip';

        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        self::addFolderToZip($zip, $folderId, $userId, '', $pdo);
        $zip->close();

        return file_exists($tempZipPath) ? $tempZipPath : null;
    }

    /**
     * Recursively add folder contents into ZipArchive
     */
    private static function addFolderToZip(ZipArchive $zip, int $folderId, int $userId, string $localPath, PDO $pdo): void {
        // Add files in this folder
        $stmtFiles = $pdo->prepare("SELECT original_name, file_path FROM files WHERE folder_id = ? AND user_id = ? AND is_deleted = 0");
        $stmtFiles->execute([$folderId, $userId]);
        $files = $stmtFiles->fetchAll();

        foreach ($files as $file) {
            $fullPath = APP_ROOT . '/' . $file['file_path'];
            if (file_exists($fullPath) && is_readable($fullPath)) {
                $zip->addFile($fullPath, ltrim($localPath . '/' . $file['original_name'], '/'));
            }
        }

        // Add subfolders
        $stmtSub = $pdo->prepare("SELECT id, name FROM folders WHERE parent_id = ? AND user_id = ? AND is_deleted = 0");
        $stmtSub->execute([$folderId, $userId]);
        $subfolders = $stmtSub->fetchAll();

        foreach ($subfolders as $sub) {
            $subPath = ltrim($localPath . '/' . $sub['name'], '/');
            $zip->addEmptyDir($subPath);
            self::addFolderToZip($zip, (int)$sub['id'], $userId, $subPath, $pdo);
        }
    }
}
