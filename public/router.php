<?php
/**
 * 统一入口 / 路由（PHP 内置服务器 + Nginx + Apache 共用）
 *
 * 启动方式（PHP 内置服务器，必须带这个文件）：
 *     php -S 127.0.0.1:8090 -t public public/router.php
 *
 * 为什么必须带 router.php：
 *   内置服务器默认只按「静态文件」找路径，/api/orders、/admin 这类虚拟路径
 *   找不到文件就直接 404。带上 router.php 后，所有请求先经过这里再分发。
 *
 * 本文件语法要求：只用 PHP 7.0 就能解析的写法（不用箭头函数 / match / never / mixed），
 * 这样即使服务器版本过低，也能先执行 preflight.php 输出可读的「版本过低」页面，
 * 而不是抛出一段裸的 Parse error（线上 404 的真实成因之一）。
 */

require_once __DIR__ . '/preflight.php';

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/Path.php';

$uri = Path::current();

// 路径穿越与空字节防御
if (strpos($uri, '..') !== false || strpos($uri, "\0") !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad Request';
    return true;
}

// ---------------------------------------------------------------------------
// 敏感文件一律 404（数据库、备份、配置、版本库）
// 应用层独立拦截：即使 Nginx/Apache 规则漏配，也拿不到内容
// ---------------------------------------------------------------------------
$denyExt = '/\.(sqlite|sqlite3|db|db3|sql|sqlitedb|log|ini|env|bak|backup|old|orig|swp|swo|tmp|tar|tgz|gz|bz2|xz|zip|rar|7z|yml|yaml|lock)$/i';
if (preg_match($denyExt, $uri) === 1
    || preg_match('#(^|/)\.(env|git|svn|hg|htaccess|htpasswd|DS_Store)#i', $uri) === 1
    || preg_match('#(^|/)(data|config|database|src|bin|deploy|vendor|node_modules)(/|$)#i', $uri) === 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Not Found';
    return true;
}

// ---------------------------------------------------------------------------
// 计算「相对 public/ 的路径」：自动剥离子目录部署前缀
//   /index.html        → /index.html
//   /shop/index.html   → /index.html
//   /shop/             → /
// ---------------------------------------------------------------------------
$rel  = $uri;
$file = __DIR__ . $rel;

// ---------------------------------------------------------------------------
// v1.2 插件目录：文件不存在时明确 404，不落到「回首页」兜底。
// 否则停用 / 卸载插件后访问旧的 /plugins/<id>/ 会看到商城首页，容易误以为插件还在。
// 用原始 URI 里的 /plugins/ 段判断，兼容子目录部署（此时 $rel 还没剥掉前缀）。
// ---------------------------------------------------------------------------
if (preg_match('#/plugins/(.+)$#', $uri, $pm) === 1) {
    $sub = $pm[1];
    if (strpos($sub, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $sub) === 1) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad Request';
        return true;
    }
    $candidate = __DIR__ . '/plugins/' . $sub;
    if (is_dir($candidate)) {
        $candidate = rtrim($candidate, '/') . '/index.html';
    }
    if (!is_file($candidate)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo 'Not Found';
        return true;
    }
}

if (!is_file($file) && !is_dir($file)) {
    $parts = array_values(array_filter(explode('/', trim($uri, '/')), function ($s) {
        return $s !== '';
    }));
    // 逐段剥离前缀；$i 取到 count 时得到 '/'（子目录根路径的情况）
    for ($i = 1; $i <= count($parts); $i++) {
        $cand = '/' . implode('/', array_slice($parts, $i));
        $cf   = __DIR__ . $cand;
        if (is_file($cf) || is_dir($cf)) {
            $rel  = $cand;
            $file = $cf;
            break;
        }
    }
}

// 归一化 API 路径：/shop/api/orders → /api/orders（子目录部署）
$apiPath = Path::normalizeApi($uri);
$isApi   = ($apiPath === '/api' || strpos($apiPath, '/api/') === 0);

// API 统一入口
if ($isApi) {
    require __DIR__ . '/api/index.php';
    return true;
}

// ---------------------------------------------------------------------------
// 虚拟路由（不对应真实文件）：/install、/admin、/console
// 子目录部署时 $rel 可能仍是 /shop/install（因为 /install 不是真实文件），
// 所以这里同时按「路径后缀」判断，两种部署都能命中。
// ---------------------------------------------------------------------------
$routePath = $rel;
if (preg_match('#/(install|admin|console)/?$#', $uri) === 1) {
    $routePath = '/' . ltrim(preg_replace('#^.*/(install|admin|console)/?$#', '$1', $uri), '/');
}

// 虚拟目录路由必须带结尾斜杠，否则相对资源（assets/...）会解析到错误基址：
// 例如 /admin 不带斜杠时，浏览器把 <script src="assets/api.js"> 算成 /assets/api.js（404），
// 导致后台 JS 全部加载失败、guard() 无法运行、页面不跳登录页。
// 这里 301 补斜杠，并保留查询串（如安装向导可能存在的参数）。
if (preg_match('#^/(install|admin|console|innovative)$#', $uri) === 1) {
    $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
    http_response_code(301);
    header('Location: ' . $uri . '/' . $qs);
    return true;
}

/** 输出一个 HTML 文件（带安全头）
 *  内置服务器直接请求 /router.php 时会把本文件执行两遍，
 *  用 function_exists 守卫避免 "Cannot redeclare shop_send_html()" 造成 500。 */
if (!function_exists('shop_send_html')) {
function shop_send_html($path, $noStore)
{
    if (!is_file($path)) {
        return false;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    if ($noStore) {
        header('Cache-Control: no-store');
    }
    readfile($path);
    return true;
}

/** 输出静态资源（带正确 MIME 与安全头；子目录部署时 $rel 已剥掉前缀） */
function shop_send_static($path)
{
    if (!is_file($path)) {
        return false;
    }
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = array(
        'css' => 'text/css; charset=utf-8',
        'js'  => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json'=> 'application/json; charset=utf-8',
        'html'=> 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg'=> 'image/jpeg',
        'gif' => 'image/gif',
        'webp'=> 'image/webp',
        'avif'=> 'image/avif',
        'ico' => 'image/x-icon',
        'woff'=> 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    );
    header('Content-Type: ' . (isset($mime[$ext]) ? $mime[$ext] : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    // JS / CSS / HTML 属于「会随版本更新的代码资源」，绝不能强缓存：
    // 否则后台新增菜单 / 改路由后，浏览器仍拿旧脚本，表现为「点了没反应、还是旧界面」。
    // 改为 no-cache 协商缓存（带 Last-Modified，未变更时回 304 不重复传内容）。
    // 图片 / 字体等不变资源仍可长缓存。
    $volatile  = array('css','js','mjs','html');
    $cacheable = array('woff','woff2','ttf','png','jpg','jpeg','gif','webp','avif','ico','svg');
    if (in_array($ext, $volatile, true)) {
        $mtime = @filemtime($path);
        header('Cache-Control: no-cache, must-revalidate');
        if ($mtime !== false) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            $ims = isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
                ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
            if ($ims !== false && $ims >= $mtime) {
                http_response_code(304);
                return true;
            }
        }
    } else {
        header('Cache-Control: ' . (in_array($ext, $cacheable, true) ? 'public, max-age=604800' : 'no-store'));
    }
    readfile($path);
    return true;
}
}

// 安装向导：/install、/install/、/install.php 都指向同一页面
if ($routePath === '/install' || $routePath === '/install/' || $routePath === '/install.php') {
    if (shop_send_html(__DIR__ . '/install.html', true)) {
        return true;
    }
}

// 后台页面：/admin、/admin/、/admin/index.html 都指向 admin/index.html
if ($routePath === '/admin' || $routePath === '/admin/' || $routePath === '/admin/index.html') {
    if (shop_send_html(__DIR__ . '/admin/index.html', true)) {
        return true;
    }
}

// 前台用户中心：/console、/console/、/console/index.html 都指向 console/index.html
if ($routePath === '/console' || $routePath === '/console/' || $routePath === '/console/index.html') {
    if (shop_send_html(__DIR__ . '/console/index.html', true)) {
        return true;
    }
}

// 直接请求 public/ 下的 .php 源文件一律 404。
// 与 Nginx「除入口外 .php 一律 404」、Apache 的 .php 拦截保持一致：
// router.php / preflight.php / api/*.php 都是真实文件，若不拦，
// 内置服务器会把它们当静态文件吐出 PHP 源码（配置源码泄露）。
// /api/* 已在上面的统一入口分支 return，不会走到这里。
if (preg_match('/\.php$/i', $uri) === 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Not Found';
    return true;
}

// 静态文件：子目录部署时 $rel 已经剥掉 /shop 前缀，
// 不能 return false 让内置服务器按原始 URI 去文档根找（那样 /shop/assets/app.js 必然 404），
// 必须自己按算出来的真实路径输出。
if ($rel !== '/' && is_file($file)) {
    if (shop_send_static($file)) {
        return true;
    }
    return false;
}

// 目录或根路径 → 首页
if ($rel === '/' || is_dir($file)) {
    // v1.6：后台「站点设置 → 界面风格」为 innovative 时，根路径呈现创新主题首页。
    // 读取失败（未安装/表缺失）时安全回退经典首页，绝不让首页白屏。
    $theme = 'classic';
    try {
        require_once dirname(__DIR__) . '/src/Db.php';
        require_once dirname(__DIR__) . '/src/SettingsService.php';
        $theme = SettingsService::get('site_theme');
    } catch (Throwable $e) {
        $theme = 'classic';
    }
    // 只在站点根路径跳转；/innovative/ 等子目录直接走下面的 index.html 输出，避免自跳死循环。
    if ($rel === '/' && $theme === 'innovative' && is_file(__DIR__ . '/innovative/index.html')) {
        // 创新首页相对资源（api/、assets/）位于 /innovative/ 下，需改写基址。
        // 这里用 302 跳到 /innovative/，让浏览器按正确目录解析资源，避免相对路径 404。
        $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
        header('Location: /innovative/' . $qs);
        return true;
    }
    $index = rtrim($file, '/') . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');   // 首页 HTML 每次取最新，避免引用到已被删改的旧资源
        readfile($index);
        return true;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>404</title>'
   . '<div style="font:15px/1.7 system-ui;padding:60px;color:#12161c">'
   . '<h1 style="font-weight:600">404</h1><p>页面不存在。<a href="/">回到首页</a></p></div>';
return true;
