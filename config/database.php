<?php
// config/database.php

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3308');
define('DB_NAME', 'example_db');
define('DB_USER', 'example_user');
define('DB_PASS', 'example_password');
define('DB_CHARSET', 'utf8mb4');

// Режим отладки: true — показывать текст ошибки, false — только лог
define('APP_DEBUG', true);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST 
             . ";port=" . DB_PORT 
             . ";dbname=" . DB_NAME 
             . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            http_response_code(500);

            if (defined('APP_DEBUG') && APP_DEBUG) {
                die(
                    '<div style="font-family:monospace;padding:24px;background:#fee2e2;'
                    . 'color:#991b1b;border-left:4px solid #ef4444;margin:24px;">'
                    . '<strong>Ошибка подключения к БД</strong><br><br>'
                    . 'DSN: ' . htmlspecialchars($dsn) . '<br>'
                    . 'Код: ' . (int)$e->getCode() . '<br>'
                    . 'Сообщение: ' . htmlspecialchars($e->getMessage())
                    . '</div>'
                );
            }

            die("Ошибка подключения к базе данных");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}
