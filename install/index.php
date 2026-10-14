<?php
/**
 * AetherVault - Modern Installation Wizard
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('APP_ROOT', dirname(__DIR__));
define('LOCK_FILE', APP_ROOT . '/config/install.lock');
define('DB_CONFIG_FILE', APP_ROOT . '/config/database_config.php');
define('SCHEMA_FILE', __DIR__ . '/schema.sql');

// Prevent running installer if already locked
if (file_exists(LOCK_FILE)) {
    die('<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>AetherVault - Installation Locked</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>body { font-family: "Plus Jakarta Sans", sans-serif; background: #0f172a; color: #f8fafc; }</style>
    </head>
    <body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="card bg-dark border-secondary shadow-lg p-4 text-center text-light" style="max-width: 500px; border-radius: 1rem;">
            <div class="mb-3 text-warning">
                <svg width="48" height="48" fill="currentColor" viewBox="0 0 16 16"><path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/></svg>
            </div>
            <h3 class="fw-bold mb-2">Installation Locked</h3>
            <p class="text-secondary small mb-4">AetherVault has already been installed on this server. To reinstall, remove <code>config/install.lock</code> and <code>config/database_config.php</code>.</p>
            <a href="../login.php" class="btn btn-primary px-4 py-2 fw-semibold">Proceed to Login</a>
        </div>
    </body>
    </html>');
}

// Check system requirements
$reqs = [
    'php' => [
        'name' => 'PHP 8.2 or Higher',
        'status' => version_compare(PHP_VERSION, '8.2.0', '>=')
    ],
    'pdo_mysql' => [
        'name' => 'PDO MySQL Extension',
        'status' => extension_loaded('pdo_mysql')
    ],
    'mbstring' => [
        'name' => 'MBString Extension',
        'status' => extension_loaded('mbstring')
    ],
    'fileinfo' => [
        'name' => 'FileInfo Extension',
        'status' => extension_loaded('fileinfo')
    ],
    'gd' => [
        'name' => 'GD Image Extension',
        'status' => extension_loaded('gd')
    ],
    'zip' => [
        'name' => 'ZipArchive Extension',
        'status' => extension_loaded('zip')
    ],
];

// Check directory permissions
$dirs = [
    'config' => [
        'name' => 'config/',
        'path' => APP_ROOT . '/config',
        'status' => is_writable(APP_ROOT . '/config')
    ],
    'uploads' => [
        'name' => 'uploads/',
        'path' => APP_ROOT . '/uploads',
        'status' => is_writable(APP_ROOT . '/uploads')
    ]
];

$allReqsPassed = true;
foreach ($reqs as $r) {
    if (!$r['status']) {
        $allReqsPassed = false;
        break;
    }
}
foreach ($dirs as $d) {
    if (!$d['status']) {
        $allReqsPassed = false;
        break;
    }
}

$error = '';
$success = false;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? 'aethervault_db');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';

    $adminName = trim($_POST['admin_name'] ?? '');
    $adminUsername = trim($_POST['admin_username'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = $_POST['admin_password'] ?? '';
    $adminPassConfirm = $_POST['admin_password_confirm'] ?? '';

    if (empty($adminName) || empty($adminUsername) || empty($adminEmail) || empty($adminPass)) {
        $error = 'All administrator fields are required.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid administrator email address.';
    } elseif (strlen($adminPass) < 8) {
        $error = 'Administrator password must be at least 8 characters.';
    } elseif ($adminPass !== $adminPassConfirm) {
        $error = 'Administrator passwords do not match.';
    } else {
        try {
            // 1. Test Server Connection & Create Database if needed
            $serverDsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdoServer = new PDO($serverDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // 2. Connect to Target Database
            $dbDsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // 3. Execute Schema Script
            if (!file_exists(SCHEMA_FILE)) {
                throw new Exception('Database schema file schema.sql is missing.');
            }
            $schemaSql = file_get_contents(SCHEMA_FILE);
            $pdo->exec($schemaSql);

            // 4. Create Initial Administrator Account
            $adminPassHash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmtAdmin = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, full_name, role, status, storage_quota, storage_used, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'admin', 'active', 107374182400, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), role = 'admin', status = 'active'
            ");
            $stmtAdmin->execute([$adminUsername, $adminEmail, $adminPassHash, $adminName]);
            $adminId = (int)$pdo->lastInsertId();

            // Seed a standard demonstration user account
            $demoPassHash = password_hash('User@123456', PASSWORD_BCRYPT);
            $stmtDemo = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, full_name, role, status, storage_quota, storage_used, created_at, updated_at)
                VALUES ('demouser', 'demo@aethervault.local', ?, 'Demo Workspace User', 'user', 'active', 10737418240, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active'
            ");
            $stmtDemo->execute([$demoPassHash]);

            // 5. Create storage directories
            $filesDir = APP_ROOT . '/uploads/files';
            $versionsDir = APP_ROOT . '/uploads/versions';
            if (!is_dir($filesDir)) mkdir($filesDir, 0755, true);
            if (!is_dir($versionsDir)) mkdir($versionsDir, 0755, true);

            // 6. Write database_config.php
            $dbConfigContent = "<?php\n// AetherVault Database Configuration\nreturn [\n" .
                "    'host'     => " . var_export($dbHost, true) . ",\n" .
                "    'port'     => {$dbPort},\n" .
                "    'dbname'   => " . var_export($dbName, true) . ",\n" .
                "    'user'     => " . var_export($dbUser, true) . ",\n" .
                "    'password' => " . var_export($dbPass, true) . ",\n" .
                "    'charset'  => 'utf8mb4'\n" .
                "];\n";

            file_put_contents(DB_CONFIG_FILE, $dbConfigContent);

            // 7. Write install.lock
            $lockContent = "Installed on " . date('Y-m-d H:i:s') . " UTC\nAdmin: {$adminUsername} ({$adminEmail})\n";
            file_put_contents(LOCK_FILE, $lockContent);

            $success = true;

        } catch (Exception $e) {
            $error = 'Installation Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AetherVault - Installation Wizard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-accent: #6366f1;
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            --card-bg: #1e293b;
            --body-bg: #0f172a;
            --border-color: #334155;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--body-bg);
            color: #f1f5f9;
            min-height: 100vh;
        }
        .installer-container {
            max-width: 840px;
            margin: 40px auto;
        }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(99, 102, 241, 0.1);
            padding: 8px 18px;
            border-radius: 9999px;
            border: 1px solid rgba(99, 102, 241, 0.25);
        }
        .installer-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1.25rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .form-control, .form-select {
            background-color: #0f172a;
            border: 1px solid var(--border-color);
            color: #f8fafc;
            border-radius: 0.5rem;
            padding: 0.65rem 1rem;
        }
        .form-control:focus {
            background-color: #0f172a;
            border-color: var(--primary-accent);
            color: #fff;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25);
        }
        .section-header {
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-gradient:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            color: white;
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.4);
        }
    </style>
</head>
<body class="py-4">
    <div class="container installer-container">
        <!-- Logo & Header -->
        <div class="text-center mb-4">
            <div class="brand-badge mb-3">
                <i class="fa-solid fa-cloud-arrow-up text-primary fs-5"></i>
                <span class="fw-bold tracking-wide text-white">AetherVault Platform</span>
            </div>
            <h2 class="fw-bold mb-1">System Setup Wizard</h2>
            <p class="text-secondary small">Configure your database and initialize the enterprise file storage cluster</p>
        </div>

        <?php if ($success): ?>
            <!-- Success Screen -->
            <div class="installer-card p-5 text-center">
                <div class="mb-4 text-success">
                    <i class="fa-solid fa-circle-check fa-4x"></i>
                </div>
                <h3 class="fw-bold text-white mb-3">Installation Completed Successfully!</h3>
                <p class="text-secondary mb-4">
                    The database schema was generated, administrative privileges were granted, and your storage directories are secured.
                </p>
                <div class="p-3 bg-dark rounded border border-secondary text-start mx-auto mb-4" style="max-width: 450px;">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-secondary">Administrator Email:</span>
                        <strong class="text-white"><?= htmlspecialchars($adminEmail) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">Installation Lock:</span>
                        <span class="badge bg-success-subtle text-success">Generated (config/install.lock)</span>
                    </div>
                </div>
                <a href="../login.php" class="btn btn-gradient btn-lg px-5 py-2">
                    <i class="fa-solid fa-right-to-bracket me-2"></i> Launch Application
                </a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger border-0 d-flex align-items-center mb-4 shadow-sm" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-3 fs-5"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- System Pre-Check Card -->
            <div class="installer-card p-4 mb-4">
                <h5 class="fw-bold section-header d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-server me-2 text-primary"></i> 1. Server Environment Check</span>
                    <?php if ($allReqsPassed): ?>
                        <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Ready</span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i> Action Required</span>
                    <?php endif; ?>
                </h5>
                <div class="row g-3">
                    <?php foreach ($reqs as $r): ?>
                        <div class="col-md-4 col-sm-6">
                            <div class="p-2 bg-dark rounded border border-secondary d-flex align-items-center justify-content-between">
                                <span class="small"><?= $r['name'] ?></span>
                                <?php if ($r['status']): ?>
                                    <i class="fa-solid fa-circle-check text-success"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-circle-xmark text-danger"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($dirs as $d): ?>
                        <div class="col-md-6">
                            <div class="p-2 bg-dark rounded border border-secondary d-flex align-items-center justify-content-between">
                                <span class="small">Writable: <code><?= $d['name'] ?></code></span>
                                <?php if ($d['status']): ?>
                                    <span class="badge bg-success-subtle text-success">Writable</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger">Not Writable</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Configuration Form -->
            <form method="POST" action="">
                <!-- Database Configuration -->
                <div class="installer-card p-4 mb-4">
                    <h5 class="fw-bold section-header">
                        <i class="fa-solid fa-database me-2 text-primary"></i> 2. Database Connection
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Database Host</label>
                            <input type="text" name="db_host" class="form-control" value="127.0.0.1" required>
                            <div class="form-text text-secondary">Default is 127.0.0.1 or localhost</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Port</label>
                            <input type="number" name="db_port" class="form-control" value="3307" required>
                            <div class="form-text text-secondary">3306 or 3307 on XAMPP</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Database Name</label>
                            <input type="text" name="db_name" class="form-control" value="aethervault_db" required>
                            <div class="form-text text-secondary">Will be created automatically</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Database Username</label>
                            <input type="text" name="db_user" class="form-control" value="root" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Database Password</label>
                            <input type="password" name="db_pass" class="form-control" placeholder="Leave empty if none">
                        </div>
                    </div>
                </div>

                <!-- Admin Account Setup -->
                <div class="installer-card p-4 mb-4">
                    <h5 class="fw-bold section-header">
                        <i class="fa-solid fa-user-shield me-2 text-primary"></i> 3. Master Administrator Setup
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name</label>
                            <input type="text" name="admin_name" class="form-control" placeholder="System Administrator" value="<?= htmlspecialchars($_POST['admin_name'] ?? 'System Administrator') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username</label>
                            <input type="text" name="admin_username" class="form-control" placeholder="admin" value="<?= htmlspecialchars($_POST['admin_username'] ?? 'admin') ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" name="admin_email" class="form-control" placeholder="admin@aethervault.local" value="<?= htmlspecialchars($_POST['admin_email'] ?? 'admin@aethervault.local') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Password</label>
                            <input type="password" name="admin_password" class="form-control" placeholder="Min. 8 characters" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Confirm Password</label>
                            <input type="password" name="admin_password_confirm" class="form-control" placeholder="Re-type password" required>
                        </div>
                    </div>
                </div>

                <div class="text-end mb-5">
                    <button type="submit" class="btn btn-gradient btn-lg px-5" <?= !$allReqsPassed ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-rocket me-2"></i> Install AetherVault
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
