<?php
defined('PAN_ACCESS') or exit('No direct access');

class AdminController
{
    private array $admin;

    public function __construct()
    {
        $this->admin = Auth::requireAdmin();
    }

    public function stats(): void
    {
        $users = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('users'));
        $files = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE type="file" AND deleted=0');
        $folders = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE type="folder" AND deleted=0');
        $shares = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('shares') . ' WHERE status=1');
        $used = (int)Db::column('SELECT IFNULL(SUM(used),0) FROM ' . Db::t('users'));
        $totalSize = (int)Db::column('SELECT IFNULL(SUM(size),0) FROM ' . Db::t('nodes') . ' WHERE type="file"');
        $today = date('Y-m-d');
        $todayFiles = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE type="file" AND created_at >= ?', [$today . ' 00:00:00']);
        $todayUsers = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('users') . ' WHERE created_at >= ?', [$today . ' 00:00:00']);

        $recent = Db::fetchAll('SELECT * FROM ' . Db::t('logs') . ' ORDER BY id DESC LIMIT 8');
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $c = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' WHERE type="file" AND created_at >= ? AND created_at < ?', [$day . ' 00:00:00', date('Y-m-d', strtotime('-' . ($i - 1) . ' days')) . ' 00:00:00']);
            $trend[] = ['day' => date('m-d', strtotime($day)), 'count' => $c];
        }
        Http::ok([
            'users' => $users, 'files' => $files, 'folders' => $folders, 'shares' => $shares,
            'used' => $used, 'used_text' => Util::size($used),
            'total_size' => $totalSize, 'total_size_text' => Util::size($totalSize),
            'today_files' => $todayFiles, 'today_users' => $todayUsers,
            'recent' => $recent, 'trend' => $trend,
            'storage_free' => disk_free_space(STORAGE_PATH) ?: 0,
            'storage_free_text' => Util::size(disk_free_space(STORAGE_PATH) ?: 0),
        ]);
    }

    public function users(): void
    {
        $page = max(1, Util::int('page', 1));
        $size = min(100, max(5, Util::int('size', 20)));
        $kw = Util::str('keyword', '');
        $where = '1';
        $params = [];
        if ($kw !== '') {
            $where .= ' AND (u.username LIKE ? OR u.email LIKE ? OR u.nickname LIKE ?)';
            $params[] = "%$kw%";
            $params[] = "%$kw%";
            $params[] = "%$kw%";
        }
        $total = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('users') . ' u WHERE ' . $where, $params);
        $rows = Db::fetchAll(
            'SELECT u.*, g.name AS group_name, g.is_admin AS g_is_admin FROM ' . Db::t('users') . ' u
             LEFT JOIN ' . Db::t('groups') . ' g ON u.group_id = g.id
             WHERE ' . $where . ' ORDER BY u.id DESC LIMIT ' . (int)(($page - 1) * $size) . ',' . (int)$size,
            $params
        );
        $users = array_map([User::class, 'normalize'], $rows);
        Http::ok(['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $users]);
    }

    public function userSave(): void
    {
        $id = Util::int('id');
        $data = [];
        $username = Util::str('username');
        if ($id) {
            if ($username !== '') {
                $exists = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('users') . ' WHERE username = ? AND id <> ?', [$username, $id]);
                if ($exists) Http::fail('用户名已存在');
                $data['username'] = $username;
            }
            foreach (['nickname', 'email', 'phone'] as $f) {
                $v = Util::str($f);
                if ($v !== '') $data[$f] = $v;
            }
            if (Util::input('group_id', null) !== null) $data['group_id'] = Util::int('group_id');
            if (Util::input('status', null) !== null) $data['status'] = Util::int('status') ? 1 : 0;
            if (Util::input('quota', null) !== null) $data['quota'] = max(0, Util::int('quota'));
            if (Util::input('upload_speed', null) !== null) $data['upload_speed'] = max(0, Util::int('upload_speed'));
            if (Util::input('download_speed', null) !== null) $data['download_speed'] = max(0, Util::int('download_speed'));
            $pw = (string)Util::input('password', '');
            if ($pw !== '') {
                if (strlen($pw) < 6) Http::fail('密码至少 6 位');
                $data['password'] = password_hash($pw, PASSWORD_DEFAULT);
            }
            if ($data) {
                $data['updated_at'] = Util::now();
                Db::update('users', $data, 'id = ?', [$id]);
            }
            LogModel::add($this->admin['id'], 'admin', 'user_edit', (string)$id);
            Http::ok(User::find($id));
        }

        if ($username === '') Http::fail('请填写用户名');
        if (User::byName($username)) Http::fail('用户名已存在');
        $pw = (string)Util::input('password', '');
        if (strlen($pw) < 6) Http::fail('密码至少 6 位');
        $uid = User::create([
            'group_id' => Util::int('group_id') ?: 1,
            'username' => $username,
            'email' => Util::str('email'),
            'password' => $pw,
            'nickname' => Util::str('nickname') ?: $username,
            'status' => Util::int('status', 1),
        ]);
        if (Util::input('quota', null) !== null) {
            Db::update('users', ['quota' => max(0, Util::int('quota'))], 'id = ?', [$uid]);
        }
        LogModel::add($this->admin['id'], 'admin', 'user_add', $username);
        Http::ok(User::find($uid));
    }

    public function userDelete(): void
    {
        $id = Util::int('id');
        if ($id === $this->admin['id']) Http::fail('不能删除自己');
        $rows = Db::fetchAll('SELECT id FROM ' . Db::t('nodes') . ' WHERE uid = ?', [$id]);
        Node::destroyNodes($id, array_map(fn($r) => (int)$r['id'], $rows));
        Db::delete('shares', 'uid = ?', [$id]);
        Db::delete('tokens', 'user_id = ?', [$id]);
        Db::delete('users', 'id = ?', [$id]);
        LogModel::add($this->admin['id'], 'admin', 'user_delete', (string)$id);
        Http::ok(null);
    }

    public function nodes(): void
    {
        $page = max(1, Util::int('page', 1));
        $size = min(100, max(5, Util::int('size', 20)));
        $kw = Util::str('keyword', '');
        $uid = Util::int('uid', 0);
        $where = 'n.type = "file"';
        $params = [];
        if ($kw !== '') {
            $where .= ' AND n.name LIKE ?';
            $params[] = "%$kw%";
        }
        if ($uid) {
            $where .= ' AND n.uid = ?';
            $params[] = $uid;
        }
        $total = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('nodes') . ' n WHERE ' . $where, $params);
        $rows = Db::fetchAll(
            'SELECT n.*, u.username FROM ' . Db::t('nodes') . ' n LEFT JOIN ' . Db::t('users') . ' u ON n.uid = u.id
             WHERE ' . $where . ' ORDER BY n.id DESC LIMIT ' . (int)(($page - 1) * $size) . ',' . (int)$size,
            $params
        );
        foreach ($rows as &$r) {
            $r = Node::normalize($r);
        }
        Http::ok(['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows]);
    }

    public function nodeDelete(): void
    {
        $ids = Util::arr('ids');
        if (!$ids && Util::input('id')) $ids = [Util::int('id')];
        if (!$ids) Http::fail('请选择文件');
        foreach ($ids as $id) {
            $row = Db::fetch('SELECT uid FROM ' . Db::t('nodes') . ' WHERE id = ?', [(int)$id]);
            if ($row) Node::destroyNodes((int)$row['uid'], [(int)$id]);
        }
        LogModel::add($this->admin['id'], 'admin', 'node_delete', implode(',', array_map('strval', $ids)));
        Http::ok(null);
    }

    public function shares(): void
    {
        $page = max(1, Util::int('page', 1));
        $size = min(100, max(5, Util::int('size', 20)));
        $kw = Util::str('keyword', '');
        $where = '1';
        $params = [];
        if ($kw !== '') {
            $where .= ' AND (s.code LIKE ? OR s.title LIKE ?)';
            $params[] = "%$kw%";
            $params[] = "%$kw%";
        }
        $total = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('shares') . ' s WHERE ' . $where, $params);
        $rows = Db::fetchAll(
            'SELECT s.*, n.name, n.type, n.ext, u.username FROM ' . Db::t('shares') . ' s
             LEFT JOIN ' . Db::t('nodes') . ' n ON s.node_id = n.id
             LEFT JOIN ' . Db::t('users') . ' u ON s.uid = u.id
             WHERE ' . $where . ' ORDER BY s.id DESC LIMIT ' . (int)(($page - 1) * $size) . ',' . (int)$size,
            $params
        );
        foreach ($rows as &$r) {
            $r['url'] = base_url('index.php?_route=/s/' . $r['code']);
            $r['expire_text'] = empty($r['expire_at']) ? '永久' : $r['expire_at'];
            unset($r['password']);
        }
        Http::ok(['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows]);
    }

    public function shareDelete(): void
    {
        $id = Util::int('id');
        Db::delete('shares', 'id = ?', [$id]);
        LogModel::add($this->admin['id'], 'admin', 'share_delete', (string)$id);
        Http::ok(null);
    }

    public function groups(): void
    {
        Http::ok(Db::fetchAll('SELECT g.*, (SELECT COUNT(*) FROM ' . Db::t('users') . ' u WHERE u.group_id = g.id) AS user_count FROM ' . Db::t('groups') . ' g ORDER BY sort ASC, id ASC'));
    }

    public function groupSave(): void
    {
        $id = Util::int('id');
        $data = [
            'name' => Util::str('name'),
            'quota' => max(0, Util::int('quota')),
            'upload_speed' => max(0, Util::int('upload_speed')),
            'download_speed' => max(0, Util::int('download_speed')),
            'max_filesize' => max(0, Util::int('max_filesize')),
            'can_share' => Util::int('can_share') ? 1 : 0,
            'is_admin' => Util::int('is_admin') ? 1 : 0,
            'sort' => Util::int('sort'),
            'remark' => Util::str('remark'),
        ];
        if ($data['name'] === '') Http::fail('请填写组名');
        if (!$id) {
            $data['is_default'] = 0;
            Db::insert('groups', $data);
        } else {
            Db::update('groups', $data, 'id = ?', [$id]);
        }
        LogModel::add($this->admin['id'], 'admin', 'group_save', $data['name']);
        Http::ok(null);
    }

    public function groupDelete(): void
    {
        $id = Util::int('id');
        if ($id === 1) Http::fail('默认用户组不可删除');
        $count = (int)Db::column('SELECT COUNT(*) FROM ' . Db::t('users') . ' WHERE group_id = ?', [$id]);
        if ($count > 0) Http::fail('该组下还有 ' . $count . ' 个用户，请先转移');
        Db::delete('groups', 'id = ?', [$id]);
        LogModel::add($this->admin['id'], 'admin', 'group_delete', (string)$id);
        Http::ok(null);
    }

    public function settings(): void
    {
        Http::ok(Setting::all());
    }

    public function settingSave(): void
    {
        $data = Util::arr('data');
        foreach ($data as $k => $v) {
            if (is_scalar($v)) Setting::set((string)$k, (string)$v);
        }
        LogModel::add($this->admin['id'], 'admin', 'setting_save', '');
        Http::ok(Setting::all());
    }

    public function logs(): void
    {
        Http::ok(LogModel::page(Util::int('page', 1), Util::int('size', 20), Util::str('keyword', '')));
    }

    public function logClear(): void
    {
        LogModel::clear(Util::int('days', 0) ?: null);
        Http::ok(null);
    }
}
