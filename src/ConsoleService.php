<?php
/**
 * 用户中心服务（/console）
 *
 * 所有方法都要求传入已登录用户 id，并在 SQL 层强制 user_id 过滤。
 * 这里不做任何鉴权判断（由路由层 UserAuth::require() 负责），
 * 但每个查询都带 user_id 条件，即使路由层出错也不会串号。
 */
declare(strict_types=1);

final class ConsoleService
{
    /** 用户中心首页聚合：用户信息 + 余额 + 订单统计 */
    public static function overview(int $userId): array
    {
        $pdo = Db::conn();

        $user = UserAuth::publicUser($userId);
        if ($user === null) {
            Response::fail('not_found', '账号不存在', 404);
        }

        $stats = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
                SUM(CASE WHEN status IN ('paid','shipped','done') THEN 1 ELSE 0 END) AS paid_orders,
                COALESCE(SUM(CASE WHEN status IN ('paid','shipped','done') THEN COALESCE(paid_cents, total_cents) ELSE 0 END), 0) AS spent_cents
             FROM orders WHERE user_id = ?"
        );
        $stats->execute([$userId]);
        $s = $stats->fetch() ?: [];

        $delivered = $pdo->prepare(
            "SELECT COUNT(*) FROM order_deliveries d
             JOIN orders o ON o.id = d.order_id
             WHERE o.user_id = ? AND d.status = 'delivered'"
        );
        $delivered->execute([$userId]);

        return [
            'user' => $user,
            'balance' => (int)$user['balance_cents'],
            'stats' => [
                'total_orders'   => (int)($s['total_orders'] ?? 0),
                'pending_orders' => (int)($s['pending_orders'] ?? 0),
                'paid_orders'    => (int)($s['paid_orders'] ?? 0),
                'spent_cents'    => (int)($s['spent_cents'] ?? 0),
                'delivered_items' => (int)$delivered->fetchColumn(),
            ],
            'payment' => PaymentService::publicInfo(),
        ];
    }

    /** 我的订单列表 */
    public static function orders(int $userId, array $q): array
    {
        $limit  = max(1, min(50, (int)($q['limit'] ?? 20)));
        $offset = max(0, (int)($q['offset'] ?? 0));
        $status = (string)($q['status'] ?? '');
        return OrderService::listForUser($userId, $limit, $offset, $status);
    }

    /** 订单详情（强制归属校验） */
    public static function orderDetail(int $userId, string $orderNo): array
    {
        $o = OrderService::findForUser($userId, $orderNo);
        if ($o === null) {
            // 不区分「不存在」与「不是你的」，避免枚举他人订单号
            Response::fail('not_found', '订单不存在', 404);
        }
        return $o;
    }

    /** 余额流水 */
    public static function ledger(int $userId, array $q): array
    {
        $limit  = max(1, min(50, (int)($q['limit'] ?? 30)));
        $offset = max(0, (int)($q['offset'] ?? 0));
        return BalanceService::ledger($userId, $limit, $offset);
    }

    /** 充值记录 */
    public static function recharges(int $userId, array $q): array
    {
        $limit  = max(1, min(50, (int)($q['limit'] ?? 20)));
        $offset = max(0, (int)($q['offset'] ?? 0));
        return PaymentService::listRecharges($userId, $limit, $offset);
    }

    /** 修改个人资料（昵称 / 邮箱） */
    public static function updateProfile(int $userId, array $in): array
    {
        $fields = [];
        $bind = [];

        if (array_key_exists('display_name', $in)) {
            $name = trim((string)$in['display_name']);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 40) {
                Response::fail('invalid_display_name', '昵称需为 1-40 个字符', 400);
            }
            if (preg_match('/[<>{}\\\\]/', $name)) {
                Response::fail('invalid_display_name', '昵称包含不允许的字符', 400);
            }
            $fields[] = 'display_name = :dn';
            $bind[':dn'] = $name;
        }

        if (array_key_exists('email', $in)) {
            $email = trim((string)$in['email']);
            if ($email !== '') {
                if (mb_strlen($email, 'UTF-8') > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Response::fail('invalid_email', '邮箱格式不正确', 400);
                }
            }
            $fields[] = 'email = :em';
            $bind[':em'] = $email;
        }

        if ($fields === []) {
            Response::fail('nothing_to_update', '没有需要更新的字段', 400);
        }

        $fields[] = "updated_at = datetime('now')";
        $bind[':id'] = $userId;
        Db::conn()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($bind);

        return ['user' => UserAuth::publicUser($userId)];
    }
}
