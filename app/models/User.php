<?php
/**
 * 用户模型
 */
defined('PAN_ACCESS') or exit('No direct access');

class User
{
    public static function find(int $id): ?array
    {
        $row = Db::fetch('SELECT u.*, g.name AS group_name, g.quota AS group_quota, g.upload_speed AS g_up, g.download_speed AS g_down,
            g.max_filesize AS g_maxsize, g.can_share AS g_can_share, g.is_admin AS g_is_admin
            FROM ' . Db::t('users') . ' u LEFT JOIN ' . Db::t('groups') . ' g ON u.group_id = g.id WHERE u.id = ?', [$id]);
        return $row ? self::normalize($row) : null;
    }

    public static function byName(string $username): ?array
    {
        $row = Db::fetch('SELECT * FROM ' . Db::t('users') . ' WHERE username = ? OR email = ? LIMIT 1', [$username, $username]);
        return $row ?: null;
    }

    public static function normalize(array $u): array
    {
        $u['id'] = (int)$u['id'];
        $u['used'] = (int)$u['used'];
        $u['status'] = (int)$u['status'];
        $u['quota'] = (int)$u['quota'] > 0 ? (int)$u['quota'] : (int)($u['group_quota'] ?? 0);
        $u['upload_speed'] = (int)$u['upload_speed'] > 0 ? (int)$u['upload_speed'] : (int)($u['g_up'] ?? 0);
        $u['download_speed'] = (int)$u['download_speed'] > 0 ? (int)$u['download_speed'] : (int)($u['g_down'] ?? 0);
        $u['max_filesize'] = (int)($u['g_maxsize'] ?? 0);
        $u['can_share'] = (int)($u['g_can_share'] ?? 1) === 1;
        $u['is_admin'] = (int)($u['g_is_admin'] ?? 0) === 1;
        $u['group_name'] = $u['group_name'] ?? '未分组';
        $u['used_text'] = Util::size($u['used']);
        $u['quota_text'] = Util::size($u['quota']);
        $u['percent'] = $u['quota'] > 0 ? min(100, round($u['used'] / $u['quota'] * 100, 2)) : 0;
        unset($u['password']);
        return $u;
    }

    public static function addUsage(int $uid, int $delta): void
    {
        Db::query('UPDATE ' . Db::t('users') . ' SET used = GREATEST(0, CAST(used AS SIGNED) + ?) WHERE id = ?', [$delta, $uid]);
    }

    public static function create(array $data): int
    {
        $now = Util::now();
        return Db::insert('users', [
            'group_id' => (int)$data['group_id'],
            'username' => $data['username'],
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'nickname' => $data['nickname'] ?? $data['username'],
            'avatar' => '',
            'status' => (int)($data['status'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function touchLogin(int $uid): void
    {
        Db::update('users', ['last_login_at' => Util::now(), 'last_login_ip' => Util::clientIp()], 'id = ?', [$uid]);
    }

    /** 剩余配额 */
    public static function free(int $uid): int
    {
        $u = self::find($uid);
        if (!$u) return 0;
        return max(0, (int)$u['quota'] - (int)$u['used']);
    }
}
