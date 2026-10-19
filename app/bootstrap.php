<?php
/**
 * VortexPan 引导文件
 */
defined('PAN_ACCESS') or exit('No direct access');

define('PAN_VERSION', '1.0.0');
define('PAN_NAME', 'VortexPan');
define('PAN_ROOT', dirname(__DIR__));
define('APP_PATH', PAN_ROOT . '/app');
define('STORAGE_PATH', PAN_ROOT . '/storage');
define('CONFIG_FILE', PAN_ROOT . '/config/config.php');

mb_internal_encoding('UTF-8');
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Shanghai');
}

require APP_PATH . '/core/Util.php';
require APP_PATH . '/core/Db.php';
require APP_PATH . '/core/Http.php';
require APP_PATH . '/core/Auth.php';
require APP_PATH . '/core/Schema.php';
require APP_PATH . '/models/User.php';
require APP_PATH . '/models/Node.php';
require APP_PATH . '/models/Share.php';
require APP_PATH . '/models/Setting.php';
require APP_PATH . '/models/LogModel.php';

$config = require CONFIG_FILE;
$GLOBALS['config'] = $config;
Db::init($config['db']);
Auth::boot();
Setting::load();

/** 站点基础 URL */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $https = Util::isHttps();
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
        if (in_array($dir, ['/index', '/index.php', '/\\'], true)) $dir = '';
        $base = $scheme . '://' . $host . $dir . '/';
    }
    return $base . ltrim($path, '/');
}

/** 获取每次请求的 CSRF token */
function csrf_token(): string
{
    return Http::csrfToken();
}
