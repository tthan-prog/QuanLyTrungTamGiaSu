<?php
// Database giả bằng SQLite trong bộ nhớ, chỉ dùng cho test (không đụng MySQL thật).
class Database
{
    public static $pdo;
    public static function pdo()
    {
        if (!self::$pdo) {
            self::$pdo = new PDO('sqlite::memory:');
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$pdo;
    }
    public static function query($s, $p = []) { $st = self::pdo()->prepare($s); $st->execute($p); return $st->fetchAll(); }
    public static function execute($s, $p = []) { $st = self::pdo()->prepare($s); $st->execute($p); return $st->rowCount(); }
    public static function beginTransaction() { self::pdo()->beginTransaction(); }
    public static function commit() { self::pdo()->commit(); }
    public static function rollback() { self::pdo()->rollBack(); }
}
