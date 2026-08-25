# ⚡ AI Gateway · AI 中转站

> 轻量级 AI API 中转站，基于 **PHP + SQLite**，零依赖、免安装、开箱即用 (๑•̀ㅂ•́)و✧
>
> 对接上游 AI 服务 / 中转站，向下游用户发放密钥，请求原样转发、响应原样透传，后台管理渠道 / 密钥 / 接口，实时统计 Token 用量与消费 💰

---

## ✨ 功能特性

| 功能 | 说明 |
| --- | --- |
| 🔌 **渠道管理** | 添加任意 OpenAI 兼容上游（地址 + Key + 模型列表），优先级路由，一键测试连通性 |
| 🔑 **密钥管理** | 批量生成密钥，支持**到期时间**（过期自动 403），启停/删除随意 |
| 🌐 **接口管理** | 管理展示给用户的接入地址（如「⭐ 推荐接口」「🚀 全国加速接口」），站点换域名随时改 |
| 📊 **数据总览** | 累计/今日请求数、Token 消耗、近 14 天用量柱状图 |
| 💰 **今日消费** | 按 **DeepSeek V4 官方价格**实时估算（美元 + 人民币），价格表可自定义 |
| 📜 **请求日志** | 逐条记录模型/Token/延迟/状态码，支持筛选、分页、**CSV 导出** |
| 👥 **用户面板** | 下游用户用 `sk-` 密钥直接登录，查看自己的用量、性能、消费、使用记录、接入文档 |
| ⚡ **流式转发** | SSE 流式响应边收边发，Token 用量自动提取入库 |
| 🛡 **错误透传** | 上游 4xx/5xx 错误**原样透传**（状态码 + JSON body），不吞错误 |
| 🎨 **暗色电竞风 UI** | 深色主题 + 青色强调色，管理后台 + 用户面板都好看 (｡•̀ᴗ-)✧ |

---

## 🏗 项目结构

```
├── index.php      # 🎛 管理后台（总览/渠道/接口/密钥/日志/文档/设置）
├── user.php       # 👥 用户自助面板（sk- 密钥登录）
├── api.php        # ⚡ API 代理入口（鉴权 + 转发 + 流式 + 统计）
├── config.php     # ⚙️ 配置文件（管理员账号、密钥前缀、超时、定价表）
├── lib.php        # 🧰 公共库（SQLite 初始化、辅助函数、消费计算）
├── assets/        # 🎨 样式与前端脚本
└── data/          # 🗄 SQLite 数据库（自动生成，请勿上传/泄露！）
```

---

## 🚀 快速部署

### 📋 环境要求

- **PHP ≥ 8.0**（推荐 8.1）+ `pdo_sqlite`、`curl`、`openssl` 扩展
- **Nginx**（宝塔面板 / 原生均可）
- 无需 MySQL、无需 Composer、无需 Node.js ヽ(✿ﾟ▽ﾟ)ノ

### 📦 安装步骤

1. 将源码上传到站点根目录（如 `C:\wwwroot\127.0.0.1` 或 `/www/wwwroot/你的域名`）
2. 确认 `data/` 目录有写入权限（PHP 自动创建数据库）
3. 配置伪静态（见下文）⚠️ **必须配置，否则 API 404**
4. 浏览器打开站点 → 用默认账号登录：
   - 用户名：`admin`
   - 密码：`admin123`
   - 🔒 **登录后请立即在「设置」页修改密码！**

---

## 🔧 伪静态配置（Nginx）⚠️ 必看

API 路径 `/v1/*` 需要重写到 `api.php`，**不配置则所有 API 请求 404**。

### 🐧 宝塔面板（推荐）

1. 网站 → 你的站点 → **设置** → **伪静态**
2. 将下面的规则**完整粘贴**进去 → 保存（面板自动重载 Nginx）

```nginx
# ============ AI Gateway API 伪静态 ============
# /v1/* → api.php（保留 v1 前缀）
location /v1/ {
    rewrite ^/v1/(.*)$ /api.php?path=v1/$1 last;
}

# 兼容：客户端 base_url 漏配 /v1 时自动补全
location ~ ^/(chat/completions|models|embeddings|responses|images|audio|batches|files|fine_tuning)(/|$) {
    rewrite ^/(.*)$ /api.php?path=v1/$1 last;
}
```

### 🐧 原生 Nginx

在站点 `server { }` 块内加入：

```nginx
server {
    # ... 原有配置 ...

    # AI Gateway API 伪静态
    location /v1/ {
        rewrite ^/v1/(.*)$ /api.php?path=v1/$1 last;
    }
    location ~ ^/(chat/completions|models|embeddings|responses|images|audio|batches|files|fine_tuning)(/|$) {
        rewrite ^/(.*)$ /api.php?path=v1/$1 last;
    }
}
```

### ⚠️ 两个关键补充配置（宝塔必做）

**1. 让 PHP 收到 Authorization 头**（否则 Bearer 鉴权失效，一律 401）：

找到 PHP 版本的 fastcgi 配置（Linux 宝塔：`/www/server/nginx/conf/enable-php-81.conf`；Windows 宝塔：`/BtSoft/nginx/conf/php/81.conf`），在 `include fastcgi_params;` 后加一行：

```nginx
fastcgi_param  HTTP_AUTHORIZATION  $http_authorization;
```

**2. 注释错误页劫持**（否则上游返回的 JSON 错误会被 Nginx 换成 HTML 页面）：

站点「配置文件」中，给这两行前面加 `#`：

```nginx
#error_page 404 /404.html;
#error_page 502 /502.html;
```

> 💡 在宝塔面板重新保存站点设置后，请检查：① PHP 版本选 8.1+；② 伪静态规则还在；③ error_page 保持注释状态。

---

## 🔌 使用指南

### 1️⃣ 配置上游渠道

后台「渠道管理」→ 添加渠道：

| 字段 | 说明 | 示例 |
| --- | --- | --- |
| 渠道名称 | 任意名字 | `TokenRhythm上游` |
| 上游地址 | 上游接口地址（带不带 `/v1` 均可，兼容 `api.php?path=` 直连） | `https://api.upstream.com/v1` |
| 上游 API Key | 你在上游的密钥 | `sk-xxxxx` |
| 支持的模型 | 每行一个，`*` = 全部转发 | `deepseek-v4-flash` |
| 优先级 | 数字越小越优先（同模型多渠道时） | `0` |
| 超时秒数 | 流式对话建议 300 | `300` |

支持添加**多个渠道**：请求按模型匹配 → 优先级路由，实现负载均衡 / 多上游容灾 (๑•̀ㅂ•́)و✧

### 2️⃣ 生成密钥

后台「密钥管理」→ 生成密钥：

- **备注名称**：如 `张三-办公`
- **到期时间**：可指定过期时间（留空 = 永久有效，过期后调用返回 `403`）
- 生成的密钥发给下游用户即可

### 3️⃣ 配置对外接口（展示给用户）

后台「接口管理」→ 添加接口（如 `⭐ 推荐接口`、`🚀 全国加速接口`）：

- 接口 = 名称 + 地址，用户面板「接入文档」会按列表展示，带一键复制 📋
- 可设**推荐接口**（用户面板示例代码自动使用）
- 换域名 / 加线路时直接改，用户面板立即生效

---

## 📡 API 用法

### 🔗 接入信息

| 项目 | 值 |
| --- | --- |
| Base URL | `http://你的域名/v1` |
| 鉴权 | `Authorization: Bearer <你的密钥>` |
| 兼容性 | OpenAI 格式，ChatGPT 类客户端 / one-api / new-api / openai SDK 均可 |

### 🐚 curl 调用（非流式）

```bash
curl http://你的域名/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-你的密钥" \
  -d '{
    "model": "deepseek-v4-flash",
    "messages": [{"role": "user", "content": "你好"}]
  }'
```

### 🐚 curl 调用（流式）

```bash
curl http://你的域名/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-你的密钥" \
  -d '{
    "model": "deepseek-v4-flash",
    "stream": true,
    "messages": [{"role": "user", "content": "讲个笑话"}]
  }'
```

### 🐍 Python（openai 库）

```python
from openai import OpenAI

client = OpenAI(
    base_url="http://你的域名/v1",   # 改成你的中转站地址
    api_key="sk-你的密钥",            # 换成你的密钥
)

resp = client.chat.completions.create(
    model="deepseek-v4-flash",
    messages=[{"role": "user", "content": "你好"}],
)
print(resp.choices[0].message.content)
```

### 📚 其他接口

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| `/v1/models` | GET | 模型列表 |
| `/v1/chat/completions` | POST | 对话补全（支持流式） |
| `/v1/embeddings` | POST | 向量化 |
| `/user.php` | GET | 用户用量面板（sk- 密钥登录） |

---

## 💰 消费计算

用户面板「今日消费」按 **DeepSeek 官方价格**估算（2026-08 更新）：

| 模型 | 输入 $/M（峰值） | 输出 $/M（峰值） |
| --- | --- | --- |
| `deepseek-v4-flash` | $0.44 | $1.32 |
| `deepseek-v4-pro` | $1.32 | $3.96 |

- 非峰值时段约为 5 折；价格表在 `config.php` 的 `PRICING`，按模型前缀匹配，可自由增删修改 ヽ(•̀ω•́ )ゝ
- 汇率默认 6.8（`EXCHANGE_RATE`），展示用

---

## 🔒 安全提醒

- ⚠️ **立即修改默认密码** `admin123`（设置页可改）
- ⚠️ `data/gateway.db` 包含渠道密钥与日志，**不要上传到公开仓库**（本仓库已通过 `.gitignore` 排除）
- 公网部署建议将 `config.php` 中 `SSL_VERIFY` 改为 `true`
- 定期备份 `data/` 目录

---

## 🛠 常见问题

| 现象 | 处理 |
| --- | --- |
| 下游报 `401 无效密钥` | 检查密钥是否禁用/删除，Bearer 前缀是否正确 |
| 下游报 `403 已过期` | 「密钥管理」重新设置到期时间 |
| 全部请求 `503 无渠道` | 检查渠道是否启用、模型是否匹配（`*` 通配） |
| API 请求全部 `404` | ⚠️ 伪静态没配置！见上文「伪静态配置」 |
| 鉴权全部 `401` | ⚠️ 没配 `HTTP_AUTHORIZATION` 传递！见上文补充配置 |
| 上游报错变成 HTML 页面 | ⚠️ 注释 `error_page 404/502`！见上文补充配置 |
| 流式输出中断 | 检查渠道超时时间，流式对话建议 300s |
| 上游 429 限流 | 上游限流原样透传，等待重试或配置多个渠道负载均衡 |

---

## 📜 开源协议

[MIT](./LICENSE) · 自由使用、自由修改、自由分享 ٩(◕‿◕｡)۶

> ⚡ AI Gateway — 让每个人都能轻松搭建自己的 AI 中转站 (｡•̀ᴗ-)✧
