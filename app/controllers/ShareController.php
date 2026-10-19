<?php
defined('PAN_ACCESS') or exit('No direct access');

class ShareController
{
    public function create(): void
    {
        $u = Auth::requireLogin();
        if (!$u['can_share']) Http::fail('当前用户组无分享权限');
        $nodeId = Util::int('node_id');
        if (!$nodeId) Http::fail('请选择要分享的文件');

        $period = Util::str('period', 'forever');
        $expire = null;
        $map = ['1d' => 1, '7d' => 7, '30d' => 30, '365d' => 365];
        if (isset($map[$period])) $expire = date('Y-m-d H:i:s', time() + $map[$period] * 86400);
        elseif ($period === 'custom') {
            $at = Util::str('expire_at');
            if ($at !== '') $expire = date('Y-m-d H:i:s', strtotime($at));
        }

        $share = Share::create($u['id'], $nodeId, [
            'title' => Util::str('title'),
            'password' => Util::str('password'),
            'pickcode' => strtoupper(Util::str('pickcode')),
            'expire_at' => $expire,
            'allow_preview' => Util::input('allow_preview', 1),
            'allow_download' => Util::input('allow_download', 1),
        ]);
        Http::ok(array_merge($share, ['url' => base_url('index.php?_route=/s/' . $share['code'])]));
    }

    public function my(): void
    {
        $u = Auth::requireLogin();
        $rows = Share::myList($u['id']);
        foreach ($rows as &$r) {
            $r['url'] = base_url('index.php?_route=/s/' . $r['code']);
        }
        Http::ok($rows);
    }

    public function update(): void
    {
        $u = Auth::requireLogin();
        Http::ok(Share::update($u['id'], Util::int('id'), [
            'title' => Util::input('title'),
            'status' => Util::input('status'),
            'expire_at' => Util::input('expire_at'),
            'password' => Util::input('password'),
            'pickcode' => Util::input('pickcode'),
            'allow_download' => Util::input('allow_download'),
            'allow_preview' => Util::input('allow_preview'),
        ]));
    }

    public function delete(): void
    {
        $u = Auth::requireLogin();
        Share::remove($u['id'], Util::int('id'));
        Http::ok(null);
    }

    /** 取消某个节点的分享（便于前端切换） */
    public function byNode(): void
    {
        $u = Auth::requireLogin();
        $row = Db::fetch('SELECT * FROM ' . Db::t('shares') . ' WHERE node_id = ? AND uid = ? AND status = 1 ORDER BY id DESC LIMIT 1', [Util::int('node_id'), $u['id']]);
        Http::ok($row ? array_merge(Share::normalize($row), ['url' => base_url('index.php?_route=/s/' . $row['code'])]) : null);
    }
}
