<?php
defined('PAN_ACCESS') or exit('No direct access');

class FileController
{
    private function user(): array
    {
        return Auth::requireLogin();
    }

    /** 目录列表 */
    public function list(): void
    {
        $u = $this->user();
        $parentId = Util::int('parent_id', 0);
        $sort = Util::str('sort', 'name_asc');
        $type = Util::str('type', 'all'); // all | star | trash | folder
        $keyword = Util::str('keyword', '');

        if ($type === 'trash') {
            Http::ok([
                'mode' => 'trash',
                'nodes' => Node::trashList($u['id']),
                'breadcrumb' => [['id' => 0, 'name' => '回收站']],
                'user' => User::find($u['id']),
            ]);
        }
        if ($keyword !== '') {
            Http::ok([
                'mode' => 'search',
                'nodes' => Node::search($u['id'], $keyword),
                'breadcrumb' => [['id' => 0, 'name' => '搜索：' . $keyword]],
                'user' => User::find($u['id']),
            ]);
        }
        if ($type === 'star') {
            $rows = Db::fetchAll('SELECT * FROM ' . Db::t('nodes') . ' WHERE uid = ? AND star = 1 AND deleted = 0 ORDER BY updated_at DESC LIMIT 500', [$u['id']]);
            Http::ok([
                'mode' => 'star',
                'nodes' => array_map([Node::class, 'normalize'], $rows),
                'breadcrumb' => [['id' => 0, 'name' => '我的收藏']],
                'user' => User::find($u['id']),
            ]);
        }
        if (in_array($type, ['image', 'video', 'audio', 'doc', 'xls', 'ppt', 'pdf', 'zip', 'code', 'text', 'other'], true)) {
            Http::ok([
                'mode' => 'type',
                'nodes' => $this->byType($u['id'], $type, Util::str('sort', 'time_desc')),
                'breadcrumb' => [['id' => 0, 'name' => self::typeName($type)]],
                'user' => User::find($u['id']),
            ]);
        }

        if ($parentId !== 0) {
            $p = Node::find($parentId, $u['id']);
            if (!$p) Http::fail('目录不存在', 404);
        }
        $nodes = Node::children($u['id'], $parentId, $sort);
        Http::ok([
            'mode' => 'normal',
            'nodes' => $nodes,
            'breadcrumb' => Node::breadcrumb($u['id'], $parentId),
            'user' => User::find($u['id']),
            'stat' => Node::statUser($u['id']),
        ]);
    }

    private static function typeName(string $type): string
    {
        return [
            'image' => '图片', 'video' => '视频', 'audio' => '音乐', 'doc' => '文档',
            'xls' => '表格', 'ppt' => '演示文稿', 'pdf' => 'PDF', 'zip' => '压缩包',
            'code' => '代码', 'text' => '文本', 'other' => '其它',
        ][$type] ?? '文件';
    }

    private function byType(int $uid, string $type, string $sort): array
    {
        $groups = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'avif', 'heic', 'tiff'],
            'video' => ['mp4', 'mkv', 'webm', 'avi', 'mov', 'flv', 'wmv', 'm4v', 'ts'],
            'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'ape', 'wma'],
            'doc' => ['doc', 'docx', 'rtf', 'odt', 'pages'],
            'xls' => ['xls', 'xlsx', 'ods', 'numbers', 'csv'],
            'ppt' => ['ppt', 'pptx', 'odp', 'key'],
            'pdf' => ['pdf'],
            'zip' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'iso', 'dmg'],
            'code' => ['php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'json', 'css', 'scss', 'html', 'htm', 'xml', 'py', 'java', 'go', 'c', 'h', 'cpp', 'cs', 'rb', 'sh', 'sql', 'ini', 'conf', 'yml', 'yaml', 'md', 'rs', 'swift', 'kt'],
            'text' => ['txt', 'log', 'diff', 'patch'],
        ];
        [$field, $dir] = [null, null];
        preg_match('/^(\w+)_(asc|desc)$/', $sort, $m);
        $map = ['name' => 'name', 'size' => 'size', 'time' => 'updated_at'];
        $field = $map[$m[1] ?? 'time'] ?? 'updated_at';
        $dir = strtoupper($m[2] ?? 'DESC');

        if ($type === 'other') {
            $known = [];
            foreach ($groups as $list) $known = array_merge($known, $list);
            $ph = implode(',', array_fill(0, count($known), '?'));
            $rows = Db::fetchAll(
                'SELECT * FROM ' . Db::t('nodes') . ' WHERE uid = ? AND type = "file" AND deleted = 0 AND (ext = "" OR ext NOT IN (' . $ph . ')) ORDER BY ' . $field . ' ' . $dir . ' LIMIT 500',
                array_merge([$uid], $known)
            );
            return array_map([Node::class, 'normalize'], $rows);
        }

        $exts = $groups[$type] ?? [];
        if (!$exts) return [];
        $ph = implode(',', array_fill(0, count($exts), '?'));
        $rows = Db::fetchAll(
            'SELECT * FROM ' . Db::t('nodes') . ' WHERE uid = ? AND type = "file" AND deleted = 0 AND ext IN (' . $ph . ') ORDER BY ' . $field . ' ' . $dir . ' LIMIT 500',
            array_merge([$uid], $exts)
        );
        return array_map([Node::class, 'normalize'], $rows);
    }

    /** 侧边栏文件夹树 */
    public function tree(): void
    {
        $u = $this->user();
        $parentId = Util::int('parent_id', 0);
        $rows = Db::fetchAll(
            'SELECT id,name,id_path FROM ' . Db::t('nodes') . ' WHERE uid = ? AND parent_id = ? AND type = "folder" AND deleted = 0 ORDER BY name COLLATE utf8mb4_general_ci ASC',
            [$u['id'], $parentId]
        );
        $out = array_map(function ($r) use ($u) {
            $has = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE uid = ? AND parent_id = ? AND type = "folder" AND deleted = 0', [$u['id'], (int)$r['id']]);
            return ['id' => (int)$r['id'], 'name' => $r['name'], 'has_child' => $has > 0];
        }, $rows);
        Http::ok($out);
    }

    public function createFolder(): void
    {
        $u = $this->user();
        $parentId = Util::int('parent_id', 0);
        $name = Util::str('name');
        if ($name === '') Http::fail('请输入文件夹名称');
        Http::ok(Node::createFolder($u['id'], $parentId, $name));
    }

    public function rename(): void
    {
        $u = $this->user();
        Http::ok(Node::rename($u['id'], Util::int('id'), Util::str('name')));
    }

    public function move(): void
    {
        $u = $this->user();
        Node::move($u['id'], Util::arr('ids'), Util::int('target_id'));
        Http::ok(null);
    }

    public function copy(): void
    {
        $u = $this->user();
        Node::copy($u['id'], Util::arr('ids'), Util::int('target_id'));
        Http::ok(null);
    }

    public function trash(): void
    {
        $u = $this->user();
        Node::trash($u['id'], Util::arr('ids'));
        Http::ok(null);
    }

    public function trashList(): void
    {
        $u = $this->user();
        Http::ok(Node::trashList($u['id']));
    }

    public function restore(): void
    {
        $u = $this->user();
        Node::restore($u['id'], Util::arr('ids'));
        Http::ok(null);
    }

    public function purge(): void
    {
        $u = $this->user();
        Node::purge($u['id'], Util::arr('ids'));
        Http::ok(null);
    }

    public function clearTrash(): void
    {
        $u = $this->user();
        Node::clearTrash($u['id']);
        Http::ok(null);
    }

    public function star(): void
    {
        $u = $this->user();
        $value = Util::input('value', null);
        $val = $value === null ? null : ((int)$value === 1);
        Http::ok(['star' => Node::star($u['id'], Util::int('id'), $val)]);
    }

    public function info(): void
    {
        $u = $this->user();
        $node = Node::own($u['id'], Util::int('id'));
        $share = Db::fetch('SELECT code,status,expire_at,view_count FROM ' . Db::t('shares') . ' WHERE node_id = ? AND status = 1 ORDER BY id DESC LIMIT 1', [$node['id']]);
        $node['share'] = $share ?: null;
        Http::ok($node);
    }

    public function stat(): void
    {
        $u = $this->user();
        Http::ok([
            'stat' => Node::statUser($u['id']),
            'user' => User::find($u['id']),
        ]);
    }

    /* ---------------- 上传 ---------------- */

    private function checkExt(string $ext): void
    {
        $allow = array_filter(array_map('trim', explode(',', strtolower(Setting::get('allow_ext', '')))));
        $deny = array_filter(array_map('trim', explode(',', strtolower(Setting::get('deny_ext', '')))));
        if (in_array($ext, $deny, true)) throw new ApiException('禁止上传 .' . $ext . ' 类型的文件');
        if ($allow && !in_array($ext, $allow, true)) throw new ApiException('不支持的文件类型：.' . $ext);
    }

    /** 简单上传（multipart 单文件） */
    public function uploadSimple(): void
    {
        $u = $this->user();
        if (empty($_FILES['file'])) Http::fail('没有收到文件数据');
        $parentId = Util::int('parent_id', 0);
        $file = $_FILES['file'];
        $name = Util::safeName($file['name'] ?? 'unnamed');
        $ext = Util::ext($name);
        $this->checkExt($ext);

        if (!empty($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) {
            Http::fail('上传失败，错误码：' . $file['error']);
        }
        $size = (int)($file['size'] ?? 0);
        $max = (int)Setting::get('max_upload_size', '0');
        if ($max > 0 && $size > $max) Http::fail('文件超过单文件上限 ' . Util::size($max));
        if ($size > User::free($u['id'])) Http::fail('存储空间不足');

        $tmp = $file['tmp_name'];
        $hash = hash_file('sha256', $tmp);
        $mime = Util::mimeOf($tmp);
        $storageKey = Node::storageKey($hash, $ext);

        // 同用户内秒传 / 去重
        $exist = Db::fetch('SELECT storage FROM ' . Db::t('nodes') . ' WHERE uid = ? AND hash = ? LIMIT 1', [$u['id'], $hash]);
        if ($exist && is_file(Node::absolute($exist['storage']))) {
            Http::ok(['node' => Node::createFile($u['id'], $parentId, $name, $size, $hash, $mime, $exist['storage']), 'instant' => true]);
        }

        $dest = Node::absolute($storageKey);
        Util::ensureDir(dirname($dest));
        if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
            if (!move_uploaded_file($tmp, $dest)) Http::fail('文件写入失败，请检查 storage 目录权限');
        }
        @chmod($dest, 0644);
        Http::ok(['node' => Node::createFile($u['id'], $parentId, $name, $size, $hash, $mime, $storageKey), 'instant' => false]);
    }

    /** 分片上传：初始化 */
    public function uploadInit(): void
    {
        $u = $this->user();
        $name = Util::safeName(Util::str('name'));
        $size = Util::int('size');
        $hash = strtolower(Util::str('hash'));
        $parentId = Util::int('parent_id', 0);
        $ext = Util::ext($name);
        $this->checkExt($ext);

        $max = (int)Setting::get('max_upload_size', '0');
        if ($max > 0 && $size > $max) Http::fail('文件超过单文件上限 ' . Util::size($max));
        if ($size > User::free($u['id'])) Http::fail('存储空间不足');

        // 秒传
        if ($hash !== '') {
            $exist = Db::fetch('SELECT storage FROM ' . Db::t('nodes') . ' WHERE uid = ? AND hash = ? LIMIT 1', [$u['id'], $hash]);
            if ($exist && is_file(Node::absolute($exist['storage']))) {
                $node = Node::createFile($u['id'], $parentId, $name, $size, $hash, Util::mimeOf(Node::absolute($exist['storage'])), $exist['storage']);
                Http::ok(['instant' => true, 'node' => $node]);
            }
        }

        $token = Util::random(24);
        $chunkSize = (int)Setting::get('chunk_size', (string)(4 * 1048576)) ?: 4 * 1048576;
        $chunks = max(1, (int)ceil($size / $chunkSize));
        $dir = STORAGE_PATH . '/tmp/' . $token;
        Util::ensureDir($dir);
        file_put_contents($dir . '/meta.json', json_encode([
            'uid' => $u['id'], 'parent_id' => $parentId, 'name' => $name, 'size' => $size,
            'hash' => $hash, 'chunks' => $chunks, 'chunk_size' => $chunkSize, 'created' => time(),
        ]));
        Http::ok(['token' => $token, 'chunks' => $chunks, 'chunk_size' => $chunkSize, 'instant' => false]);
    }

    /** 分片上传：写入分片 */
    public function uploadChunk(): void
    {
        $u = $this->user();
        $token = Util::str('token');
        $index = Util::int('index');
        $metaFile = STORAGE_PATH . '/tmp/' . $token . '/meta.json';
        if (!is_file($metaFile)) Http::fail('上传会话不存在', 404);
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if ((int)$meta['uid'] !== $u['id']) Http::fail('无权操作', 403);
        if (empty($_FILES['chunk'])) Http::fail('没有收到分片数据');

        $dir = STORAGE_PATH . '/tmp/' . $token;
        $target = $dir . '/part_' . str_pad((string)$index, 8, '0', STR_PAD_LEFT);
        $tmpName = $_FILES['chunk']['tmp_name'];
        if (!@rename($tmpName, $target) && !@move_uploaded_file($tmpName, $target)) {
            if (!@copy($tmpName, $target)) Http::fail('分片写入失败');
        }
        // 上传限速：按分片大小做粗略限速
        $up = (int)($u['upload_speed'] ?? 0);
        if ($up > 0) {
            $wrote = (int)@filesize($target);
            if ($wrote > 0) usleep((int)($wrote / max(1, $up * 1024) * 1000000));
        }
        Http::ok(['index' => $index]);
    }

    /** 分片上传：合并 */
    public function uploadComplete(): void
    {
        $u = $this->user();
        $token = Util::str('token');
        $baseDir = STORAGE_PATH . '/tmp/' . $token;
        $metaFile = $baseDir . '/meta.json';
        if (!is_file($metaFile)) Http::fail('上传会话不存在', 404);
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if ((int)$meta['uid'] !== $u['id']) Http::fail('无权操作', 403);

        $parts = glob($baseDir . '/part_*') ?: [];
        sort($parts, SORT_STRING);
        if (count($parts) < (int)$meta['chunks']) {
            Http::fail('分片不完整：' . count($parts) . '/' . $meta['chunks'], 400, ['received' => count($parts)]);
        }

        $tmpPath = $baseDir . '/merged.bin';
        $out = fopen($tmpPath, 'wb');
        if (!$out) Http::fail('无法创建临时文件');
        foreach ($parts as $p) {
            $in = fopen($p, 'rb');
            if (!$in) continue;
            while (!feof($in)) fwrite($out, fread($in, 1048576));
            fclose($in);
        }
        fclose($out);

        $realSize = filesize($tmpPath);
        if ($realSize !== (int)$meta['size']) {
            @unlink($tmpPath);
            Http::fail('文件大小不一致，请重新上传');
        }
        $hash = hash_file('sha256', $tmpPath);
        if (!empty($meta['hash']) && strtolower($meta['hash']) !== $hash) {
            @unlink($tmpPath);
            Http::fail('文件校验失败，内容可能已损坏');
        }
        $mime = Util::mimeOf($tmpPath);
        $ext = Util::ext($meta['name']);
        $storageKey = Node::storageKey($hash, $ext);
        $dest = Node::absolute($storageKey);

        if (!is_file($dest)) {
            Util::ensureDir(dirname($dest));
            if (!@rename($tmpPath, $dest)) {
                if (!@copy($tmpPath, $dest)) {
                    @unlink($tmpPath);
                    Http::fail('文件保存失败，请检查 storage 目录权限');
                }
                @unlink($tmpPath);
            }
        } else {
            @unlink($tmpPath);
        }
        @chmod($dest, 0644);
        Util::rmDir($baseDir);

        $node = Node::createFile($u['id'], (int)$meta['parent_id'], $meta['name'], $realSize, $hash, $mime, $storageKey);
        Http::ok(['node' => $node, 'instant' => false]);
    }

    /* ---------------- 下载 / 预览 ---------------- */

    private function nodeForRead(int $id): array
    {
        return Node::own(Auth::id(), $id);
    }

    public function download(): void
    {
        $u = $this->user();
        $ids = Util::arr('ids');
        if (!$ids && Util::input('id')) $ids = [Util::int('id')];
        if (!$ids) Http::fail('请选择要下载的文件');

        $u = User::find($u['id']);
        $speed = (int)$u['download_speed'];

        if (count($ids) === 1) {
            $node = $this->nodeForRead((int)$ids[0]);
            if ($node['type'] === 'folder') {
                $this->zipDownload($u, [(int)$ids[0]]);
            }
            $abs = Node::absolute($node['storage']);
            if (!is_file($abs)) Http::fail('文件已丢失', 404);
            Node::incrDownload((int)$node['id']);
            LogModel::add($u['id'], 'file', 'download', $node['name']);
            Http::streamFile($abs, $node['name'], $node['mime'] ?: 'application/octet-stream', false, $speed);
        }
        $this->zipDownload($u, array_map('intval', $ids));
    }

    private function zipDownload(array $u, array $ids): void
    {
        if (!class_exists('ZipArchive')) Http::fail('服务器未安装 zip 扩展，无法打包下载');
        $nodes = [];
        foreach ($ids as $id) {
            $n = Node::find($id, $u['id']);
            if ($n) $nodes[] = $n;
        }
        if (!$nodes) Http::fail('文件不存在');

        $zipFile = STORAGE_PATH . '/tmp/zip_' . md5($u['id'] . microtime(true)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) Http::fail('无法创建压缩包');
        $zip->setArchiveComment('Created by VortexPan ' . date('Y-m-d H:i:s'));

        $used = [];
        foreach ($nodes as $n) {
            $this->zipAdd($zip, $u['id'], $n, '', $used);
        }
        $zip->close();

        $name = count($nodes) === 1 ? ($nodes[0]['name'] . '.zip') : date('Ymd-His') . '.zip';
        if (count($nodes) === 1) $name = $nodes[0]['name'] . '.zip';
        LogModel::add($u['id'], 'file', 'download_zip', $name);
        register_shutdown_function(function () use ($zipFile) {
            if (is_file($zipFile)) @unlink($zipFile);
        });
        Http::streamFile($zipFile, $name, 'application/zip', false, (int)$u['download_speed']);
    }

    private function zipAdd(ZipArchive $zip, int $uid, array $node, string $prefix, array &$used): void
    {
        $path = $prefix . $node['name'];
        if ($node['type'] === 'folder') {
            $zip->addEmptyDir($this->zipPath($path, $used));
            foreach (Node::children($uid, (int)$node['id'], 'name_asc') as $child) {
                $this->zipAdd($zip, $uid, $child, $path . '/', $used);
            }
            return;
        }
        $abs = Node::absolute($node['storage']);
        if (is_file($abs)) {
            $zip->addFile($abs, $this->zipPath($path, $used));
        }
    }

    private function zipPath(string $path, array &$used): string
    {
        $p = $path;
        $i = 1;
        while (isset($used[$p])) {
            $ext = Util::ext($p);
            $base = $ext !== '' ? mb_substr($p, 0, -(strlen($ext) + 1)) : $p;
            $p = $base . '(' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
            $i++;
        }
        $used[$p] = true;
        return $p;
    }

    public function preview(): void
    {
        $u = $this->user();
        $node = $this->nodeForRead(Util::int('id'));
        if ($node['type'] === 'folder') Http::fail('文件夹不支持预览');
        $abs = Node::absolute($node['storage']);
        if (!is_file($abs)) Http::fail('文件已丢失', 404);
        Node::incrView((int)$node['id']);
        $speed = (int)(User::find($u['id'])['download_speed'] ?? 0);
        $inline = in_array(strtolower($node['ext']), ['php', 'html', 'htm', 'js', 'svg'], true) ? false : true;
        Http::streamFile($abs, $node['name'], $node['mime'] ?: 'application/octet-stream', $inline, $speed);
    }

    public function thumb(): void
    {
        $u = $this->user();
        $node = $this->nodeForRead(Util::int('id'));
        $abs = Node::absolute($node['storage']);
        if (!is_file($abs)) {
            http_response_code(404);
            exit;
        }
        if ($node['kind'] !== 'image' || !function_exists('imagecreatetruecolor')) {
            header('Location: ' . base_url('index.php?_route=api/files/preview&id=' . $node['id']));
            exit;
        }
        $key = md5($node['storage']);
        $cache = STORAGE_PATH . '/thumbs/' . $key[0] . '/' . $key . '.jpg';
        Util::ensureDir(dirname($cache));
        if (!is_file($cache)) {
            $info = @getimagesize($abs);
            if ($info === false) {
                header('Location: ' . base_url('index.php?_route=api/files/preview&id=' . $node['id']));
                exit;
            }
            $maxW = 400;
            $maxH = 400;
            [$w, $h] = [$info[0], $info[1]];
            $scale = min($maxW / max(1, $w), $maxH / max(1, $h), 1);
            $nw = (int)($w * $scale);
            $nh = (int)($h * $scale);
            $src = $this->loadImage($abs, $info[2]);
            if (!$src) {
                header('Location: ' . base_url('index.php?_route=api/files/preview&id=' . $node['id']));
                exit;
            }
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagejpeg($dst, $cache, 82);
            imagedestroy($dst);
            imagedestroy($src);
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($cache));
        header('Cache-Control: public, max-age=604800');
        readfile($cache);
        exit;
    }

    private function loadImage(string $path, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : null,
            default => null,
        };
    }

    /** 文本文件内容（用于在线查看 / 编辑器） */
    public function text(): void
    {
        $u = $this->user();
        $node = $this->nodeForRead(Util::int('id'));
        if ($node['type'] === 'folder') Http::fail('文件夹不支持编辑');
        $abs = Node::absolute($node['storage']);
        if (!is_file($abs)) Http::fail('文件已丢失', 404);
        if ((int)$node['size'] > 2 * 1048576) Http::fail('文件过大，无法在线打开（上限 2MB）');
        $content = file_get_contents($abs);
        Http::ok(['content' => $content, 'name' => $node['name'], 'ext' => $node['ext']]);
    }

    /** 保存文本编辑 */
    public function saveText(): void
    {
        $u = $this->user();
        $node = $this->nodeForRead(Util::int('id'));
        $content = (string)Util::input('content', '');
        $dir = STORAGE_PATH . '/tmp/edit_' . $u['id'];
        Util::ensureDir($dir);
        $tmp = $dir . '/' . Util::random(12);
        file_put_contents($tmp, $content);
        $size = filesize($tmp);
        if ($size > User::free($u['id']) + (int)$node['size']) {
            @unlink($tmp);
            Http::fail('存储空间不足');
        }
        $hash = hash_file('sha256', $tmp);
        $storageKey = Node::storageKey($hash, $node['ext']);
        $dest = Node::absolute($storageKey);
        Util::ensureDir(dirname($dest));
        if (is_file($dest)) @unlink($tmp);
        else @rename($tmp, $dest);

        // 旧物理文件清理
        $old = $node['storage'];
        User::addUsage($u['id'], -(int)$node['size']);
        Db::update('nodes', [
            'storage' => $storageKey, 'hash' => $hash, 'size' => $size, 'updated_at' => Util::now(),
        ], 'id = ?', [$node['id']]);
        User::addUsage($u['id'], $size);
        if ($old !== $storageKey) Node::recycleStorage($old);
        Http::ok(Node::find($node['id']));
    }
}
