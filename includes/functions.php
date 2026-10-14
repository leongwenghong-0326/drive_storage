<?php
/**
 * AetherVault - General Utility & Helper Functions
 */

/**
 * Escape HTML output for XSS prevention
 */
function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Format bytes into human-readable string (KB, MB, GB, TB)
 */
function format_bytes(int|float $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Get client IP address
 */
function get_client_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ipList = explode(',', $_SERVER[$key]);
            $ip = trim($ipList[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '127.0.0.1';
}

/**
 * Log activity/audit event
 */
function log_activity(?int $userId, string $action, ?string $resourceType = null, ?int $resourceId = null, string $description = ''): void {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, resource_type, resource_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $action,
            $resourceType,
            $resourceId,
            $description,
            get_client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255)
        ]);
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

/**
 * Get system setting
 */
function get_setting(string $key, mixed $default = null): mixed {
    static $settingsCache = [];
    
    if (isset($settingsCache[$key])) {
        return $settingsCache[$key];
    }

    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            $settingsCache[$key] = $val;
            return $val;
        }
    } catch (Exception $e) {
        error_log("Failed to fetch setting {$key}: " . $e->getMessage());
    }

    return $default;
}

/**
 * Update system setting
 */
function update_setting(string $key, string $value): bool {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        error_log("Failed to update setting {$key}: " . $e->getMessage());
        return false;
    }
}

/**
 * Flash messages
 */
function set_flash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_messages'][$type][] = $message;
}

function get_flash(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Standard JSON response helper
 */
function json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Safe redirect
 */
function redirect(string $path): void {
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header("Location: {$path}");
    } else {
        $url = BASE_URL . '/' . ltrim($path, '/');
        header("Location: {$url}");
    }
    exit;
}

/**
 * Sanitize displayed file/folder name
 */
function sanitize_file_name(string $name): string {
    $name = trim($name);
    // Remove null bytes and invalid characters for file systems
    $name = str_replace(["\0", "\\", "/", ":", "*", "?", "\"", "<", ">", "|"], '_', $name);
    return mb_substr($name, 0, 250);
}

/**
 * Map file extension / MIME type to FontAwesome icons and color tags
 */
function get_file_icon_meta(string $extension, string $mimeType = ''): array {
    $ext = strtolower($extension);
    
    // Images
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'])) {
        return ['icon' => 'fa-file-image', 'color' => '#10b981', 'type' => 'image'];
    }
    // PDF
    if ($ext === 'pdf') {
        return ['icon' => 'fa-file-pdf', 'color' => '#ef4444', 'type' => 'pdf'];
    }
    // Documents
    if (in_array($ext, ['doc', 'docx', 'odt', 'rtf'])) {
        return ['icon' => 'fa-file-word', 'color' => '#3b82f6', 'type' => 'document'];
    }
    // Spreadsheets
    if (in_array($ext, ['xls', 'xlsx', 'csv', 'ods'])) {
        return ['icon' => 'fa-file-excel', 'color' => '#059669', 'type' => 'spreadsheet'];
    }
    // Presentations
    if (in_array($ext, ['ppt', 'pptx', 'odp'])) {
        return ['icon' => 'fa-file-powerpoint', 'color' => '#f97316', 'type' => 'presentation'];
    }
    // Archives
    if (in_array($ext, ['zip', 'rar', 'tar', 'gz', '7z', 'bz2'])) {
        return ['icon' => 'fa-file-zipper', 'color' => '#8b5cf6', 'type' => 'archive'];
    }
    // Audio
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'])) {
        return ['icon' => 'fa-file-audio', 'color' => '#ec4899', 'type' => 'audio'];
    }
    // Video
    if (in_array($ext, ['mp4', 'webm', 'mkv', 'avi', 'mov', 'wmv'])) {
        return ['icon' => 'fa-file-video', 'color' => '#f43f5e', 'type' => 'video'];
    }
    // Code & Text
    if (in_array($ext, ['txt', 'md', 'json', 'xml', 'html', 'css', 'js', 'sql', 'py', 'c', 'cpp', 'java'])) {
        return ['icon' => 'fa-file-lines', 'color' => '#64748b', 'type' => 'text'];
    }

    return ['icon' => 'fa-file', 'color' => '#94a3b8', 'type' => 'generic'];
}
