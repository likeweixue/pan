<?php
/**
 * 系统设置
 */
defined('PAN_ACCESS') or exit('No direct access');

class Setting
{
    private static array $cache = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        try {
            foreach (Db::fetchAll('SELECT k, v FROM ' . Db::t('settings')) as $row) {
                self::$cache[$row['k']] = $row['v'];
            }
        } catch (Throwable $e) {
            self::$cache = [];
        }
    }

    public static function get(string $key, $default = '')
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        self::load();
        self::$cache[$key] = $value;
        Db::query('INSERT INTO ' . Db::t('settings') . ' (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
    }

    public static function all(): array
    {
        self::load();
        return self::$cache;
    }

    public static function saveAll(array $data): void
    {
        foreach ($data as $k => $v) {
            self::set((string)$k, is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }
    }

    public static function bool(string $key): bool
    {
        return (string)self::get($key, '0') === '1';
    }
}
