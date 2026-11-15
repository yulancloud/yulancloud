<?php
/**
 * 订单状态机 —— 全站唯一的订单状态推进入口
 *
 * 状态与允许的流转：
 *
 *   pending（待支付）
 *      ├─ paid      支付成功（网关回调 / 余额支付 / 后台人工确认）
 *      └─ cancelled 用户取消 / 超时关单
 *
 *   paid（已支付 / 待发货）
 *      ├─ shipped   已发货（虚拟商品自动发货后 / 实体商品管理员填运单号后）
 *      ├─ done      已完成（无需物流的订单可直接完成）
 *      └─ cancelled 退款后关单（库存归还）
 *
 *   shipped（已发货）
 *      ├─ done      用户确认收货 / 管理员标记完成
 *      └─ cancelled 退款后关单
 *
 *   done / cancelled 为终态，不再流转
 *
 * 幂等保证：
 *  - 每次推进都是「条件更新」：UPDATE ... WHERE id = ? AND status = <旧状态>
 *  - 并发下只有一个请求能 rowCount = 1，其余返回 false（不报错、不重复发货）
 *  - 每次成功推进写一条 order_events，便于对账与追责
 */
declare(strict_types=1);

final class OrderStateMachine
{
    public const PENDING   = 'pending';
    public const PAID      = 'paid';
    public const SHIPPED   = 'shipped';
    public const DONE      = 'done';
    public const CANCELLED = 'cancelled';

    /** 允许的流转表：from => [to, ...] */
    private const ALLOWED = [
        self::PENDING   => [self::PAID, self::CANCELLED],
        self::PAID      => [self::SHIPPED, self::DONE, self::CANCELLED],
        self::SHIPPED   => [self::DONE, self::CANCELLED],
        self::DONE      => [],
        self::CANCELLED => [],
    ];

    public static function statuses(): array
    {
        return array_keys(self::ALLOWED);
    }

    public static function isStatus(string $s): bool
    {
        return isset(self::ALLOWED[$s]);
    }

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function label(string $s): string
    {
        return [
            self::PENDING   => '待支付',
            self::PAID      => '已支付',
            self::SHIPPED   => '已发货',
            self::DONE      => '已完成',
            self::CANCELLED => '已取消',
        ][$s] ?? $s;
    }

    /**
     * 推进订单状态（幂等）
     *
     * @param PDO    $pdo
     * @param int    $orderId
     * @param string $to      目标状态
     * @param array  $opts    from: 期望的旧状态（不传则按当前状态判断）
     *                        actor: 操作者（system / user:12 / admin:admin / gateway）
     *                        reason: 原因
     *                        fields: 额外要一起更新的列（白名单由调用方保证）
     * @return bool true = 本次真的推进了；false = 状态已不是期望值（并发/重复）
     */
    public static function transition(PDO $pdo, int $orderId, string $to, array $opts = []): bool
    {
        if (!self::isStatus($to)) {
            throw new InvalidArgumentException('非法目标状态：' . $to);
        }

        $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $from = $stmt->fetchColumn();
        if ($from === false) {
            return false;
        }
        $from = (string)$from;

        // 调用方指定了期望旧状态时，必须一致（防止「已支付」被当成「待支付」再推一次）
        if (isset($opts['from']) && (string)$opts['from'] !== $from) {
            return false;
        }
        if (!self::can($from, $to)) {
            return false;
        }

        $fields = ['status = :to', "updated_at = datetime('now')"];
        $bind   = [':to' => $to, ':id' => $orderId, ':from' => $from];

        if ($to === self::CANCELLED) {
            $fields[] = "closed_at = COALESCE(closed_at, datetime('now'))";
        }
        if ($to === self::SHIPPED) {
            $fields[] = "shipped_at = COALESCE(shipped_at, datetime('now'))";
        }
        if ($to === self::DONE) {
            $fields[] = "done_at = COALESCE(done_at, datetime('now'))";
        }

        // 额外列（调用方已做白名单/参数化）
        foreach (($opts['fields'] ?? []) as $col => $val) {
            if (!preg_match('/^[a-z_]{2,32}$/', (string)$col)) {
                continue;
            }
            $ph = ':x_' . $col;
            $fields[] = $col . ' = ' . $ph;
            $bind[$ph] = $val;
        }

        $sql = 'UPDATE orders SET ' . implode(', ', $fields)
             . ' WHERE id = :id AND status = :from';
        $upd = $pdo->prepare($sql);
        $upd->execute($bind);
        if ($upd->rowCount() !== 1) {
            return false;
        }

        self::log($pdo, $orderId, $from, $to, (string)($opts['actor'] ?? 'system'), (string)($opts['reason'] ?? ''));
        return true;
    }

    /** 写状态流转事件（失败不影响主流程） */
    private static function log(PDO $pdo, int $orderId, string $from, string $to, string $actor, string $reason): void
    {
        try {
            $no = $pdo->prepare('SELECT order_no FROM orders WHERE id = ?');
            $no->execute([$orderId]);
            $orderNo = (string)($no->fetchColumn() ?: '');

            $pdo->prepare(
                "INSERT INTO order_events (order_id, order_no, from_status, to_status, actor, reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, datetime('now'))"
            )->execute([
                $orderId,
                mb_substr($orderNo, 0, 32, 'UTF-8'),
                $from,
                $to,
                mb_substr($actor, 0, 40, 'UTF-8'),
                mb_substr($reason, 0, 200, 'UTF-8'),
            ]);
        } catch (Throwable $e) {
            error_log('[shop][state] event log failed: ' . $e->getMessage());
        }
    }

    /** 订单状态流转历史（后台/用户中心可查） */
    public static function events(int $orderId): array
    {
        $stmt = Db::conn()->prepare(
            'SELECT from_status, to_status, actor, reason, created_at
             FROM order_events WHERE order_id = ? ORDER BY id'
        );
        $stmt->execute([$orderId]);
        return array_map(static fn($r) => [
            'from'       => (string)$r['from_status'],
            'to'         => (string)$r['to_status'],
            'from_label' => self::label((string)$r['from_status']),
            'to_label'   => self::label((string)$r['to_status']),
            'actor'      => (string)$r['actor'],
            'reason'     => (string)$r['reason'],
            'at'         => (string)$r['created_at'],
        ], $stmt->fetchAll());
    }
}
