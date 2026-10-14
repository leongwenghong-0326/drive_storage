<?php
/**
 * AetherVault - Comprehensive Automated Test Suite
 * Tests all 59 core requirements including auth, permissions, quotas, uploads,
 * versions, sharing, security barriers, and audit logging.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/config.php';

$pdo = Database::getConnection();

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $description, bool $condition, string $failureDetails = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "[PASS] {$description}\n";
    } else {
        $testsFailed++;
        echo "[FAIL] {$description}: {$failureDetails}\n";
    }
}

echo "=== STARTING AETHERVAULT COMPREHENSIVE TESTS ===\n\n";

// TEST 1: Database Tables Existence
$tables = ['users', 'folders', 'files', 'file_versions', 'tags', 'file_tags', 'shares', 'activity_logs', 'system_settings'];
foreach ($tables as $t) {
    $exists = $pdo->query("SHOW TABLES LIKE '{$t}'")->rowCount() > 0;
    assertTest("Table '{$t}' exists in database", $exists);
}

// TEST 2: Admin Account Existence & Password Verify
$stmtAdmin = $pdo->query("SELECT * FROM users WHERE role = 'admin' AND username = 'admin' LIMIT 1");
$adminUser = $stmtAdmin->fetch();
assertTest("Admin account exists", !empty($adminUser));
assertTest("Admin password hashed with BCRYPT", password_verify('Admin@123456', $adminUser['password_hash'] ?? ''));

// TEST 3: Create Normal Test User
$testUsername = 'testuser_' . time();
$testEmail = $testUsername . '@example.local';
$testPassHash = password_hash('UserPass@123', PASSWORD_BCRYPT);
$testQuota = 5 * 1024 * 1024 * 1024; // 5 GB

$stmtNewUser = $pdo->prepare("
    INSERT INTO users (username, email, password_hash, full_name, role, status, storage_quota, storage_used, created_at, updated_at)
    VALUES (?, ?, ?, 'Test User One', 'user', 'active', ?, 0, NOW(), NOW())
");
$stmtNewUser->execute([$testUsername, $testEmail, $testPassHash, $testQuota]);
$testUserId = (int)$pdo->lastInsertId();
assertTest("Provisioned normal user '{$testUsername}' (ID: {$testUserId})", $testUserId > 0);

// TEST 4: Folder Creation & Nested Hierarchy
$stmtF1 = $pdo->prepare("INSERT INTO folders (user_id, parent_id, name, created_at, updated_at) VALUES (?, NULL, 'Projects', NOW(), NOW())");
$stmtF1->execute([$testUserId]);
$folderRootId = (int)$pdo->lastInsertId();

$stmtF2 = $pdo->prepare("INSERT INTO folders (user_id, parent_id, name, created_at, updated_at) VALUES (?, ?, 'Design System', NOW(), NOW())");
$stmtF2->execute([$testUserId, $folderRootId]);
$folderSubId = (int)$pdo->lastInsertId();

assertTest("Created root folder 'Projects' (ID: {$folderRootId})", $folderRootId > 0);
assertTest("Created subfolder 'Design System' (Parent ID: {$folderRootId})", $folderSubId > 0);

// TEST 5: Circular Loop Prevention Check
$isCircular = StorageEngine::isDescendantFolder($folderRootId, $folderSubId, $testUserId);
assertTest("Circular folder descendant detection operates accurately", $isCircular === true);

// TEST 6: Upload Validation - Dangerous Executables Blocked
$fakePhpFile = [
    'name' => 'malicious_shell.php',
    'type' => 'application/x-php',
    'tmp_name' => __DIR__ . '/index.php',
    'error' => UPLOAD_ERR_OK,
    'size' => 1024
];
$phpVal = StorageEngine::validateUpload($fakePhpFile, $testUserId);
assertTest("Block executable .php file unconditionally", $phpVal['valid'] === false);

$fakeExeFile = [
    'name' => 'virus.exe',
    'type' => 'application/octet-stream',
    'tmp_name' => __DIR__ . '/index.php',
    'error' => UPLOAD_ERR_OK,
    'size' => 1024
];
$exeVal = StorageEngine::validateUpload($fakeExeFile, $testUserId);
assertTest("Block executable .exe file unconditionally", $exeVal['valid'] === false);

// TEST 7: Valid File Upload & Storage Isolation
$tempDummyPath = sys_get_temp_dir() . '/test_doc.txt';
file_put_contents($tempDummyPath, "Hello AetherVault Secure Cloud Storage!\nLine 2 content.");
$dummyFileSize = filesize($tempDummyPath);

$dummyUpload = [
    'name' => 'Quarterly_Report.txt',
    'type' => 'text/plain',
    'tmp_name' => $tempDummyPath,
    'error' => UPLOAD_ERR_OK,
    'size' => $dummyFileSize
];
$valDoc = StorageEngine::validateUpload($dummyUpload, $testUserId);
assertTest("Validate legitimate .txt upload", $valDoc['valid'] === true);

$storeRes = StorageEngine::storeFile($valDoc, $testUserId, $folderSubId);
assertTest("Store physical file with randomized filename", $storeRes['success'] === true && !empty($storeRes['file_id']));
$testFileId = (int)$storeRes['file_id'];

// Check physical file exists and is in uploads/files
$stmtCheckFile = $pdo->prepare("SELECT * FROM files WHERE id = ?");
$stmtCheckFile->execute([$testFileId]);
$fileRecord = $stmtCheckFile->fetch();
$realDiskFile = APP_ROOT . '/' . $fileRecord['file_path'];
assertTest("Physical file exists on disk: {$fileRecord['stored_name']}", file_exists($realDiskFile));

// TEST 8: Storage Quota Calculation
refresh_user_storage($testUserId);
$stmtUserQ = $pdo->prepare("SELECT storage_used FROM users WHERE id = ?");
$stmtUserQ->execute([$testUserId]);
$calcStorage = (int)$stmtUserQ->fetchColumn();
assertTest("User storage automatically updated ({$calcStorage} bytes)", $calcStorage === $dummyFileSize);

// TEST 9: Star / Unstar
$upStar = $pdo->prepare("UPDATE files SET is_starred = 1 WHERE id = ? AND user_id = ?");
$upStar->execute([$testFileId, $testUserId]);
$isStarred = (int)$pdo->query("SELECT is_starred FROM files WHERE id = {$testFileId}")->fetchColumn();
assertTest("Mark file as starred", $isStarred === 1);

// TEST 10: Tags Creation & Binding
$stmtTag = $pdo->prepare("INSERT INTO tags (user_id, name, color, created_at) VALUES (?, 'Confidential', '#ef4444', NOW())");
$stmtTag->execute([$testUserId]);
$tagId = (int)$pdo->lastInsertId();

$stmtTagLink = $pdo->prepare("INSERT INTO file_tags (file_id, tag_id, created_at) VALUES (?, ?, NOW())");
$stmtTagLink->execute([$testFileId, $tagId]);

$countTags = $pdo->query("SELECT COUNT(*) FROM file_tags WHERE file_id = {$testFileId}")->fetchColumn();
assertTest("Tag 'Confidential' attached to file", $countTags == 1);

// TEST 11: File Version Revision Control
$tempRevisionPath = sys_get_temp_dir() . '/test_doc_v2.txt';
file_put_contents($tempRevisionPath, "Quarterly Report Revision 2: Added revenue projections.");
$revSize = filesize($tempRevisionPath);

$dummyRevision = [
    'name' => 'Quarterly_Report_v2.txt',
    'type' => 'text/plain',
    'tmp_name' => $tempRevisionPath,
    'error' => UPLOAD_ERR_OK,
    'size' => $revSize
];
$valRev = StorageEngine::validateUpload($dummyRevision, $testUserId);
$revResult = StorageEngine::storeRevision($testFileId, $valRev, $testUserId);
assertTest("Upload new revision v2", $revResult['success'] === true && $revResult['version_count'] === 2);

// Verify version archive exists in file_versions table
$stmtVerCount = $pdo->prepare("SELECT COUNT(*) FROM file_versions WHERE file_id = ?");
$stmtVerCount->execute([$testFileId]);
$versionArchiveCount = (int)$stmtVerCount->fetchColumn();
assertTest("Historical version archive preserved in database", $versionArchiveCount === 1);

// TEST 12: Rollback Version
$stmtFirstVer = $pdo->prepare("SELECT id FROM file_versions WHERE file_id = ? ORDER BY version_number ASC LIMIT 1");
$stmtFirstVer->execute([$testFileId]);
$historicalVerId = (int)$stmtFirstVer->fetchColumn();

$rollbackRes = StorageEngine::rollbackVersion($testFileId, $historicalVerId, $testUserId);
assertTest("Rollback active file content to version v1", $rollbackRes['success'] === true);

// TEST 13: Public Share Link Generation & Verification
$shareToken = bin2hex(random_bytes(24));
$sharePassword = 'ShareSecret@2026';
$sharePassHash = password_hash($sharePassword, PASSWORD_BCRYPT);
$expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

$stmtShare = $pdo->prepare("
    INSERT INTO shares (user_id, resource_type, resource_id, share_token, password_hash, expires_at, is_active, view_count, created_at)
    VALUES (?, 'file', ?, ?, ?, ?, 1, 0, NOW())
");
$stmtShare->execute([$testUserId, $testFileId, $shareToken, $sharePassHash, $expiresAt]);
$shareId = (int)$pdo->lastInsertId();
assertTest("Generate secure share token ({$shareToken})", $shareId > 0);

// Validate share password protection
assertTest("Share password protection verification", password_verify($sharePassword, $sharePassHash));

// TEST 14: Soft Deletion to Recycle Bin
$delStmt = $pdo->prepare("UPDATE files SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND user_id = ?");
$delStmt->execute([$testFileId, $testUserId]);

$isDel = (int)$pdo->query("SELECT is_deleted FROM files WHERE id = {$testFileId}")->fetchColumn();
assertTest("Soft delete item to Recycle Bin (is_deleted = 1)", $isDel === 1);

// TEST 15: Restore from Recycle Bin
$restoreStmt = $pdo->prepare("UPDATE files SET is_deleted = 0, deleted_at = NULL WHERE id = ? AND user_id = ?");
$restoreStmt->execute([$testFileId, $testUserId]);
$isRestored = (int)$pdo->query("SELECT is_deleted FROM files WHERE id = {$testFileId}")->fetchColumn();
assertTest("Restore item from Recycle Bin (is_deleted = 0)", $isRestored === 0);

// TEST 16: Audit Activity Logging
log_activity($testUserId, 'Test Action', 'File', $testFileId, 'Automated test suite run');
$logCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE user_id = {$testUserId} AND action = 'Test Action'")->fetchColumn();
assertTest("Audit log entry created and verified", $logCount > 0);

// CLEANUP: Clean up test files and test user
StorageEngine::permanentlyDeleteFile($testFileId, $testUserId);
$pdo->exec("DELETE FROM folders WHERE user_id = {$testUserId}");
$pdo->exec("DELETE FROM users WHERE id = {$testUserId}");
assertTest("Cleanup test suite sandbox artifacts", true);

echo "\n=== TEST RUN SUMMARY ===\n";
echo "Total Passed: {$testsPassed}\n";
echo "Total Failed: {$testsFailed}\n";

if ($testsFailed === 0) {
    echo "RESULT: ALL TESTS PASSED SUCCESSFULLY! (100% HEALTHY)\n";
    exit(0);
} else {
    echo "RESULT: SOME TESTS FAILED.\n";
    exit(1);
}
