<?php
/**
 * 安装状态、安装锁、环境自检、.env 写入
 *
 * 锁文件放在 data/ 之外（config/ 下），因为 data/ 目录可能被整体备份/迁移，
 * 而「是否已安装」属于部署状态，不该跟着数据库文件走。
 *
 * 判定「已安装」需要同时满足：
 *   1. 锁文件存在且内容合法（含安装时间与随机指纹）
 *   2. 数据库里确实有管理员账号
 * 只满足其一时仍视为未安装（例如锁文件被误删），但会拒绝重复建管理员。
 *
 * 安全要点：
 *   - 锁文件与 .env 都在 public/ 之外，HTTP 无法访问
 *   - 安装接口在已安装后一律 403，且不泄露任何配置
 *   - 安装时若已存在管理员，拒绝覆盖（防止有人重装把店主踢出去）
 *   - .env 只允许写入白名单键，值做引号包裹，权限 0600
 *   - 网页安装 /api/install 必须校验服务器本地的一次性安装密钥，防止抢装接管
 *   - CLI 以 root 安装时自动把 .env / 安装锁的属主移交给 Web 服务用户，
 *     否则 0600 的 root 文件会让 FPM 读不到，全站被误判「未安装」
 */
declare(strict_types=1);

final class Installer
{
    /** 当前代码版本（写入锁文件，便于运维核对） */
    public const VERSION = '5.5';

    /** 项目根目录 */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** 锁文件路径（config/ 下，Web 根之外） */
    public static function lockPath(): string
    {
        return self::root() . '/config/installed.lock';
    }

    /** .env 路径 */
    public static function envPath(): string
    {
        return self::root() . '/config/.env';
    }

    // -----------------------------------------------------------------
    // 一次性安装密钥（防「抢装接管」）
    // -----------------------------------------------------------------

    /** 密钥文件路径（只存 SHA-256 摘要，不存明文；位于 Web 根之外） */
    public static function tokenPath(): string
    {
        return self::root() . '/config/.install_token';
    }

    /**
     * 校验安装密钥。
     *
     * 未安装的站点原本任何访客都能 POST /api/install 把自己设成 owner 并永久接管；
     * 现在必须持有「只能从服务器 CLI 取得」的密钥。文件里存的是摘要，读到了也无法反推。
     * 未生成密钥文件时一律拒绝，不给任何绕过路径。
     */
    public static function verifyInstallToken(string $token): bool
    {
        $file = self::tokenPath();
        if ($token === '' || !is_file($file)) {
            return false;
        }
        $stored = @file_get_contents($file);
        if (!is_string($stored)) {
            return false;
        }
        $stored = trim($stored);
        if ($stored === '') {
            return false;
        }
        // 常量时间比较，避免按字节响应时间侧信道
        return hash_equals($stored, hash('sha256', $token));
    }

    /** 安装成功后作废旧密钥（一次性） */
    public static function consumeInstallToken(): void
    {
        @unlink(self::tokenPath());
    }

    /** 是否已生成安装密钥（仅供安装页提示，不泄露密钥内容） */
    public static function installTokenReady(): bool
    {
        $file = self::tokenPath();
        return is_file($file) && trim((string)@file_get_contents($file)) !== '';
    }

    /** 是否已安装 */
    public static function isInstalled(): bool
    {
        $lock = self::lockPath();
        if (!is_file($lock)) {
            return false;
        }
        $raw = @file_get_contents($lock);
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['installed_at'])) {
            return false;
        }
        // 锁文件存在但数据库里没有管理员 → 视为未完成安装（允许继续装）
        return self::hasAdmin();
    }

    /** 数据库里是否已有管理员 */
    public static function hasAdmin(): bool
    {
        try {
            $n = (int)Db::conn()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 数据库文件是否已存在（用于判断「全新站点」） */
    public static function dbExists(): bool
    {
        $cfg = require self::root() . '/config/config.php';
        return is_file($cfg['db_path']);
    }

    /**
     * 写入安装锁
     * 0600 权限，内容不含任何密码；CLI root 创建时会移交给 Web 服务用户。
     */
    public static function writeLock(string $adminUser): bool
    {
        $lock = self::lockPath();
        $dir  = dirname($lock);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $payload = json_encode([
            'installed_at' => date('c'),
            'version'      => self::VERSION,
            'admin_user'   => $adminUser,
            // 随机指纹：证明这个锁是本程序写的，不是手工 touch 出来的
            'fingerprint'  => bin2hex(random_bytes(16)),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($payload === false) {
            return false;
        }
        $tmp = $lock . '.tmp';
        if (@file_put_contents($tmp, $payload) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $lock)) {
            @unlink($tmp);
            return false;
        }
        @chmod($lock, 0600);
        self::handoffToWebUser($lock);
        return true;
    }

    // -----------------------------------------------------------------
    // .env 写入
    // -----------------------------------------------------------------

    /** 允许由安装向导写入的键（白名单，防止被塞任意变量） */
    private const ENV_KEYS = [
        'SHOP_ENV', 'SHOP_BASE_URL', 'SHOP_DB_PATH', 'SHOP_COOKIE_SECURE',
        'EPAY_ENABLED', 'EPAY_PID', 'EPAY_KEY', 'EPAY_GATEWAY', 'EPAY_SIGN_TYPE',
        'EPAY_CHANNELS', 'EPAY_NOTIFY_URL', 'EPAY_RETURN_URL',
        'EPAY_AMOUNT_TOLERANCE_CENTS', 'EPAY_EXPIRE_MINUTES',
        'SHOP_ADMIN_USER', 'SHOP_ADMIN_PASSWORD',
    ];

    /**
     * 写入 / 更新 config/.env
     *
     * 行为：
     *   - 保留文件里已有的注释与未知键（运维手工加的东西不会被冲掉）
     *   - 白名单内的键就地更新，缺失的追加到末尾
     *   - 值统一用双引号包裹并转义，避免 # $ & 空格 等字符破坏解析
     *   - 原子写（tmp + rename），权限 0600
     *
     * @param array<string,string> $values
     */
    public static function writeEnv(array $values): bool
    {
        $path = self::envPath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }

        // 过滤白名单
        $clean = [];
        foreach ($values as $k => $v) {
            $k = strtoupper(trim((string)$k));
            if (!in_array($k, self::ENV_KEYS, true)) {
                continue;
            }
            $clean[$k] = (string)$v;
        }
        if ($clean === []) {
            return false;
        }

        // 读现有内容（没有就空）
        $lines = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
            }
        }

        $done = [];
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }
            $pos = strpos($t, '=');
            if ($pos === false) {
                continue;
            }
            $key = strtoupper(trim(substr($t, 0, $pos)));
            if (!array_key_exists($key, $clean)) {
                continue;
            }
            $lines[$i] = $key . '=' . self::envQuote($clean[$key]);
            $done[$key] = true;
        }

        // 追加缺失键
        $append = [];
        foreach ($clean as $k => $v) {
            if (!isset($done[$k])) {
                $append[] = $k . '=' . self::envQuote($v);
            }
        }
        if ($append !== []) {
            if ($lines !== [] && trim((string)end($lines)) !== '') {
                $lines[] = '';
            }
            $lines[] = '# ---- 由安装向导写入 ----';
            foreach ($append as $a) {
                $lines[] = $a;
            }
        }

        $body = implode("\n", $lines) . "\n";
        $tmp  = $path . '.tmp';
        if (@file_put_contents($tmp, $body) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        self::handoffToWebUser($path);
        return true;
    }

    /** .env 值转义：双引号包裹，内部 " 与 \ 转义 */
    private static function envQuote(string $v): string
    {
        // 换行会破坏 KEY=VALUE 结构，直接去掉
        $v = str_replace(["\r", "\n"], '', $v);
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    /**
     * 把「CLI root 创建」的敏感文件移交给 Web 服务用户。
     *
     * 背景：bin/init_db.php 常由 root 执行，写出的 0600 root:root 文件，
     * PHP-FPM（www-data）读不到 → .env 配置全部丢失、安装锁被判为未安装，
     * 站点表面能开但支付/登录/安装状态全错。HTTP 安装路径本身就是 FPM 用户创建的，无需处理。
     */
    public static function handoffToWebUser(string $path): void
    {
        if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
            return;   // 非 root：文件属主就是当前运行用户（通常正是 FPM 用户）
        }
        $isDir = is_dir($path);
        $mode  = $isDir ? 0750 : 0600;   // 目录必须保留执行位，不能套用文件的 0600
        $names = array_filter([
            (string)(getenv('SHOP_WEB_USER') ?: ''),
            'www-data', 'apache', 'nginx', 'http',
        ]);
        foreach ($names as $name) {
            $info = @posix_getpwnam($name);
            if (!is_array($info) || !isset($info['uid'], $info['gid'])) {
                continue;
            }
            if (@chown($path, (int)$info['uid'])) {
                @chgrp($path, (int)$info['gid']);
                @chmod($path, $mode);
                return;
            }
        }
        // 找不到 Web 用户时以可用性优先：放宽权限，避免 FPM 写不了库 / 读不到配置。
        // 这些路径都在 public/ 之外（DocumentRoot 指向 public/），HTTP 无法直接下载。
        @chmod($path, $isDir ? 0770 : 0644);
    }

    // -----------------------------------------------------------------
    // 环境自检
    // -----------------------------------------------------------------

    /**
     * 伪静态是否生效
     *
     * 判定方式：当前请求的路径在 public/ 下并不存在真实文件，
     * 却仍然被 PHP 入口（index.php / router.php）处理 → 说明重写规则生效。
     * 这是「/admin 404」「点下单弹 404」最直接的探针。
     */
    public static function rewriteOk(): bool
    {
        $uri    = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $file   = self::root() . '/public' . $uri;

        // 请求的就是真实文件 → 无法据此判断
        if (is_file($file)) {
            return false;
        }
        return str_ends_with($script, 'index.php') || str_ends_with($script, 'router.php');
    }

    /** 环境自检：返回 [ ['label'=>..., 'ok'=>bool, 'hint'=>...], ... ] */
    public static function requirements(): array
    {
        $cfg   = require self::root() . '/config/config.php';
        $dbDir = dirname($cfg['db_path']);
        $pub   = self::root() . '/public';

        $checks = [];

        // 硬性门槛：低于 8.1 代码无法解析（枚举 / never / mixed / readonly）
        $checks[] = [
            'label' => 'PHP 版本 ≥ 8.1（最低要求）',
            'ok'    => version_compare(PHP_VERSION, '8.1.0', '>='),
            'hint'  => '当前 ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : '，低于最低要求，代码无法解析'),
        ];
        // 推荐版本：8.5。低于此只提示，不阻塞安装（8.1~8.4 均可正常运行）
        $checks[] = [
            'label' => 'PHP 版本 ≥ 8.5（推荐）',
            'ok'    => version_compare(PHP_VERSION, '8.5.0', '>='),
            'hint'  => version_compare(PHP_VERSION, '8.5.0', '>=')
                ? '已满足推荐版本'
                : '当前 ' . PHP_VERSION . '，可正常运行；建议升级到 PHP 8.5',
            'soft'  => true,
        ];
        foreach (['pdo_sqlite', 'json', 'mbstring'] as $ext) {
            $checks[] = [
                'label' => '扩展 ' . $ext,
                'ok'    => extension_loaded($ext),
                'hint'  => extension_loaded($ext) ? '已启用' : '未启用，请在 php.ini 中开启',
            ];
        }
        $checks[] = [
            'label' => '数据目录可写',
            'ok'    => is_dir($dbDir) ? is_writable($dbDir) : is_writable(dirname($dbDir)),
            'hint'  => $dbDir,
        ];
        $checks[] = [
            'label' => '配置目录可写（写 .env 与安装锁）',
            'ok'    => is_writable(dirname(self::lockPath())),
            'hint'  => dirname(self::lockPath()),
        ];
        $checks[] = [
            'label' => '数据库文件不在 Web 根内',
            'ok'    => !str_starts_with(
                str_replace('\\', '/', (string)realpath($dbDir)),
                str_replace('\\', '/', (string)realpath($pub))
            ),
            'hint'  => '数据库应放在 public/ 之外，避免被直接下载',
        ];
        $checks[] = [
            'label' => '伪静态 / 路由生效',
            'ok'    => self::rewriteOk(),
            'hint'  => self::rewriteOk()
                ? '请求已正确进入 PHP 入口'
                : '未生效：请确认 DocumentRoot 指向 public/，且已加载 router.php / Nginx 伪静态 / .htaccess',
        ];

        return $checks;
    }

    /** 是否全部通过（soft 项只提示、不阻塞） */
    public static function requirementsOk(): bool
    {
        foreach (self::requirements() as $c) {
            if (!$c['ok'] && empty($c['soft'])) {
                return false;
            }
        }
        return true;
    }

    /** 已安装后访问安装页时返回的锁定页（403） */
    public static function lockedPage(): string
    {
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>安装程序已锁定</title><style>'
            . 'body{margin:0;background:#f5f6f8;color:#12161c;'
            . 'font:15px/1.7 "Noto Sans SC",-apple-system,system-ui,sans-serif;display:grid;place-items:center;min-height:100vh}'
            . '.box{background:#fff;border:1px solid rgba(18,22,28,.09);border-radius:14px;'
            . 'box-shadow:0 8px 28px rgba(18,22,28,.06);padding:40px 36px;max-width:460px;text-align:center}'
            . 'h1{font-size:20px;margin:0 0 12px;font-weight:600}'
            . 'p{color:#4a5158;font-size:14px;margin:0 0 22px}'
            . 'a{display:inline-block;padding:11px 24px;background:#0052d9;color:#fff;'
            . 'text-decoration:none;border-radius:10px;font-weight:600;font-size:14px}'
            . 'code{background:#f0f2f5;padding:1px 5px;border-radius:4px;font-size:13px}'
            . '</style></head><body><div class="box">'
            . '<h1>🔒 安装程序已锁定</h1>'
            . '<p>本站已完成安装，安装入口已关闭，无法重复安装或修改配置。<br>'
            . '如需重装，请先在服务器上删除 <code>config/installed.lock</code> 与数据库文件。</p>'
            . '<a href="' . htmlspecialchars(self::basePrefix(), ENT_QUOTES) . '/admin/">前往后台登录</a>'
            . '</div></body></html>';
    }

    /** 部署前缀（子目录部署时非空），用于锁定页链接 */
    private static function basePrefix(): string
    {
        $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        if (preg_match('#^(.*?)/install(\.php)?/?$#', $uri, $m) === 1) {
            return rtrim($m[1], '/');
        }
        return '';
    }
}
