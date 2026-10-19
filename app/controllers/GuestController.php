<?php
defined('PAN_ACCESS') or exit('No direct access');

class GuestController
{
    /** 校验并取回分享 + 根/目标节点 */
    private function resolve(bool $checkLock = true): array
    {
        $code = Util::str('code');
        if ($code === '') Http::fail('缺少分享码', 400);
        $share = Share::open($code);
        if ($checkLock && $share['need'] !== null) {
            Http::fail('需要访问密码或提取码', 403, ['need' => $share['need']]);
        }
        $rootId = (int)$share['node_id'];
        $root = Node::find($rootId);
        if (!$root || (int)$root['deleted'] === 1) Http::fail('分享内容已被删除', 404);

        $targetId = Util::input('node_id', null);
        $target = $root;
        if ($targetId !== null && (int)$targetId !== $rootId) {
            $target = Node::find((int)$targetId);
            if (!$target || (int)$target['deleted'] === 1) Http::fail('文件不存在', 404);
            $rootPath = $root['id_path'] ?: ('0/' . $rootId . '/');
            if ($root['type'] === 'folder' && !str_starts_with($target['id_path'], $rootPath)) {
                Http::fail('该文件不在此分享中', 403);
            }
        }
        return [$share, $root, $target];
    }

    public function info(): void
    {
        $code = Util::str('code');
        $share = Share::open($code);
        $root = Node::find((int)$share['node_id']);
        if (!$root) Http::fail('分享内容已被删除', 404);
        $owner = Db::fetch('SELECT nickname, username FROM ' . Db::t('users') . ' WHERE id = ?', [(int)$share['uid']]);

        $out = [
            'code' => $share['code'],
            'title' => $share['title'],
            'need' => $share['need'],
            'expire_text' => $share['expire_text'],
            'expired' => $share['expired'],
            'allow_download' => $share['allow_download'],
            'allow_preview' => $share['allow_preview'],
            'view_count' => $share['view_count'],
            'download_count' => $share['download_count'],
            'owner' => $owner['nickname'] ?? $owner['username'] ?? '匿名',
            'created_text' => Util::timeAgo($share['created_at']),
            'url' => base_url('index.php?_route=/s/' . $share['code']),
            'node' => $root,
        ];
        if ($share['need'] === null) {
            Share::incrView($code);
        }
        Http::ok($out);
    }

    public function unlock(): void
    {
        $code = Util::str('code');
        Http::ok(Share::unlock($code, Util::str('value')));
    }

    public function list(): void
    {
        [$share, $root, $target] = $this->resolve();
        if ($target['type'] === 'folder') {
            $nodes = Node::children((int)$share['uid'], (int)$target['id'], Util::str('sort', 'name_asc'));
            $scope = ($root['type'] === 'folder') ? ($root['id_path'] ?: ('0/' . $root['id'] . '/')) : '';
            $nodes = array_values(array_filter($nodes, function ($n) use ($scope) {
                return $scope === '' || str_starts_with($n['id_path'], $scope);
            }));
            Http::ok([
                'mode' => 'folder',
                'nodes' => $nodes,
                'breadcrumb' => $this->crumbs($root, $target),
                'share' => ['code' => $share['code'], 'allow_download' => $share['allow_download'], 'allow_preview' => $share['allow_preview']],
                'current' => $target,
            ]);
        }
        Http::ok([
            'mode' => 'file',
            'nodes' => [],
            'breadcrumb' => $this->crumbs($root, $target),
            'share' => ['code' => $share['code'], 'allow_download' => $share['allow_download'], 'allow_preview' => $share['allow_preview']],
            'current' => $target,
        ]);
    }

    private function crumbs(array $root, array $target): array
    {
        $crumbs = [['id' => $root['id'], 'name' => $root['name']]];
        if ((int)$root['id'] !== (int)$target['id']) {
            $uid = (int)$target['uid'];
            $chain = explode('/', trim($target['id_path'], '/'));
            $ids = array_values(array_filter(array_map('intval', $chain)));
            $start = array_search((int)$root['id'], $ids, true);
            if ($start !== false) {
                $ids = array_slice($ids, $start + 1);
                foreach ($ids as $id) {
                    $row = Db::fetch('SELECT id,name FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ?', [$id, $uid]);
                    if ($row) $crumbs[] = ['id' => (int)$row['id'], 'name' => $row['name']];
                }
            }
        }
        return $crumbs;
    }

    public function preview(): void
    {
        [$share, $root, $target] = $this->resolve();
        if ((int)$share['allow_preview'] !== 1) Http::fail('该分享未开启预览', 403);
        if ($target['type'] === 'folder') Http::fail('文件夹不支持预览');
        $abs = Node::absolute($target['storage']);
        if (!is_file($abs)) Http::fail('文件已丢失', 404);
        $owner = User::find((int)$share['uid']);
        Node::incrView((int)$target['id']);
        Http::streamFile($abs, $target['name'], $target['mime'] ?: 'application/octet-stream', true, (int)($owner['download_speed'] ?? 0));
    }

    public function download(): void
    {
        [$share, $root, $target] = $this->resolve();
        if ((int)$share['allow_download'] !== 1) Http::fail('该分享未开启下载', 403);
        $owner = User::find((int)$share['uid']);
        $speed = (int)($owner['download_speed'] ?? 0);
        Share::incrDownload($share['code']);
        Node::incrDownload((int)$target['id']);
        LogModel::add((int)$share['uid'], 'share', 'guest_download', $target['name']);

        if ($target['type'] === 'folder') {
            $this->zip((int)$share['uid'], $target, $speed, $share['code']);
        }
        $abs = Node::absolute($target['storage']);
        if (!is_file($abs)) Http::fail('文件已丢失', 404);
        Http::streamFile($abs, $target['name'], $target['mime'] ?: 'application/octet-stream', false, $speed);
    }

    private function zip(int $uid, array $node, int $speed, string $code): void
    {
        if (!class_exists('ZipArchive')) Http::fail('服务器未安装 zip 扩展，无法打包下载');
        $zipFile = STORAGE_PATH . '/tmp/share_' . md5($code . microtime(true)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) Http::fail('无法创建压缩包');
        $used = [];
        $walk = function (array $n, string $prefix) use (&$walk, $zip, $uid, &$used) {
            $path = $prefix . $n['name'];
            if ($n['type'] === 'folder') {
                $zip->addEmptyDir($path);
                foreach (Node::children($uid, (int)$n['id'], 'name_asc') as $c) $walk($c, $path . '/');
                return;
            }
            $abs = Node::absolute($n['storage']);
            if (is_file($abs)) $zip->addFile($abs, $path);
        };
        $walk($node, '');
        $zip->close();
        register_shutdown_function(fn() => is_file($zipFile) && @unlink($zipFile));
        Http::streamFile($zipFile, $node['name'] . '.zip', 'application/zip', false, $speed);
    }

    /** 转存到我的网盘 */
    public function save(): void
    {
        $u = Auth::requireLogin();
        [$share, $root, $target] = $this->resolve();
        $targetId = Util::int('target_id', 0);

        if ($targetId !== 0) {
            $dest = Node::own($u['id'], $targetId);
            if ($dest['type'] !== 'folder') Http::fail('目标必须是文件夹');
        }

        $srcUid = (int)$share['uid'];
        $total = Node::folderSize($srcUid, (int)$target['id']) + ($target['type'] === 'file' ? (int)$target['size'] : 0);
        if ($total > User::free($u['id'])) Http::fail('存储空间不足，请先清理');

        $newId = $this->copyFrom($srcUid, $u['id'], $target, $targetId, $target['name']);
        Share::incrSave($share['code']);
        LogModel::add($u['id'], 'share', 'save', $target['name']);
        Http::ok(['node' => Node::find($newId)]);
    }

    private function copyFrom(int $srcUid, int $dstUid, array $node, int $parentId, string $name): int
    {
        $now = Util::now();
        $newName = Node::uniqueName($dstUid, $parentId, $name);
        $newId = Db::insert('nodes', [
            'uid' => $dstUid, 'parent_id' => $parentId, 'id_path' => '', 'name' => $newName,
            'type' => $node['type'], 'mime' => $node['mime'], 'ext' => $node['ext'], 'size' => $node['size'],
            'storage' => $node['storage'], 'hash' => $node['hash'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $parentPath = '0/';
        if ($parentId !== 0) {
            $p = Db::fetch('SELECT id_path FROM ' . Db::t('nodes') . ' WHERE id = ? AND uid = ?', [$parentId, $dstUid]);
            $parentPath = ($p['id_path'] ?? '') . $parentId . '/';
        }
        Db::update('nodes', ['id_path' => $parentPath . $newId . '/'], 'id = ?', [$newId]);

        if ($node['type'] === 'file') {
            // 跨用户：物理复制一份，避免删除互相影响
            if ($srcUid !== $dstUid) {
                $src = Node::absolute($node['storage']);
                $storageKey = Node::storageKey(md5($dstUid . microtime(true) . $node['id']), $node['ext']);
                $dest = Node::absolute($storageKey);
                Util::ensureDir(dirname($dest));
                @copy($src, $dest);
                $hash = hash_file('sha256', $dest);
                Db::update('nodes', ['storage' => $storageKey, 'hash' => $hash], 'id = ?', [$newId]);
                User::addUsage($dstUid, (int)$node['size']);
            } else {
                User::addUsage($dstUid, (int)$node['size']);
            }
        } else {
            foreach (Db::fetchAll('SELECT * FROM ' . Db::t('nodes') . ' WHERE uid = ? AND parent_id = ? AND deleted = 0', [$srcUid, (int)$node['id']]) as $child) {
                $this->copyFrom($srcUid, $dstUid, Node::normalize($child), $newId, $child['name']);
            }
        }
        return $newId;
    }
}
