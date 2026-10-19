<?php
/**
 * VortexPan 安装向导
 * 访问 install.php 按步骤完成安装；安装成功后建议删除本文件或禁止外部访问
 */
define('PAN_ACCESS', true);
define('PAN_ROOT', __DIR__);

date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');

require PAN_ROOT . '/app/core/Util.php';
require PAN_ROOT . '/app/core/Db.php';
require PAN_ROOT . '/app/core/Http.php';
require PAN_ROOT . '/app/core/Schema.php';

define('CONFIG_FILE', PAN_ROOT . '/config/config.php');
define('LOCK_FILE', PAN_ROOT . '/config/installed.lock');

$installed = file_exists(CONFIG_FILE) || file_exists(LOCK_FILE);

// 导出 SQL
if (isset($_GET['action']) && $_GET['action'] === 'sql') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="vortexpan-schema.sql"');
    echo "-- VortexPan database schema\n";
    echo "-- Generated at " . date('Y-m-d H:i:s') . "\n\n";
    echo Schema::exportSql($_GET['prefix'] ?? 'vp_');
    exit;
}

/* ---------- AJAX 安装执行 ---------- */
if (($_POST['act'] ?? '') === 'install') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($installed) throw new Exception('系统已安装，请勿重复安装');
        $secret = bin2hex(random_bytes(16));
        $db = [
            'host' => trim($_POST['db_host'] ?? ''),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'database' => trim($_POST['db_name'] ?? ''),
            'username' => trim($_POST['db_user'] ?? ''),
            'password' => (string)($_POST['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
            'prefix' => trim($_POST['db_prefix'] ?? 'vp_'),
        ];
        $admin = [
            'username' => trim($_POST['admin_user'] ?? ''),
            'password' => (string)($_POST['admin_pass'] ?? ''),
            'email' => trim($_POST['admin_email'] ?? ''),
            'nickname' => trim($_POST['admin_nick'] ?? '') ?: '管理员',
        ];
        if (!$db['host'] || !$db['database'] || !$db['username']) throw new Exception('请填写完整的数据库信息');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $db['database'])) throw new Exception('数据库名只能包含字母、数字与下划线');
        if (!preg_match('/^[A-Za-z0-9_]*$/', $db['prefix'])) throw new Exception('表前缀只能包含字母、数字与下划线');
        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $admin['username'])) throw new Exception('管理员用户名需为 3-20 位字母、数字或下划线');
        if (strlen($admin['password']) < 6) throw new Exception('管理员密码至少 6 位');

        // 1. 连接并选择数据库（不存在则尝试创建）
        $dsnNoDb = 'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?: 3306) . ';charset=utf8mb4';
        $pdo = new PDO($dsnNoDb, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $pdo->exec('USE `' . $db['database'] . '`');

        // 2. 建表
        $sql = Schema::exportSql($db['prefix']);
        foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r", '', $sql)))) as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }

        // 3. 写入配置
        $config = <<<PHP
<?php
/**
 * VortexPan 配置文件（由安装向导生成）
 */
return [
    'installed' => true,
    'secret' => '{$secret}',
    'debug' => '0',
    'db' => [
        'host' => '{$db['host']}',
        'port' => {$db['port']},
        'database' => '{$db['database']}',
        'username' => '{$db['username']}',
        'password' => '{$db['password']}',
        'charset' => 'utf8mb4',
        'prefix' => '{$db['prefix']}',
    ],
];
PHP;

        Db::init($db);
        Schema::seed($admin);
        @file_put_contents(CONFIG_FILE, $config);
        @file_put_contents(LOCK_FILE, date('Y-m-d H:i:s'));

        echo json_encode(['ok' => true, 'msg' => '安装成功']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ---------- 环境检测 ---------- */
$checks = [];
$checks['php'] = [
    'name' => 'PHP 版本 >= 8.0',
    'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'value' => PHP_VERSION,
    'required' => true,
];
$exts = [
    'pdo_mysql' => ['name' => 'PDO MySQL 扩展', 'required' => true],
    'json' => ['name' => 'JSON 扩展', 'required' => true],
    'mbstring' => ['name' => 'mbstring 扩展', 'required' => true],
    'openssl' => ['name' => 'OpenSSL 扩展', 'required' => true],
    'fileinfo' => ['name' => 'fileinfo 扩展（MIME 识别）', 'required' => false],
    'gd' => ['name' => 'GD 扩展（图片缩略图）', 'required' => false],
    'zip' => ['name' => 'Zip 扩展（打包下载）', 'required' => false],
];
foreach ($exts as $ext => $info) {
    $checks[$ext] = [
        'name' => $info['name'],
        'ok' => extension_loaded($ext),
        'value' => extension_loaded($ext) ? '已安装' : '未安装',
        'required' => $info['required'],
    ];
}
$dirs = ['config' => '配置文件目录 config/', 'storage' => '文件存储目录 storage/', 'storage/files' => '物理文件目录 storage/files/', 'storage/tmp' => '临时目录 storage/tmp/'];
foreach ($dirs as $dir => $label) {
    $path = PAN_ROOT . '/' . $dir;
    if (!is_dir($path)) @mkdir($path, 0755, true);
    $checks['dir_' . $dir] = [
        'name' => '可写：' . $label,
        'ok' => is_dir($path) && is_writable($path),
        'value' => is_dir($path) ? (is_writable($path) ? '可写' : '不可写') : '不存在',
        'required' => true,
    ];
}
$blocked = !$installed && array_filter($checks, fn($c) => $c['required'] && !$c['ok']);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/';
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VortexPan 安装向导</title>
<link rel="stylesheet" href="<?= e($base) ?>assets/css/app.css">
<link rel="icon" type="image/svg+xml" href="<?= e($base) ?>favicon.svg">
<style>
.install-page{min-height:100vh;overflow:auto;background:radial-gradient(1200px 600px at 20% 0%,#e9ebff 0%,transparent 55%),radial-gradient(900px 500px at 100% 100%,#e6f0ff 0%,transparent 50%),var(--bg);padding:40px 20px}
.install-wrap{width:760px;max-width:100%;margin:0 auto}
.install-card{background:var(--surface);border-radius:22px;box-shadow:var(--shadow-2);overflow:hidden;margin-bottom:16px}
.install-head{padding:24px 30px;background:linear-gradient(120deg,#5b5bd6,#8f8ff0);color:#fff;display:flex;align-items:center;gap:14px}
.install-head h1{margin:0;font-size:20px;font-weight:650}
.install-body{padding:24px 30px 28px}
.steps{display:flex;gap:10px;margin-bottom:22px}
.step{flex:1;height:4px;border-radius:99px;background:var(--surface-3)}
.step.on{background:var(--primary)}
.check-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px dashed var(--border-soft);font-size:13.5px}
.check-row .dot{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;flex:none;font-size:11px;color:#fff}
.dot.ok{background:var(--success)}.dot.no{background:var(--danger)}.dot.warn{background:var(--warn)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:640px){.grid2{grid-template-columns:1fr}}
</style>
</head>
<body class="install-page">
<div class="install-wrap">
  <div class="install-card">
    <div class="install-head">
      <div style="width:44px;height:44px;border-radius:13px;background:rgba(255,255,255,.22);display:grid;place-items:center">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 17a4 4 0 0 0-.9-7.9A6 6 0 0 0 6 10.3A3.5 3.5 0 0 0 6.5 17z"/></svg>
      </div>
      <div>
        <h1>VortexPan 安装向导</h1>
        <div style="opacity:.9;font-size:13px">几分钟即可搭建属于自己的私有网盘</div>
      </div>
    </div>
    <div class="install-body">
      <?php if ($installed): ?>
        <div style="text-align:center;padding:20px 0">
          <div style="color:var(--success)">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.5 2.5L16 9.5"/></svg>
          </div>
          <h2 style="margin:14px 0 6px">系统已安装完成</h2>
          <p class="muted">如需重新安装，请先删除 <code class="mono">config/config.php</code> 与 <code class="mono">config/installed.lock</code>，并清空对应数据表。</p>
          <p style="margin-top:18px"><a class="btn btn-primary btn-lg" href="<?= e($base) ?>index.php">进入网盘</a></p>
          <p class="small muted" style="margin-top:12px">出于安全考虑，建议将 install.php 重命名或删除。</p>
        </div>
      <?php else: ?>
        <div class="steps"><div class="step on"></div><div class="step on"></div><div class="step" id="st3"></div></div>

        <h3 style="margin:0 0 4px">第一步：环境检测</h3>
        <div style="margin:10px 0 20px">
          <?php foreach ($checks as $c): ?>
            <div class="check-row">
              <span class="dot <?= $c['ok'] ? 'ok' : ($c['required'] ? 'no' : 'warn') ?>"><?= $c['ok'] ? '✓' : ($c['required'] ? '!' : '!') ?></span>
              <span><?= e($c['name']) ?></span>
              <span class="spacer"></span>
              <span class="small muted"><?= e($c['value']) ?><?= $c['ok'] ? '' : ($c['required'] ? '（必需）' : '（可选，影响部分功能）') ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($blocked): ?>
          <div class="chip chip-danger" style="height:auto;padding:10px 12px;white-space:normal">存在必需项未通过，请先修正服务器环境后刷新本页面。</div>
        <?php else: ?>
          <h3 style="margin:22px 0 12px">第二步：数据库与管理员</h3>
          <form id="f">
            <div class="grid2">
              <div class="field"><label class="field-label">数据库主机</label><input class="input" name="db_host" value="127.0.0.1" required></div>
              <div class="field"><label class="field-label">端口</label><input class="input" name="db_port" value="3306" required></div>
              <div class="field"><label class="field-label">数据库名</label><input class="input" name="db_name" value="vortexpan" required placeholder="不存在将自动创建"></div>
              <div class="field"><label class="field-label">表前缀</label><input class="input" name="db_prefix" value="vp_"></div>
              <div class="field"><label class="field-label">数据库用户名</label><input class="input" name="db_user" value="root" required></div>
              <div class="field"><label class="field-label">数据库密码</label><input class="input" name="db_pass" type="password" autocomplete="new-password"></div>
            </div>
            <div class="divider"></div>
            <div class="grid2">
              <div class="field"><label class="field-label">管理员用户名</label><input class="input" name="admin_user" value="admin" required></div>
              <div class="field"><label class="field-label">管理员昵称</label><input class="input" name="admin_nick" value="管理员"></div>
              <div class="field"><label class="field-label">管理员密码</label><input class="input" name="admin_pass" type="password" required placeholder="至少 6 位"></div>
              <div class="field"><label class="field-label">管理员邮箱</label><input class="input" name="admin_email" placeholder="可选"></div>
            </div>
            <div style="margin-top:18px;display:flex;gap:10px;align-items:center">
              <button class="btn btn-primary btn-lg" id="btn" type="submit">开始安装</button>
              <a class="btn" href="?action=sql&prefix=vp_" target="_blank">下载建表 SQL</a>
              <span class="small muted">若服务器禁止 PHP 建库，可手动导入 SQL</span>
            </div>
            <div id="msg" style="margin-top:14px"></div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="share-footer" style="text-align:center;color:var(--text-3);font-size:12.5px">VortexPan v1.0.0 · PHP + MySQL 单文件入口架构</div>
</div>
<script>
<?php if (!$installed && !$blocked): ?>
document.getElementById('f').addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const btn = document.getElementById('btn');
  const msg = document.getElementById('msg');
  btn.disabled = true; btn.textContent = '安装中…';
  msg.innerHTML = '<div class="small muted">正在写入数据库，请稍候…</div>';
  const fd = new FormData(ev.target);
  fd.append('act', 'install');
  try {
    const res = await fetch(location.href, { method: 'POST', body: fd });
    const j = await res.json();
    if (!j.ok) throw new Error(j.error || '安装失败');
    document.getElementById('st3').classList.add('on');
    msg.innerHTML = '<div class="chip chip-success" style="height:auto;padding:10px 12px;white-space:normal">安装成功！正在跳转…</div>';
    setTimeout(() => location.href = '<?= e($base) ?>index.php', 1200);
  } catch (err) {
    btn.disabled = false; btn.textContent = '开始安装';
    msg.innerHTML = '<div class="chip chip-danger" style="height:auto;padding:10px 12px;white-space:normal">' + (err.message || '') + '</div>';
  }
});
<?php endif; ?>
</script>
</body>
</html>
