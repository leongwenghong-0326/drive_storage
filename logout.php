<?php
/**
 * AetherVault - User Sign Out Handler
 */

require_once __DIR__ . '/config/config.php';

auth_logout();
set_flash('info', 'You have been safely signed out.');
redirect('login.php');
