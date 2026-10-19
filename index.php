<?php
/**
 * VortexPan 前端控制器
 * 所有页面与 API 均由此文件分发
 */
define('PAN_ACCESS', true);

if (!is_file(__DIR__ . '/config/config.php')) {
    header('Location: install.php');
    exit('系统尚未安装，请访问 install.php 完成安装。');
}

require __DIR__ . '/app/bootstrap.php';

/**
 * 解析路由
 */
function resolve_route(): string
{
    $path = (string)($_GET['_route'] ?? $_SERVER['PATH_INFO'] ?? '');
    if ($path === '') {
        $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = str_replace('\\', '/', $uri);
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir = rtrim(dirname($script), '/');
        if ($dir !== '' && $dir !== '/' && str_starts_with($uri, $dir . '/')) {
            $uri = substr($uri, strlen($dir));
        }
        $base = basename($script);
        if ($base !== '' && str_starts_with($uri, '/' . $base)) {
            $uri = substr($uri, strlen($base) + 1);
        }
        $path = $uri;
    }
    return trim($path, '/');
}

$route = resolve_route();
$method = Http::method();

/* -------------------- API 分发 -------------------- */
if (str_starts_with($route, 'api/')) {
    $parts = array_values(array_filter(explode('/', substr($route, 4))));
    $module = $parts[0] ?? '';
    $action = $parts[1] ?? '';

    require APP_PATH . '/controllers/AuthController.php';
    require APP_PATH . '/controllers/FileController.php';
    require APP_PATH . '/controllers/ShareController.php';
    require APP_PATH . '/controllers/GuestController.php';
    require APP_PATH . '/controllers/AdminController.php';

    try {
        // CSRF：除 GET 与分享提取页面的公开接口外均校验
        if ($method !== 'GET') {
            Http::verifyCsrf();
        }
        $map = [
            'auth/login' => [AuthController::class, 'login'],
            'auth/register' => [AuthController::class, 'register'],
            'auth/logout' => [AuthController::class, 'logout'],
            'auth/me' => [AuthController::class, 'me'],
            'auth/profile' => [AuthController::class, 'profile'],
            'auth/password' => [AuthController::class, 'password'],

            'files/list' => [FileController::class, 'list'],
            'files/tree' => [FileController::class, 'tree'],
            'files/folder' => [FileController::class, 'createFolder'],
            'files/rename' => [FileController::class, 'rename'],
            'files/move' => [FileController::class, 'move'],
            'files/copy' => [FileController::class, 'copy'],
            'files/trash' => [FileController::class, 'trash'],
            'files/trash-list' => [FileController::class, 'trashList'],
            'files/restore' => [FileController::class, 'restore'],
            'files/purge' => [FileController::class, 'purge'],
            'files/clear-trash' => [FileController::class, 'clearTrash'],
            'files/star' => [FileController::class, 'star'],
            'files/info' => [FileController::class, 'info'],
            'files/stat' => [FileController::class, 'stat'],
            'files/upload' => [FileController::class, 'uploadSimple'],
            'files/upload-init' => [FileController::class, 'uploadInit'],
            'files/upload-chunk' => [FileController::class, 'uploadChunk'],
            'files/upload-complete' => [FileController::class, 'uploadComplete'],
            'files/download' => [FileController::class, 'download'],
            'files/preview' => [FileController::class, 'preview'],
            'files/thumb' => [FileController::class, 'thumb'],
            'files/text' => [FileController::class, 'text'],
            'files/text-save' => [FileController::class, 'saveText'],

            'share/create' => [ShareController::class, 'create'],
            'share/my' => [ShareController::class, 'my'],
            'share/update' => [ShareController::class, 'update'],
            'share/delete' => [ShareController::class, 'delete'],
            'share/by-node' => [ShareController::class, 'byNode'],

            'guest/info' => [GuestController::class, 'info'],
            'guest/unlock' => [GuestController::class, 'unlock'],
            'guest/list' => [GuestController::class, 'list'],
            'guest/download' => [GuestController::class, 'download'],
            'guest/preview' => [GuestController::class, 'preview'],
            'guest/save' => [GuestController::class, 'save'],

            'admin/stats' => [AdminController::class, 'stats'],
            'admin/users' => [AdminController::class, 'users'],
            'admin/user-save' => [AdminController::class, 'userSave'],
            'admin/user-delete' => [AdminController::class, 'userDelete'],
            'admin/nodes' => [AdminController::class, 'nodes'],
            'admin/node-delete' => [AdminController::class, 'nodeDelete'],
            'admin/shares' => [AdminController::class, 'shares'],
            'admin/share-delete' => [AdminController::class, 'shareDelete'],
            'admin/groups' => [AdminController::class, 'groups'],
            'admin/group-save' => [AdminController::class, 'groupSave'],
            'admin/group-delete' => [AdminController::class, 'groupDelete'],
            'admin/settings' => [AdminController::class, 'settings'],
            'admin/setting-save' => [AdminController::class, 'settingSave'],
            'admin/logs' => [AdminController::class, 'logs'],
            'admin/log-clear' => [AdminController::class, 'logClear'],
        ];

        $key = $module . '/' . $action;
        if (!isset($map[$key])) Http::fail('接口不存在：' . $key, 404);
        [$class, $fn] = $map[$key];
        (new $class())->{$fn}();
        Http::fail('未返回有效响应', 500);
    } catch (ApiException $e) {
        Http::json(array_merge(['ok' => false], $e->payload), $e->getCode() ?: 400);
    } catch (Throwable $e) {
        if (($GLOBALS['config']['debug'] ?? '0') === '1') {
            Http::fail('[DEBUG] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500, ['trace' => $e->getTraceAsString()]);
        }
        Http::fail('服务器内部错误：' . $e->getMessage(), 500);
    }
}

/* -------------------- 页面 -------------------- */

function shell(string $mode, array $extra = [])
{
    $title = Setting::get('site_name', 'VortexPan');
    $user = Auth::isLogin() ? Auth::$user : null;
    $boot = [
        'mode' => $mode,
        'csrf' => Http::csrfToken(),
        'user' => $user,
        'base' => base_url(),
        'index' => base_url('index.php'),
        'version' => PAN_VERSION,
        'site' => [
            'name' => $title,
            'desc' => Setting::get('site_desc', ''),
            'register_open' => Setting::bool('register_open'),
            'max_upload_size' => (int)Setting::get('max_upload_size', '536870912'),
            'chunk_size' => (int)Setting::get('chunk_size', '4194304'),
            'footer' => Setting::get('footer', ''),
            'allow_ext' => Setting::get('allow_ext', ''),
        ],
    ];
    $boot = array_merge($boot, $extra);
    $js = match ($mode) {
        'admin' => 'assets/js/admin.js',
        'share' => 'assets/js/share.js',
        default => 'assets/js/app.js',
    };
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    echo '<!doctype html>' . "\n";
    echo '<html lang="zh-CN" data-theme="light">' . "\n";
    echo '<head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
    echo '<meta name="renderer" content="webkit">';
    echo '<title>' . Util::e($title) . '</title>';
    echo '<link rel="icon" type="image/svg+xml" href="' . base_url('favicon.svg') . '">';
    echo '<link rel="stylesheet" href="' . base_url('assets/css/app.css') . '">';
    echo '<script>window.PAN=' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
    echo '</head><body class="mode-' . $mode . '">';
    echo '<div id="app" class="boot-loading"><div class="boot-spinner"></div></div>';
    echo '<div id="layer-root"></div><div id="toast-root"></div>';
    echo '<script src="' . base_url('assets/js/core.js') . '"></script>';
    echo '<script src="' . base_url($js) . '"></script>';
    echo '</body></html>';
    exit;
}

if ($route === 'install') {
    Http::redirect(base_url('install.php'));
}

// 分享短链：/s/{code}
if (preg_match('#^s/([A-Za-z0-9]+)$#', $route, $m)) {
    shell('share', ['code' => $m[1]]);
}
if ($route === 'admin' || str_starts_with($route, 'admin/')) {
    shell('admin');
}
shell('app', ['route' => $route]);
