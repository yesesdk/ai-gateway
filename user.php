<?php
/**
 * AI 中转站 - 用户自助面板
 * 下游用户用 sk- 密钥直接登录，查看自己的用量 / 性能 / 消费 / 使用记录 / 接入文档
 */
require_once __DIR__ . '/lib.php';
session_start();

$action = $_POST['action'] ?? '';

/* ================= POST 处理 ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'login':
            $inputKey = trim($_POST['key'] ?? '');
            if ($inputKey !== '') {
                $st = db()->prepare('SELECT * FROM api_keys WHERE key = ? AND status = 1');
                $st->execute([$inputKey]);
                $ak = $st->fetch();
                if ($ak) {
                    if (!empty($ak['expires_at']) && strtotime($ak['expires_at']) < time()) {
                        $login_err = '该密钥已过期（' . $ak['expires_at'] . '），请联系管理员续期 ⏰';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_key'] = $inputKey;
                        header('Location: user.php');
                        exit;
                    }
                } else {
                    $login_err = '密钥无效或已被禁用，请检查后重试 (｡•́︿•̀｡)';
                }
            } else {
                $login_err = '请输入你的 API 密钥';
            }
            break;

        case 'logout':
            unset($_SESSION['user_key']);
            session_regenerate_id(true);
            header('Location: user.php');
            exit;
    }
}

/* ================= 未登录 → 登录页 ================= */
if (empty($_SESSION['user_key'])) {
    render_user_login($login_err ?? '');
    exit;
}

$apiKey = (string)$_SESSION['user_key'];

// 每次进入都重新校验（管理员可能已禁用/删除）
$st = db()->prepare('SELECT * FROM api_keys WHERE key = ?');
$st->execute([$apiKey]);
$ak = $st->fetch();
if (!$ak || (int)$ak['status'] !== 1) {
    unset($_SESSION['user_key']);
    render_user_login('密钥已失效（被禁用或删除），请重新登录');
    exit;
}
if (!empty($ak['expires_at']) && strtotime($ak['expires_at']) < time()) {
    unset($_SESSION['user_key']);
    render_user_login('该密钥已过期（' . $ak['expires_at'] . '），请联系管理员续期 ⏰');
    exit;
}

/* ================= 渲染面板 ================= */
$stats   = get_user_stats($apiKey);
$daily   = get_user_daily($apiKey, 14);
$max     = max(array_merge([1], array_values($daily)));
$interfaces = get_interfaces();
$base    = '';
foreach ($interfaces as $f) {
    if (!empty($f['is_recommend'])) { $base = $f['url']; break; }
}
if ($base === '' && $interfaces) {
    $base = $interfaces[0]['url'];
}
$per     = 15;
$pageNo  = max(1, (int)($_GET['p'] ?? 1));
$totalCnt = db()->prepare('SELECT COUNT(*) FROM logs WHERE api_key = ?');
$totalCnt->execute([$apiKey]);
$total = (int)$totalCnt->fetchColumn();
$pages = max(1, ceil($total / $per));
$rows = db()->prepare("SELECT * FROM logs WHERE api_key = ? ORDER BY id DESC LIMIT $per OFFSET " . (($pageNo - 1) * $per));
$rows->execute([$apiKey]);
$logs = $rows->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>我的用量 · AI 中转站</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <span class="logo">⚡</span>
    <span class="brand-name">AI Gateway</span>
    <span class="brand-sub">用户面板</span>
  </div>
  <nav>
    <a href="#overview" class="active">📊 用量总览</a>
    <a href="#usage">🕘 使用记录</a>
    <a href="#docs">📖 接入文档</a>
  </nav>
  <div class="topbar-right">
    <span class="whoami">🔑 <?= e(substr($apiKey, 0, 6)) ?>••••••<?= e(substr($apiKey, -4)) ?></span>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="logout">
      <button class="btn btn-ghost btn-sm" type="submit">退出</button>
    </form>
  </div>
</header>

<main id="overview">
  <h1 class="page-title">我的用量</h1>

  <?php if (!empty($ak['name'])): ?>
  <div class="panel" style="padding:14px 20px">
    <span class="whoami">👤 密钥备注：<b><?= e($ak['name']) ?></b></span>
    <span class="whoami" style="margin-left:18px">⏳ 到期时间：<b><?= $ak['expires_at'] ? e($ak['expires_at']) : '永久有效' ?></b></span>
    <span class="badge <?= $ak['expires_at'] && strtotime($ak['expires_at']) < time() + 7 * 86400 ? 'err' : 'ok' ?>" style="margin-left:18px">
      <?= $ak['expires_at'] ? ((strtotime($ak['expires_at']) - time()) / 86400 < 7 ? '⚠️ 快到期了' : '状态正常') : '状态正常' ?>
    </span>
  </div>
  <?php endif; ?>

  <!-- 核心指标 -->
  <div class="cards">
    <div class="card"><div class="card-num"><?= number_format($stats['tokens']) ?></div><div class="card-label">累计 Token</div></div>
    <div class="card"><div class="card-num"><?= number_format($stats['requests']) ?></div><div class="card-label">总请求数</div></div>
    <div class="card"><div class="card-num"><?= $stats['avg_ms'] ? $stats['avg_ms'] . '<small style="font-size:14px">ms</small>' : '—' ?></div><div class="card-label">平均响应</div></div>
    <div class="card"><div class="card-num" style="color:<?= $stats['success_rate'] >= 95 ? 'var(--green)' : 'var(--red)' ?>"><?= $stats['success_rate'] ?>%</div><div class="card-label">成功率</div></div>
  </div>

  <!-- 今日消费 -->
  <div class="cards">
    <div class="card cost-card">
      <div class="cost-title">💰 今日消费</div>
      <div class="cost-usd">$ <?= number_format($stats['today_cost'], 4) ?></div>
      <div class="cost-cny">≈ ¥ <?= number_format($stats['today_cost_cny'], 2) ?> <span class="cost-note">（按 DeepSeek 官方价格估算）</span></div>
    </div>
    <div class="card"><div class="card-num accent"><?= number_format($stats['today_tokens']) ?></div><div class="card-label">今日 Token</div></div>
    <div class="card"><div class="card-num"><?= number_format($stats['today_requests']) ?></div><div class="card-label">今日请求</div></div>
  </div>

  <div class="panel">
    <h2>📈 近 14 天 Token 消耗</h2>
    <div class="chart">
      <?php foreach ($daily as $d => $v): ?>
      <div class="chart-col" title="<?= $d ?> · <?= number_format($v) ?> tokens">
        <div class="chart-bar" style="height: <?= max(2, round($v / $max * 100)) ?>%"></div>
        <div class="chart-label"><?= substr($d, 5) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- 使用记录 -->
  <div class="panel" id="usage">
    <h2>🕘 使用记录 <span class="hint">共 <?= number_format($total) ?> 条</span></h2>
    <table class="tbl">
      <thead><tr><th>时间</th><th>模型</th><th>路径</th><th>Prompt</th><th>完成</th><th>总Token</th><th>状态</th><th>延迟</th><th>费用</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $r):
          $fee = calc_cost((string)$r['model'], (int)$r['prompt_tokens'], (int)$r['completion_tokens']);
      ?>
      <tr>
        <td><?= e($r['created_at']) ?></td>
        <td class="mono"><?= e($r['model'] ?: '—') ?></td>
        <td class="mono">/<?= e($r['path']) ?></td>
        <td><?= number_format($r['prompt_tokens']) ?></td>
        <td><?= number_format($r['completion_tokens']) ?></td>
        <td><b><?= number_format($r['total_tokens']) ?></b></td>
        <td><span class="badge <?= $r['status_code'] < 400 ? 'ok' : 'err' ?>"><?= (int)$r['status_code'] ?></span></td>
        <td><?= $r['latency_ms'] ?>ms</td>
        <td class="mono" style="color:var(--accent)">$<?= number_format($fee, 5) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="9" class="empty">还没有使用记录，去调一下 API 试试吧 (๑•̀ㅂ•́)و✧</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="page-link <?= $i === $pageNo ? 'active' : '' ?>" href="?p=<?= $i ?>#usage"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- 接入文档 -->
  <div class="panel" id="docs">
    <h2>📖 接入文档</h2>
    <table class="kv">
      <tr><td>接入接口</td><td>
        <?php foreach ($interfaces as $f): ?>
        <div class="iface-row">
          <span class="badge <?= $f['is_recommend'] ? 'ok' : 'off' ?>"><?= $f['is_recommend'] ? '⭐ 推荐' : '备用' ?></span>
          <b><?= e($f['name']) ?></b>
          <span class="mono"><?= e($f['url']) ?></span>
          <button class="btn btn-sm btn-copy" data-full="<?= e($f['url']) ?>" title="复制地址">📋</button>
        </div>
        <?php endforeach; ?>
        <?php if (!$interfaces): ?><span class="hint">暂无接口，请联系管理员</span><?php endif; ?>
      </td></tr>
      <tr><td>鉴权方式</td><td class="mono">Authorization: Bearer &lt;你的密钥&gt;</td></tr>
      <tr><td>兼容性</td><td>OpenAI 格式，ChatGPT 类客户端 / one-api / new-api / openai SDK 均可</td></tr>
    </table>

    <h2 style="margin-top:22px">🐚 curl 调用</h2>
<pre class="code">curl <?= e($base) ?>/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <?= e($apiKey) ?>" \
  -d '{
    "model": "gpt-4o",
    "stream": true,
    "messages": [{"role": "user", "content": "你好"}]
  }'</pre>

    <h2 style="margin-top:22px">🐍 Python（openai 库）</h2>
<pre class="code">from openai import OpenAI

client = OpenAI(
    base_url="<?= e($base) ?>",
    api_key="<?= e($apiKey) ?>",
)

resp = client.chat.completions.create(
    model="gpt-4o",
    messages=[{"role": "user", "content": "你好"}],
)
print(resp.choices[0].message.content)</pre>

    <h2 style="margin-top:22px">📌 说明</h2>
    <ul class="notes">
      <li>费用按 <b>DeepSeek 官方峰值价</b> 估算（输入按缓存未命中计）；非峰值时段约为 5 折，实际以服务商结算为准。</li>
      <li>密钥到期前 7 天面板会提示"快到期了"，记得提前找管理员续期。</li>
      <li>面板数据为实时统计，刷新页面即可看到最新用量。</li>
    </ul>
  </div>
</main>

<footer class="footer">AI Gateway · 用户面板 (｡•̀ᴗ-)✧ 有问题找管理员</footer>
</body>
</html>
<?php

/* ================= 登录页 ================= */
function render_user_login(string $err = ''): void {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户登录 · AI 中转站</title>
    <link rel="stylesheet" href="assets/style.css">
    </head>
    <body class="login-body">
    <div class="login-box">
        <div class="login-logo">🔑</div>
        <h1>AI Gateway</h1>
        <p class="login-sub">用你的 API 密钥登录查看用量</p>
        <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <input name="key" placeholder="sk-你的API密钥" required autofocus style="font-family:Consolas,monospace">
            <button class="btn btn-primary btn-block" type="submit">查 看 用 量</button>
        </form>
        <p class="login-tip">密钥由管理员发放，如忘记或过期请联系管理员 ✉️</p>
    </div>
    </body>
    </html>
    <?php
}
