<?php
/**
 * AI 中转站 - 公共库
 * 数据库初始化 + 辅助函数
 */
require_once __DIR__ . '/config.php';

/** 获取 PDO 单例，自动建表 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // WAL 模式：读写并发更好
        $pdo->exec('PRAGMA journal_mode = WAL');
        init_tables($pdo);
    }
    return $pdo;
}

/** 建表 */
function init_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS channels (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        base_url      TEXT NOT NULL DEFAULT '',
        urls          TEXT NOT NULL DEFAULT '',
        api_key       TEXT NOT NULL,
        models        TEXT NOT NULL DEFAULT '[\"*\"]',
        extra_headers TEXT NOT NULL DEFAULT '{}',
        priority      INTEGER NOT NULL DEFAULT 0,
        timeout       INTEGER NOT NULL DEFAULT 120,
        status        INTEGER NOT NULL DEFAULT 1,
        created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // 旧库迁移：补 urls 列（多地址），并从 base_url 填充
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(channels)') as $c) {
        $cols[] = $c['name'];
    }
    if (!in_array('urls', $cols, true)) {
        $pdo->exec("ALTER TABLE channels ADD COLUMN urls TEXT NOT NULL DEFAULT ''");
    }
    $mig = $pdo->query("SELECT id, base_url FROM channels WHERE urls = ''");
    foreach ($mig as $r) {
        $base = trim($r['base_url']);
        if ($base === '') { continue; }
        $pdo->prepare('UPDATE channels SET urls = ? WHERE id = ?')
            ->execute([json_encode([$base], JSON_UNESCAPED_UNICODE), $r['id']]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        key         TEXT NOT NULL UNIQUE,
        name        TEXT NOT NULL DEFAULT '',
        expires_at  TEXT,
        status      INTEGER NOT NULL DEFAULT 1,
        remark      TEXT NOT NULL DEFAULT '',
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        last_used_at TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        api_key           TEXT NOT NULL,
        channel_name      TEXT,
        model             TEXT,
        path              TEXT,
        prompt_tokens     INTEGER NOT NULL DEFAULT 0,
        completion_tokens INTEGER NOT NULL DEFAULT 0,
        total_tokens      INTEGER NOT NULL DEFAULT 0,
        status_code       INTEGER NOT NULL DEFAULT 0,
        latency_ms        INTEGER NOT NULL DEFAULT 0,
        created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_time ON logs(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_key  ON logs(api_key)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT
    )");

    // 对外接口列表（后台可管理，用户面板展示）
    $pdo->exec("CREATE TABLE IF NOT EXISTS interfaces (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        name         TEXT NOT NULL,
        url          TEXT NOT NULL,
        is_recommend INTEGER NOT NULL DEFAULT 0,
        status       INTEGER NOT NULL DEFAULT 1,
        sort         INTEGER NOT NULL DEFAULT 0,
        created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    // 首次安装：插入默认接口
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM interfaces')->fetchColumn();
    if ($cnt === 0) {
        $pdo->prepare('INSERT INTO interfaces (name, url, is_recommend) VALUES (?, ?, 1)')
            ->execute(['推荐接口', 'http://127.0.0.1/v1']);
    }
}

/* ================= 小工具 ================= */

/** HTML 转义 */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 输出 JSON 并结束 */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** OpenAI 兼容错误响应 */
function openai_error(string $msg, int $code = 400, string $type = 'invalid_request_error'): void {
    json_out([
        'error' => [
            'message' => $msg,
            'type'    => $type,
            'code'    => $code,
        ],
    ], $code);
}

/** 生成随机 API 密钥 */
function gen_key(): string {
    return KEY_PREFIX . bin2hex(random_bytes(16));
}

/** 生成随机 token（管理员会话用） */
function gen_token(): string {
    return bin2hex(random_bytes(16));
}

/** 当前时间 */
function now(): string {
    return date('Y-m-d H:i:s');
}

/* ================= 配置读取 ================= */

/** 读取设置项 */
function setting_get(string $key, ?string $default = null): ?string {
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ? $row['value'] : $default;
}

/** 写入设置项 */
function setting_set(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                         ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, $value]);
}

/* ================= 管理员会话 ================= */

function admin_login(string $user, string $pass): bool {
    $stored_user = setting_get('admin_user', DEFAULT_ADMIN_USER);
    $stored_hash = setting_get('admin_pass_hash');
    if ($stored_hash === null) {
        // 首次安装：使用 config.php 默认密码
        if (hash_equals($stored_user, $user) && hash_equals(DEFAULT_ADMIN_PASS, $pass)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['login_time'] = now();
            return true;
        }
        return false;
    }
    if (hash_equals($stored_user, $user) && password_verify($pass, $stored_hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['login_time'] = now();
        return true;
    }
    return false;
}

function admin_check(): bool {
    return !empty($_SESSION['admin']);
}

function admin_logout(): void {
    unset($_SESSION['admin']);
    session_regenerate_id(true);
}

/* ================= 渠道 ================= */

/** 取渠道的上游地址数组（urls 优先，兼容旧 base_url） */
function channel_urls(array $channel): array {
    $urls = json_decode($channel['urls'] ?? '', true);
    if (!is_array($urls) || count($urls) === 0) {
        $base = trim($channel['base_url'] ?? '');
        $urls = $base !== '' ? [$base] : [];
    }
    $out = [];
    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u !== '') { $out[] = $u; }
    }
    return $out;
}

/* ================= 对外接口（用户面板展示） ================= */

/** 启用的接口列表（按排序） */
function get_interfaces(): array {
    return db()->query('SELECT * FROM interfaces WHERE status = 1 ORDER BY sort ASC, id ASC')->fetchAll();
}

/** 全部接口（含停用，后台用） */
function get_all_interfaces(): array {
    return db()->query('SELECT * FROM interfaces ORDER BY sort ASC, id ASC')->fetchAll();
}

/* ================= 统计 ================= */

/** 仪表盘统计 */
function get_stats(): array {
    $db = db();
    $t = fn(string $sql, array $p = []) => (int)$db->prepare($sql)->execute($p) === false ? 0 : (int)$db->query($sql)->fetchColumn();

    $today = date('Y-m-d');
    return [
        'total_requests'   => $t("SELECT COUNT(*) FROM logs"),
        'total_tokens'     => $t("SELECT COALESCE(SUM(total_tokens),0) FROM logs"),
        'today_requests'   => $t("SELECT COUNT(*) FROM logs WHERE created_at LIKE '$today%'"),
        'today_tokens'     => $t("SELECT COALESCE(SUM(total_tokens),0) FROM logs WHERE created_at LIKE '$today%'"),
        'channels'         => $t("SELECT COUNT(*) FROM channels WHERE status = 1"),
        'keys'             => $t("SELECT COUNT(*) FROM api_keys WHERE status = 1"),
    ];
}

/** 最近 N 天每日 token 用量（返回 日期=>token 有序数组） */
function get_daily_tokens(int $days = 14): array {
    $db = db();
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $st = $db->prepare("SELECT COALESCE(SUM(total_tokens),0) FROM logs WHERE created_at LIKE ?");
        $st->execute([$d . '%']);
        $out[$d] = (int)$st->fetchColumn();
    }
    return $out;
}

/* ================= 用户面板统计 ================= */

/** 按模型前缀取定价 [输入$, 输出$] */
function price_for(string $model): array {
    foreach (PRICING as $prefix => $price) {
        if ($prefix !== '_default' && str_starts_with($model, $prefix)) {
            return $price;
        }
    }
    return PRICING['_default'];
}

/** 计算一次请求的费用（美元） */
function calc_cost(string $model, int $prompt, int $completion): float {
    [$in, $out] = price_for($model);
    return $prompt / 1000000 * $in + $completion / 1000000 * $out;
}

/** 单个密钥的用户统计（累计 + 今日 + 性能） */
function get_user_stats(string $apiKey): array {
    $db = db();
    $today = date('Y-m-d') . '%';

    $st = $db->prepare("SELECT COUNT(*) AS cnt,
                               COALESCE(SUM(total_tokens),0) AS tokens,
                               COALESCE(ROUND(AVG(latency_ms)),0) AS avg_ms,
                               COALESCE(SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END),0) AS ok_cnt,
                               COALESCE(SUM(CASE WHEN created_at LIKE ? THEN total_tokens ELSE 0 END),0) AS today_tokens,
                               COALESCE(SUM(CASE WHEN created_at LIKE ? THEN 1 ELSE 0 END),0) AS today_cnt
                        FROM logs WHERE api_key = ?");
    $st->execute([$today, $today, $apiKey]);
    $row = $st->fetch();

    // 今日消费：按模型价格逐条计算
    $st2 = $db->prepare('SELECT model, prompt_tokens, completion_tokens FROM logs WHERE api_key = ? AND created_at LIKE ?');
    $st2->execute([$apiKey, $today]);
    $cost = 0.0;
    foreach ($st2 as $r) {
        $cost += calc_cost((string)$r['model'], (int)$r['prompt_tokens'], (int)$r['completion_tokens']);
    }

    $cnt = (int)$row['cnt'];
    return [
        'requests'       => $cnt,
        'tokens'         => (int)$row['tokens'],
        'avg_ms'         => (int)$row['avg_ms'],
        'success_rate'   => $cnt > 0 ? round($row['ok_cnt'] / $cnt * 100, 1) : 100.0,
        'today_tokens'   => (int)$row['today_tokens'],
        'today_requests' => (int)$row['today_cnt'],
        'today_cost'     => round($cost, 4),
        'today_cost_cny' => round($cost * EXCHANGE_RATE, 2),
    ];
}

/** 单个密钥近 N 天每日 token 用量 */
function get_user_daily(string $apiKey, int $days = 14): array {
    $db = db();
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $st = $db->prepare('SELECT COALESCE(SUM(total_tokens),0) FROM logs WHERE api_key = ? AND created_at LIKE ?');
        $st->execute([$apiKey, $d . '%']);
        $out[$d] = (int)$st->fetchColumn();
    }
    return $out;
}
