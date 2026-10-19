<?php
defined('PAN_ACCESS') or exit('No direct access');

class AuthController
{
    public function login(): void
    {
        $username = Util::str('username');
        $password = (string)Util::input('password', '');
        $remember = (bool)Util::input('remember', false);
        if ($username === '' || $password === '') Http::fail('请输入用户名和密码');

        $row = User::byName($username);
        if (!$row || !password_verify($password, $row['password'])) {
            LogModel::add(0, 'auth', 'login_fail', $username);
            Http::fail('用户名或密码错误', 401);
        }
        if ((int)$row['status'] !== 1) Http::fail('该账号已被禁用', 403);

        Auth::login($row, $remember);
        User::touchLogin((int)$row['id']);
        LogModel::add((int)$row['id'], 'auth', 'login', $username);
        Http::ok(User::find((int)$row['id']));
    }

    public function register(): void
    {
        if (!Setting::bool('register_open')) Http::fail('站点已关闭注册');
        $username = Util::str('username');
        $password = (string)Util::input('password', '');
        $confirm = (string)Util::input('password_confirm', '');
        $email = Util::str('email');

        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) Http::fail('用户名需为 3-20 位字母、数字或下划线');
        if (strlen($password) < 6) Http::fail('密码至少 6 位');
        if ($confirm !== '' && $confirm !== $password) Http::fail('两次输入的密码不一致');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) Http::fail('邮箱格式不正确');
        if (User::byName($username)) Http::fail('用户名已被占用');

        $gid = (int)Setting::get('default_group', '1') ?: 1;
        $uid = User::create([
            'group_id' => $gid,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'nickname' => $username,
        ]);
        $user = User::find($uid);
        Auth::login(['id' => $uid]);
        LogModel::add($uid, 'auth', 'register', $username);
        Http::ok($user);
    }

    public function logout(): void
    {
        Auth::logout();
        Http::ok(null);
    }

    public function me(): void
    {
        if (!Auth::isLogin()) Http::fail('未登录', 401, ['need_login' => true]);
        Http::ok(User::find(Auth::id()));
    }

    public function profile(): void
    {
        $u = Auth::requireLogin();
        $data = [];
        $nickname = Util::str('nickname');
        $email = Util::str('email');
        $phone = Util::str('phone');
        if ($nickname !== '') $data['nickname'] = $nickname;
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::fail('邮箱格式不正确');
            $data['email'] = $email;
        }
        if ($phone !== '') $data['phone'] = $phone;
        $avatar = Util::str('avatar');
        if ($avatar !== '') $data['avatar'] = $avatar;
        if ($data) {
            $data['updated_at'] = Util::now();
            Db::update('users', $data, 'id = ?', [$u['id']]);
        }
        Http::ok(User::find(Auth::id()));
    }

    public function password(): void
    {
        $u = Auth::requireLogin();
        $old = (string)Util::input('old_password', '');
        $new = (string)Util::input('new_password', '');
        if (strlen($new) < 6) Http::fail('新密码至少 6 位');
        $row = Db::fetch('SELECT password FROM ' . Db::t('users') . ' WHERE id = ?', [$u['id']]);
        if (!$row || !password_verify($old, $row['password'])) Http::fail('原密码不正确');
        Db::update('users', ['password' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => Util::now()], 'id = ?', [$u['id']]);
        LogModel::add($u['id'], 'auth', 'password', '');
        Http::ok(null);
    }
}
