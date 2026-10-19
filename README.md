# VortexPan

一个轻量、好看、够快的 **PHP + MySQL 私有网盘系统**。零依赖、无 Composer、单入口架构，丢到服务器就能跑。

- 运行环境：PHP ≥ 8.0 + MySQL ≥ 5.7 / 8.0（或 MariaDB 10.3+）
- 前端：原生 JS + CSS（无框架、无构建），首屏一个小 CSS + 两个 JS
- 架构：单一入口 `index.php`，后端输出 JSON API，前端负责渲染

---

## 一、功能一览

### 文件管理
- **三种视图**：列表、图标网格、**Mac 访达式分栏（Miller Columns）**
- 左侧文件夹树（懒加载）、面包屑、搜索、按类型筛选（图片/视频/音乐/文档/压缩包/代码…）
- 多选（点击 / Ctrl+点击 / Shift 连选 / Ctrl+A），拖动到文件夹即移动
- 复制、剪切、粘贴、重命名、移动、复制到…、删除（进回收站）、恢复、彻底删除、清空回收站
- 上传：拖拽整个页面上传 / 按钮选择，**大文件自动分片**（默认 4MB/片），带进度条与队列面板，同用户文件**秒传**（SHA-256 去重）
- 下载：单文件 / 多选批量 ZIP 打包，支持断点续传（HTTP Range）与**按用户限速**
- 预览：图片、视频、音频、PDF、文本/代码（可在线编辑保存）
- 详情信息面板（类型、大小、时间、下载次数、分享状态）

### 分享
- 短链：`https://你的域名/s/xxxxxxx`（7 位随机码，永不重复）
- 可选 **提取码**、可选 **访问密码**、有效期（永久 / 1 天 / 7 天 / 30 天 / 自定义）
- 可单独控制「允许预览」「允许下载」，随时开启/关闭
- 访问者无需登录即可浏览、预览、下载、**转存到自己网盘**
- 我的分享：复制链接、查看访问/下载次数、关闭、删除

### 用户与后台
- 注册/登录/记住我/修改资料/修改密码（注册可在后台关闭）
- 用户组（等级）：空间配额、单文件上限、**上传/下载限速**、是否可分享、是否管理员
- 后台：概览（用户/文件/空间/近 7 天趋势/最近操作）、用户管理、用户组管理、文件管理、分享管理、系统设置、操作日志

---

## 二、安装

### 1. 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | ≥ 8.0（推荐 8.1/8.2） |
| MySQL | ≥ 5.7 / 8.0（MariaDB 10.3+ 亦可） |
| 必需扩展 | `pdo_mysql`、`json`、`mbstring`、`openssl` |
| 可选扩展 | `fileinfo`（MIME 识别）、`gd`（图片缩略图）、`zip`（批量打包下载） |

### 2. 上传与安装

1. 把整个目录上传到网站根目录（例如 `/www/wwwroot/pan.cn`），**网站根目录指向本目录**；
   也可以放在子目录里（如 `pan.cn/pan`），程序会自动识别路径。
2. 确保以下目录可写：
   ```bash
   chmod -R 755 storage config
   # 若 PHP 运行用户与文件属主不同，临时给 777：
   chmod -R 777 storage config
   ```
3. 浏览器访问 `http://你的域名/install.php`，按向导填写数据库与管理员信息，点「开始安装」。
4. 安装完成后**删除或重命名 `install.php`**，并确认 `config/config.php` 已生成。

> 若服务器不允许 PHP 建库：点安装页的「下载建表 SQL」手动导入，再填写相同的库名/前缀执行一次安装即可（程序会自动复用已有表）。

### 3. 上传大小限制

后台的「单文件上限」只是程序层面的限制，实际还受 PHP 限制，请同步调整 `php.ini`：

```ini
upload_max_filesize = 512M
post_max_size    = 512M
max_execution_time = 300
memory_limit    = 256M
```

Nginx 还需：`client_max_body_size 512m;`

---

## 三、伪静态（让 `/s/xxxx` 短链生效）

程序本身已兼容 `index.php?_route=...`，但为了得到 `pan.cn/s/xxxx` 这种干净短链，请加一条规则。

### Nginx（推荐）

```nginx
server {
    listen 80;
    server_name pan.cn;
    root /www/wwwroot/pan.cn;
    index index.php;

    client_max_body_size 512m;

    location / {
        try_files $uri $uri/ /index.php$uri?$args;
    }

    location ~ \.php$ {
        fastcgi_pass   127.0.0.1:9000;          # 或 unix:/run/php/php8.1-fpm.sock
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # 若用上面的 try_files 写法，PATH_INFO 由 fastcgi_params 传递即可
        fastcgi_param  PATH_INFO $fastcgi_path_info;
    }

    # 禁止直接访问内部目录
    location ~ ^/(app|config)/ { deny all; }
    location ~ ^/storage/ { deny all; }
}
```

备选写法（不依赖 PATH_INFO）：

```nginx
location / {
    try_files $uri $uri/ /index.php?_route=$uri&$args;
}
```

### Apache

项目已自带 `app/`、`config/`、`storage/` 三个 `.htaccess` 保护文件，只需在站点根目录新建：

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php?_route=/$1 [QSA,L]
</IfModule>
```

---

## 四、目录结构

```
pan/
├── index.php              # 唯一入口（页面 + API 分发）
├── install.php            # 安装向导（装完请删除）
├── favicon.svg
├── assets/
│   ├── css/app.css        # 全部样式（WorkBuddy 桌面端风格：圆角/柔和阴影/毛玻璃）
│   ├── js/core.js         # 请求封装、弹窗、菜单、Toast、图标
│   ├── js/app.js          # 网盘主程序
│   ├── js/share.js        # 分享提取页
│   └── js/admin.js        # 管理后台
├── app/
│   ├── bootstrap.php      # 引导（配置、数据库、会话、设置）
│   ├── core/              # Db / Http / Auth / Util / Schema
│   ├── models/            # User / Node / Share / Setting / LogModel
│   └── controllers/       # Auth / File / Share / Guest / Admin
├── config/
│   └── config.php         # 安装生成的配置（含数据库账号）
├── storage/               # 文件存储（禁止直接访问）
│   ├── files/             # 实际文件，按 hash 前两位分目录
│   ├── thumbs/            # 图片缩略图缓存
│   └── tmp/               # 分片上传临时目录
└── database/
    └── schema.sql         # 建表 SQL（手动建库时使用）
```

**文件是怎么存的**：上传的文件以 `SHA-256` 命名，存到 `storage/files/xx/xxxx...`，原始文件名只存在数据库里，所有下载都经 PHP 鉴权后流式输出（支持 Range 与限速），因此未授权用户拿到 URL 也下载不了。

---

## 五、常见问题

**Q：页面空白 / 500？**
A：开启后台「系统设置 → 调试模式」，或在 `config/config.php` 里把 `debug` 改成 `'1'`，重新访问看具体报错。

**Q：上传大文件失败？**
A：依次检查 `php.ini` 的 `upload_max_filesize` / `post_max_size`、Nginx 的 `client_max_body_size`、后台设置的单文件上限，以及 `storage` 目录是否可写。

**Q：无法打包下载？**
A：需要 PHP `zip` 扩展，安装后重启 PHP-FPM 即可。

**Q：图片不显示缩略图？**
A：需要 `gd` 扩展；没有也不影响使用，会自动回退到原图。

**Q：install.php 提示「已安装」？**
A：删除 `config/config.php` 与 `config/installed.lock` 后重试（重装会清空同名数据表）。

**Q：想改主题色？**
A：只改 `assets/css/app.css` 顶部的 CSS 变量即可，例如 `--primary`、`--radius`。

---

## 六、安全建议

- 安装完务必删除 `install.php`
- 使用 HTTPS，避免密码与 Cookie 明文传输
- 定期备份 `storage/` 与数据库
- 后台「禁止的文件后缀」默认已屏蔽 `php5/phtml/phar/sh` 等可执行类型

---

MIT License · VortexPan v1.0.0
