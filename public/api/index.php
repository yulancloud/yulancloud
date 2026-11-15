<?php
/**
 * HTTP 路由入口 —— 所有 /api/* 请求都从这里分发
 *
 * 安全基线：
 *  - 只接受声明的 HTTP 方法，其他一律 405
 *  - JSON 请求体有大小上限，防御内存耗尽
 *  - 生产环境不向客户端暴露异常细节
 *  - 后台接口全部要求登录 + CSRF 同源校验
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// 【v5.5 加固】保证 /api/* 在任何情况下都只输出纯 JSON
//
// 为什么需要：
//   线上曾出现「HTTP 200 但响应体不是 JSON」的情况，前端 JSON.parse 失败后
//   统一显示「服务器返回异常」，把真实原因完全掩盖。可能污染响应的来源：
//     - PHP warning / notice / deprecated 直接打印到响应体
//     - 致命错误（E_ERROR）在输出中途中断，留下半截 JSON
//     - 某个被 require 的文件在 <?php 之前有 BOM 或空行
//     - 上游（Nginx）把 .php 当静态文件返回源码
//   这里用「关显示 + 缓冲 + 错误转 JSON + 输出前清缓冲」四道闸兜住前三种。
// ---------------------------------------------------------------------------

// 1) 绝不让 PHP 把错误详情写进响应体（日志照记）
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// 2) 开启输出缓冲：任何意外输出都先攒在缓冲里，由 Response::json 决定丢弃还是保留
if (!ob_get_level()) {
    ob_start();
}

/**
 * 把「非 JSON 的意外输出」统一转成 JSON 错误响应
 *
 * 触发时机：脚本正常结束或致命错误退出时。若此时缓冲里已有内容，
 * 说明业务代码没走 Response::json（否则缓冲已被清空并 exit），
 * 那就丢弃这些脏输出，改吐一个结构化 JSON，让前端能看懂。
 */
$shopApiFlushGuard = static function (): void {
    // 已经通过 Response::json 正常输出过（它会把缓冲清空并设置标记）
    if (defined('SHOP_API_SENT')) {
        return;
    }
    $last = error_get_last();
    $fatal = $last !== null
        && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);

    // 丢弃缓冲里的脏输出（warning/notice/半截内容）
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    $detail = $fatal
        ? $last['message'] . ' @ ' . $last['file'] . ':' . $last['line']
        : '接口未返回 JSON（可能被 PHP 警告或上游输出污染）';
    error_log('[shop][api] non-json output: ' . $detail);

    echo json_encode([
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => '服务暂时不可用，请稍后重试',
            'detail'  => $detail,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

register_shutdown_function($shopApiFlushGuard);

// 3) warning / notice 只进日志，绝不进响应体
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    error_log('[shop][php] ' . $str . ' @ ' . $file . ':' . $line);
    return true; // 已处理，不再走 PHP 默认的「打印到输出」
});

// 版本守卫：必须在任何现代语法之前执行，否则低版本 PHP 会直接 Parse error
require_once dirname(__DIR__) . '/preflight.php';

$ROOT = dirname(__DIR__, 2);
$SRC  = $ROOT . '/src';
require_once $SRC . '/Db.php';
require_once $SRC . '/Response.php';
require_once $SRC . '/Validator.php';
require_once $SRC . '/RateLimiter.php';
require_once $SRC . '/ProductRepo.php';
require_once $SRC . '/UserAuth.php';
require_once $SRC . '/BalanceService.php';
require_once $SRC . '/DeliveryService.php';
require_once $SRC . '/ConsoleService.php';
require_once $SRC . '/OrderService.php';
require_once $SRC . '/EPay.php';
require_once $SRC . '/OrderStateMachine.php';
require_once $SRC . '/PaymentService.php';
require_once $SRC . '/Audit.php';
require_once $SRC . '/AdminAuth.php';
require_once $SRC . '/AdminService.php';
require_once $SRC . '/Path.php';
require_once $SRC . '/SettingsService.php';
require_once $SRC . '/YulanConnector.php';
require_once $SRC . '/YulanProvision.php';
require_once $SRC . '/Installer.php';

$cfg = require $ROOT . '/config/config.php';

// 生产环境：错误只进日志，不输出到响应
if ($cfg['env'] === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
error_reporting(E_ALL);

// 未捕获异常统一转 500，不回显堆栈
set_exception_handler(function (Throwable $e) use ($cfg): void {
    error_log('[shop] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::fail('server_error', '服务暂时不可用，请稍后重试', 500);
});

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ---------------------------------------------------------------------------
// 路径归一化：支持子目录部署
//   浏览器在 /shop/ 下打开时，前端可能请求 /shop/api/orders；
//   路由表只认 /api/orders，不归一化就会全部落到 404（这是「点下单弹 404」的主因）。
// ---------------------------------------------------------------------------
$path = Path::normalizeApi(Path::current());
$path = rtrim($path, '/') ?: '/';

// 预检
if ($method === 'OPTIONS') {
    Response::headers();
    if (!defined('SHOP_API_SENT')) { define('SHOP_API_SENT', true); }
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// 安装门禁
//   未安装：除 /api/install、/api/health、/api/site 外全部拒绝，避免暴露半成品站点
//   已安装：/api/install/* 一律 403，无法重复安装
// ---------------------------------------------------------------------------
$installed = Installer::isInstalled();
$isInstallRoute = ($path === '/api/install' || str_starts_with($path, '/api/install/'));
$isInstallStatus = ($path === '/api/install/status');

// 已安装：安装动作一律 403（status 只读，允许访问以便页面显示「已安装」）
if ($isInstallRoute && !$isInstallStatus && $installed) {
    Response::fail('already_installed', '站点已完成安装，安装程序已锁定。如需重装请手动删除 config/installed.lock 与数据库文件', 403);
}
if (!$installed && !$isInstallRoute
    && $path !== '/api/health' && $path !== '/api/site' && $path !== '/api/install/status') {
    Response::fail('not_installed', '站点尚未安装，请先访问 /install 完成初始化', 503);
}

// 已安装时 /api/install/status 仍可读（安装页据此显示「已安装」并拒绝），
// 但 /api/install 的 POST 已在上面被 403 拦掉。

// ---------- 路由表 ----------
switch (true) {

    // ================= 公开接口 =================

    case $path === '/api/health' && $method === 'GET':
        Response::ok([
            'status' => 'up',
            'time' => date('c'),
            'installed' => $installed,
            'version' => '5.5',
            // 构建号：升级后用 curl /api/health 看这里即可确认服务器跑的是不是新代码
            'build' => '20261115a',
            'php' => PHP_VERSION,
        ]);

    // 购物袋金额预估（只读、公开、不写库）：服务端按数据库真实 type 计算运费，
    // 前端不再自行判断虚拟商品，避免商品列表被缓存/分页/静态兜底污染时误收运费。
    // 必须放在 /api/orders/{no} 之前，否则会被订单号路由当成单号吃掉。
    case $path === '/api/orders/quote' && $method === 'POST':
        RateLimiter::guard('read');
        Response::ok(OrderService::quote(readJsonBody()));

    // 站点设置（前台读取标题/副标题/版权等，后台改完即时生效）
    case $path === '/api/site' && $method === 'GET':
        Response::ok(SettingsService::publicPayload());

    // ---------------- 安装向导 ----------------

    // 安装状态与自检（未安装时前台据此跳转）
    // 整个状态构建过程包在 try/catch 里：任何一步抛异常（配置缺失、目录不可读、
    // SQLite 扩展未启用导致 PDO 构造失败等）都返回结构化 JSON 错误，
    // 而不是让未捕获异常冒泡成一段裸的 HTTP 500 —— 那正是「无法读取安装状态：HTTP 500」的成因之一。
    case $path === '/api/install/status' && $method === 'GET':
        try {
            Response::ok([
                'installed'    => $installed,
                'db_exists'    => Installer::dbExists(),
                'requirements' => Installer::requirements(),
                'requirements_ok' => Installer::requirementsOk(),
                // 只回传「是否已生成密钥」这个布尔值，不泄露密钥内容
                'install_token_ready' => Installer::installTokenReady(),
            ]);
        } catch (Throwable $e) {
            error_log('[shop][install] status failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::fail(
                'install_status_error',
                '无法读取安装状态：' . $e->getMessage()
                . '（请检查 PHP 版本 ≥ 8.1、pdo_sqlite 扩展是否启用、data/ 与 config/ 目录是否可写）',
                500
            );
        }

    // 执行安装：建库 + 建管理员 + 写锁
    case $path === '/api/install' && $method === 'POST':
        RateLimiter::guard('admin');
        $body = readJsonBody();

        // 安装密钥校验默认关闭（部署需要）。Installer::verifyInstallToken()、
        // tokenPath()、installTokenReady()、bin/install_token.php 等能力完整保留，
        // 将来需要重新启用「防抢装」时，在此调用 verifyInstallToken() 即可。

        if (!Installer::requirementsOk()) {
            Response::fail('requirements_failed', '运行环境不满足要求，请先按页面提示修复', 400);
        }
        if (Installer::hasAdmin()) {
            Response::fail('already_installed', '站点已完成安装，无法重复初始化', 403);
        }

        $u = $body['username'] ?? '';
        $p = $body['password'] ?? '';
        if (!is_string($u) || !is_string($p) || $u === '' || $p === '') {
            Response::fail('invalid_input', '请填写管理员账号与密码', 400);
        }
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $u)) {
            Response::fail('invalid_username', '账号只能用字母、数字、下划线、点、短横，长度 3-32', 400);
        }
        $problem = AdminAuth::passwordProblem($p);
        if ($problem !== null) {
            Response::fail('weak_password', $problem, 400);
        }

        // ---- 站点地址与易支付配置（可选，但强烈建议填） ----
        $siteUrl = is_string($body['site_url'] ?? null) ? trim($body['site_url']) : '';
        $epayPid = is_string($body['epay_pid'] ?? null) ? trim($body['epay_pid']) : '';
        $epayKey = is_string($body['epay_key'] ?? null) ? trim($body['epay_key']) : '';
        $epayGw  = is_string($body['epay_gateway'] ?? null) ? trim($body['epay_gateway']) : '';

        if ($siteUrl !== '') {
            // 只接受 http(s) 绝对地址，且不允许内嵌凭据 / 查询串，避免被塞进回调地址
            $parts = parse_url($siteUrl);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                Response::fail('invalid_site_url', '站点地址格式不正确，应为 https://your-domain.com（不含路径参数）', 400);
            }
            $siteUrl = rtrim($siteUrl, '/');
        }
        if ($epayGw !== '') {
            $g = parse_url($epayGw);
            if (!is_array($g) || empty($g['scheme']) || empty($g['host'])
                || strtolower($g['scheme']) !== 'https'
                || isset($g['user']) || isset($g['pass'])) {
                Response::fail('invalid_gateway', '易支付网关必须是 https 地址，例如 https://pay.example.com/submit.php', 400);
            }
        }
        // PID 与 KEY 必须成对出现，否则视为未配置在线支付
        $epayOn = ($epayPid !== '' && $epayKey !== '' && $epayGw !== '');
        if (($epayPid !== '' || $epayKey !== '') && !$epayOn) {
            Response::fail('incomplete_epay', '易支付配置不完整：PID、KEY、网关地址需要同时填写（或全部留空只用余额支付）', 400);
        }
        if ($epayPid !== '' && !preg_match('/^[A-Za-z0-9]{1,32}$/', $epayPid)) {
            Response::fail('invalid_pid', '易支付 PID 格式不正确（仅字母数字，最长 32 位）', 400);
        }
        if ($epayKey !== '' && (strlen($epayKey) < 8 || strlen($epayKey) > 128)) {
            Response::fail('invalid_key', '易支付 KEY 长度不正确（8-128 位）', 400);
        }

        // 0) 先写 config/.env（在连库之前，保证后续请求读到正确配置）
        $envValues = [
            'SHOP_ENV'      => 'production',
            'SHOP_BASE_URL' => $siteUrl,
            'EPAY_ENABLED'  => $epayOn ? 'true' : 'false',
            'EPAY_PID'      => $epayPid,
            'EPAY_KEY'      => $epayKey,
            'EPAY_GATEWAY'  => $epayGw,
            'EPAY_CHANNELS' => 'wxpay,alipay',
        ];
        if (!Installer::writeEnv($envValues)) {
            Response::fail('env_write_failed', '无法写入 config/.env，请检查 config/ 目录权限（需对 PHP-FPM 运行用户可写）', 500);
        }

        // 1) 建库（显式允许新建）+ 建表
        try {
            Db::conn(true);
        } catch (Throwable $e) {
            error_log('[shop][install] db create failed: ' . $e->getMessage());
            Response::fail('db_unavailable', '无法创建数据库文件，请检查 data/ 目录权限', 500);
        }
        $schema = @file_get_contents($ROOT . '/database/schema.sql');
        if (!is_string($schema) || trim($schema) === '') {
            Response::fail('schema_missing', '缺少 database/schema.sql，无法建表', 500);
        }
        try {
            Db::conn()->exec($schema);
        } catch (Throwable $e) {
            error_log('[shop][install] schema failed: ' . $e->getMessage());
            Response::fail('schema_failed', '建表失败，请检查数据库文件是否可写', 500);
        }

        // 1b) v5 增量表（users / user_sessions / balance_ledger / recharge_orders /
        //     order_deliveries / product_delivery_fields）
        //     ⚠️ 漏掉这一步会导致注册/登录报 "no such table: users"，
        //     命令行 init_db.php 有执行，网页安装向导必须同样执行。
        $schemaV5 = @file_get_contents($ROOT . '/database/schema_v5.sql');
        if (!is_string($schemaV5) || trim($schemaV5) === '') {
            Response::fail('schema_missing', '缺少 database/schema_v5.sql，无法建 v5 表', 500);
        }
        try {
            Db::conn()->exec($schemaV5);
        } catch (Throwable $e) {
            error_log('[shop][install] schema_v5 failed: ' . $e->getMessage());
            Response::fail('schema_failed', 'v5 建表失败，请检查数据库文件是否可写', 500);
        }

        // 2) 灌种子数据（分类/商品/评价）—— 失败不影响安装，只是站上没有商品
        $seeded = false;
        try {
            require_once $ROOT . '/database/Seeder.php';
            $seeded = Seeder::run(Db::conn());
        } catch (Throwable $e) {
            error_log('[shop][install] seed failed: ' . $e->getMessage());
        }

        // 3) 建管理员（本次输入的账号密码就是店主）
        try {
            Db::conn()->prepare(
                "INSERT INTO admin_users (username, password_hash, display_name, role, status, must_change_password)
                 VALUES (?, ?, '店主', 'owner', 'active', 0)"
            )->execute([$u, AdminAuth::hash($p)]);
        } catch (Throwable $e) {
            error_log('[shop][install] admin create failed: ' . $e->getMessage());
            Response::fail('admin_failed', '创建管理员失败，请稍后重试', 500);
        }

        // 4) 写安装锁（最后一步）：写失败就回退管理员，避免「已装但没锁」的半成品
        if (!Installer::writeLock($u)) {
            Db::conn()->prepare('DELETE FROM admin_users WHERE username = ?')->execute([$u]);
            Response::fail('lock_failed', '无法写入安装锁文件，请检查 config/ 目录权限', 500);
        }

        // 安装密钥是一次性的：成功后立即作废，杜绝装完仍可重放。
        Installer::consumeInstallToken();

        Audit::log(null, $u, 'site_installed', '', 'installer');

        Response::json([
            'ok' => true,
            'data' => [
                'installed' => true,
                'admin_url' => Path::basePrefix() . '/admin/',
                'seeded'    => $seeded,
            ],
        ], 201);

    // 站点聚合数据：分类 + 商品列表 + 信息条（首页一次性拿全）
    case $path === '/api/catalog' && $method === 'GET':
        RateLimiter::guard('read');
        $cat = isset($_GET['cat']) ? Validator::categoryId($_GET['cat']) : 'all';
        $kw  = Validator::keyword($_GET['q'] ?? '');
        $sort = Validator::sort($_GET['sort'] ?? 'featured');
        $page = Validator::int($_GET['page'] ?? null, 1, 1000, 1);
        $per  = Validator::int($_GET['per_page'] ?? null, 1, 50, 24);

        $total = ProductRepo::count($cat === 'all' ? '' : $cat, $kw);
        $items = ProductRepo::list($cat === 'all' ? '' : $cat, $kw, $sort, $per, ($page - 1) * $per);

        Response::ok([
            'categories' => ProductRepo::categories(),
            'products'   => $items,
            'marquee'    => ProductRepo::block('marquee', []),
            'meta'       => ['total' => $total, 'page' => $page, 'per_page' => $per],
            'payment'    => PaymentService::publicInfo(),
        ]);

    case $path === '/api/products' && $method === 'GET':
        RateLimiter::guard('read');
        $cat = isset($_GET['cat']) ? Validator::categoryId($_GET['cat']) : 'all';
        $kw  = Validator::keyword($_GET['q'] ?? '');
        $sort = Validator::sort($_GET['sort'] ?? 'featured');
        $limit = Validator::int($_GET['limit'] ?? null, 1, 50, 24);
        $offset = Validator::int($_GET['offset'] ?? null, 0, 10000, 0);
        Response::ok([
            'products' => ProductRepo::list($cat === 'all' ? '' : $cat, $kw, $sort, $limit, $offset),
            'total'    => ProductRepo::count($cat === 'all' ? '' : $cat, $kw),
        ]);

    case preg_match('#^/api/products/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
        RateLimiter::guard('read');
        $id = Validator::productId($m[1]);
        $p = ProductRepo::find($id);
        if ($p === null) {
            Response::fail('not_found', '商品不存在或已下架', 404);
        }
        $p['thinking'] = ProductRepo::block('thinking', []);
        Response::ok($p);

    // 前台发表评价（公开，按 IP 限流防刷）。评价一律来自真实用户提交，
    // 不再有演示假评价；新评价默认可见，后台可隐藏/删除。
    case preg_match('#^/api/products/([^/]+)/reviews$#', $path, $m) === 1 && $method === 'POST':
        RateLimiter::guard('review');
        $rid = Validator::productId($m[1]);
        $chk = Db::conn()->prepare("SELECT 1 FROM products WHERE id = :id AND status = 'active'");
        $chk->execute([':id' => $rid]);
        if ($chk->fetchColumn() === false) {
            Response::fail('not_found', '商品不存在或已下架', 404);
        }
        $in = readJsonBody();
        $rawAuthor = $in['author'] ?? '';
        $author = Validator::text(is_string($rawAuthor) ? $rawAuthor : '', 0, 30, '称呼');
        if ($author === '') {
            $author = '匿名';
        }
        $stars = Validator::int($in['stars'] ?? 5, 1, 5, 5);
        $text  = Validator::text($in['body'] ?? '', 1, 600, '评价内容');
        $ins = Db::conn()->prepare(
            "INSERT INTO reviews (product_id, author, days_ago, stars, body, likes, status)
             VALUES (?, ?, '刚刚', ?, ?, 0, 'visible')"
        );
        $ins->execute([$rid, $author, $stars, $text]);
        Response::json(['ok' => true, 'data' => [
            'id'    => (int)Db::conn()->lastInsertId(),
            'n'     => $author,
            't'     => '刚刚',
            's'     => $stars,
            'text'  => $text,
            'likes' => 0,
        ]], 201);

    case $path === '/api/categories' && $method === 'GET':
        RateLimiter::guard('read');
        Response::ok(['categories' => ProductRepo::categories()]);

    // 支付能力（前端据此决定是否显示「去支付」）
    case $path === '/api/payment/info' && $method === 'GET':
        RateLimiter::guard('read');
        Response::ok(PaymentService::publicInfo());

    // 创建订单（v5：必须登录）
    case $path === '/api/orders' && $method === 'POST':
        RateLimiter::guard('order');
        $user = UserAuth::require();
        $body = readJsonBody();
        $ik = Validator::idempotencyKey(
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($body['idempotency_key'] ?? null)
        );
        $order = OrderService::create($user, $body, $ik);
        Response::json(['ok' => true, 'data' => $order], 201);

    // 查询订单（订单号 + token）
    case preg_match('#^/api/orders/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
        RateLimiter::guard('lookup');
        $no = Validator::orderNo($m[1]);
        $token = $_GET['token'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            Response::fail('invalid_token', '查询凭证不正确', 400);
        }
        $o = OrderService::lookup($no, $token);
        if ($o === null) {
            // 不用 404/403 区分，避免泄露订单号是否存在
            Response::fail('not_found', '订单不存在或凭证已失效', 404);
        }
        Response::ok($o);

    // 发起支付：返回网关表单参数（前端 POST 到网关）
    case preg_match('#^/api/orders/([^/]+)/pay$#', $path, $m) === 1 && $method === 'POST':
        RateLimiter::guard('pay');
        $no = Validator::orderNo($m[1]);
        $body = readJsonBody();
        $token = $body['token'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            Response::fail('invalid_token', '查询凭证不正确', 400);
        }
        $channel = $body['channel'] ?? '';
        if (!is_string($channel)) {
            Response::fail('invalid_channel', '不支持的支付方式', 400);
        }
        Response::ok(PaymentService::start($no, $token, $channel));

    // 免费订单（¥0.00）直接支付成功：不跳转易支付，点击即视为支付成功
    case preg_match('#^/api/orders/([^/]+)/free-pay$#', $path, $m) === 1 && $method === 'POST':
        RateLimiter::guard('pay');
        $no = Validator::orderNo($m[1]);
        $user = UserAuth::require();
        Response::ok(PaymentService::payFreeOrder((int)$user['id'], $no));

    // 异步通知：易支付 V1 协议用 GET 回调（部分站点也会 POST），两种都接受。
    // 必须返回纯文本 success，否则网关会持续重试。
    case $path === '/api/pay/notify':
        if ($method !== 'GET' && $method !== 'POST') {
            header('Allow: GET, POST');
            Response::fail('method_not_allowed', '请求方法不被允许', 405);
        }
        RateLimiter::guard('notify');
        // 合并 GET / POST / JSON body 三种来源，交给 PaymentService 统一验签
        $params = array_merge($_GET, $_POST);
        if (count($params) === 0) {
            $raw = file_get_contents('php://input', false, null, 0, 65537);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true, 8);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }
        }
        $res = PaymentService::handleNotify($params, RateLimiter::ipHash());
        // 易支付协议：成功必须回纯文本 success，否则会持续重试
        // 走 Response::plain 而不是直接 echo —— 否则会被上面的 shutdown 守卫
        // 当成「非 JSON 脏输出」转成 500 JSON，网关就会无限重试。
        Response::plain($res['ok'] ? 'success' : 'fail', $res['ok'] ? 200 : 400);

    // 同步返回：浏览器 GET 回来。这里只做「解析订单号 + 302 跳转」，
    // 绝不在这里改订单状态——订单是否已支付只以 notify 为准，
    // 否则用户手工构造 URL 就能把订单标成已支付。
    case $path === '/api/pay/return' && $method === 'GET':
        $orderNo = PaymentService::handleReturn($_GET);
        // 跳回结果页；结果页会带订单号去查真实状态（仍需 token 才能看到明细）
        $target = $orderNo !== null
            ? '/pay-result.html?no=' . rawurlencode($orderNo)
            : '/pay-result.html?error=1';
        header('Location: ' . $target, true, 302);
        header('Cache-Control: no-store');
        // 这是「自己主动结束、且不是 JSON 响应」的分支，必须标记已输出，
        // 否则 v5.5 的 shutdown 守卫会把 302 改写成 500 + JSON。
        if (!defined('SHOP_API_SENT')) { define('SHOP_API_SENT', true); }
        exit;

    // ================= 前台用户中心 /api/console/* =================
    // 与后台 /api/admin/* 完全独立：独立表、独立 Cookie、独立限流。
    // 除 register / login / site 外，全部要求已登录 + 同源校验。

    // 注册
    case $path === '/api/console/register' && $method === 'POST':
        // 限流在 UserAuth::register() 内部按 IP 计数（避免同一请求被计两次）
        $body = readJsonBody();
        $r = UserAuth::register(
            (string)($body['username'] ?? ''),
            (string)($body['password'] ?? ''),
            (string)($body['display_name'] ?? ''),
            (string)($body['email'] ?? '')
        );
        if (!$r['ok']) {
            Response::fail('register_failed', $r['message'], 400);
        }
        Response::json(['ok' => true, 'data' => ['user' => $r['user']]], 201);

    // 登录
    case $path === '/api/console/login' && $method === 'POST':
        // 限流在 UserAuth::login() 内部按 IP + 账号双维度计数
        $body = readJsonBody();
        $u = (string)($body['username'] ?? '');
        $p = (string)($body['password'] ?? '');
        if ($u === '' || $p === '') {
            Response::fail('invalid_credentials', '请输入用户名和密码', 400);
        }
        if (mb_strlen($u, 'UTF-8') > 64 || mb_strlen($p, 'UTF-8') > 200) {
            Response::fail('invalid_credentials', '用户名或密码错误', 400);
        }
        $r = UserAuth::login($u, $p);
        if (!$r['ok']) {
            Response::fail('login_failed', $r['message'], 401);
        }
        Response::ok(['user' => $r['user']]);

    // 登出
    case $path === '/api/console/logout' && $method === 'POST':
        UserAuth::require();
        UserAuth::logout();
        Response::ok(['ok' => true]);

    // 当前登录态（前端启动时探测；未登录返回 401 而不是报错）
    case $path === '/api/console/me' && $method === 'GET':
        $u = UserAuth::user();
        if ($u === null) {
            Response::fail('unauthorized', '未登录', 401);
        }
        Response::ok([
            'user'    => UserAuth::publicUser((int)$u['id']),
            'payment' => PaymentService::publicInfo(),
        ]);

    // 用户中心首页聚合
    case $path === '/api/console/overview' && $method === 'GET':
        $u = UserAuth::require();
        RateLimiter::guard('console');
        Response::ok(ConsoleService::overview((int)$u['id']));

    // 我的订单列表
    case $path === '/api/console/orders' && $method === 'GET':
        $u = UserAuth::require();
        RateLimiter::guard('console');
        Response::ok(ConsoleService::orders((int)$u['id'], $_GET));

    // 我的订单详情（强制归属校验）
    case preg_match('#^/api/console/orders/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
        $u = UserAuth::require();
        RateLimiter::guard('console');
        $no = Validator::orderNo($m[1]);
        Response::ok(ConsoleService::orderDetail((int)$u['id'], $no));

    // 我的订单：发起在线支付
    case preg_match('#^/api/console/orders/([^/]+)/pay$#', $path, $m) === 1 && $method === 'POST':
        $u = UserAuth::require();
        RateLimiter::guard('pay');
        $no = Validator::orderNo($m[1]);
        $body = readJsonBody();
        $channel = (string)($body['channel'] ?? '');
        Response::ok(PaymentService::startForUser((int)$u['id'], $no, $channel));

    // 我的订单：余额支付
    case preg_match('#^/api/console/orders/([^/]+)/pay-balance$#', $path, $m) === 1 && $method === 'POST':
        $u = UserAuth::require();
        RateLimiter::guard('pay');
        $no = Validator::orderNo($m[1]);
        Response::ok(PaymentService::payWithBalance((int)$u['id'], $no));

    // 余额流水
    case $path === '/api/console/balance/ledger' && $method === 'GET':
        $u = UserAuth::require();
        RateLimiter::guard('console');
        Response::ok(ConsoleService::ledger((int)$u['id'], $_GET));

    // 发起充值
    case $path === '/api/console/balance/recharge' && $method === 'POST':
        $u = UserAuth::require();
        RateLimiter::guard('recharge');
        $body = readJsonBody();
        $amount = $body['amount_cents'] ?? null;
        if (!is_int($amount) && !(is_string($amount) && ctype_digit($amount))) {
            Response::fail('invalid_amount', '充值金额必须是整数（分）', 400);
        }
        $amount = (int)$amount;
        $channel = (string)($body['channel'] ?? '');
        Response::ok(PaymentService::startRecharge((int)$u['id'], $amount, $channel));

    // 充值记录
    case $path === '/api/console/balance/recharges' && $method === 'GET':
        $u = UserAuth::require();
        RateLimiter::guard('console');
        Response::ok(ConsoleService::recharges((int)$u['id'], $_GET));

    // 修改资料
    case $path === '/api/console/profile' && $method === 'PUT':
        $u = UserAuth::require();
        $body = readJsonBody();
        Response::ok(ConsoleService::updateProfile((int)$u['id'], $body));

    // 修改密码
    case $path === '/api/console/password' && $method === 'POST':
        $u = UserAuth::require();
        $body = readJsonBody();
        $old = (string)($body['old_password'] ?? '');
        $new = (string)($body['new_password'] ?? '');
        $r = UserAuth::changePassword((int)$u['id'], $old, $new);
        if (!$r['ok']) {
            Response::fail('password_change_failed', $r['message'], 400);
        }
        Response::ok(['message' => $r['message']]);

    // ================= 后台接口 =================

    // 登录（不需要已登录）
    case $path === '/api/admin/login' && $method === 'POST':
        RateLimiter::guard('admin');
        $body = readJsonBody();
        $u = $body['username'] ?? '';
        $p = $body['password'] ?? '';
        if (!is_string($u) || !is_string($p) || $u === '' || $p === '') {
            Response::fail('invalid_credentials', '请输入账号和密码', 400);
        }
        if (mb_strlen($u, 'UTF-8') > 64 || mb_strlen($p, 'UTF-8') > 200) {
            Response::fail('invalid_credentials', '账号或密码错误', 400);
        }
        $r = AdminAuth::login($u, $p);
        if (!$r['ok']) {
            Response::fail('login_failed', $r['message'], 401);
        }
        // 直接用 login() 返回的用户信息：本次请求的 Cookie 还没进 $_COOKIE，
        // 再调 user() 会拿到 null。
        Response::ok(['user' => $r['user']]);

    case $path === '/api/admin/logout' && $method === 'POST':
        AdminAuth::require();
        AdminAuth::logout();
        Response::ok(['ok' => true]);

    // 当前登录态
    case $path === '/api/admin/me' && $method === 'GET':
        $u = AdminAuth::user();
        if ($u === null) {
            Response::fail('unauthorized', '未登录', 401);
        }
        Response::ok(['user' => $u, 'payment' => PaymentService::publicInfo()]);

    // 概览
    case $path === '/api/admin/dashboard' && $method === 'GET':
        AdminAuth::require();
        Response::ok(AdminService::dashboard());

    // ---- 商品 ----
    case $path === '/api/admin/products' && $method === 'GET':
        AdminAuth::require();
        Response::ok(AdminService::products($_GET));

    case $path === '/api/admin/products' && $method === 'POST':
        $u = AdminAuth::require();
        $body = readJsonBody();
        $r = AdminService::saveProduct($body, true);
        Audit::log($u['id'], $u['username'], 'product_create', $r['id'], (string)($body['name'] ?? ''));
        Response::json(['ok' => true, 'data' => $r], 201);

    case preg_match('#^/api/admin/products/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
        AdminAuth::require();
        $id = Validator::productId($m[1]);
        $p = AdminService::productFull($id);
        if ($p === null) {
            Response::fail('not_found', '商品不存在', 404);
        }
        Response::ok($p);

    case preg_match('#^/api/admin/products/([^/]+)$#', $path, $m) === 1 && $method === 'PUT':
        $u = AdminAuth::require();
        $id = Validator::productId($m[1]);
        $body = readJsonBody();
        $body['id'] = $id;
        $r = AdminService::saveProduct($body, false);
        Audit::log($u['id'], $u['username'], 'product_update', $id, (string)($body['name'] ?? ''));
        Response::ok($r);

    case preg_match('#^/api/admin/products/([^/]+)$#', $path, $m) === 1 && $method === 'DELETE':
        $u = AdminAuth::require();
        $id = Validator::productId($m[1]);
        AdminService::deleteProduct($id);
        Audit::log($u['id'], $u['username'], 'product_delete', $id, '');
        Response::ok(['id' => $id]);

    case preg_match('#^/api/admin/products/([^/]+)/status$#', $path, $m) === 1 && $method === 'POST':
        $u = AdminAuth::require();
        $id = Validator::productId($m[1]);
        $body = readJsonBody();
        $status = (string)($body['status'] ?? '');
        AdminService::setProductStatus($id, $status);
        Audit::log($u['id'], $u['username'], 'product_status', $id, $status);
        Response::ok(['id' => $id, 'status' => $status]);

    case preg_match('#^/api/admin/products/([^/]+)/stock$#', $path, $m) === 1 && $method === 'POST':
        $u = AdminAuth::require();
        $id = Validator::productId($m[1]);
        $body = readJsonBody();
        $delta = $body['delta'] ?? null;
        if (!is_numeric($delta)) {
            Response::fail('invalid_delta', '库存调整值必须是数字', 400);
        }
        $r = AdminService::adjustStock($id, (int)$delta);
        Audit::log($u['id'], $u['username'], 'product_stock', $id, 'delta=' . (int)$delta . ' → ' . $r['stock']);
        Response::ok($r);

    // ---- 分类 ----
    case $path === '/api/admin/categories' && $method === 'GET':
        AdminAuth::require();
        Response::ok(['items' => AdminService::categories()]);

    case $path === '/api/admin/categories' && $method === 'POST':
        $u = AdminAuth::require();
        $body = readJsonBody();
        $r = AdminService::saveCategory($body, true);
        Audit::log($u['id'], $u['username'], 'category_create', $r['id'], (string)($body['label'] ?? ''));
        Response::json(['ok' => true, 'data' => $r], 201);

    case preg_match('#^/api/admin/categories/([^/]+)$#', $path, $m) === 1 && $method === 'PUT':
        $u = AdminAuth::require();
        $id = Validator::categoryId($m[1]);
        $body = readJsonBody();
        $body['id'] = $id;
        $r = AdminService::saveCategory($body, false);
        Audit::log($u['id'], $u['username'], 'category_update', $id, (string)($body['label'] ?? ''));
        Response::ok($r);

    case preg_match('#^/api/admin/categories/([^/]+)$#', $path, $m) === 1 && $method === 'DELETE':
        $u = AdminAuth::require();
        $id = Validator::categoryId($m[1]);
        AdminService::deleteCategory($id);
        Audit::log($u['id'], $u['username'], 'category_delete', $id, '');
        Response::ok(['id' => $id]);

    // ---- 订单 ----
    case $path === '/api/admin/orders' && $method === 'GET':
        AdminAuth::require();
        Response::ok(AdminService::orders($_GET));

    // 手动关单（超时未支付）—— 必须放在 /orders/{no} 之前，否则会被当成订单号
    case $path === '/api/admin/orders/close-expired' && $method === 'POST':
        $u = AdminAuth::require();
        $n = PaymentService::closeAllExpired(200);
        Audit::log($u['id'], $u['username'], 'order_close_expired', '', 'closed=' . $n);
        Response::ok(['closed' => $n]);

    case preg_match('#^/api/admin/orders/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
        AdminAuth::require();
        $no = Validator::orderNo($m[1]);
        $o = AdminService::orderDetail($no);
        if ($o === null) {
            Response::fail('not_found', '订单不存在', 404);
        }
        Response::ok($o);

    case preg_match('#^/api/admin/orders/([^/]+)/status$#', $path, $m) === 1 && $method === 'POST':
        $u = AdminAuth::requireOwner();
        $no = Validator::orderNo($m[1]);
        $body = readJsonBody();
        $to = (string)($body['status'] ?? '');
        if (!in_array($to, ['pending', 'paid', 'shipped', 'done', 'cancelled'], true)) {
            Response::fail('invalid_status', '订单状态不合法', 400);
        }
        $r = AdminService::transitionOrder($no, $to, $body);
        Audit::log($u['id'], $u['username'], 'order_status', $no, $r['from'] . ' → ' . $to);
        Response::ok($r);

    // ---- 评价 ----
    case $path === '/api/admin/reviews' && $method === 'GET':
        AdminAuth::require();
        Response::ok(AdminService::reviews($_GET));

    case $path === '/api/admin/reviews' && $method === 'POST':
        $u = AdminAuth::require();
        $body = readJsonBody();
        $r = AdminService::saveReview($body, null);
        Audit::log($u['id'], $u['username'], 'review_create', (string)$r['id'], (string)($body['product_id'] ?? ''));
        Response::json(['ok' => true, 'data' => $r], 201);

    case preg_match('#^/api/admin/reviews/(\d+)$#', $path, $m) === 1 && $method === 'PUT':
        $u = AdminAuth::require();
        $id = (int)$m[1];
        $body = readJsonBody();
        $r = AdminService::saveReview($body, $id);
        Audit::log($u['id'], $u['username'], 'review_update', (string)$id, '');
        Response::ok($r);

    case preg_match('#^/api/admin/reviews/(\d+)$#', $path, $m) === 1 && $method === 'DELETE':
        $u = AdminAuth::require();
        $id = (int)$m[1];
        AdminService::deleteReview($id);
        Audit::log($u['id'], $u['username'], 'review_delete', (string)$id, '');
        Response::ok(['id' => $id]);

    case preg_match('#^/api/admin/reviews/(\d+)/status$#', $path, $m) === 1 && $method === 'POST':
        $u = AdminAuth::require();
        $id = (int)$m[1];
        $body = readJsonBody();
        $status = (string)($body['status'] ?? '');
        AdminService::setReviewStatus($id, $status);
        Audit::log($u['id'], $u['username'], 'review_status', (string)$id, $status);
        Response::ok(['id' => $id, 'status' => $status]);

    // ---- 站点设置 ----
    case $path === '/api/admin/settings' && $method === 'GET':
        AdminAuth::require();
        // 商户密钥只写不读：不随响应下发，另返回「是否已配置」状态
        Response::ok(['settings' => SettingsService::adminPayload()]);

    case $path === '/api/admin/settings' && $method === 'PUT':
        $u = AdminAuth::require();
        $body = readJsonBody();
        $input = $body['settings'] ?? $body;
        if (!is_array($input)) {
            Response::fail('invalid_settings', '设置数据格式不正确', 400);
        }
        try {
            $r = SettingsService::save($input);
        } catch (InvalidArgumentException $e) {
            Response::fail('invalid_settings', $e->getMessage(), 400);
        }
        Audit::log($u['id'], $u['username'], 'site_settings_update', '', 'updated=' . $r['updated']);
        Response::ok($r);

    case $path === '/api/admin/settings/reset' && $method === 'POST':
        $u = AdminAuth::requireOwner();
        $r = SettingsService::reset();
        Audit::log($u['id'], $u['username'], 'site_settings_reset', '', '');
        Response::ok(['settings' => $r]);

    // ---- 账号 ----
    case $path === '/api/admin/password' && $method === 'POST':
        $u = AdminAuth::require();
        $body = readJsonBody();
        $old = $body['old_password'] ?? '';
        $new = $body['new_password'] ?? '';
        if (!is_string($old) || !is_string($new)) {
            Response::fail('invalid_password', '密码格式不正确', 400);
        }
        $r = AdminAuth::changePassword($u['id'], $old, $new);
        if (!$r['ok']) {
            Response::fail('password_change_failed', $r['message'], 400);
        }
        Response::ok(['message' => $r['message']]);

    // ---- 审计 ----
    case $path === '/api/admin/audit' && $method === 'GET':
        AdminAuth::requireOwner();
        $limit = Validator::int($_GET['limit'] ?? null, 1, 100, 50);
        $offset = Validator::int($_GET['offset'] ?? null, 0, 100000, 0);
        $action = (string)($_GET['action'] ?? '');
        Response::ok(Audit::page($limit, $offset, $action));

    // ================= 后台 v5：用户 / 余额 / 发货 =================

    // 用户列表
    case $path === '/api/admin/users' && $method === 'GET':
        AdminAuth::require();
        Response::ok(AdminService::users($_GET));

    // 用户详情（含余额流水、订单）
    case preg_match('#^/api/admin/users/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        AdminAuth::require();
        $u = AdminService::userDetail((int)$m[1]);
        if ($u === null) {
            Response::fail('not_found', '用户不存在', 404);
        }
        Response::ok($u);

    // 启用 / 停用用户
    case preg_match('#^/api/admin/users/(\d+)/status$#', $path, $m) === 1 && $method === 'POST':
        $op = AdminAuth::requireOwner();
        $body = readJsonBody();
        $status = (string)($body['status'] ?? '');
        $r = AdminService::setUserStatus((int)$m[1], $status);
        Audit::log($op['id'], $op['username'], 'user_status', (string)$m[1], $status);
        Response::ok($r);

    // 重置用户密码（生成一次性临时密码，强制下次登录修改）
    case preg_match('#^/api/admin/users/(\d+)/reset-password$#', $path, $m) === 1 && $method === 'POST':
        $op = AdminAuth::requireOwner();
        $r = AdminService::resetUserPassword((int)$m[1]);
        Audit::log($op['id'], $op['username'], 'user_reset_password', (string)$m[1], '');
        Response::ok($r);

    // 余额调整（加款 / 扣款，必须写备注）
    case preg_match('#^/api/admin/users/(\d+)/balance$#', $path, $m) === 1 && $method === 'POST':
        $op = AdminAuth::requireOwner();
        $body = readJsonBody();
        $r = AdminService::adjustBalance((int)$m[1], $body, (string)$op['username']);
        Audit::log(
            $op['id'], $op['username'], 'user_balance_adjust', (string)$m[1],
            'delta=' . $r['delta'] . ' → ' . $r['balance']
        );
        Response::ok($r);

    // 用户余额流水（后台视角）
    case preg_match('#^/api/admin/users/(\d+)/ledger$#', $path, $m) === 1 && $method === 'GET':
        AdminAuth::require();
        $limit  = Validator::int($_GET['limit'] ?? null, 1, 100, 30);
        $offset = Validator::int($_GET['offset'] ?? null, 0, 100000, 0);
        Response::ok(BalanceService::ledger((int)$m[1], $limit, $offset));

    // 商品发货模板：读取
    case preg_match('#^/api/admin/products/([^/]+)/delivery$#', $path, $m) === 1 && $method === 'GET':
        AdminAuth::require();
        $id = Validator::productId($m[1]);
        Response::ok(AdminService::productDelivery($id));

    // 商品发货模板：保存（类型 + 结构化字段 + 模板文本）
    case preg_match('#^/api/admin/products/([^/]+)/delivery$#', $path, $m) === 1 && $method === 'PUT':
        $op = AdminAuth::require();
        $id = Validator::productId($m[1]);
        $body = readJsonBody();
        $r = AdminService::saveProductDelivery($id, $body);
        Audit::log($op['id'], $op['username'], 'product_delivery', $id, 'type=' . $r['type']);
        Response::ok($r);

    // 订单发货内容：读取
    case preg_match('#^/api/admin/orders/([^/]+)/deliveries$#', $path, $m) === 1 && $method === 'GET':
        AdminAuth::require();
        $no = Validator::orderNo($m[1]);
        $o = AdminService::orderDetail($no);
        if ($o === null) {
            Response::fail('not_found', '订单不存在', 404);
        }
        Response::ok(['order_no' => $no, 'deliveries' => $o['deliveries'] ?? []]);

    // 订单发货内容：修改 / 补发
    case preg_match('#^/api/admin/deliveries/(\d+)$#', $path, $m) === 1 && $method === 'PUT':
        $op = AdminAuth::require();
        $body = readJsonBody();
        $content = (string)($body['content'] ?? '');
        $status = (string)($body['status'] ?? 'delivered');
        $r = DeliveryService::setContent((int)$m[1], $content, $status);
        Audit::log($op['id'], $op['username'], 'delivery_update', (string)$m[1], 'status=' . $status);
        Response::ok($r);

    // 订单发货：实体商品填快递信息并置为已发货
    case preg_match('#^/api/admin/orders/([^/]+)/ship$#', $path, $m) === 1 && $method === 'POST':
        $op = AdminAuth::require();
        $no = Validator::orderNo($m[1]);
        $body = readJsonBody();
        $r = AdminService::shipOrder($no, $body);
        Audit::log($op['id'], $op['username'], 'order_ship', $no, (string)($body['ship_no'] ?? ''));
        Response::ok($r);

    // 已知路径但方法不对 → 405；未知路径 → 404
    case str_starts_with($path, '/api/'):
        $known = [
            '/api/health', '/api/catalog', '/api/products', '/api/categories',
            '/api/orders', '/api/orders/quote', '/api/payment/info', '/api/pay/notify', '/api/pay/return',
            '/api/site', '/api/install', '/api/install/status',
            '/api/admin/login', '/api/admin/logout', '/api/admin/me', '/api/admin/dashboard',
            '/api/admin/products', '/api/admin/categories', '/api/admin/orders',
            '/api/admin/orders/close-expired', '/api/admin/reviews', '/api/admin/password',
            '/api/admin/audit', '/api/admin/settings', '/api/admin/settings/reset',
            '/api/admin/users',
            '/api/console/register', '/api/console/login', '/api/console/logout',
            '/api/console/me', '/api/console/overview', '/api/console/orders',
            '/api/console/balance/ledger', '/api/console/balance/recharge',
            '/api/console/balance/recharges', '/api/console/profile', '/api/console/password',
        ];
        $isKnown = in_array($path, $known, true)
            || preg_match('#^/api/(products|orders)/[^/]+$#', $path) === 1
            || preg_match('#^/api/products/[^/]+/reviews$#', $path) === 1
            || preg_match('#^/api/orders/[^/]+/pay$#', $path) === 1
            || preg_match('#^/api/admin/(products|categories|orders)/[^/]+$#', $path) === 1
            || preg_match('#^/api/admin/products/[^/]+/(status|stock|delivery)$#', $path) === 1
            || preg_match('#^/api/admin/orders/[^/]+/(status|deliveries|ship)$#', $path) === 1
            || preg_match('#^/api/admin/reviews/\d+$#', $path) === 1
            || preg_match('#^/api/admin/reviews/\d+/status$#', $path) === 1
            || preg_match('#^/api/admin/users/\d+$#', $path) === 1
            || preg_match('#^/api/admin/users/\d+/(status|reset-password|balance|ledger)$#', $path) === 1
            || preg_match('#^/api/admin/deliveries/\d+$#', $path) === 1
            || preg_match('#^/api/console/orders/[^/]+$#', $path) === 1
            || preg_match('#^/api/console/orders/[^/]+/(pay|pay-balance)$#', $path) === 1;
        if ($isKnown) {
            header('Allow: GET, POST, PUT, DELETE, OPTIONS');
            Response::fail('method_not_allowed', '请求方法不被允许', 405);
        }
        Response::fail('not_found', '接口不存在', 404);

    default:
        Response::fail('not_found', '接口不存在', 404);
}

/** 读取 JSON body，限制 64KB，并强制 Content-Type */
function readJsonBody(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== 0) {
        Response::fail('unsupported_media_type', '请使用 application/json', 415);
    }
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > 65536) {
        Response::fail('payload_too_large', '请求体过大', 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || $raw === '') {
        Response::fail('empty_body', '请求体为空', 400);
    }
    if (strlen($raw) > 65536) {
        Response::fail('payload_too_large', '请求体过大', 413);
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        Response::fail('invalid_json', '请求体不是合法 JSON', 400);
    }
    if (!is_array($data)) {
        Response::fail('invalid_json', '请求体必须是 JSON 对象', 400);
    }
    return $data;
}
