<?php
/**
 * v1.2.2 插件运行时（PluginRuntime）
 *
 * 设计目标：**系统零内置功能底座**。SMTP 邮箱验证码、多管理员、登录页暗色
 * 这些功能全部由插件自带代码通过本运行时接入，系统主程序不认识任何具体功能。
 *
 * 插件包（runtime 插件）在 data/extensions/plugins/<id>/ 下多出一个 code/ 目录：
 *   code/plugin.php       插件入口，返回一份「声明」数组（routes / hooks / assets）
 *   code/*.php            插件自己的业务代码（由 plugin.php require）
 *   code/assets/*.js      注入到前台 / 后台 / 登录页的脚本
 *   code/assets/*.css     注入的样式
 *
 * plugin.php 返回的声明格式：
 *   [
 *     'routes' => [
 *        ['method' => 'POST', 'path' => '/api/console/register/send-code', 'file' => 'register.php', 'auth' => ''],
 *        ...
 *     ],
 *     'hooks' => [
 *        'console.register' => 'register.php',   // 注册拦截钩子
 *     ],
 *     'assets' => [
 *        'login'   => ['assets/login.js'],        // 注入登录页
 *        'console' => ['assets/console.js'],      // 注入用户中心
 *        'admin'   => ['assets/admin.js'],        // 注入管理后台
 *     ],
 *   ]
 *
 * 安全基线：
 *   - 只加载「已安装且启用」且 manifest.runtime=true 的插件
 *   - code/ 目录位于 data/ 下（Web 不可直接访问），不复制到 public/
 *   - 入口与业务文件必须是插件目录下的真实文件，禁止 .. 穿越与符号链接
 *   - 插件路由只在声明表内匹配，未声明的路径主程序照旧 404
 *   - 前端资源通过 /api/plugin-asset/<id>/<file> 输出，强制 text/javascript|text/css
 */
declare(strict_types=1);

final class PluginRuntime
{
    /** 已加载的插件声明缓存（本次请求内） */
    private static ?array $loaded = null;

    /** 钩子执行标记：同一钩子同一插件只跑一次，避免嵌套重复 */
    private static array $hookRan = [];

    // ---------------------------------------------------------------------
    // 加载
    // ---------------------------------------------------------------------

    /**
     * 加载所有启用且声明 runtime 的插件入口，返回：
     *   [ id => ['dir'=>..., 'declare'=>..., 'manifest'=>...] ]
     */
    public static function loaded(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }
        self::$loaded = [];
        $regPath = dirname(__DIR__) . '/data/extensions/registry.json';
        if (!is_file($regPath)) {
            return self::$loaded;
        }
        $raw = @file_get_contents($regPath);
        $reg = $raw === false ? null : json_decode($raw, true);
        if (!is_array($reg) || !is_array($reg['plugins'] ?? null)) {
            return self::$loaded;
        }
        foreach ($reg['plugins'] as $id => $m) {
            if (empty($m['enabled'])) {
                continue;
            }
            $id = (string)$id;
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,47}$/', $id) !== 1) {
                continue;
            }
            $dir = dirname(__DIR__) . '/data/extensions/plugins/' . $id . '/code';
            $entry = $dir . '/plugin.php';
            if (!is_file($entry) || is_link($entry)) {
                continue;   // 静态插件（无 code/）跳过，保持向后兼容
            }
            try {
                $declare = self::loadEntry($entry);
            } catch (Throwable $e) {
                error_log('[shop][plugin] ' . $id . ' 入口加载失败: ' . $e->getMessage());
                continue;   // 单个插件损坏不影响全站
            }
            if (!is_array($declare)) {
                continue;
            }
            self::$loaded[$id] = [
                'dir'      => $dir,
                'declare'  => $declare,
                'manifest' => $m,
            ];
        }
        return self::$loaded;
    }

    /** 在隔离函数作用域里 require 入口，拿到它返回的声明数组 */
    private static function loadEntry(string $file): array
    {
        $loader = static function (string $f): array {
            $r = require $f;
            return is_array($r) ? $r : [];
        };
        return $loader($file);
    }

    /** 插件是否装了运行时（供后台/前端显示“已装功能插件”用） */
    public static function has(string $id): bool
    {
        return isset(self::loaded()[$id]);
    }

    /** 已装运行时插件的 id 列表 */
    public static function ids(): array
    {
        return array_values(array_map('strval', array_keys(self::loaded())));
    }

    // ---------------------------------------------------------------------
    // 路由分发
    // ---------------------------------------------------------------------

    /**
     * 将声明路径（可含 <id> 占位符）与实际路径匹配，抽取命名参数到 $params。
     */
    private static function matchRoute(string $declared, string $actual, array &$params): bool
    {
        if ($declared === '') {
            return false;
        }
        if (!str_contains($declared, '<')) {
            return $declared === $actual;
        }
        $tokens = [];
        $tpl = preg_replace_callback('/<([a-z][a-z0-9_]*)>/', static function ($mm) use (&$tokens) {
            $tok = '__PLPH' . count($tokens) . '__';
            $tokens[$tok] = '(?P<' . $mm[1] . '>[^/]+)';
            return $tok;
        }, $declared);
        if ($tpl === null) {
            return false;
        }
        $re = preg_quote($tpl, '#');
        $re = str_replace(array_keys($tokens), array_values($tokens), $re);
        if (preg_match('#^' . $re . '$#', $actual, $m) !== 1) {
            return false;
        }
        foreach ($m as $k => $v) {
            if (is_string($k)) {
                $params[$k] = $v;
            }
        }
        return true;
    }

    /**
     * 尝试用插件路由处理当前请求。命中并执行（handler 内部会 Response::json 并 exit），
     * 未命中返回 false，由主程序路由表继续处理。
     *
     * @return bool 是否命中
     */
    public static function dispatch(string $path, string $method): bool
    {
        $method = strtoupper($method);
        foreach (self::loaded() as $id => $p) {
            foreach (($p['declare']['routes'] ?? []) as $route) {
                if (!is_array($route)) {
                    continue;
                }
                $rm = strtoupper((string)($route['method'] ?? 'GET'));
                if ($rm !== $method) {
                    continue;
                }
                $rp = (string)($route['path'] ?? '');
                if ($rp === '') {
                    continue;
                }
                // 支持 <id> 占位符：用 matchRoute 编译成正则并抽取命名参数
                $params = [];
                if (!self::matchRoute($rp, $path, $params)) {
                    continue;
                }
                $file = self::safeFile($p['dir'], (string)($route['file'] ?? ''));
                if ($file === null) {
                    continue;
                }
                // 鉴权：auth=owner 要求店主，auth=admin 要求管理员，空则公开
                $auth = (string)($route['auth'] ?? '');
                if ($auth === 'owner') {
                    AdminAuth::requireOwner();
                } elseif ($auth === 'admin') {
                    AdminAuth::requireAdmin();
                }
                // 把路径参数与请求体交给插件 handler
                $ctx = [
                    'id'     => $id,
                    'path'   => $path,
                    'method' => $method,
                    'query'  => $_GET,
                    'params' => $params,
                ];
                $loader = static function (string $f, array $ctx): void {
                    require $f;   // handler 内可访问 $ctx，并最终 Response::json() exit
                };
                $loader($file, $ctx);
                return true;   // handler 若没 exit，也视为已处理
            }
        }
        return false;
    }

    /**
     * 供主程序判断某路径是否属于插件声明（用于 405/404 判定与安装门禁放行）。
     */
    public static function isDeclaredPath(string $path): bool
    {
        foreach (self::loaded() as $p) {
            foreach (($p['declare']['routes'] ?? []) as $route) {
                if (!is_array($route)) {
                    continue;
                }
                $rp = (string)($route['path'] ?? '');
                $params = [];
                if (self::matchRoute($rp, $path, $params)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // 钩子
    // ---------------------------------------------------------------------

    /**
     * 运行某个钩子的所有插件处理器。handler 可通过 Response::fail() 中断注册，
     * 也可直接修改传入的 $ctx（引用）。
     */
    public static function hook(string $name, array &$ctx): void
    {
        foreach (self::loaded() as $id => $p) {
            $key = $id . '|' . $name;
            if (isset(self::$hookRan[$key])) {
                continue;
            }
            $fileSpec = $p['declare']['hooks'][$name] ?? null;
            if ($fileSpec === null) {
                continue;
            }
            $file = self::safeFile($p['dir'], (string)$fileSpec);
            if ($file === null) {
                continue;
            }
            self::$hookRan[$key] = true;
            $loader = static function (string $f, array &$ctx, string $pid): void {
                require $f;   // handler 内可读写 $ctx，可 Response::fail() exit
            };
            $loader($file, $ctx, $id);
        }
    }

    // ---------------------------------------------------------------------
    // 前端资源注入
    // ---------------------------------------------------------------------

    /** 某注入点（login/console/admin）的所有资源 [['id'=>, 'path'=>, 'file'=>], ...] */
    public static function assets(string $slot): array
    {
        $out = [];
        foreach (self::loaded() as $id => $p) {
            $list = $p['declare']['assets'][$slot] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $rel) {
                $file = self::safeFile($p['dir'], (string)$rel);
                if ($file === null || !is_file($file)) {
                    continue;
                }
                $out[] = [
                    'id'   => $id,
                    'path' => (string)$rel,
                    'file' => $file,
                ];
            }
        }
        return $out;
    }

    /** 输出某个插件前端资源，限制为 js/css 且强制正确 Content-Type。直接 exit。 */
    public static function serveAsset(string $id, string $rel): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,47}$/', $id) !== 1) {
            Response::fail('not_found', '资源不存在', 404);
        }
        $p = self::loaded()[$id] ?? null;
        if ($p === null) {
            Response::fail('not_found', '资源不存在', 404);
        }
        $file = self::safeFile($p['dir'], $rel);
        if ($file === null || !is_file($file) || is_link($file)) {
            Response::fail('not_found', '资源不存在', 404);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'js' || $ext === 'mjs') {
            $type = 'text/javascript; charset=utf-8';
        } elseif ($ext === 'css') {
            $type = 'text/css; charset=utf-8';
        } else {
            Response::fail('not_found', '资源不存在', 404);
        }
        $body = @file_get_contents($file);
        if ($body === false) {
            Response::fail('not_found', '资源读取失败', 404);
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!defined('SHOP_API_SENT')) {
            define('SHOP_API_SENT', true);
        }
        http_response_code(200);
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }

    // ---------------------------------------------------------------------
    // 工具
    // ---------------------------------------------------------------------

    /**
     * 解析插件 code 目录下的相对文件，拒绝穿越 / 绝对路径 / 符号链接。
     * 返回绝对路径或 null。
     */
    private static function safeFile(string $dir, string $rel): ?string
    {
        if ($rel === '' || str_contains($rel, "\0")) {
            return null;
        }
        $rel = str_replace('\\', '/', $rel);
        if (str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel) === 1) {
            return null;
        }
        $parts = explode('/', $rel);
        foreach ($parts as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || str_starts_with($seg, '.')) {
                return null;
            }
        }
        $path = $dir . '/' . $rel;
        $real = realpath($path);
        $base = realpath($dir);
        if ($real === false || $base === false || !str_starts_with($real, $base . '/')) {
            return null;
        }
        if (is_link($real)) {
            return null;
        }
        return $real;
    }
}
