<?php
/**
 * AetherVault - Users Route Alias
 * Smoothly redirects to admin/users.php
 */

require_once __DIR__ . '/config/config.php';
redirect('admin/users.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
