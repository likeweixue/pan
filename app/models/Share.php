<?php
/**
 * 分享模型
 */
defined('PAN_ACCESS') or exit('No direct access');

class Share
{
    /** 生成不重复的短链码 */
    public static function newCode(): string
    {
        do {
            $code = Util::random(7, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
            $exists = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('shares') . ' WHERE code = ?', [$code]);
        } while ($exists > 0);
        return $code;
    }

    public static function create(int $uid, int $nodeId, array $opts = []): array
    {
        $node = Node::own($uid, $nodeId);
        $code = self::newCode();
        $now = Util::now();
        $id = Db::insert('shares', [
            'code' => $code,
            'uid' => $uid,
            'node_id' => $nodeId,
            'title' => $opts['title'] ?? $node['name'],
            'password' => !empty($opts['password']) ? password_hash($opts['password'], PASSWORD_DEFAULT) : '',
            'pickcode' => $opts['pickcode'] ?? '',
            'expire_at' => $opts['expire_at'] ?? null,
            'allow_preview' => (int)($opts['allow_preview'] ?? 1),
            'allow_download' => (int)($opts['allow_download'] ?? 1),
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        LogModel::add($uid, 'share', 'create', $node['name']);
        return self::byCode($code);
    }

    public static function byCode(string $code): ?array
    {
        $row = Db::fetch('SELECT * FROM ' . Db::t('shares') . ' WHERE code = ?', [$code]);
        return $row ? self::normalize($row) : null;
    }

    public static function normalize(array $s): array
    {
        $s['id'] = (int)$s['id'];
        $s['uid'] = (int)$s['uid'];
        $s['node_id'] = (int)$s['node_id'];
        $s['expired'] = !empty($s['expire_at']) && strtotime($s['expire_at']) < time();
        $s['has_password'] = $s['password'] !== '';
        $s['has_pickcode'] = $s['pickcode'] !== '';
        $s['expire_text'] = empty($s['expire_at']) ? '永久有效' : date('Y-m-d', strtotime($s['expire_at']));
        unset($s['password']);
        return $s;
    }

    public static function myList(int $uid): array
    {
        $rows = Db::fetchAll('SELECT s.*, n.name, n.type, n.size, n.ext, n.mime FROM ' . Db::t('shares') . ' s
            LEFT JOIN ' . Db::t('nodes') . ' n ON s.node_id = n.id
            WHERE s.uid = ? ORDER BY s.created_at DESC LIMIT 500', [$uid]);
        return array_map(function ($r) {
            $base = [
                'id' => (int)$r['id'], 'uid' => (int)$r['uid'], 'node_id' => (int)$r['node_id'],
                'title' => $r['title'], 'code' => $r['code'], 'pickcode' => $r['pickcode'],
                'password' => $r['password'], 'expire_at' => $r['expire_at'], 'expire_text' => '',
                'allow_preview' => (int)$r['allow_preview'], 'allow_download' => (int)$r['allow_download'],
                'view_count' => (int)$r['view_count'], 'download_count' => (int)$r['download_count'],
                'save_count' => (int)$r['save_count'], 'status' => (int)$r['status'],
                'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
            ];
            $s = self::normalize($base);
            $s['node'] = ['name' => $r['name'], 'type' => $r['type'], 'size' => (int)$r['size'], 'ext' => $r['ext'], 'kind' => Util::kind($r['ext'], $r['mime'])];
            return $s;
        }, $rows);
    }

    /** 游客访问校验（返回分享对象，或抛出需要密码/失效） */
    public static function open(string $code): array
    {
        $s = self::byCode($code);
        if (!$s || (int)$s['status'] !== 1) throw new ApiException('分享不存在或已被取消', 404);
        $raw = Db::fetch('SELECT * FROM ' . Db::t('shares') . ' WHERE code = ?', [$code]);
        if ($s['expired']) throw new ApiException('该分享已过期', 403, ['expired' => true]);
        $node = Node::find((int)$s['node_id']);
        if (!$node || (int)$node['deleted'] === 1) throw new ApiException('分享的文件已被删除', 404);

        $need = null;
        $sessionKey = 'share_ok_' . $code;
        if ($raw['password'] !== '' && empty($_SESSION[$sessionKey])) $need = 'password';
        elseif ($raw['pickcode'] !== '' && empty($_SESSION[$sessionKey])) $need = 'pickcode';

        $s['need'] = $need;
        $s['_raw'] = $raw;
        return $s;
    }

    /** 校验访问凭证 */
    public static function unlock(string $code, string $value): array
    {
        $raw = Db::fetch('SELECT * FROM ' . Db::t('shares') . ' WHERE code = ?', [$code]);
        if (!$raw) throw new ApiException('分享不存在', 404);
        $ok = false;
        if ($raw['password'] !== '' && password_verify($value, $raw['password'])) $ok = true;
        if (!$ok && $raw['pickcode'] !== '' && hash_equals(strtoupper($raw['pickcode']), strtoupper($value))) $ok = true;
        if (!$ok) throw new ApiException('密码或提取码不正确', 403);
        $_SESSION['share_ok_' . $code] = time();
        return ['ok' => true];
    }

    public static function incrView(string $code): void
    {
        Db::query('UPDATE ' . Db::t('shares') . ' SET view_count = view_count + 1 WHERE code = ?', [$code]);
    }

    public static function incrDownload(string $code): void
    {
        Db::query('UPDATE ' . Db::t('shares') . ' SET download_count = download_count + 1 WHERE code = ?', [$code]);
    }

    public static function incrSave(string $code): void
    {
        Db::query('UPDATE ' . Db::t('shares') . ' SET save_count = save_count + 1 WHERE code = ?', [$code]);
    }

    public static function update(int $uid, int $id, array $data): array
    {
        $row = Db::fetch('SELECT * FROM ' . Db::t('shares') . ' WHERE id = ? AND uid = ?', [$id, $uid]);
        if (!$row) throw new ApiException('分享不存在', 404);
        $patch = ['updated_at' => Util::now()];
        if (array_key_exists('title', $data) && is_string($data['title']) && $data['title'] !== '') {
            $patch['title'] = Util::safeName($data['title']);
        }
        if (array_key_exists('status', $data)) $patch['status'] = (int)$data['status'] ? 1 : 0;
        if (array_key_exists('allow_download', $data)) $patch['allow_download'] = (int)$data['allow_download'] ? 1 : 0;
        if (array_key_exists('allow_preview', $data)) $patch['allow_preview'] = (int)$data['allow_preview'] ? 1 : 0;
        if (array_key_exists('expire_at', $data)) $patch['expire_at'] = $data['expire_at'] ?: null;
        if (array_key_exists('password', $data)) {
            $pw = (string)$data['password'];
            $patch['password'] = $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : '';
        }
        if (array_key_exists('pickcode', $data)) {
            $patch['pickcode'] = strtoupper(substr((string)preg_replace('/[^0-9A-Za-z]/', '', (string)$data['pickcode']), 0, 8));
        }
        Db::update('shares', $patch, 'id = ?', [$id]);
        LogModel::add($uid, 'share', 'update', $row['code']);
        return self::byCode($row['code']);
    }

    public static function remove(int $uid, int $id): void
    {
        Db::delete('shares', 'id = ? AND uid = ?', [$id, $uid]);
        LogModel::add($uid, 'share', 'delete', (string)$id);
    }
}
