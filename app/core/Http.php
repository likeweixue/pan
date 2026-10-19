<?php
/**
 * HTTP 请求 / 响应封装
 */
defined('PAN_ACCESS') or exit('No direct access');

class ApiException extends RuntimeException
{
    public array $payload;
    public function __construct(string $message, int $code = 400, array $extra = [])
    {
        parent::__construct($message, $code);
        $this->payload = array_merge(['error' => $message], $extra);
    }
}

class Http
{
    public static function json(array $data, int $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok($data = null, array $extra = [])
    {
        self::json(array_merge(['ok' => true, 'data' => $data], $extra));
    }

    public static function fail(string $message, int $code = 400, array $extra = [])
    {
        self::json(array_merge(['ok' => false, 'error' => $message], $extra), $code);
    }

    public static function redirect(string $url)
    {
        header('Location: ' . $url);
        exit;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** 请求必须是 POST（或带 _method 覆盖） */
    public static function requirePost(): void
    {
        if (Http::method() !== 'POST') {
            Http::fail('请求方法不正确', 405);
        }
    }

    /** CSRF 校验 */
    public static function verifyCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? Util::input('_token', '');
        if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
            Http::fail('CSRF 校验失败，请刷新页面重试', 419);
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * 带断点续传 + 限速的文件流输出
     * @param int $speedLimit KB/s，0 表示不限
     */
    public static function streamFile(string $path, string $filename, string $mime, bool $inline, int $speedLimit = 0)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (!is_file($path)) {
            http_response_code(404);
            exit('文件不存在');
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $partial = false;

        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] === '') {
                $suffix = (int)$m[2];
                $start = max(0, $size - $suffix);
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
            }
            if ($start <= $end && $start < $size) {
                $partial = true;
                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            }
        }

        $disposition = ($inline ? 'inline' : 'attachment');
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $fallback = rawurlencode($filename);
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $filename);
        if ($ascii === '') $ascii = 'download';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace(['"', "\\"], '', $ascii) . '"; filename*=UTF-8\'\'' . $fallback);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('X-Content-Type-Options: nosniff');

        $fh = fopen($path, 'rb');
        if ($fh === false) exit;
        fseek($fh, $start);
        $remaining = $end - $start + 1;
        $chunk = $speedLimit > 0 ? min(262144, max(16384, (int)($speedLimit * 1024 / 4))) : 1048576;

        while ($remaining > 0 && !feof($fh)) {
            $read = min($chunk, $remaining);
            $buf = fread($fh, $read);
            if ($buf === false || $buf === '') break;
            echo $buf;
            $remaining -= strlen($buf);
            flush();
            if (connection_aborted()) break;
            if ($speedLimit > 0) {
                usleep((int)($read / max(1, $speedLimit * 1024) * 1000000));
            }
        }
        fclose($fh);
        exit;
    }
}
