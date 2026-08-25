<?php
/**
 * AI 中转站 - 配置文件
 * 修改后无需重启，立即生效
 */

// ===== 数据库 =====
// SQLite 数据库文件路径（默认放在 data/ 目录，可改成任意绝对路径）
define('DB_PATH', __DIR__ . '/data/gateway.db');

// ===== 管理员（首次安装账号，登录后请在「设置」页立即修改密码）=====
define('DEFAULT_ADMIN_USER', 'admin');
define('DEFAULT_ADMIN_PASS', 'admin123');

// ===== API 密钥 =====
// 生成的密钥前缀（下游用户拿到的 key 形如 sk-xxxxxxxx）
define('KEY_PREFIX', 'sk-');

// ===== 上游请求 =====
define('UPSTREAM_CONNECT_TIMEOUT', 10);   // 连接上游超时（秒）
define('UPSTREAM_TIMEOUT', 300);          // 整体超时（秒），流式对话建议调大

// 是否校验证书（默认 false：兼容大部分中转站/自签证书；上生产环境建议改 true）
define('SSL_VERIFY', false);

// ===== 消费计算（用户面板用）=====
// 模型前缀 => [输入价$/百万token(缓存未命中), 输出价$/百万token]
// 以 DeepSeek 官方价格为准（2026-08-16 起生效的 V4 峰值价；非峰值时段约为 5 折，缓存命中输入更便宜）
// 前缀匹配，未匹配到用 _default
define('PRICING', [
    'deepseek-v4-pro'        => [1.32, 3.96],   // V4-Pro-0813
    'deepseek-v4-flash'      => [0.44, 1.32],   // V4-Flash-0731（含 vision-exp）
    'deepseek-reasoner'      => [0.55, 2.19],   // 旧模型，官方已下线，仅兼容历史日志
    'deepseek-chat'          => [0.28, 0.42],   // 旧模型，官方已下线，仅兼容历史日志
    '_default'               => [0.44, 1.32],   // 兜底按 V4-Flash 价估算
]);
// 美元 → 人民币 估算汇率（官方中文定价对应 ≈6.8，展示用）
define('EXCHANGE_RATE', 6.8);

// ===== 其他 =====
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
