<?php
/**
 * 文件 / 文件夹节点模型
 */
defined('PAN_ACCESS') or exit('No direct access');

class Node
{
    public const ROOT = 0;

    public static function normalize(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['parent_id'] = (int)$row['parent_id'];
        $row['size'] = (int)$row['size'];
        $row['star'] = (int)$row['star'];
        $row['deleted'] = (int)$row['deleted'];
        $row['kind'] = $row['type'] === 'folder' ? 'folder' : Util::kind($row['ext'] ?? '', $row['mime'] ?? '');
        $row['size_text'] = $row['type'] === 'folder' ? '' : Util::size($row['size']);
        $row['created_text'] = Util::timeAgo($row['created_at'] ?? '');
        $row['updated_text'] = Util::timeAgo($row['updated_at'] ?? '');
        return $row;
    }

    public static function find(int $id, ?int $uid = null): ?array
    {
        $sql = 'SELECT * FROM ' . Db::t('nodes') . ' WHERE id = ?';
        $params = [$id];
        if ($uid !== null) {
            $sql .= ' AND uid = ?';
            $params[] = $uid;
        }
        $row = Db::fetch($sql, $params);
        return $row ? self::normalize($row) : null;
    }

    public static function children(int $uid, int $parentId, string $sort = 'folder_desc,name_asc', bool $trashed = false): array
    {
        $deleted = $trashed ? 1 : 0;
        [$field, $dir] = self::parseSort($sort);
        $sql = 'SELECT * FROM ' . Db::t('nodes') . ' WHERE uid = ? AND parent_id = ? AND deleted = ? ORDER BY type ASC, ';
        if ($field === 'name') {
            $sql .= 'name COLLATE utf8mb4_general_ci ' . $dir;
        } else {
            $sql .= '`' . $field . '` ' . $dir;
        }
        $rows = Db::fetchAll($sql, [$uid, $parentId, $deleted]);
        return array_map([self::class, 'normalize'], $rows);
    }

    private static function parseSort(string $sort): array
    {
        $map = [
            'name_asc' => ['name', 'ASC'], 'name_desc' => ['name', 'DESC'],
            'size_asc' => ['size', 'ASC'], 'size_desc' => ['size', 'DESC'],
            'time_asc' => ['updated_at', 'ASC'], 'time_desc' => ['updated_at', 'DESC'],
            'type_asc' => ['ext', 'ASC'], 'type_desc' => ['ext', 'DESC'],
        ];
        return $map[$sort] ?? ['name', 'ASC'];
    }

    public static function breadcrumb(int $uid, int $id): array
    {
        $crumbs = [];
        $guard = 0;
        while ($id > 0 && $guard++ < 64) {
            $row = Db::fetch('SELECT id,parent_id,name FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ? AND deleted = 0', [$id, $uid]);
            if (!$row) break;
            array_unshift($crumbs, ['id' => (int)$row['id'], 'name' => $row['name']]);
            $id = (int)$row['parent_id'];
        }
        array_unshift($crumbs, ['id' => 0, 'name' => '全部文件']);
        return $crumbs;
    }

    /** 父节点 path 字符串 */
    private static function parentPath(int $uid, int $parentId): string
    {
        if ($parentId === self::ROOT) return '0/';
        $row = Db::fetch('SELECT id_path FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ?', [$parentId, $uid]);
        if (!$row) throw new ApiException('父目录不存在');
        // id_path 形如 '0/12/33/'，已包含父节点自身
        return $row['id_path'] !== '' ? $row['id_path'] : ('0/' . $parentId . '/');
    }

    public static function createFolder(int $uid, int $parentId, string $name): array
    {
        $name = Util::safeName($name);
        self::checkUnique($uid, $parentId, $name);
        $now = Util::now();
        $id = Db::insert('nodes', [
            'uid' => $uid, 'parent_id' => $parentId, 'id_path' => '', 'name' => $name,
            'type' => 'folder', 'size' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $path = self::parentPath($uid, $parentId) . $id . '/';
        Db::update('nodes', ['id_path' => $path], 'id = ?', [$id]);
        LogModel::add($uid, 'file', 'create_folder', $name);
        return self::find($id);
    }

    public static function checkUnique(int $uid, int $parentId, string $name, ?int $exclude = null): void
    {
        $sql = 'SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND parent_id = ? AND name = ? AND deleted = 0';
        $params = [$uid, $parentId, $name];
        if ($exclude) {
            $sql .= ' AND id <> ?';
            $params[] = $exclude;
        }
        if ((int)Db::column($sql, $params) > 0) {
            throw new ApiException('当前目录下已存在同名项目：' . $name);
        }
    }

    public static function rename(int $uid, int $id, string $name): array
    {
        $node = self::own($uid, $id);
        $name = Util::safeName($name);
        self::checkUnique($uid, (int)$node['parent_id'], $name, $id);
        Db::update('nodes', ['name' => $name, 'updated_at' => Util::now()], 'id = ? AND uid = ?', [$id, $uid]);
        LogModel::add($uid, 'file', 'rename', $name);
        return self::find($id);
    }

    public static function own(int $uid, int $id): array
    {
        $node = self::find($id, $uid);
        if (!$node) throw new ApiException('文件不存在或无权访问', 404);
        return $node;
    }

    /** 获取某节点的子树 id 集合（含自身） */
    public static function subtreeIds(int $uid, int $id): array
    {
        $node = self::find($id, $uid);
        if (!$node) return [];
        $prefix = $node['id_path'] ?: ('0/' . $id . '/');
        $rows = Db::fetchAll('SELECT id FROM ' . Db::t('nodes') . ' WHERE uid = ? AND id_path LIKE ?', [$uid, $prefix . '%']);
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $ids[] = $id;
        return array_values(array_unique($ids));
    }

    /** 移动 */
    public static function move(int $uid, array $ids, int $targetId): void
    {
        if ($targetId !== self::ROOT) {
            $target = self::own($uid, $targetId);
            if ($target['type'] !== 'folder') throw new ApiException('目标位置必须是文件夹');
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id === $targetId) throw new ApiException('不能移动到自身');
            $desc = self::subtreeIds($uid, $id);
            if (in_array($targetId, $desc, true)) throw new ApiException('不能移动到自己的子目录中');
            $node = self::own($uid, $id);
            self::checkUnique($uid, $targetId, $node['name'], null);
        }
        $targetPath = self::parentPath($uid, $targetId);
        foreach ($ids as $id) {
            $id = (int)$id;
            $node = self::own($uid, $id);
            Db::begin();
            try {
                Db::update('nodes', ['parent_id' => $targetId, 'updated_at' => Util::now()], 'id = ? AND uid = ?', [$id, $uid]);
                self::rebase($uid, $id, $targetPath);
                Db::commit();
            } catch (Throwable $e) {
                Db::rollBack();
                throw $e;
            }
            LogModel::add($uid, 'file', 'move', $node['name']);
        }
    }

    /**
     * 把节点及其子孙的 id_path 迁移到新的父路径下
     * @param string $parentPath 形如 '0/12/'
     */
    private static function rebase(int $uid, int $id, string $parentPath): void
    {
        $oldPath = (string)Db::column('SELECT id_path FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ?', [$id, $uid]);
        $newPath = $parentPath . $id . '/';
        Db::update('nodes', ['id_path' => $newPath], 'id = ? AND uid = ?', [$id, $uid]);
        if ($oldPath === '' || $oldPath === $newPath) return;
        Db::query(
            'UPDATE ' . Db::t('nodes') . ' SET id_path = CONCAT(?, SUBSTRING(id_path, ?)) WHERE uid = ? AND id_path LIKE ?',
            [$newPath, strlen($oldPath) + 1, $uid, $oldPath . '%']
        );
    }

    /** 复制（递归，保留结构，文件复用同一份物理存储） */
    public static function copy(int $uid, array $ids, int $targetId): void
    {
        if ($targetId !== self::ROOT) {
            $target = self::own($uid, $targetId);
            if ($target['type'] !== 'folder') throw new ApiException('目标位置必须是文件夹');
        }
        foreach ($ids as $id) {
            self::copyOne($uid, (int)$id, $targetId);
        }
    }

    private static function copyOne(int $uid, int $id, int $targetId, ?string $targetName = null): int
    {
        $node = self::own($uid, $id);
        $name = $targetName ?: self::uniqueName($uid, $targetId, $node['name']);
        $now = Util::now();
        $newId = Db::insert('nodes', [
            'uid' => $uid, 'parent_id' => $targetId, 'id_path' => '', 'name' => $name,
            'type' => $node['type'], 'mime' => $node['mime'], 'ext' => $node['ext'], 'size' => $node['size'],
            'storage' => $node['storage'], 'hash' => $node['hash'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $path = self::parentPath($uid, $targetId) . $newId . '/';
        Db::update('nodes', ['id_path' => $path], 'id = ?', [$newId]);
        if ($node['type'] === 'folder') {
            foreach (Db::fetchAll('SELECT id FROM ' . Db::t('nodes') . ' WHERE uid = ? AND parent_id = ? AND deleted = 0', [$uid, $id]) as $child) {
                self::copyOne($uid, (int)$child['id'], $newId);
            }
        }
        LogModel::add($uid, 'file', 'copy', $name);
        return $newId;
    }

    public static function uniqueName(int $uid, int $parentId, string $name): string
    {
        $ext = Util::ext($name);
        $base = $ext !== '' ? mb_substr($name, 0, -(strlen($ext) + 1)) : $name;
        $try = $name;
        $i = 1;
        while (true) {
            $exists = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND parent_id = ? AND name = ? AND deleted = 0', [$uid, $parentId, $try]);
            if (!$exists) return $try;
            $try = $base . '(' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
            $i++;
            if ($i > 200) return $base . '_' . Util::random(4) . ($ext !== '' ? '.' . $ext : '');
        }
    }

    /** 移入回收站 */
    public static function trash(int $uid, array $ids): void
    {
        foreach ($ids as $id) {
            $id = (int)$id;
            $node = self::own($uid, $id);
            $sub = self::subtreeIds($uid, $id);
            $placeholders = implode(',', array_fill(0, count($sub), '?'));
            $params = array_merge([Util::now()], $sub, [$uid]);
            Db::query('UPDATE ' . Db::t('nodes') . ' SET deleted = 1, deleted_at = ? WHERE id IN (' . $placeholders . ') AND uid = ?', $params);
            LogModel::add($uid, 'file', 'trash', $node['name']);
        }
    }

    public static function trashList(int $uid): array
    {
        $rows = Db::fetchAll('SELECT * FROM ' . Db::t('nodes') . ' WHERE uid = ? AND deleted = 1 ORDER BY deleted_at DESC LIMIT 1000', [$uid]);
        return array_map([self::class, 'normalize'], $rows);
    }

    public static function restore(int $uid, array $ids): void
    {
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = Db::fetch('SELECT * FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ? AND deleted = 1', [$id, $uid]);
            if (!$row) continue;
            $oldPath = $row['id_path'] !== '' ? $row['id_path'] : ('0/' . $id . '/');
            // 先把自己与子孙全部恢复
            Db::query(
                'UPDATE ' . Db::t('nodes') . ' SET deleted = 0, deleted_at = NULL WHERE uid = ? AND (id = ? OR id_path LIKE ?)',
                [$uid, $id, $oldPath . '%']
            );
            // 父目录已被删除时，恢复到根目录并重算路径
            $parentId = (int)$row['parent_id'];
            if ($parentId !== self::ROOT) {
                $parent = Db::fetch('SELECT id FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ? AND deleted = 0', [$parentId, $uid]);
                if (!$parent) {
                    Db::update('nodes', ['parent_id' => 0], 'id = ? AND uid = ?', [$id, $uid]);
                    self::rebase($uid, $id, '0/');
                }
            }
            LogModel::add($uid, 'file', 'restore', $row['name']);
        }
    }

    /** 彻底删除（含物理文件回收） */
    public static function purge(int $uid, array $ids): void
    {
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = Db::fetch('SELECT * FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ? AND deleted = 1', [$id, $uid]);
            if (!$row) continue;
            $sub = [$id];
            $rows = Db::fetchAll('SELECT id FROM ' . Db::t('nodes') . ' WHERE uid = ? AND id_path LIKE ?', [$uid, ($row['id_path'] ?: '0/' . $id . '/') . '%']);
            foreach ($rows as $r) $sub[] = (int)$r['id'];
            $sub = array_values(array_unique($sub));
            self::destroyNodes($uid, $sub);
            LogModel::add($uid, 'file', 'purge', $row['name']);
        }
    }

    public static function clearTrash(int $uid): void
    {
        $rows = Db::fetchAll('SELECT id FROM ' . Db::t('nodes') . ' WHERE uid = ? AND deleted = 1', [$uid]);
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        if ($ids) self::destroyNodes($uid, $ids);
        LogModel::add($uid, 'file', 'clear_trash', '回收站');
    }

    /** 删除节点并回收存储（不限 deleted 状态，管理员/系统可调用） */
    public static function destroyNodes(int $uid, array $ids): void
    {
        if (!$ids) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::fetchAll('SELECT id,type,size,storage FROM ' . Db::t('nodes') . ' WHERE id IN (' . $ph . ') AND uid = ?', array_merge($ids, [$uid]));
        $freed = 0;
        Db::query('DELETE FROM ' . Db::t('nodes') . ' WHERE id IN (' . $ph . ') AND uid = ?', array_merge($ids, [$uid]));
        foreach ($rows as $r) {
            if ($r['type'] === 'file') {
                $freed += (int)$r['size'];
                self::recycleStorage($r['storage']);
            }
        }
        if ($freed > 0) User::addUsage($uid, -$freed);
    }

    /** 没有任何节点引用则删除物理文件 */
    public static function recycleStorage(string $storage): void
    {
        if ($storage === '') return;
        if ((int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE storage = ?', [$storage]) > 0) return;
        $abs = self::absolute($storage);
        if (is_file($abs)) @unlink($abs);
        $thumb = STORAGE_PATH . '/thumbs/' . md5($storage) . '.jpg';
        if (is_file($thumb)) @unlink($thumb);
    }

    public static function absolute(string $storage): string
    {
        return STORAGE_PATH . '/' . $storage;
    }

    /** 依据 hash 生成本地存储相对路径 */
    public static function storageKey(string $hash, string $ext): string
    {
        return 'files/' . substr($hash, 0, 2) . '/' . $hash . ($ext !== '' ? '.' . $ext : '');
    }

    /** 创建正式文件记录 */
    public static function createFile(int $uid, int $parentId, string $name, int $size, string $hash, string $mime, ?string $storageKey = null): array
    {
        $name = Util::safeName($name);
        $ext = Util::ext($name);
        $storageKey = $storageKey ?: self::storageKey($hash, $ext);
        $name = self::uniqueName($uid, $parentId, $name);
        $now = Util::now();
        $id = Db::insert('nodes', [
            'uid' => $uid, 'parent_id' => $parentId, 'id_path' => '', 'name' => $name,
            'type' => 'file', 'mime' => $mime, 'ext' => $ext, 'size' => $size,
            'storage' => $storageKey, 'hash' => $hash, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $path = self::parentPath($uid, $parentId) . $id . '/';
        Db::update('nodes', ['id_path' => $path], 'id = ?', [$id]);
        User::addUsage($uid, $size);
        LogModel::add($uid, 'file', 'upload', $name);
        return self::find($id);
    }

    public static function star(int $uid, int $id, ?bool $value = null): bool
    {
        $node = self::own($uid, $id);
        $new = $value === null ? ((int)$node['star'] === 1 ? 0 : 1) : ($value ? 1 : 0);
        Db::update('nodes', ['star' => $new], 'id = ? AND uid = ?', [$id, $uid]);
        return (bool)$new;
    }

    public static function incrView(int $id): void
    {
        Db::query('UPDATE ' . Db::t('nodes') . ' SET view_count = view_count + 1 WHERE id = ?', [$id]);
    }

    public static function incrDownload(int $id): void
    {
        Db::query('UPDATE ' . Db::t('nodes') . ' SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }

    public static function search(int $uid, string $keyword, int $limit = 200): array
    {
        $kw = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
        $rows = Db::fetchAll(
            'SELECT * FROM ' . Db::t('nodes') . ' WHERE uid = ? AND deleted = 0 AND name LIKE ? ORDER BY type DESC, updated_at DESC LIMIT ' . (int)$limit,
            [$uid, $kw]
        );
        return array_map([self::class, 'normalize'], $rows);
    }

    /** 统计文件夹树中某个文件夹的总大小（懒：直接累加子树文件） */
    public static function folderSize(int $uid, int $id): int
    {
        $node = self::find($id, $uid);
        if (!$node) return 0;
        return (int)Db::column('SELECT IFNULL(SUM(size),0) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND id_path LIKE ? AND type = "file"', [$uid, ($node['id_path'] ?: '0/' . $id . '/') . '%']);
    }

    public static function statUser(int $uid): array
    {
        $files = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND type="file" AND deleted=0', [$uid]);
        $folders = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND type="folder" AND deleted=0', [$uid]);
        $trash = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND deleted=1', [$uid]);
        $star = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND star=1 AND deleted=0', [$uid]);
        return compact('files', 'folders', 'trash', 'star');
    }
}
