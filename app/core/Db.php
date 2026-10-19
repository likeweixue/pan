<?php
/**
 * 数据库封装（PDO）
 */
defined('PAN_ACCESS') or exit('No direct access');

class Db
{
    private static ?PDO $pdo = null;
    private static array $config = [];
    private static string $prefix = '';

    public static function init(array $config): void
    {
        self::$config = $config;
        self::$prefix = $config['prefix'] ?? '';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $c = self::$config;
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . ($c['port'] ?: 3306) . ';dbname=' . $c['database'] . ';charset=' . ($c['charset'] ?: 'utf8mb4');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        self::$pdo = new PDO($dsn, $c['username'], $c['password'], $options);
        self::$pdo->exec("SET NAMES " . ($c['charset'] ?: 'utf8mb4') . " COLLATE utf8mb4_general_ci");
        return self::$pdo;
    }

    public static function t(string $table): string
    {
        return '`' . self::$prefix . $table . '`';
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function column(string $sql, array $params = []): mixed
    {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function insert(string $table, array $data): int
    {
        $keys = array_keys($data);
        $cols = implode('`,`', $keys);
        $ph = implode(',', array_map(fn($k) => ':' . $k, $keys));
        $sql = 'INSERT INTO ' . self::t($table) . ' (`' . $cols . '`) VALUES (' . $ph . ')';
        self::query($sql, $data);
        return self::lastId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[] = '`' . $k . '` = ?';
            $params[] = $v;
        }
        foreach ($whereParams as $v) {
            $params[] = $v;
        }
        $sql = 'UPDATE ' . self::t($table) . ' SET ' . implode(',', $sets) . ' WHERE ' . $where;
        return self::query($sql, $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::query('DELETE FROM ' . self::t($table) . ' WHERE ' . $where, $params)->rowCount();
    }

    public static function count(string $table, string $where = '1', array $params = []): int
    {
        return (int)self::column('SELECT COUNT(*) FROM ' . self::t($table) . ' WHERE ' . $where, $params);
    }

    public static function begin(): void { self::pdo()->beginTransaction(); }
    public static function commit(): void { self::pdo()->commit(); }
    public static function rollBack(): void { if (self::pdo()->inTransaction()) self::pdo()->rollBack(); }
    /** 自增 ID（某些代理层下 lastInsertId 可能为 0，回退到 LAST_INSERT_ID()） */
    public static function lastId(): int
    {
        $id = (int)self::pdo()->lastInsertId();
        if ($id <= 0) {
            try {
                $id = (int)self::column('SELECT LAST_INSERT_ID()');
            } catch (Throwable $e) {
                $id = 0;
            }
        }
        return $id;
    }
}
