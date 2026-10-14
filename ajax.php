<?php
/**
 * AetherVault - Central AJAX Handler
 * Processes async requests for stars, renames, moves, trash operations, details, shares, etc.
 */

require_once __DIR__ . '/config/config.php';
require_login();

$user = auth_user();
$userId = (int)$user['id'];
$pdo = Database::getConnection();

// Read input JSON or POST
$rawInput = file_get_contents('php://input');
$requestData = [];
if (!empty($rawInput)) {
    $requestData = json_decode($rawInput, true) ?: [];
}
if (empty($requestData)) {
    $requestData = $_POST;
}

// CSRF validation for state-changing requests
$action = $requestData['action'] ?? '';
$safeReadActions = ['get_file_details', 'get_folder_tree', 'get_share_info', 'get_user_tags'];

if (!in_array($action, $safeReadActions, true)) {
    Csrf::requireValid();
}

switch ($action) {
    /**
     * Create new folder
     */
    case 'create_folder':
        $name = sanitize_file_name($requestData['name'] ?? '');
        $parentId = !empty($requestData['parent_id']) ? (int)$requestData['parent_id'] : null;
        $color = $requestData['color'] ?? '#4f46e5';

        if (empty($name)) {
            json_response(['success' => false, 'message' => 'Folder name is required.'], 400);
        }

        // Verify parent folder belongs to user
        if ($parentId !== null && $parentId > 0) {
            $chk = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $chk->execute([$parentId, $userId]);
            if (!$chk->fetch()) {
                json_response(['success' => false, 'message' => 'Invalid parent folder selected.'], 403);
            }
        } else {
            $parentId = null;
        }

        // Check if duplicate folder name exists in the same level
        $chkDup = $pdo->prepare("
            SELECT id FROM folders 
            WHERE user_id = ? AND (parent_id <=> ?) AND name = ? AND is_deleted = 0
        ");
        $chkDup->execute([$userId, $parentId, $name]);
        if ($chkDup->fetch()) {
            json_response(['success' => false, 'message' => 'A folder with this name already exists in this location.'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO folders (user_id, parent_id, name, color, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$userId, $parentId, $name, $color]);
        $folderId = (int)$pdo->lastInsertId();

        log_activity($userId, 'Create Folder', 'Folder', $folderId, "Created folder '{$name}'");

        json_response([
            'success' => true,
            'message' => "Folder '{$name}' created successfully.",
            'folder_id' => $folderId
        ]);
        break;

    /**
     * Toggle Star on file or folder
     */
    case 'toggle_star':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);

        if ($type === 'file') {
            $stmt = $pdo->prepare("SELECT id, is_starred, original_name FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $stmt->execute([$id, $userId]);
            $item = $stmt->fetch();
            if (!$item) json_response(['success' => false, 'message' => 'File not found or access denied.'], 404);

            $newStarred = $item['is_starred'] ? 0 : 1;
            $update = $pdo->prepare("UPDATE files SET is_starred = ? WHERE id = ? AND user_id = ?");
            $update->execute([$newStarred, $id, $userId]);

            log_activity($userId, $newStarred ? 'Star' : 'Unstar', 'File', $id, ($newStarred ? "Starred" : "Unstarred") . " file '{$item['original_name']}'");

            json_response([
                'success' => true,
                'is_starred' => (bool)$newStarred,
                'message' => $newStarred ? 'Marked as starred' : 'Removed from starred'
            ]);
        } elseif ($type === 'folder') {
            $stmt = $pdo->prepare("SELECT id, is_starred, name FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $stmt->execute([$id, $userId]);
            $item = $stmt->fetch();
            if (!$item) json_response(['success' => false, 'message' => 'Folder not found or access denied.'], 404);

            $newStarred = $item['is_starred'] ? 0 : 1;
            $update = $pdo->prepare("UPDATE folders SET is_starred = ? WHERE id = ? AND user_id = ?");
            $update->execute([$newStarred, $id, $userId]);

            log_activity($userId, $newStarred ? 'Star' : 'Unstar', 'Folder', $id, ($newStarred ? "Starred" : "Unstarred") . " folder '{$item['name']}'");

            json_response([
                'success' => true,
                'is_starred' => (bool)$newStarred,
                'message' => $newStarred ? 'Marked as starred' : 'Removed from starred'
            ]);
        } else {
            json_response(['success' => false, 'message' => 'Invalid resource type.'], 400);
        }
        break;

    /**
     * Rename file or folder
     */
    case 'rename':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);
        $newName = sanitize_file_name($requestData['new_name'] ?? '');

        if (empty($newName)) {
            json_response(['success' => false, 'message' => 'New name cannot be blank.'], 400);
        }

        if ($type === 'file') {
            $stmt = $pdo->prepare("SELECT id, original_name, extension FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $stmt->execute([$id, $userId]);
            $file = $stmt->fetch();
            if (!$file) json_response(['success' => false, 'message' => 'File not found.'], 404);

            // Preserve extension if user didn't specify one
            if (!str_contains($newName, '.')) {
                $newName .= '.' . $file['extension'];
            }

            $update = $pdo->prepare("UPDATE files SET original_name = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $update->execute([$newName, $id, $userId]);

            log_activity($userId, 'Rename File', 'File', $id, "Renamed file from '{$file['original_name']}' to '{$newName}'");
            json_response(['success' => true, 'message' => 'File renamed successfully.']);

        } elseif ($type === 'folder') {
            $stmt = $pdo->prepare("SELECT id, name FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $stmt->execute([$id, $userId]);
            $folder = $stmt->fetch();
            if (!$folder) json_response(['success' => false, 'message' => 'Folder not found.'], 404);

            $update = $pdo->prepare("UPDATE folders SET name = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $update->execute([$newName, $id, $userId]);

            log_activity($userId, 'Rename Folder', 'Folder', $id, "Renamed folder from '{$folder['name']}' to '{$newName}'");
            json_response(['success' => true, 'message' => 'Folder renamed successfully.']);
        } else {
            json_response(['success' => false, 'message' => 'Invalid resource type.'], 400);
        }
        break;

    /**
     * Move file or folder to destination folder
     */
    case 'move':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);
        $targetFolderId = !empty($requestData['target_folder_id']) ? (int)$requestData['target_folder_id'] : null;

        if ($targetFolderId !== null && $targetFolderId > 0) {
            $chkDest = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $chkDest->execute([$targetFolderId, $userId]);
            if (!$chkDest->fetch()) {
                json_response(['success' => false, 'message' => 'Destination folder not found or inaccessible.'], 400);
            }
        } else {
            $targetFolderId = null; // root folder
        }

        if ($type === 'file') {
            $update = $pdo->prepare("UPDATE files SET folder_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $update->execute([$targetFolderId, $id, $userId]);
            log_activity($userId, 'Move File', 'File', $id, "Moved file to folder ID " . ($targetFolderId ?? 'Root'));
            json_response(['success' => true, 'message' => 'File moved successfully.']);

        } elseif ($type === 'folder') {
            // Prevent circular folder moving
            if ($targetFolderId !== null) {
                if (StorageEngine::isDescendantFolder($id, $targetFolderId, $userId)) {
                    json_response(['success' => false, 'message' => 'Cannot move a folder into itself or its subfolders.'], 400);
                }
            }

            $update = $pdo->prepare("UPDATE folders SET parent_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $update->execute([$targetFolderId, $id, $userId]);
            log_activity($userId, 'Move Folder', 'Folder', $id, "Moved folder to parent ID " . ($targetFolderId ?? 'Root'));
            json_response(['success' => true, 'message' => 'Folder moved successfully.']);
        } else {
            json_response(['success' => false, 'message' => 'Invalid resource type.'], 400);
        }
        break;

    /**
     * Soft Delete (Move to Trash)
     */
    case 'trash_item':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);

        if ($type === 'file') {
            $stmt = $pdo->prepare("SELECT original_name FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $stmt->execute([$id, $userId]);
            $name = $stmt->fetchColumn();
            if (!$name) json_response(['success' => false, 'message' => 'File not found.'], 404);

            $del = $pdo->prepare("UPDATE files SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND user_id = ?");
            $del->execute([$id, $userId]);

            refresh_user_storage($userId);
            log_activity($userId, 'Delete', 'File', $id, "Moved file '{$name}' to trash");
            json_response(['success' => true, 'message' => "File '{$name}' moved to trash."]);

        } elseif ($type === 'folder') {
            $stmt = $pdo->prepare("SELECT name FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $stmt->execute([$id, $userId]);
            $name = $stmt->fetchColumn();
            if (!$name) json_response(['success' => false, 'message' => 'Folder not found.'], 404);

            $del = $pdo->prepare("UPDATE folders SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND user_id = ?");
            $del->execute([$id, $userId]);

            // Also mark child files as deleted
            $delFiles = $pdo->prepare("UPDATE files SET is_deleted = 1, deleted_at = NOW() WHERE folder_id = ? AND user_id = ?");
            $delFiles->execute([$id, $userId]);

            refresh_user_storage($userId);
            log_activity($userId, 'Delete', 'Folder', $id, "Moved folder '{$name}' and its contents to trash");
            json_response(['success' => true, 'message' => "Folder '{$name}' moved to trash."]);
        }
        break;

    /**
     * Restore from Trash
     */
    case 'restore_item':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);

        if ($type === 'file') {
            $res = $pdo->prepare("UPDATE files SET is_deleted = 0, deleted_at = NULL WHERE id = ? AND user_id = ?");
            $res->execute([$id, $userId]);
            refresh_user_storage($userId);
            log_activity($userId, 'Restore', 'File', $id, "Restored file from trash");
            json_response(['success' => true, 'message' => 'File restored from trash.']);
        } elseif ($type === 'folder') {
            $res = $pdo->prepare("UPDATE folders SET is_deleted = 0, deleted_at = NULL WHERE id = ? AND user_id = ?");
            $res->execute([$id, $userId]);

            // Also restore files in folder
            $resFiles = $pdo->prepare("UPDATE files SET is_deleted = 0, deleted_at = NULL WHERE folder_id = ? AND user_id = ?");
            $resFiles->execute([$id, $userId]);

            refresh_user_storage($userId);
            log_activity($userId, 'Restore', 'Folder', $id, "Restored folder from trash");
            json_response(['success' => true, 'message' => 'Folder restored from trash.']);
        }
        break;

    /**
     * Permanently Delete from Trash
     */
    case 'permanent_delete':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);

        if ($type === 'file') {
            $ok = StorageEngine::permanentlyDeleteFile($id, $userId);
            if ($ok) {
                json_response(['success' => true, 'message' => 'File permanently erased.']);
            } else {
                json_response(['success' => false, 'message' => 'Failed to delete file.'], 404);
            }
        } elseif ($type === 'folder') {
            // Delete all physical files in folder
            $stmtFiles = $pdo->prepare("SELECT id FROM files WHERE folder_id = ? AND user_id = ?");
            $stmtFiles->execute([$id, $userId]);
            $files = $stmtFiles->fetchAll();
            foreach ($files as $f) {
                StorageEngine::permanentlyDeleteFile((int)$f['id'], $userId);
            }

            $delF = $pdo->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?");
            $delF->execute([$id, $userId]);

            log_activity($userId, 'Permanent Delete', 'Folder', $id, "Permanently erased folder");
            json_response(['success' => true, 'message' => 'Folder and contents permanently erased.']);
        }
        break;

    /**
     * Empty entire Trash
     */
    case 'empty_trash':
        // Delete all deleted files
        $stmtFiles = $pdo->prepare("SELECT id FROM files WHERE user_id = ? AND is_deleted = 1");
        $stmtFiles->execute([$userId]);
        $files = $stmtFiles->fetchAll();
        foreach ($files as $f) {
            StorageEngine::permanentlyDeleteFile((int)$f['id'], $userId);
        }

        // Delete all deleted folders
        $delFolders = $pdo->prepare("DELETE FROM folders WHERE user_id = ? AND is_deleted = 1");
        $delFolders->execute([$userId]);

        refresh_user_storage($userId);
        log_activity($userId, 'Empty Trash', 'Storage', null, 'Emptied recycle bin completely');
        json_response(['success' => true, 'message' => 'Recycle bin emptied successfully.']);
        break;

    /**
     * Get detailed file information
     */
    case 'get_file_details':
        $fileId = (int)($requestData['file_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT f.*, fo.name AS folder_name
            FROM files f
            LEFT JOIN folders fo ON f.folder_id = fo.id
            WHERE f.id = ? AND f.user_id = ?
        ");
        $stmt->execute([$fileId, $userId]);
        $file = $stmt->fetch();

        if (!$file) {
            json_response(['success' => false, 'message' => 'File not found.'], 404);
        }

        // Fetch tags attached to file
        $stmtTags = $pdo->prepare("
            SELECT t.id, t.name, t.color
            FROM tags t
            JOIN file_tags ft ON t.id = ft.tag_id
            WHERE ft.file_id = ? AND t.user_id = ?
        ");
        $stmtTags->execute([$fileId, $userId]);
        $tags = $stmtTags->fetchAll();

        $file['tags'] = $tags;
        $file['file_size_formatted'] = format_bytes($file['file_size']);
        $file['created_at'] = date('M d, Y h:i A', strtotime($file['created_at']));
        $file['updated_at'] = date('M d, Y h:i A', strtotime($file['updated_at']));

        json_response(['success' => true, 'file' => $file]);
        break;

    /**
     * Get folder tree for Move selection
     */
    case 'get_folder_tree':
        $excludeId = (int)($requestData['exclude_id'] ?? 0);

        function buildFolderList(PDO $pdo, int $userId, ?int $parentId = null, int $depth = 0, int $excludeId = 0): array {
            $stmt = $pdo->prepare("
                SELECT id, name, parent_id 
                FROM folders 
                WHERE user_id = ? AND (parent_id <=> ?) AND is_deleted = 0 AND id != ?
                ORDER BY name ASC
            ");
            $stmt->execute([$userId, $parentId, $excludeId]);
            $folders = $stmt->fetchAll();

            $list = [];
            foreach ($folders as $f) {
                $f['depth'] = $depth;
                $list[] = $f;
                $children = buildFolderList($pdo, $userId, (int)$f['id'], $depth + 1, $excludeId);
                $list = array_merge($list, $children);
            }
            return $list;
        }

        $folderList = buildFolderList($pdo, $userId, null, 0, $excludeId);
        json_response(['success' => true, 'folders' => $folderList]);
        break;

    /**
     * Get active share info
     */
    case 'get_share_info':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT * FROM shares 
            WHERE user_id = ? AND resource_type = ? AND resource_id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId, $type, $id]);
        $share = $stmt->fetch();

        if ($share) {
            $shareUrl = BASE_URL . '/public_share.php?token=' . $share['share_token'];
            $expiresText = 'Never expires';
            if (!empty($share['expires_at'])) {
                $expiresText = 'Expires on ' . date('M d, Y', strtotime($share['expires_at']));
            }
            json_response([
                'success' => true,
                'share' => [
                    'share_url' => $shareUrl,
                    'view_count' => (int)$share['view_count'],
                    'expires_text' => $expiresText
                ]
            ]);
        } else {
            json_response(['success' => false, 'share' => null]);
        }
        break;

    /**
     * Create or regenerate share token
     */
    case 'create_share':
        $type = $requestData['type'] ?? '';
        $id = (int)($requestData['id'] ?? 0);
        $password = trim($requestData['password'] ?? '');
        $expiration = $requestData['expiration'] ?? 'never';

        // Verify resource ownership
        if ($type === 'file') {
            $chk = $pdo->prepare("SELECT id FROM files WHERE id = ? AND user_id = ? AND is_deleted = 0");
        } else {
            $chk = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ? AND is_deleted = 0");
        }
        $chk->execute([$id, $userId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'Resource not found or inaccessible.'], 404);
        }

        $passHash = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : null;
        $expiresAt = null;

        if ($expiration === '1') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
        } elseif ($expiration === '7') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        } elseif ($expiration === '30') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        }

        $token = bin2hex(random_bytes(24));

        // Disable existing active shares for this resource
        $del = $pdo->prepare("DELETE FROM shares WHERE user_id = ? AND resource_type = ? AND resource_id = ?");
        $del->execute([$userId, $type, $id]);

        $ins = $pdo->prepare("
            INSERT INTO shares (user_id, resource_type, resource_id, share_token, password_hash, expires_at, is_active, view_count, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW())
        ");
        $ins->execute([$userId, $type, $id, $token, $passHash, $expiresAt]);

        log_activity($userId, 'Create Share', ucfirst($type), $id, "Generated public share token for {$type}");

        $shareUrl = BASE_URL . '/public_share.php?token=' . $token;
        $expiresText = $expiresAt ? 'Expires on ' . date('M d, Y', strtotime($expiresAt)) : 'Never expires';

        json_response([
            'success' => true,
            'share_url' => $shareUrl,
            'expires_text' => $expiresText
        ]);
        break;

    /**
     * Get user tags for folder creation color palette
     */
    case 'get_user_tags':
        $stmtUTags = $pdo->prepare("SELECT id, name, color FROM tags WHERE user_id = ? ORDER BY name ASC");
        $stmtUTags->execute([$userId]);
        $tags = $stmtUTags->fetchAll();
        json_response(['success' => true, 'tags' => $tags]);
        break;

    default:
        json_response(['success' => false, 'message' => 'Unknown AJAX operation specified.'], 400);
}
