<?php
/**
 * 限流 —— 基于 SQLite 的固定窗口计数，按 IP 哈希分桶
 * 目的：防止下单接口被刷、防止暴力枚举订单号
 */
declare(strict_types=1);

final class RateLimiter
{
    /**
     * @return bool true = 放行，false = 超限
     */
    public static function hit(string $action, string $identity, ?int $window = null, ?int $max = null): bool
    {
        $cfg = require dirname(__DIR__) . '/config/config.php';
        $rule = $cfg['rate_limit'][$action] ?? ['window' => 60, 'max' => 60];
        $window = $window ?? $rule['window'];
        $max    = $max ?? $rule['max'];

        $now    = time();
        $start  = intdiv($now, $window) * $window;
        $bucket = $action . ':' . hash('sha256', $identity);

        // 数据库还没建好（首次访问 / 正在安装）时不限流：
        // 此时也没有可保护的业务数据，硬拦只会让安装向导 500。
        try {
            $pdo = Db::conn();
        } catch (Throwable $e) {
            return true;
        }

        try {
            $pdo->prepare(
                'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                 ON CONFLICT(bucket, window_start) DO UPDATE SET hits = hits + 1'
            )->execute([$bucket, $start]);

            $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
            $stmt->execute([$bucket, $start]);
            $hits = (int)$stmt->fetchColumn();

            // 顺手清理过期窗口（概率触发，避免每次全表扫）
            if (random_int(1, 50) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 86400]);
            }
        } catch (Throwable $e) {
            // 表还不存在或写锁冲突：放行，由业务层自己的错误处理负责
            return true;
        }

        return $hits <= $max;
    }

    public static function guard(string $action): void
    {
        if (!self::hit($action, self::clientIp())) {
            header('Retry-After: 60');
            Response::fail('rate_limited', '请求过于频繁，请稍后再试', 429);
        }
    }

    /** 客户端 IP：只信任 REMOTE_ADDR，不信任可伪造的 X-Forwarded-For */
    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** IP 只以哈希形式落库，避免明文留存个人信息 */
    public static function ipHash(): string
    {
        return hash('sha256', self::clientIp() . '|shiwu');
    }
}
