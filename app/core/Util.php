<?php
/**
 * VortexPan - 轻量私有网盘系统
 * 通用工具库
 */
defined('PAN_ACCESS') or exit('No direct access');

class Util
{
    /** 读取 $_GET / $_POST / JSON 混合参数 */
    public static function input(string $key, $default = null)
    {
        if (isset(self::$jsonCache[$key])) return self::$jsonCache[$key];
        if (isset($_GET[$key])) return $_GET[$key];
        if (isset($_POST[$key])) return $_POST[$key];
        $json = self::jsonBody();
        if (is_array($json) && array_key_exists($key, $json)) return $json[$key];
        return $default;
    }

    private static $jsonCache = [];
    private static $jsonParsed = false;

    public static function jsonBody()
    {
        if (self::$jsonParsed) return self::$jsonCache;
        self::$jsonParsed = true;
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        self::$jsonCache = is_array($data) ? $data : [];
        return self::$jsonCache;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int)self::input($key, $default);
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_string($v) ? trim($v) : (string)$v;
    }

    /** 读取数组参数，兼容 ids[]=1&ids[]=2 与 ids=1 两种写法 */
    public static function arr(string $key, array $default = []): array
    {
        $v = self::input($key, $default);
        if (is_array($v)) return $v;
        if ($v === null || $v === '' || $v === []) return $default;
        return [(string)$v];
    }

    public static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function random(int $length = 16, ?string $chars = null): string
    {
        $chars = $chars ?: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;
        $out = '';
        try {
            for ($i = 0; $i < $length; $i++) {
                $out .= $chars[random_int(0, $max)];
            }
        } catch (Exception $e) {
            for ($i = 0; $i < $length; $i++) {
                $out .= $chars[mt_rand(0, $max)];
            }
        }
        return $out;
    }

    /** 人类可读的文件大小 */
    public static function size(int|string $bytes, int $precision = 2): string
    {
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return ($i === 0 ? (int)$bytes : number_format($bytes, $precision)) . ' ' . $units[$i];
    }

    /** 把 10G / 500M 之类转成字节 */
    public static function toBytes(string $size): int
    {
        $size = trim($size);
        if ($size === '') return 0;
        if (is_numeric($size)) return (int)$size;
        $unit = strtoupper(substr($size, -1));
        $num = (float)substr($size, 0, -1);
        return (int)match ($unit) {
            'K' => $num * 1024,
            'M' => $num * 1048576,
            'G' => $num * 1073741824,
            'T' => $num * 1099511627776,
            default => $num,
        };
    }

    public static function timeAgo($time): string
    {
        $ts = is_numeric($time) ? (int)$time : strtotime((string)$time);
        if (!$ts) return '-';
        $diff = time() - $ts;
        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . ' 分钟前';
        if ($diff < 86400) return floor($diff / 3600) . ' 小时前';
        if ($diff < 2592000) return floor($diff / 86400) . ' 天前';
        return date('Y-m-d', $ts);
    }

    public static function clientIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $parts = explode(',', $_SERVER[$k]);
                return trim($parts[0]);
            }
        }
        return '0.0.0.0';
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
        return (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }

    /** 去除文件名中的危险字符 */
    public static function safeName(string $name): string
    {
        $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|', "\0"], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: $name;
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') $name = 'unnamed';
        return mb_substr($name, 0, 200);
    }

    public static function ext(string $name): string
    {
        $pos = strrpos($name, '.');
        return $pos === false ? '' : strtolower(substr($name, $pos + 1));
    }

    public static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) return true;
        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    /** 递归删除目录 */
    public static function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? self::rmDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** 文件类型归类，用于图标渲染 */
    public static function kind(string $ext, string $mime = ''): string
    {
        $mime = strtolower($mime);
        $ext = strtolower($ext);
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        $map = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'avif', 'heic', 'tiff'],
            'video' => ['mp4', 'mkv', 'webm', 'avi', 'mov', 'flv', 'wmv', 'm4v', 'ts'],
            'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'ape', 'wma'],
            'code' => ['php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'json', 'css', 'scss', 'html', 'htm', 'xml', 'py', 'java', 'go', 'c', 'h', 'cpp', 'cs', 'rb', 'sh', 'sql', 'ini', 'conf', 'yml', 'yaml', 'md', 'rs', 'swift', 'kt'],
            'text' => ['txt', 'log', 'csv', 'diff', 'patch'],
            'doc' => ['doc', 'docx', 'rtf', 'odt', 'pages'],
            'xls' => ['xls', 'xlsx', 'ods', 'numbers'],
            'ppt' => ['ppt', 'pptx', 'odp', 'key'],
            'pdf' => ['pdf'],
            'zip' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'iso', 'dmg'],
            'apk' => ['apk', 'ipa', 'exe', 'msi', 'deb', 'rpm'],
            'psd' => ['psd', 'ai', 'sketch', 'fig', 'xd'],
            'font' => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
        ];
        foreach ($map as $kind => $exts) {
            if (in_array($ext, $exts, true)) return $kind;
        }
        return 'file';
    }

    public static function mimeOf(string $file): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = (string)@finfo_file($f, $file);
                finfo_close($f);
            }
        } elseif (function_exists('mime_content_type')) {
            $mime = (string)@mime_content_type($file);
        }
        return $mime ?: 'application/octet-stream';
    }

    /** JSON 转义安全输出（前端再做转义，这里只保证不破坏结构） */
    public static function safeUtf8(string $str): string
    {
        return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    }
}
