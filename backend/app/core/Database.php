<?php
require_once __DIR__ . '/Env.php';

class Database
{
    private static $host = '';
    private static $port = '';
    private static $dbName = '';
    private static $username = '';
    private static $password = '';
    private static $charset = 'utf8mb4';
    private static $connection = null;

    private static $initialized = false;

    private static function initConfig(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$host = (string)Env::get('DB_HOST', 'localhost');
        self::$port = (string)Env::get('DB_PORT', '3306');
        self::$dbName = (string)Env::get('DB_NAME', 'quanlytrungtamgiasu');
        self::$username = (string)Env::get('DB_USER', 'root');
        self::$password = (string)Env::get('DB_PASSWORD', self::$password);

        self::$initialized = true;
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            try {
                self::initConfig();

                $dsn = "mysql:host=" . self::$host . 
                       ";port=" . self::$port .
                       ";dbname=" . self::$dbName . 
                       ";charset=" . self::$charset;

                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4; SET SESSION sql_mode='STRICT_TRANS_TABLES'",
                    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                ];

                // Tùy chọn: nếu đặt DB_SSL_CA (đường dẫn tới file chứng chỉ CA, ví dụ ca.pem của Aiven)
                // thì bật kiểm tra chứng chỉ máy chủ. Không đặt -> giữ hành vi cũ (không kiểm tra).
                $sslCa = (string)Env::get('DB_SSL_CA', '');
                if ($sslCa !== '' && is_file($sslCa)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                }

                self::$connection = new PDO(
                    $dsn,
                    self::$username,
                    self::$password,
                    $options
                );

            } catch (PDOException $e) {
                error_log("Database Connection Error: " . $e->getMessage());
                
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Không thể kết nối database'
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }

        return self::$connection;
    }

    public static function getInstance(): PDO
    {
        return self::getConnection();
    }

    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }

    public static function beginTransaction(): void
    {
        self::getConnection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::getConnection()->commit();
    }

    public static function rollback(): void
    {
        self::getConnection()->rollBack();
    }

    public static function close(): void
    {
        self::$connection = null;
    }
}