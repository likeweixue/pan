<?php
/**
 * 日志模型
 */
defined('PAN_ACCESS') or exit('No direct access');

class LogModel
{
    public static function add(int $uid, string $type, string $action, string $target = ''): void
    {
        try {
            Db::insert('logs', [
                'uid' => $uid,
                'username' => Auth::$user['username'] ?? '',
                'type' => $type,
                'action' => $action,
                'target' => mb_substr($target, 0, 250),
                'ip' => Util::clientIp(),
                'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
                'created_at' => Util::now(),
            ]);
        } catch (Throwable $e) {
            // 日志失败不影响业务
        }
    }

    public static function page(int $page = 1, int $size = 20, string $keyword = '', ?int $uid = null): array
    {
        $where = '1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (action LIKE ? OR target LIKE ? OR username LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        if ($uid) {
            $where .= ' AND uid = ?';
            $params[] = $uid;
        }
        $total = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('logs') . ' WHERE ' . $where, $params);
        $rows = Db::fetchAll('SELECT * FROM ' . Db::t('logs') . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . (int)(($page - 1) * $size) . ',' . (int)$size, $params);
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows];
    }

    public static function clear(?int $beforeDays = null): void
    {
        if ($beforeDays === null) Db::query('DELETE FROM ' . Db::t('logs'));
        else Db::query('DELETE FROM ' . Db::t('logs') . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $beforeDays * 86400)]);
    }
}
