<?php
/**
 * 启动前检查（版本守卫 + 扩展守卫）
 *
 * 为什么需要这个文件：
 *   入口文件（router.php / api/index.php）里用了 8.0+ 的语法。如果服务器 PHP 太老，
 *   整个文件在「编译阶段」就 Parse error，任何请求都进不到业务代码，浏览器只看到
 *   nginx 的 404 或一段裸的 Parse error。
 *
 * 本文件的职责（只有两件事）：
 *   1. 硬门槛：PHP < 8.1 → 输出一张可读的说明页并退出（代码语法确实需要 8.1）
 *   2. 扩展缺失：pdo_sqlite / json / mbstring 缺失 → 输出可读说明页
 *
 * ⚠️ 重要修正（v5.4）：
 *   旧版本把 PHP 8.5 当成「硬门槛」，导致 8.1 / 8.2 / 8.3 / 8.4 的服务器上
 *   **每一个请求都返回 HTTP 500**，安装页因此显示「无法读取安装状态：HTTP 500」。
 *   8.5 只是「推荐版本」，不是运行前提。现在：
 *     - 硬门槛 = 8.1（低于此无法解析代码）
 *     - 推荐   = 8.5（低于此仅提示，不阻塞）
 *
 * 本文件必须保持「PHP 5.6 也能解析」的语法水平：不用箭头函数、不用 match、
 * 不用类型化属性、不用 nullsafe、不用 never/mixed 返回类型。
 */

// 硬门槛：代码语法实际需要 8.1（枚举 / never / mixed / readonly 等）
if (!defined('SHOP_HARD_MIN_PHP')) {
    define('SHOP_HARD_MIN_PHP', '8.1.0');
}
// 推荐版本：8.5（用户线上环境将升级到 8.5）。低于此只提示，不阻塞。
if (!defined('SHOP_RECOMMENDED_PHP')) {
    define('SHOP_RECOMMENDED_PHP', '8.5.0');
}
// 兼容旧常量名（其它文件可能引用）
if (!defined('SHOP_MIN_PHP')) {
    define('SHOP_MIN_PHP', SHOP_HARD_MIN_PHP);
}

/**
 * 轻量读取 config/.env（本文件在 config/config.php 之前执行，不能依赖 Env 类）
 */
function shop_php_env_value($key)
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    static $map = null;
    if ($map === null) {
        $map = array();
        $file = dirname(__DIR__) . '/config/.env';
        if (is_file($file) && is_readable($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $k = trim(substr($line, 0, $pos));
                    if ($k === '' || !preg_match('/^[A-Z0-9_]+$/', $k)) {
                        continue;
                    }
                    $val = trim(substr($line, $pos + 1));
                    $len = strlen($val);
                    if ($len >= 2) {
                        $f = $val[0];
                        $l = $val[$len - 1];
                        if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                            $val = substr($val, 1, -1);
                        }
                    }
                    $map[$k] = $val;
                }
            }
        }
    }
    return isset($map[$key]) ? $map[$key] : false;
}

/**
 * 是否允许在低于硬门槛的环境下继续（仅本地开发/CI）
 */
function shop_php_check_bypassed()
{
    $env = shop_php_env_value('SHOP_ENV');
    $allow = shop_php_env_value('SHOP_ALLOW_OLD_PHP');
    return ($env === 'development' || $env === 'dev')
        && ($allow === '1' || strtolower((string)$allow) === 'true');
}

/**
 * 当前请求是否期望 JSON 响应（/api/* 一律 JSON）
 *
 * 为什么需要：/api/* 的调用方是 fetch + JSON.parse。如果这里输出 HTML 说明页，
 * 前端解析失败只会显示「服务器返回异常」，把真实原因（版本/扩展问题）掩盖掉。
 * 所以对 API 请求必须输出结构化 JSON。
 */
function shop_php_wants_json()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) {
        $path = $uri;
    }
    // 兼容子目录部署：/shop/api/xxx 也算
    return (bool)preg_match('#(^|/)api(/|$)#', $path);
}

/**
 * 输出一个 JSON 错误并退出（供 /api/* 请求使用）
 */
function shop_php_json_die($code, $message, $status)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    if (!defined('SHOP_API_SENT')) { define('SHOP_API_SENT', true); }
    echo json_encode(array(
        'ok'    => false,
        'error' => array('code' => $code, 'message' => $message),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 输出一张可读的「PHP 版本过低」页面并退出
 *
 * 状态码用 503（服务不可用）而不是 500：这是环境问题，不是程序崩溃，
 * 503 更准确，也便于监控区分。
 */
function shop_php_version_die($current, $hard, $recommended)
{
    // /api/* 请求：输出 JSON，让前端能显示真实原因
    if (shop_php_wants_json()) {
        shop_php_json_die(
            'php_version_too_low',
            '服务器 PHP 版本过低：当前 ' . $current . '，本站最低要求 ' . $hard . '（推荐 ' . $recommended . '）',
            503
        );
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    $cur = htmlspecialchars($current, ENT_QUOTES, 'UTF-8');
    $hd  = htmlspecialchars($hard, ENT_QUOTES, 'UTF-8');
    $rc  = htmlspecialchars($recommended, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>PHP 版本过低</title><style>'
        . 'body{margin:0;background:#f5f6f8;color:#12161c;font:15px/1.75 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px}'
        . '.box{background:#fff;border:1px solid rgba(18,22,28,.09);border-radius:14px;box-shadow:0 8px 28px rgba(18,22,28,.06);padding:36px 34px;max-width:620px}'
        . 'h1{font-size:20px;margin:0 0 14px;font-weight:600}'
        . 'p{color:#4a5158;font-size:14px;margin:0 0 12px}'
        . 'code{background:#f0f2f5;padding:1px 6px;border-radius:4px;font-size:13px}'
        . 'ul{color:#4a5158;font-size:14px;margin:8px 0 0;padding-left:20px}'
        . 'li{margin:4px 0}'
        . '.bad{color:#c0392b;font-weight:600}'
        . '</style></head><body><div class="box">'
        . '<h1>⚠️ 服务器 PHP 版本过低，站点无法运行</h1>'
        . '<p>当前 PHP 版本：<span class="bad">' . $cur . '</span></p>'
        . '<p>本站<strong>最低要求 PHP ' . $hd . '</strong>（推荐 ' . $rc . '）。</p>'
        . '<p>这不是程序 bug，而是运行环境不满足要求。请升级 PHP 后重试：</p>'
        . '<ul>'
        . '<li>宝塔面板：软件商店 → PHP → 安装 ' . $rc . ' → 站点设置里把 PHP 版本切过去</li>'
        . '<li>命令行：<code>apt install php8.5-fpm php8.5-sqlite3 php8.5-mbstring</code></li>'
        . '<li>升级后重启 PHP-FPM：<code>systemctl restart php8.5-fpm</code></li>'
        . '<li>确认 <code>php -v</code> 输出为 ' . $rc . ' 以上，再刷新本页</li>'
        . '</ul>'
        . '<p style="margin-top:18px;color:#7b838c;font-size:13px">'
        . '提示：本地开发环境可在 <code>config/.env</code> 里设置 '
        . '<code>SHOP_ENV=development</code> 与 <code>SHOP_ALLOW_OLD_PHP=1</code> 临时跳过检查（生产环境请勿使用）。'
        . '</p>'
        . '</div></body></html>';
    exit;
}

// ---- 执行检查 ----
if (version_compare(PHP_VERSION, SHOP_HARD_MIN_PHP, '<') && !shop_php_check_bypassed()) {
    // 连语法最低要求都不到：直接拦，避免后续 require 触发 Parse error
    shop_php_version_die(PHP_VERSION, SHOP_HARD_MIN_PHP, SHOP_RECOMMENDED_PHP);
}

// 版本达标：补齐常用扩展检查（缺失时给出可读提示，而不是白屏）
$shopMissingExt = array();
foreach (array('pdo_sqlite', 'json', 'mbstring') as $shopExt) {
    if (!extension_loaded($shopExt)) {
        $shopMissingExt[] = $shopExt;
    }
}
if ($shopMissingExt) {
    // /api/* 请求：输出 JSON，让前端能显示真实原因（而不是「服务器返回异常」）
    if (shop_php_wants_json()) {
        shop_php_json_die(
            'missing_php_extension',
            '服务器缺少必要的 PHP 扩展：' . implode('、', $shopMissingExt) . '（请安装并重启 PHP-FPM）',
            503
        );
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $list = htmlspecialchars(implode('、', $shopMissingExt), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<title>缺少 PHP 扩展</title><style>'
        . 'body{margin:0;background:#f5f6f8;color:#12161c;font:15px/1.75 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh}'
        . '.box{background:#fff;border:1px solid rgba(18,22,28,.09);border-radius:14px;padding:34px;max-width:560px}'
        . 'h1{font-size:19px;margin:0 0 12px}p{color:#4a5158;font-size:14px;margin:0 0 10px}'
        . 'code{background:#f0f2f5;padding:1px 6px;border-radius:4px}'
        . '</style></head><body><div class="box">'
        . '<h1>⚠️ 缺少必要的 PHP 扩展</h1>'
        . '<p>未启用：<strong>' . $list . '</strong></p>'
        . '<p>请安装并启用后重启 PHP-FPM，例如：</p>'
        . '<p><code>apt install php8.5-sqlite3 php8.5-mbstring</code></p>'
        . '</div></body></html>';
    exit;
}

unset($shopExt, $shopMissingExt);
