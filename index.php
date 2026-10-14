<?php
/**
 * AetherVault - Main Entry Point Router
 */

require_once __DIR__ . '/config/config.php';

if (auth_check()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
