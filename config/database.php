<?php
/**
 * AetherVault - Cloud Storage System
 * Database Connection Manager (PDO)
 */

class Database {
    private static ?PDO $instance = null;

    /**
     * Get or initialize PDO instance
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $configFile = defined('DB_CONFIG_FILE') ? DB_CONFIG_FILE : __DIR__ . '/database_config.php';
            
            if (!file_exists($configFile)) {
                throw new Exception('Database configuration file missing. Please run installation wizard.');
            }

            $dbConfig = require $configFile;

            $host = $dbConfig['host'] ?? '127.0.0.1';
            $port = $dbConfig['port'] ?? 3306;
            $dbname = $dbConfig['dbname'] ?? 'aethervault_db';
            $charset = $dbConfig['charset'] ?? 'utf8mb4';
            $user = $dbConfig['user'] ?? 'root';
            $password = $dbConfig['password'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, $user, $password, $options);
            } catch (PDOException $e) {
                error_log("Database Connection Error: " . $e->getMessage());
                throw new Exception("Could not connect to database. Please check your configuration.");
            }
        }

        return self::$instance;
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
