<?php
/**
 * AetherVault - Cloud Storage System
 * Core Configuration & Bootstrap
 */

// Prevent multiple definitions
if (defined('AETHER_VAULT_INIT')) {
    return;
}
define('AETHER_VAULT_INIT', true);

// Set default timezone
date_default_timezone_set('UTC');

// Paths definitions
define('APP_ROOT', dirname(__DIR__));
define('CONFIG_DIR', APP_ROOT . '/config');
define('INCLUDES_DIR', APP_ROOT . '/includes');
define('UPLOADS_DIR', APP_ROOT . '/uploads');
define('FILES_DIR', UPLOADS_DIR . '/files');
define('VERSIONS_DIR', UPLOADS_DIR . '/versions');
define('LOCK_FILE', CONFIG_DIR . '/install.lock');
define('DB_CONFIG_FILE', CONFIG_DIR . '/database_config.php');

// Determine base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

// Normalize root path for base URL
$pathSegments = explode('/', trim($scriptDir, '/'));
$rootSegments = [];
foreach ($pathSegments as $segment) {
    if ($segment === 'admin' || $segment === 'install') {
        break;
    }
    if ($segment !== '') {
        $rootSegments[] = $segment;
    }
}
$basePath = !empty($rootSegments) ? '/' . implode('/', $rootSegments) : '';
define('BASE_URL', rtrim($protocol . $host . $basePath, '/'));

// Check installation status
$isInstalled = file_exists(LOCK_FILE) && file_exists(DB_CONFIG_FILE);
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$isInstallRoute = (strpos($currentUri, '/install') !== false);

if (!$isInstalled && !$isInstallRoute && php_sapi_name() !== 'cli') {
    header('Location: ' . BASE_URL . '/install/');
    exit;
}

// Secure session initiation
if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_name('AETHER_SESSID');
    session_start();
}

// Load core includes if installed
if ($isInstalled) {
    require_once CONFIG_DIR . '/database.php';
    require_once INCLUDES_DIR . '/csrf.php';
    require_once INCLUDES_DIR . '/functions.php';
    require_once INCLUDES_DIR . '/auth.php';
    require_once INCLUDES_DIR . '/storage.php';
}
