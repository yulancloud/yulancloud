<?php
/**
 * 请求路径归一化
 *
 * 为什么要这个文件：
 *   项目可以部署在根目录（/api/orders）也可以部署在子目录（/shop/api/orders）。
 *   如果路由表只认 "/api/xxx"，子目录部署时后端永远匹配不到，最终落到 404。
 *   更糟的是前端会静默回退静态数据，页面看起来正常，只有点「下单」才弹 404。
 *
 * 处理方式（两层保险）：
 *   1. 前端 api.js 自动探测接口基址（见 public/assets/api.js）
 *   2. 后端在这里把任意 "<前缀>/api/..." 归一成 "/api/..."，不依赖部署路径
 *
 * 安全要点：
 *   只做字符串截取，不解析用户可控的文件路径，不触碰文件系统。
 */
declare(strict_types=1);

final class Path
{
    /**
     * 把可能是子目录形式的 API 路径归一化为 /api/... 形式。
     *
     *   /api/orders            → /api/orders
     *   /shop/api/orders       → /api/orders
     *   /a/b/api/orders        → /api/orders
     *   /api                   → /api
     *   /shop/api              → /api
     *   /assets/api.js         → 原样返回（非 API 路由，交给静态文件处理）
     */
    public static function normalizeApi(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        // 已经是标准形式
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return $path;
        }

        // 形如 /shop/api 或 /shop/api/
        if ($path === '/api/' || preg_match('#/api/?$#', $path) === 1) {
            $trimmed = rtrim($path, '/');
            if (str_ends_with($trimmed, '/api')) {
                // 只在前缀段数和 /api 之前另有目录时替换，避免误伤 /assets/api.js
                if (preg_match('#^/[^.]*(?=/api$)#', $trimmed) === 1) {
                    return '/api';
                }
            }
        }

        // 形如 /shop/api/orders —— 取第一个 "/api/" 之后的部分
        $pos = strpos($path, '/api/');
        if ($pos !== false && $pos > 0) {
            // "/api/" 之前不能出现点号（排除 /assets/api/x.js 这类静态资源）
            $prefix = substr($path, 0, $pos);
            if (!str_contains($prefix, '.')) {
                return substr($path, $pos);
            }
        }

        return $path;
    }

    /** 当前请求路径（已 rawurldecode） */
    public static function current(): string
    {
        $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        return rawurldecode($uri);
    }

    /**
     * 站点部署前缀（用于安装完成后的跳转链接）
     * 依据当前请求路径推断：
     *   /shop/api/install   → /shop
     *   /api/install        → ''
     * 推断不出来时返回空串（根目录部署）。
     */
    public static function basePrefix(): string
    {
        $path = self::normalizeApi(self::current());
        $raw  = self::current();
        // raw 比 path 多出的部分就是部署前缀
        if ($path !== $raw && str_ends_with($raw, $path)) {
            $prefix = substr($raw, 0, strlen($raw) - strlen($path));
            $prefix = rtrim($prefix, '/');
            return preg_match('#^/[A-Za-z0-9._~/-]*$#', $prefix) === 1 ? $prefix : '';
        }
        return '';
    }
}
