<?php
/**
 * AetherVault - CSRF Protection Module
 */

class Csrf {
    /**
     * Get or generate current CSRF token
     */
    public static function getToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Generate HTML hidden input field for forms
     */
    public static function field(): string {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    /**
     * Validate request CSRF token from POST or X-CSRF-Token header
     */
    public static function validate(?string $token = null): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            return false;
        }

        if ($token === null) {
            // Check POST parameter first
            if (isset($_POST['csrf_token'])) {
                $token = $_POST['csrf_token'];
            }
            // Check HTTP headers (for AJAX/fetch requests)
            elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
            }
        }

        if (empty($token) || !is_string($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Enforce CSRF check or terminate with error
     */
    public static function requireValid(): void {
        if (!self::validate()) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'CSRF verification failed. Please refresh the page and try again.'
                ]);
                exit;
            } else {
                http_response_code(403);
                die('CSRF token validation failed. Security check rejected this request.');
            }
        }
    }
}

/**
 * Global helper functions for brevity
 */
function csrf_token(): string {
    return Csrf::getToken();
}

function csrf_field(): string {
    return Csrf::field();
}
