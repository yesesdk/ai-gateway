<?php
/**
 * AI 中转站 - API 代理入口
 *
 * 入口方式：
 *   1. 伪静态  http://你的域名/v1/chat/completions   （推荐）
 *   2. 直连     http://你的域名/api.php?path=v1/chat/completions
 *
 * 流程：校验下游密钥 → 按模型选渠道 → 原样转发上游 → 透传响应 → 记录 token 用量
 */
require_once __DIR__ . '/lib.php';

// CORS（方便网页端直接调用）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$t0 = microtime(true);

/* ========== 1. 解析请求路径 ========== */
$path = trim($_GET['path'] ?? '');
if ($path === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('#^/v1(/.*)?$#', $uri, $m)) {
        $path = ltrim($m[1] ?? '', '/');
    }
}
$path = ltrim($path, '/');
if ($path === '') {
    openai_error('Not Found. Only /v1/* endpoints are supported.', 404, 'not_found');
}
// 归一化：确保以 v1/ 开头（伪静态 rewrite 传入的 path 不带 v1 前缀）
if ($path === 'v1') {
    $path = 'v1/';
}
if (!str_starts_with($path, 'v1/')) {
    $path = 'v1/' . $path;
}

/* ========== 2. 校验下游密钥 ========== */
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    $apiKey = trim($m[1]);
} else {
    openai_error('缺少认证信息，请使用请求头: Authorization: Bearer sk-xxx', 401, 'authentication_error');
}

$st = db()->prepare('SELECT * FROM api_keys WHERE key = ? AND status = 1');
$st->execute([$apiKey]);
$ak = $st->fetch();
if (!$ak) {
    openai_error('无效的 API 密钥', 401, 'authentication_error');
}
if (!empty($ak['expires_at']) && strtotime($ak['expires_at']) < time()) {
    openai_error('API 密钥已过期（过期时间: ' . $ak['expires_at'] . '）', 403, 'permission_error');
}
db()->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')
   ->execute([now(), $ak['id']]);

/* ========== 3. 读取请求体 ========== */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body   = file_get_contents('php://input');

// 解析 model / stream（body 非 JSON 时按默认渠道转发，不报错）
$model     = '';
$isStream  = false;
if ($body !== '') {
    $parsed = json_decode($body, true);
    if (is_array($parsed)) {
        $model    = (string)($parsed['model'] ?? '');
        $isStream = !empty($parsed['stream']);
    }
}

/* ========== 4. 渠道选择 ========== */
$channels = db()->query('SELECT * FROM channels WHERE status = 1 ORDER BY priority ASC, id ASC')->fetchAll();
if (!$channels) {
    openai_error('暂无可用的上游渠道，请联系管理员配置', 503, 'server_error');
}

$channel = null;
if ($model !== '') {
    foreach ($channels as $c) {
        $models = json_decode($c['models'], true) ?: [];
        if (in_array('*', $models, true) || in_array($model, $models, true)) {
            $channel = $c;
            break;
        }
    }
}
// 兜底：未匹配到或无需 model 的请求（如 GET /v1/models）→ 优先级最高的渠道
if (!$channel) {
    $channel = $channels[0];
}

/* ========== 5. 组装上游 URL（多地址，逐个故障切换） ========== */
$urls = channel_urls($channel);
if (!$urls) {
    openai_error('渠道未配置上游地址，请联系管理员', 500, 'server_error');
}
$apiPath = $path; // 形如 v1/chat/completions

/** 拼接上游完整 URL（兼容 base 已带 /v1 的情况；base 含 api.php 时走直连模式） */
function build_upstream_url(string $baseUrl, string $apiPath): string {
    $base = rtrim($baseUrl, '/');
    // 直连模式：对方站点未配置 /v1 伪静态，只能用 api.php?path= 方式
    if (str_contains($base, 'api.php')) {
        return $base . (str_contains($base, '?') ? '&' : '?') . 'path=' . $apiPath;
    }
    if (preg_match('#/v1/?$#', $base)) {
        $apiPath = preg_replace('#^v1/#', '', $apiPath); // base 已带 /v1，去掉重复段
    }
    return $base . '/' . $apiPath;
}

/* ========== 6. 组装请求头 ========== */
$headers = ['Authorization: Bearer ' . $channel['api_key']];
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}
if (!empty($_SERVER['HTTP_ACCEPT'])) {
    $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
}
$extra = json_decode($channel['extra_headers'] ?? '{}', true);
if (is_array($extra)) {
    foreach ($extra as $k => $v) {
        $headers[] = $k . ': ' . $v;
    }
}

$timeout = (int)$channel['timeout'] ?: UPSTREAM_TIMEOUT;

/* ========== 7. 转发 ========== */
$curlOpts = [
    CURLOPT_CONNECTTIMEOUT => UPSTREAM_CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_SSL_VERIFYPEER => SSL_VERIFY,
    CURLOPT_SSL_VERIFYHOST => SSL_VERIFY ? 2 : 0,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
];

$usage  = null;
$status = 0;

if ($isStream) {
    /* ---- 流式：边收边发（多地址自动故障切换） ---- */
    // 关闭所有输出缓冲，保证实时推送
    while (ob_get_level() > 0) { ob_end_flush(); }

    $gotHeaders = false;
    $errno = 0;
    $errmsg = '';
    $status = 0;
    foreach ($urls as $baseUrl) {
        $upstreamUrl = build_upstream_url($baseUrl, $apiPath);
        $gotHeaders = false;
        $usage = null;
        $ch = curl_init($upstreamUrl);
        curl_setopt_array($ch, $curlOpts + [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$gotHeaders) {
                $len = strlen($header);
                if (!$gotHeaders) {
                    if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $header, $m)) {
                        http_response_code((int)$m[1]);
                    } elseif (preg_match('#^([^:]+):\s*(.+?)\s*$#', $header, $m)) {
                        $k = trim($m[1]);
                        $v = trim($m[2]);
                        // 透传关键响应头
                        if (preg_match('#^(Content-Type|Cache-Control|X-|Retry-After|OpenAI-)#i', $k)) {
                            header($k . ': ' . $v);
                        }
                    } elseif ($header === "\r\n" || $header === "\n") {
                        $gotHeaders = true;
                        flush();
                    }
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$usage) {
                if (connection_aborted()) {
                    return 0; // 客户端断开，中止 curl
                }
                echo $data;
                flush();
                // 从 SSE 数据里提取 usage（OpenAI 兼容格式）
                foreach (explode("\n", $data) as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, 'data:')) {
                        $payload = trim(substr($line, 5));
                        if ($payload !== '' && $payload !== '[DONE]') {
                            $j = json_decode($payload, true);
                            if (is_array($j) && isset($j['usage']) && is_array($j['usage'])) {
                                $usage = $j['usage'];
                            }
                        }
                    }
                }
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno === 0 || $gotHeaders) {
            break; // 连接成功，或已开始向客户端输出（此时不能再切换地址）
        }
    }

    // 全部地址连接失败：补发一个 SSE 错误事件（此时尚未输出任何内容）
    if ($errno !== 0 && !$gotHeaders) {
        echo 'data: ' . json_encode([
            'error' => [
                'message' => '上游请求失败: ' . $errmsg,
                'type'    => 'upstream_error',
                'code'    => 502,
            ],
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        $status = 502;
    }
    flush();
} else {
    /* ---- 非流式：收完整再回（多地址自动故障切换） ---- */
    $resp = false;
    $errno = 0;
    $errmsg = '';
    $status = 0;
    $headerSize = 0;
    foreach ($urls as $baseUrl) {
        $upstreamUrl = build_upstream_url($baseUrl, $apiPath);
        $ch = curl_init($upstreamUrl);
        curl_setopt_array($ch, $curlOpts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HEADER         => true, // 连响应头一起拿
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($errno === 0 && $resp !== false) {
            break; // 连接成功即用（上游 4xx/5xx 业务错误原样透传，不切换）
        }
    }

    if ($errno !== 0 || $resp === false) {
        // 记录失败日志后再返回错误
        log_request($apiKey, $channel['name'], $model, $path, 0, 0, 0, 502, (int)round((microtime(true) - $t0) * 1000));
        openai_error('上游请求失败: ' . ($errmsg ?: 'connection error'), 502, 'upstream_error');
    }

    $respHeaders = substr($resp, 0, $headerSize);
    $respBody    = substr($resp, $headerSize);

    // 透传响应头
    foreach (explode("\r\n", $respHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (preg_match('#^(Content-Type|Cache-Control|X-|Retry-After|OpenAI-)#i', $k)) {
                header($k . ': ' . $v);
            }
        }
    }
    http_response_code($status);

    // 提取 usage
    $parsed = json_decode($respBody, true);
    if (is_array($parsed) && isset($parsed['usage']) && is_array($parsed['usage'])) {
        $usage = $parsed['usage'];
    }
    echo $respBody;
}

/* ========== 8. 记录日志 ========== */
if ($errno !== 0 && $status === 0) {
    $status = 502;
}
$latency = (int)round((microtime(true) - $t0) * 1000);
$prompt  = isset($usage['prompt_tokens'])     ? (int)$usage['prompt_tokens']     : 0;
$complet = isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0;
$total   = isset($usage['total_tokens'])      ? (int)$usage['total_tokens']      : ($prompt + $complet);

log_request($apiKey, $channel['name'], $model, $path, $prompt, $complet, $total, $status, $latency);

/** 写入一条请求日志（失败静默，不影响响应） */
function log_request(string $apiKey, string $channelName, string $model, string $path,
                     int $prompt, int $complet, int $total, int $status, int $latency): void {
    try {
        $st = db()->prepare('INSERT INTO logs (api_key, channel_name, model, path, prompt_tokens, completion_tokens, total_tokens, status_code, latency_ms)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$apiKey, $channelName, $model, $path, $prompt, $complet, $total, $status, $latency]);
    } catch (Throwable $e) {
        // 忽略：日志写入失败不影响主流程
    }
}
