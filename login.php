<?php
/**
 * AetherVault - User & Administrator Secure Sign-in
 */

require_once __DIR__ . '/config/config.php';

// If already logged in, redirect directly to dashboard
if (auth_check()) {
    redirect('dashboard.php');
}

$error = '';
$appName = get_setting('app_name', 'AetherVault Cloud');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = auth_login($identifier, $password);

    if ($result['success']) {
        set_flash('success', $result['message']);
        redirect('dashboard.php');
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-accent: #4f46e5;
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            --body-bg: #0f172a;
            --card-bg: #1e293b;
            --border-color: #334155;
        }
        /* Custom Modern Scrollbar */
        html {
            scrollbar-width: thin;
            scrollbar-color: #334155 #0f172a;
        }
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #0f172a;
        }
        ::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 9999px;
            border: 2px solid #0f172a;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
        ::-webkit-scrollbar-button {
            display: none;
            width: 0;
            height: 0;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--body-bg);
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1.25rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 440px;
            padding: 36px 32px;
        }
        .brand-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-gradient);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            margin-bottom: 12px;
            box-shadow: 0 8px 16px rgba(79, 70, 229, 0.4);
        }
        .form-label {
            color: #cbd5e1;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
        }
        .form-control {
            background-color: #0f172a;
            border: 1px solid #475569;
            color: #ffffff;
            border-radius: 0.65rem;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
        }
        .form-control::placeholder {
            color: #94a3b8 !important;
            opacity: 1 !important;
        }
        .form-control::-webkit-input-placeholder {
            color: #94a3b8 !important;
            opacity: 1 !important;
        }
        .form-control::-moz-placeholder {
            color: #94a3b8 !important;
            opacity: 1 !important;
        }
        .form-control:-ms-input-placeholder {
            color: #94a3b8 !important;
            opacity: 1 !important;
        }
        .form-control:focus {
            background-color: #0f172a;
            border-color: #6366f1;
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.35);
        }
        .input-icon-addon {
            background-color: #0f172a;
            border: 1px solid #475569;
            border-right: none;
            color: #818cf8;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border-top-left-radius: 0.65rem;
            border-bottom-left-radius: 0.65rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .input-group .form-control {
            border-left: none;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        .input-group:focus-within .input-icon-addon {
            border-color: #6366f1;
            color: #a5b4fc;
        }
        .input-group:focus-within .form-control {
            border-color: #6366f1;
        }
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.8rem;
            border-radius: 0.65rem;
            transition: all 0.2s ease;
        }
        .btn-gradient:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            color: white;
            box-shadow: 0 10px 20px -3px rgba(79, 70, 229, 0.4);
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center mb-4">
        <div class="brand-icon-box">
            <i class="fa-solid fa-cloud-arrow-up"></i>
        </div>
        <h4 class="fw-bold mb-1 text-white">Sign in to AetherVault</h4>
        <p class="text-secondary small">Access your enterprise cloud files and assets</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 d-flex align-items-center mb-4 py-2 px-3 small" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2 fs-6"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label">Username or Email</label>
            <div class="input-group">
                <span class="input-group-text input-icon-addon"><i class="fa-regular fa-user"></i></span>
                <input type="text" name="identifier" class="form-control" placeholder="Enter username or email" value="<?= e($_POST['identifier'] ?? '') ?>" required autofocus>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text input-icon-addon"><i class="fa-solid fa-lock"></i></span>
                <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
            </div>
        </div>

        <button type="submit" class="btn btn-gradient w-100">
            <i class="fa-solid fa-right-to-bracket me-2"></i> Sign In
        </button>
    </form>

    <!-- Demo Accounts Quick Fill Section -->
    <div class="mt-4 pt-3 border-top" style="border-color: #334155 !important;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em; color: #94a3b8;">
                <i class="fa-solid fa-bolt me-1 text-warning"></i> Demo Accounts (Click to Fill)
            </span>
            <span class="badge bg-primary-subtle text-primary small" id="fillToast" style="display: none;">Filled!</span>
        </div>
        <div class="row g-2">
            <div class="col-6">
                <button type="button" class="btn btn-outline-secondary w-100 text-start p-2 demo-card-btn" style="border-color: #475569; background: #0f172a;" onclick="fillDemoCredentials('admin', 'Admin@123456', this)">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="badge bg-primary-subtle text-primary">Admin</span>
                        <i class="fa-solid fa-arrow-turn-down small" style="color: #94a3b8;"></i>
                    </div>
                    <div class="small fw-semibold text-white mt-1 text-truncate">admin</div>
                    <div style="font-size: 0.72rem; color: #94a3b8;">Full Access</div>
                </button>
            </div>
            <div class="col-6">
                <button type="button" class="btn btn-outline-secondary w-100 text-start p-2 demo-card-btn" style="border-color: #475569; background: #0f172a;" onclick="fillDemoCredentials('demouser', 'User@123456', this)">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="badge bg-info-subtle text-info">User</span>
                        <i class="fa-solid fa-arrow-turn-down small" style="color: #94a3b8;"></i>
                    </div>
                    <div class="small fw-semibold text-white mt-1 text-truncate">demouser</div>
                    <div style="font-size: 0.72rem; color: #94a3b8;">Drive Workspace</div>
                </button>
            </div>
        </div>
    </div>

    <div class="mt-3 text-center small" style="font-size: 0.8rem; color: #94a3b8;">
        <i class="fa-solid fa-shield-halved me-1" style="color: #38bdf8; font-size: 0.95rem;"></i>
        <span>Restricted Access: Accounts are provisioned by system administrators.</span>
    </div>
</div>

<script>
function fillDemoCredentials(username, password, btn) {
    const identInput = document.querySelector('input[name="identifier"]');
    const passInput = document.querySelector('input[name="password"]');

    if (identInput && passInput) {
        identInput.value = username;
        passInput.value = password;

        // Visual feedback on inputs
        identInput.focus();
        identInput.style.borderColor = '#6366f1';
        passInput.style.borderColor = '#6366f1';

        const toast = document.getElementById('fillToast');
        if (toast) {
            toast.style.display = 'inline-block';
            setTimeout(() => {
                toast.style.display = 'none';
                identInput.style.borderColor = '';
                passInput.style.borderColor = '';
            }, 1500);
        }
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
