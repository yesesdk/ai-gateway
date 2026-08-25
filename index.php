<?php
/**
 * AI 中转站 - 后台管理
 * 页面: ?page=dashboard | channels | keys | logs | docs | settings
 */
require_once __DIR__ . '/lib.php';
session_start();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

/* ================= POST 处理 ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'login':
            if (admin_login(trim($_POST['user'] ?? ''), (string)($_POST['pass'] ?? ''))) {
                header('Location: index.php');
                exit;
            }
            $login_err = '用户名或密码错误 ╮(╯▽╰)╭';
            break;

        case 'logout':
            admin_logout();
            header('Location: index.php');
            exit;

        case 'channel_add':
        case 'channel_update':
            save_channel($action === 'channel_update' ? (int)($_POST['id'] ?? 0) : null);
            redirect('channels');
            break;

        case 'channel_delete':
            db()->prepare('DELETE FROM channels WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            redirect('channels');
            break;

        case 'channel_test':
            channel_test_json(); // 返回 JSON，供前台 fetch
            break;

        case 'interface_add':
        case 'interface_update':
            save_interface($action === 'interface_update' ? (int)($_POST['id'] ?? 0) : null);
            redirect('interfaces');
            break;

        case 'interface_delete':
            db()->prepare('DELETE FROM interfaces WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            redirect('interfaces');
            break;

        case 'interface_recommend':
            // 设为推荐（先清空其他推荐）
            $db = db();
            $db->prepare('UPDATE interfaces SET is_recommend = 0')->execute();
            $db->prepare('UPDATE interfaces SET is_recommend = 1 WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            redirect('interfaces');
            break;

        case 'key_add':
            add_key();
            redirect('keys');
            break;

        case 'key_toggle':
            db()->prepare('UPDATE api_keys SET status = 1 - status WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            redirect('keys');
            break;

        case 'key_delete':
            db()->prepare('DELETE FROM api_keys WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            redirect('keys');
            break;

        case 'settings_save':
            save_settings();
            redirect('settings');
            break;

        case 'log_export':
            export_logs();
            break;
    }
}

function redirect(string $page): void {
    header('Location: index.php?page=' . $page . '&msg=ok');
    exit;
}

/* ================= 登录页 ================= */
if (!admin_check()) {
    render_login($login_err ?? '');
    exit;
}

/* ================= 路由 ================= */
$page = $_GET['page'] ?? 'dashboard';
$msg  = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI 中转站 · 管理后台</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <span class="logo">⚡</span>
    <span class="brand-name">AI Gateway</span>
    <span class="brand-sub">中转站管理后台</span>
  </div>
  <nav>
    <a href="?page=dashboard"  class="<?= $page==='dashboard'?'active':'' ?>">📊 数据总览</a>
    <a href="?page=channels"   class="<?= $page==='channels'?'active':'' ?>">🔌 渠道管理</a>
    <a href="?page=interfaces" class="<?= $page==='interfaces'?'active':'' ?>">🌐 接口管理</a>
    <a href="?page=keys"       class="<?= $page==='keys'?'active':'' ?>">🔑 密钥管理</a>
    <a href="?page=logs"       class="<?= $page==='logs'?'active':'' ?>">📜 请求日志</a>
    <a href="?page=docs"       class="<?= $page==='docs'?'active':'' ?>">📖 接入文档</a>
    <a href="?page=settings"   class="<?= $page==='settings'?'active':'' ?>">⚙️ 设置</a>
    <a href="user.php"         target="_blank">👥 用户面板</a>
  </nav>
  <div class="topbar-right">
    <span class="whoami">👑 <?= e(setting_get('admin_user', DEFAULT_ADMIN_USER)) ?></span>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="logout">
      <button class="btn btn-ghost btn-sm" type="submit">退出</button>
    </form>
  </div>
</header>

<main>
<?php if ($msg === 'ok'): ?>
  <div class="toast">✅ 操作成功</div>
<?php endif; ?>

<?php
switch ($page) {
    case 'channels':   page_channels();   break;
    case 'interfaces': page_interfaces(); break;
    case 'keys':       page_keys();       break;
    case 'logs':       page_logs();       break;
    case 'docs':       page_docs();       break;
    case 'settings':   page_settings();   break;
    default:           page_dashboard();  break;
}
?>
</main>

<footer class="footer">AI Gateway · 让每个人都能轻松跑中转站 (｡•̀ᴗ-)✧</footer>
<script src="assets/app.js"></script>
</body>
</html>
<?php

/* ================================================================
 * 各页面
 * ================================================================ */

function page_dashboard(): void {
    $stats = get_stats();
    $daily = get_daily_tokens(14);
    $max   = max(array_merge([1], array_values($daily)));

    $recent = db()->query('SELECT * FROM logs ORDER BY id DESC LIMIT 10')->fetchAll();
    ?>
    <h1 class="page-title">数据总览</h1>

    <div class="cards">
        <div class="card"><div class="card-num"><?= number_format($stats['total_requests']) ?></div><div class="card-label">累计请求</div></div>
        <div class="card"><div class="card-num accent"><?= number_format($stats['total_tokens']) ?></div><div class="card-label">累计 Token</div></div>
        <div class="card"><div class="card-num"><?= number_format($stats['today_requests']) ?></div><div class="card-label">今日请求</div></div>
        <div class="card"><div class="card-num accent"><?= number_format($stats['today_tokens']) ?></div><div class="card-label">今日 Token</div></div>
        <div class="card"><div class="card-num"><?= $stats['channels'] ?></div><div class="card-label">启用渠道</div></div>
        <div class="card"><div class="card-num"><?= $stats['keys'] ?></div><div class="card-label">有效密钥</div></div>
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

    <div class="panel">
        <h2>🕘 最近请求</h2>
        <?php render_log_table($recent); ?>
    </div>
    <?php
}

function page_interfaces(): void {
    $db = db();
    $editing = null;
    if (!empty($_GET['edit'])) {
        $st = $db->prepare('SELECT * FROM interfaces WHERE id = ?');
        $st->execute([(int)$_GET['edit']]);
        $editing = $st->fetch();
    }
    $list = get_all_interfaces();
    ?>
    <h1 class="page-title">接口管理</h1>

    <div class="panel">
        <h2><?= $editing ? '✏️ 编辑接口 #' . (int)$editing['id'] : '➕ 添加接口' ?></h2>
        <p class="notes" style="margin-bottom:14px">这里管理的是<b>展示给下游用户的接入地址</b>（如推荐接口、全国加速接口等），用户面板会按列表显示。</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="<?= $editing ? 'interface_update' : 'interface_add' ?>">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <label>接口名称<small>显示给用户的名字</small><input name="name" required placeholder="如: 推荐接口 / 全国加速接口" value="<?= e($editing['name'] ?? '') ?>"></label>
            <label>接口地址<small>完整的接入地址</small><input name="url" required placeholder="https://你的域名/v1" value="<?= e($editing['url'] ?? '') ?>"></label>
            <label>排序<small>数字越小越靠前</small><input type="number" name="sort" value="<?= (int)($editing['sort'] ?? 0) ?>"></label>
            <label>状态<select name="status">
                <option value="1" <?= ($editing['status'] ?? 1) == 1 ? 'selected' : '' ?>>显示</option>
                <option value="0" <?= ($editing['status'] ?? 1) == 0 ? 'selected' : '' ?>>隐藏</option>
            </select></label>

            <div class="span2 form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '添加接口' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="?page=interfaces">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>🌐 接口列表 <span class="hint">共 <?= count($list) ?> 个</span></h2>
        <table class="tbl">
            <thead><tr><th>ID</th><th>名称</th><th>地址</th><th>排序</th><th>推荐</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($list as $f): ?>
            <tr>
                <td>#<?= (int)$f['id'] ?></td>
                <td><b><?= e($f['name']) ?></b></td>
                <td class="mono"><?= e($f['url']) ?></td>
                <td><?= (int)$f['sort'] ?></td>
                <td><?= $f['is_recommend'] ? '<span class="badge ok">⭐ 推荐</span>' : '—' ?></td>
                <td><span class="badge <?= $f['status'] ? 'ok' : 'off' ?>"><?= $f['status'] ? '显示' : '隐藏' ?></span></td>
                <td class="ops">
                    <a class="btn btn-sm" href="?page=interfaces&edit=<?= (int)$f['id'] ?>">编辑</a>
                    <?php if (!$f['is_recommend']): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="interface_recommend">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button class="btn btn-sm" type="submit">设为推荐</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除接口「<?= e($f['name']) ?>」吗？')">
                        <input type="hidden" name="action" value="interface_delete">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?><tr><td colspan="7" class="empty">还没有接口，先添加一个吧 (๑•̀ㅂ•́)و✧</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function save_interface(?int $id): void {
    $name = trim($_POST['name'] ?? '');
    $url  = trim($_POST['url'] ?? '');
    $sort = (int)($_POST['sort'] ?? 0);
    $st   = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;
    if ($name === '' || $url === '') {
        exit('接口名称和地址均为必填');
    }
    if ($id) {
        db()->prepare('UPDATE interfaces SET name=?, url=?, sort=?, status=? WHERE id=?')
           ->execute([$name, $url, $sort, $st, $id]);
    } else {
        db()->prepare('INSERT INTO interfaces (name, url, sort, status) VALUES (?,?,?,?)')
           ->execute([$name, $url, $sort, $st]);
    }
}

function page_channels(): void {
    $db = db();
    $editing = null;
    if (!empty($_GET['edit'])) {
        $st = $db->prepare('SELECT * FROM channels WHERE id = ?');
        $st->execute([(int)$_GET['edit']]);
        $editing = $st->fetch();
    }
    $channels = $db->query('SELECT * FROM channels ORDER BY priority ASC, id ASC')->fetchAll();
    ?>
    <h1 class="page-title">渠道管理</h1>

    <div class="panel">
        <h2><?= $editing ? '✏️ 编辑渠道 #' . (int)$editing['id'] : '➕ 添加渠道' ?></h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="<?= $editing ? 'channel_update' : 'channel_add' ?>">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <label>渠道名称<input name="name" required placeholder="如: 上游A站" value="<?= e($editing['name'] ?? '') ?>"></label>
            <label>上游地址<small>你的上游接口地址，随时可改</small><input name="base_url" required placeholder="https://api.upstream.com/v1" value="<?= e($editing ? (channel_urls($editing)[0] ?? '') : '') ?>"></label>
            <label>上游 API Key<input name="api_key" required placeholder="sk-..." value="<?= e($editing['api_key'] ?? '') ?>"></label>
            <label>优先级<small>数字越小越优先（同模型多渠道时）</small><input type="number" name="priority" value="<?= (int)($editing['priority'] ?? 0) ?>"></label>
            <label>超时秒数<small>默认 120</small><input type="number" name="timeout" value="<?= (int)($editing['timeout'] ?? 120) ?>"></label>
            <label>状态<select name="status">
                <option value="1" <?= ($editing['status'] ?? 1) == 1 ? 'selected' : '' ?>>启用</option>
                <option value="0" <?= ($editing['status'] ?? 1) == 0 ? 'selected' : '' ?>>停用</option>
            </select></label>
            <label class="span2">支持的模型<small>每行一个，填 <code>*</code> 表示转发所有模型</small>
                <textarea name="models" rows="5" placeholder="gpt-4o&#10;claude-sonnet-4-5&#10;*"><?php
                    $ml = $editing['models'] ?? '["*"]';
                    $arr = json_decode($ml, true) ?: ['*'];
                    echo e(implode("\n", $arr));
                ?></textarea>
            </label>
            <label class="span2">额外请求头<small>JSON 格式，如 {"X-Custom":"abc"}，可选</small>
                <textarea name="extra_headers" rows="2" placeholder='{"X-Project":"my-gateway"}'><?= e($editing['extra_headers'] ?? '{}') ?></textarea>
            </label>

            <div class="span2 form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '添加渠道' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="?page=channels">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>📡 渠道列表 <span class="hint">共 <?= count($channels) ?> 个</span></h2>
        <table class="tbl">
            <thead><tr><th>ID</th><th>名称</th><th>上游地址</th><th>模型</th><th>优先级</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($channels as $c): $models = json_decode($c['models'], true) ?: []; $urls = channel_urls($c); ?>
            <tr>
                <td>#<?= (int)$c['id'] ?></td>
                <td><?= e($c['name']) ?></td>
                <td class="mono"><?= e($urls[0] ?? '—') ?></td>
                <td class="mono models-cell"><?= e(implode(', ', array_slice($models, 0, 4))) ?><?= count($models) > 4 ? ' …' : '' ?></td>
                <td><?= (int)$c['priority'] ?></td>
                <td><span class="badge <?= $c['status'] ? 'ok' : 'off' ?>"><?= $c['status'] ? '启用' : '停用' ?></span></td>
                <td class="ops">
                    <a class="btn btn-sm" href="?page=channels&edit=<?= (int)$c['id'] ?>">编辑</a>
                    <button class="btn btn-sm btn-test" data-id="<?= (int)$c['id'] ?>">测试</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除渠道「<?= e($c['name']) ?>」吗？')">
                        <input type="hidden" name="action" value="channel_delete">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$channels): ?><tr><td colspan="7" class="empty">还没有渠道，先在上面添加一个吧 (๑•̀ㅂ•́)و✧</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div id="test-result"></div>
    </div>
    <?php
}

function page_keys(): void {
    $db = db();
    $keys = $db->query('SELECT * FROM api_keys ORDER BY id DESC')->fetchAll();
    ?>
    <h1 class="page-title">密钥管理</h1>

    <div class="panel">
        <h2>🎫 生成新密钥</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="key_add">
            <label>备注名称<small>给下游用户起个名，如: 张三-办公</small><input name="name" placeholder="张三-办公"></label>
            <label>到期时间<small>留空 = 永不过期</small><input type="datetime-local" name="expires_at"></label>
            <label>备注<small>内部备注，可选</small><input name="remark" placeholder="内部备注"></label>
            <div class="span2 form-actions"><button class="btn btn-primary" type="submit">✨ 生成密钥</button></div>
        </form>
    </div>

    <div class="panel">
        <h2>🔑 密钥列表 <span class="hint">共 <?= count($keys) ?> 个</span></h2>
        <table class="tbl">
            <thead><tr><th>ID</th><th>密钥</th><th>备注</th><th>到期时间</th><th>状态</th><th>最后使用</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): ?>
            <tr>
                <td>#<?= (int)$k['id'] ?></td>
                <td class="mono">
                    <span class="key-mask"><?= e(substr($k['key'], 0, 6)) ?>••••••<?= e(substr($k['key'], -4)) ?></span>
                    <button class="btn btn-sm btn-copy" data-full="<?= e($k['key']) ?>" title="复制完整密钥">📋</button>
                </td>
                <td><?= e($k['name'] ?: $k['remark']) ?></td>
                <td class="<?= $k['expires_at'] && strtotime($k['expires_at']) < time() ? 'text-danger' : '' ?>">
                    <?= $k['expires_at'] ? e($k['expires_at']) : '永久' ?>
                </td>
                <td><span class="badge <?= $k['status'] ? 'ok' : 'off' ?>"><?= $k['status'] ? '有效' : '禁用' ?></span></td>
                <td><?= e($k['last_used_at'] ?: '—') ?></td>
                <td class="ops">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="key_toggle">
                        <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                        <button class="btn btn-sm" type="submit"><?= $k['status'] ? '禁用' : '启用' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除该密钥吗？删除后无法恢复！')">
                        <input type="hidden" name="action" value="key_delete">
                        <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$keys): ?><tr><td colspan="7" class="empty">还没有密钥，点上面的「生成密钥」发一个给下游用户吧 ✨</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function page_logs(): void {
    $db = db();
    $per = 20;
    $pageNo = max(1, (int)($_GET['p'] ?? 1));
    $q  = trim($_GET['q'] ?? '');
    $st = trim($_GET['st'] ?? '');

    $where = [];
    $params = [];
    if ($q !== '') { $where[] = 'api_key LIKE ?'; $params[] = '%' . $q . '%'; }
    if ($st !== '') { $where[] = 'status_code = ?'; $params[] = (int)$st; }
    $wh = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = db()->prepare("SELECT COUNT(*) FROM logs $wh");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, ceil($total / $per));

    $sql = "SELECT * FROM logs $wh ORDER BY id DESC LIMIT $per OFFSET " . (($pageNo - 1) * $per);
    $st2 = db()->prepare($sql);
    $st2->execute($params);
    $rows = $st2->fetchAll();
    ?>
    <h1 class="page-title">请求日志</h1>

    <div class="panel">
        <form method="get" class="form-inline">
            <input type="hidden" name="page" value="logs">
            <input name="q" placeholder="按密钥搜索…" value="<?= e($q) ?>">
            <input name="st" placeholder="状态码(如 200)" value="<?= e($st) ?>">
            <button class="btn btn-primary" type="submit">筛选</button>
            <button class="btn btn-ghost" type="submit" formaction="?page=logs" formmethod="post" name="action" value="log_export">⬇️ 导出 CSV</button>
        </form>
        <table class="tbl">
            <thead><tr><th>时间</th><th>密钥</th><th>渠道</th><th>模型</th><th>路径</th><th>Prompt</th><th>完成</th><th>总Token</th><th>状态</th><th>延迟</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['created_at']) ?></td>
                <td class="mono"><?= e(substr($r['api_key'], 0, 6)) ?>•••<?= e(substr($r['api_key'], -4)) ?></td>
                <td><?= e($r['channel_name']) ?></td>
                <td class="mono"><?= e($r['model'] ?: '—') ?></td>
                <td class="mono">/<?= e($r['path']) ?></td>
                <td><?= number_format($r['prompt_tokens']) ?></td>
                <td><?= number_format($r['completion_tokens']) ?></td>
                <td><b><?= number_format($r['total_tokens']) ?></b></td>
                <td><span class="badge <?= $r['status_code'] < 400 ? 'ok' : 'err' ?>"><?= (int)$r['status_code'] ?></span></td>
                <td><?= $r['latency_ms'] ?>ms</td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="10" class="empty">暂无日志。转发过请求后这里会出现数据 (◕ᴗ◕)</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ($pages > 1): ?>
        <div class="pager">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a class="page-link <?= $i === $pageNo ? 'active' : '' ?>"
                   href="?page=logs&p=<?= $i ?>&q=<?= urlencode($q) ?>&st=<?= urlencode($st) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function page_docs(): void {
    $base = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/v1';
    $keyExample = KEY_PREFIX . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    ?>
    <h1 class="page-title">接入文档</h1>
    <div class="panel">
        <h2>🔗 基本信息</h2>
        <table class="kv">
            <tr><td>Base URL</td><td class="mono"><?= e($base) ?></td></tr>
            <tr><td>鉴权方式</td><td class="mono">Authorization: Bearer &lt;你的密钥&gt;</td></tr>
            <tr><td>密钥示例</td><td class="mono"><?= e($keyExample) ?></td></tr>
            <tr><td>兼容性</td><td>OpenAI 格式，ChatGPT 类客户端 / one-api / new-api 等均可直接接入</td></tr>
        </table>
    </div>

    <div class="panel">
        <h2>🐚 curl 调用（非流式）</h2>
<pre class="code">curl <?= e($base) ?>/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <?= e($keyExample) ?>" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "你好"}]
  }'</pre>
    </div>

    <div class="panel">
        <h2>🐚 curl 调用（流式）</h2>
<pre class="code">curl <?= e($base) ?>/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <?= e($keyExample) ?>" \
  -d '{
    "model": "gpt-4o",
    "stream": true,
    "messages": [{"role": "user", "content": "讲个冷笑话"}]
  }'</pre>
    </div>

    <div class="panel">
        <h2>🐍 Python（openai 库）</h2>
<pre class="code">from openai import OpenAI

client = OpenAI(
    base_url="<?= e($base) ?>",   # 改成你的中转站地址
    api_key="<?= e($keyExample) ?>",    # 换成你的密钥
)

resp = client.chat.completions.create(
    model="gpt-4o",
    messages=[{"role": "user", "content": "你好"}],
)
print(resp.choices[0].message.content)</pre>
    </div>

    <div class="panel">
        <h2>📌 注意事项</h2>
        <ul class="notes">
            <li>密钥有到期时间，过期后调用会返回 <code>403 permission_error</code>，记得提前续期。</li>
            <li>Token 用量在「数据总览」和「请求日志」里可以查看。</li>
            <li>请求会被原样转发到上游，上游返回的错误信息也会原样透传。</li>
        </ul>
    </div>
    <?php
}

function page_settings(): void {
    $user = setting_get('admin_user', DEFAULT_ADMIN_USER);
    $passChanged = setting_get('admin_pass_hash') !== null;
    ?>
    <h1 class="page-title">设置</h1>

    <div class="panel" style="max-width:520px">
        <h2>🔐 管理员账号</h2>
        <?php if (!$passChanged): ?>
        <div class="alert">⚠️ 你还在使用默认密码（<?= e(DEFAULT_ADMIN_PASS) ?>），请立即修改！</div>
        <?php endif; ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="settings_save">
            <label>用户名<input name="user" value="<?= e($user) ?>" required></label>
            <label>新密码<small>留空则不修改</small><input type="password" name="pass" autocomplete="new-password"></label>
            <label>确认新密码<input type="password" name="pass2" autocomplete="new-password"></label>
            <div class="span2 form-actions"><button class="btn btn-primary" type="submit">保存</button></div>
        </form>
    </div>

    <div class="panel" style="max-width:520px">
        <h2>ℹ️ 系统信息</h2>
        <table class="kv">
            <tr><td>PHP 版本</td><td class="mono"><?= PHP_VERSION ?></td></tr>
            <tr><td>数据库</td><td class="mono">SQLite <?= class_exists('PDO') ? 'PDO ' . (db()->getAttribute(PDO::ATTR_SERVER_VERSION) ?? '') : '—' ?></td></tr>
            <tr><td>数据库文件</td><td class="mono"><?= e(DB_PATH) ?></td></tr>
            <tr><td>密钥前缀</td><td class="mono"><?= e(KEY_PREFIX) ?></td></tr>
        </table>
    </div>
    <?php
}

/* ================================================================
 * 工具函数
 * ================================================================ */

function render_login(string $err = ''): void {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 · AI 中转站</title>
    <link rel="stylesheet" href="assets/style.css">
    </head>
    <body class="login-body">
    <div class="login-box">
        <div class="login-logo">⚡</div>
        <h1>AI Gateway</h1>
        <p class="login-sub">AI 中转站管理后台</p>
        <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <input name="user" placeholder="用户名" required autofocus>
            <input type="password" name="pass" placeholder="密码" required>
            <button class="btn btn-primary btn-block" type="submit">登 录</button>
        </form>
        <p class="login-tip">默认账号 admin / admin123，登录后请立即修改 ⚠️</p>
    </div>
    </body>
    </html>
    <?php
}

function render_log_table(array $rows): void {
    ?>
    <table class="tbl">
        <thead><tr><th>时间</th><th>密钥</th><th>模型</th><th>Total</th><th>状态</th><th>延迟</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['created_at']) ?></td>
            <td class="mono"><?= e(substr($r['api_key'], 0, 6)) ?>•••<?= e(substr($r['api_key'], -4)) ?></td>
            <td class="mono"><?= e($r['model'] ?: '—') ?></td>
            <td><b><?= number_format($r['total_tokens']) ?></b></td>
            <td><span class="badge <?= $r['status_code'] < 400 ? 'ok' : 'err' ?>"><?= (int)$r['status_code'] ?></span></td>
            <td><?= $r['latency_ms'] ?>ms</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无数据</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php
}

function save_channel(?int $id): void {
    $name = trim($_POST['name'] ?? '');
    $base = trim($_POST['base_url'] ?? '');
    $key  = trim($_POST['api_key'] ?? '');
    $prio = (int)($_POST['priority'] ?? 0);
    $time = max(5, (int)($_POST['timeout'] ?? 120));
    $st   = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;

    if ($name === '' || $base === '' || $key === '') {
        exit('名称 / 上游地址 / 上游 API Key 均为必填');
    }

    $urlsJson = json_encode([$base], JSON_UNESCAPED_UNICODE); // 兼容多地址字段

    // models: 每行一个 → JSON 数组
    $mlines = preg_split('/[\r\n,]+/', trim($_POST['models'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $mlines = array_map('trim', $mlines);
    $modelsJson = json_encode($mlines ?: ['*'], JSON_UNESCAPED_UNICODE);

    // extra_headers 校验
    $extra = trim($_POST['extra_headers'] ?? '{}');
    if ($extra === '') { $extra = '{}'; }
    json_decode($extra);
    if (json_last_error() !== JSON_ERROR_NONE) {
        exit('额外请求头不是合法 JSON');
    }

    if ($id) {
        db()->prepare('UPDATE channels SET name=?, base_url=?, urls=?, api_key=?, models=?, extra_headers=?, priority=?, timeout=?, status=? WHERE id=?')
           ->execute([$name, $base, $urlsJson, $key, $modelsJson, $extra, $prio, $time, $st, $id]);
    } else {
        db()->prepare('INSERT INTO channels (name, base_url, urls, api_key, models, extra_headers, priority, timeout, status) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$name, $base, $urlsJson, $key, $modelsJson, $extra, $prio, $time, $st]);
    }
}

/** 测试渠道：逐个请求上游 /v1/models（输出 JSON 供前台展示） */
function channel_test_json(): void {
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM channels WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) { json_out(['ok' => false, 'msg' => '渠道不存在'], 404); }

    $urls = channel_urls($c);
    if (!$urls) { json_out(['ok' => false, 'msg' => '该渠道未配置上游地址']); }

    $headers = ['Authorization: Bearer ' . $c['api_key'], 'Content-Type: application/json'];
    $extra = json_decode($c['extra_headers'] ?? '{}', true);
    if (is_array($extra)) {
        foreach ($extra as $k => $v) { $headers[] = "$k: $v"; }
    }

    $results = [];
    $models  = [];
    $anyOk   = false;
    foreach ($urls as $u) {
        $base = rtrim($u, '/');
        // 直连模式（api.php?path=）适配
        if (str_contains($base, 'api.php')) {
            $url = $base . (str_contains($base, '?') ? '&' : '?') . 'path=v1/models';
        } else {
            $url = preg_match('#/v1/?$#', $base) ? $base . '/models' : $base . '/v1/models';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => SSL_VERIFY,
            CURLOPT_SSL_VERIFYHOST => SSL_VERIFY ? 2 : 0,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $ok = $status >= 200 && $status < 300 && $resp !== false;
        if ($ok) { $anyOk = true; }

        $msg = $ok ? '✅ 连接成功' : ('❌ HTTP ' . $status . ($err ? " (curl: $err)" : ''));
        if ($resp !== false && $ok && !$models) {
            $j = json_decode($resp, true);
            if (is_array($j) && isset($j['data']) && is_array($j['data'])) {
                foreach ($j['data'] as $m) {
                    $models[] = is_array($m) ? ($m['id'] ?? '?') : (string)$m;
                }
                $models = array_slice($models, 0, 10);
            }
        }
        $results[] = ['url' => $u, 'ok' => $ok, 'status' => $status, 'msg' => $msg];
    }

    json_out([
        'ok'      => $anyOk,
        'results' => $results,
        'models'  => $models,
        'msg'     => $anyOk
            ? '✅ 至少一个地址可用' . ($models ? '，模型: ' . implode(', ', $models) : '')
            : '⚠️ 全部地址均不可达',
    ]);
}

function add_key(): void {
    $name = trim($_POST['name'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $expires = trim($_POST['expires_at'] ?? '');
    if ($expires !== '') {
        $expires = date('Y-m-d H:i:s', strtotime($expires));
    } else {
        $expires = null;
    }
    db()->prepare('INSERT INTO api_keys (key, name, expires_at, remark) VALUES (?,?,?,?)')
       ->execute([gen_key(), $name, $expires, $remark]);
}

function save_settings(): void {
    $user = trim($_POST['user'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');
    if ($user === '') { exit('用户名不能为空'); }

    setting_set('admin_user', $user);

    if ($pass !== '' || $pass2 !== '') {
        if ($pass !== $pass2) { exit('两次输入的密码不一致'); }
        if (strlen($pass) < 6) { exit('密码至少 6 位'); }
        setting_set('admin_pass_hash', password_hash($pass, PASSWORD_DEFAULT));
    }
}

/** 导出日志 CSV */
function export_logs(): void {
    $q  = trim($_GET['q'] ?? '');
    $st = trim($_GET['st'] ?? '');
    $where = [];
    $params = [];
    if ($q !== '') { $where[] = 'api_key LIKE ?'; $params[] = '%' . $q . '%'; }
    if ($st !== '') { $where[] = 'status_code = ?'; $params[] = (int)$st; }
    $wh = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st2 = db()->prepare("SELECT * FROM logs $wh ORDER BY id DESC");
    $st2->execute($params);
    $rows = $st2->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=logs_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM，Excel 打开不乱码
    fputcsv($out, ['时间', '密钥', '渠道', '模型', '路径', 'Prompt', '完成', '总Token', '状态', '延迟ms']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'], $r['api_key'], $r['channel_name'], $r['model'], $r['path'],
                       $r['prompt_tokens'], $r['completion_tokens'], $r['total_tokens'], $r['status_code'], $r['latency_ms']]);
    }
    fclose($out);
    exit;
}
