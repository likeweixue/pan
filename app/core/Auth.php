<?php
/**
 * 鉴权与会话
 */
defined('PAN_ACCESS') or exit('No direct access');

class Auth
{
    public static ?array $user = null;

    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $params = [
                'httponly' => true,
                'samesite' => 'Lax',
                'path' => '/',
                'secure' => Util::isHttps(),
                'lifetime' => 86400 * 30,
            ];
            session_set_cookie_params($params);
            session_name('vp_sid');
            session_start();
        }
        // 记住我（长期令牌）
        if (empty($_SESSION['uid']) && !empty($_COOKIE['vp_remember'])) {
            self::loginByRemember($_COOKIE['vp_remember']);
        }
        self::$user = null;
        if (!empty($_SESSION['uid'])) {
            self::$user = User::find((int)$_SESSION['uid']);
            if (self::$user && (int)self::$user['status'] !== 1) {
                self::logout();
            }
        }
    }

    private static function loginByRemember(string $raw): void
    {
        $parts = explode(':', $raw, 2);
        if (count($parts) !== 2) return;
        [$uid, $token] = $parts;
        $hash = hash('sha256', $token);
        $row = Db::fetch('SELECT * FROM ' . Db::t('tokens') . ' WHERE user_id = ? AND token_hash = ? AND expire_at > NOW()', [(int)$uid, $hash]);
        if ($row) {
            $_SESSION['uid'] = (int)$uid;
        }
    }

    public static function id(): int
    {
        return (int)(self::$user['id'] ?? 0);
    }

    public static function isLogin(): bool
    {
        return self::$user !== null;
    }

    public static function isAdmin(): bool
    {
        if (!self::$user) return false;
        return (int)(self::$user['is_admin'] ?? 0) === 1;
    }

    public static function requireLogin(): array
    {
        if (!self::$user) {
            Http::fail('请先登录', 401, ['need_login' => true]);
        }
        return self::$user;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireLogin();
        if (!self::isAdmin()) {
            Http::fail('没有权限访问', 403);
        }
        return $u;
    }

    public static function login(array $user, bool $remember = false): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        self::$user = User::find((int)$user['id']);
        if ($remember) {
            $token = bin2hex(random_bytes(24));
            $hash = hash('sha256', $token);
            $expire = date('Y-m-d H:i:s', time() + 86400 * 30);
            Db::insert('tokens', [
                'user_id' => (int)$user['id'],
                'token_hash' => $hash,
                'expire_at' => $expire,
                'created_at' => Util::now(),
            ]);
            setcookie('vp_remember', $user['id'] . ':' . $token, [
                'expires' => time() + 86400 * 30,
                'httponly' => true,
                'samesite' => 'Lax',
                'path' => '/',
                'secure' => Util::isHttps(),
            ]);
        }
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['uid'])) {
            Db::delete('tokens', 'user_id = ?', [(int)$_SESSION['uid']]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        setcookie('vp_remember', '', time() - 3600, '/');
        self::$user = null;
    }
}
