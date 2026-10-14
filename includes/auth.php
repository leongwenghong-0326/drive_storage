<?php
/**
 * AetherVault - Authentication & Authorization Module
 */

/**
 * Check if current visitor is authenticated
 */
function auth_check(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['auth_user_id']) && !empty($_SESSION['auth_logged_in']);
}

/**
 * Get current authenticated user details
 */
function auth_user(): ?array {
    static $currentUser = null;

    if (!auth_check()) {
        return null;
    }

    if ($currentUser !== null) {
        return $currentUser;
    }

    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, username, email, full_name, role, status, storage_quota, storage_used, created_at FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['auth_user_id']]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active') {
            $currentUser = $user;
            $_SESSION['auth_user_role'] = $user['role'];
            return $user;
        } else {
            // Account deactivated or deleted, force logout
            auth_logout();
            return null;
        }
    } catch (Exception $e) {
        error_log("Failed to load authenticated user: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if user has administrator role
 */
function auth_is_admin(): bool {
    if (!auth_check()) {
        return false;
    }
    return ($_SESSION['auth_user_role'] ?? '') === 'admin';
}

/**
 * Enforce login authentication
 */
function require_login(): void {
    if (!auth_check()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            json_response(['success' => false, 'message' => 'Authentication required. Please login.'], 401);
        }
        $redirectUrl = BASE_URL . '/login.php';
        header("Location: {$redirectUrl}");
        exit;
    }
}

/**
 * Enforce administrator role
 */
function require_admin(): void {
    require_login();
    if (!auth_is_admin()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            json_response(['success' => false, 'message' => 'Access denied. Administrator privileges required.'], 403);
        }
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Access Denied</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light d-flex align-items-center min-vh-100 justify-content-center"><div class="card shadow-sm p-4 text-center" style="max-width: 460px;"><h3 class="text-danger mb-3">Access Denied</h3><p class="text-muted">You do not have administrative privileges to access this area.</p><a href="' . BASE_URL . '/dashboard.php" class="btn btn-primary mt-2">Return to Dashboard</a></div></body></html>');
    }
}

/**
 * Process user login attempt
 */
function auth_login(string $identifier, string $password): array {
    $identifier = trim($identifier);

    if (empty($identifier) || empty($password)) {
        return ['success' => false, 'message' => 'Please provide both username/email and password.'];
    }

    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, full_name, role, status, storage_quota, storage_used
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid credentials provided.'];
        }

        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Your account is currently disabled. Please contact the administrator.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid credentials provided.'];
        }

        // Regenerate session ID to prevent session fixation attacks
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);

        $_SESSION['auth_user_id'] = (int)$user['id'];
        $_SESSION['auth_username'] = $user['username'];
        $_SESSION['auth_user_role'] = $user['role'];
        $_SESSION['auth_logged_in'] = true;

        log_activity($user['id'], 'Login', 'User', $user['id'], "User '{$user['username']}' logged in successfully");

        return [
            'success' => true,
            'message' => 'Welcome back, ' . $user['full_name'],
            'user' => $user
        ];

    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        return ['success' => false, 'message' => 'A system error occurred. Please try again later.'];
    }
}

/**
 * Process user logout
 */
function auth_logout(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $userId = $_SESSION['auth_user_id'] ?? null;
    $username = $_SESSION['auth_username'] ?? 'Unknown';

    if ($userId) {
        log_activity((int)$userId, 'Logout', 'User', (int)$userId, "User '{$username}' logged out");
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Recalculate and update user's total storage usage in database
 */
function refresh_user_storage(int $userId): int {
    try {
        $pdo = Database::getConnection();
        
        // Sum of non-deleted active files + all version archives
        $stmtFiles = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM files WHERE user_id = ? AND is_deleted = 0");
        $stmtFiles->execute([$userId]);
        $filesSize = (int)$stmtFiles->fetchColumn();

        $stmtVersions = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM file_versions WHERE user_id = ?");
        $stmtVersions->execute([$userId]);
        $versionsSize = (int)$stmtVersions->fetchColumn();

        $totalUsed = $filesSize + $versionsSize;

        $update = $pdo->prepare("UPDATE users SET storage_used = ? WHERE id = ?");
        $update->execute([$totalUsed, $userId]);

        // Synchronize active session if current logged in user
        if (isset($_SESSION['user']) && (int)$_SESSION['user']['id'] === $userId) {
            $_SESSION['user']['storage_used'] = $totalUsed;
        }

        return $totalUsed;
    } catch (Exception $e) {
        error_log("Failed to refresh user storage: " . $e->getMessage());
        return 0;
    }
}
