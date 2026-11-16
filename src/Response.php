<?php
/**
 * 统一 JSON 响应 + 安全响应头
 */
declare(strict_types=1);

final class Response
{
    public static function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('Cross-Origin-Resource-Policy: same-origin');

        $cfg = require dirname(__DIR__) . '/config/config.php';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        // 只回显白名单内的来源，绝不使用 *（避免任意站点读取用户数据）
        if ($origin !== '' && in_array($origin, $cfg['allowed_origins'], true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Idempotency-Key');
            header('Access-Control-Max-Age: 600');
        }
    }

    /**
     * 输出纯文本响应（易支付回调必须回 `success` / `fail` 纯文本）
     *
     * 【v5.5】必须走这里而不是直接 echo：
     *   api/index.php 的 shutdown 守卫会把「没标记为已输出」的响应当成脏输出转成 JSON 500，
     *   而支付网关只认纯文本 success，被转成 JSON 就会导致网关无限重试。
     */
    public static function plain(string $body, int $status = 200): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!defined('SHOP_API_SENT')) {
            define('SHOP_API_SENT', true);
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo $body;
        exit;
    }

    /**
     * 输出 CSS 响应（v1.2 当前主题样式：GET /api/theme.css）
     *
     * 与 plain() 一样必须标记 SHOP_API_SENT，否则 api/index.php 的 shutdown 守卫
     * 会把这段 CSS 当成「脏输出」转成 JSON 500，浏览器拿到非 CSS 内容就整站丢样式。
     * 用 no-store：主题切换后刷新即刻生效，不受 ?v= 版本号和 CDN 缓存影响。
     */
    public static function css(string $body, int $status = 200): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!defined('SHOP_API_SENT')) {
            define('SHOP_API_SENT', true);
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/css; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Cross-Origin-Resource-Policy: same-origin');
        }
        echo $body;
        exit;
    }

    public static function json(array $payload, int $status = 200): never
    {
        // 【v5.5】输出前清空所有缓冲：任何被 PHP 警告/notice/上游 echo 污染的脏输出
        // 都在这里被丢弃，保证响应体是**纯 JSON**（前端 JSON.parse 不会失败）。
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        // 标记「已正常输出 JSON」，让 api/index.php 的 shutdown 守卫不再兜底
        if (!defined('SHOP_API_SENT')) {
            define('SHOP_API_SENT', true);
        }

        self::headers();
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data, array $extra = []): never
    {
        self::json(array_merge(['ok' => true, 'data' => $data], $extra));
    }

    public static function fail(string $code, string $message, int $status = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]] + $extra, $status);
    }
}
