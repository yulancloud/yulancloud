<?php
/**
 * 余额服务
 *
 * 设计要点：
 *  - 余额以「分」为整数存 users.balance_cents，CHECK (balance_cents >= 0) 兜底
 *  - 每一次变动都必须在同一事务内：读旧余额 → 条件更新 → 写流水（含变动前后余额）
 *  - 条件更新 WHERE balance_cents >= :amount 保证并发下不会扣成负数
 *  - 幂等：balance_ledger 上 (user_id, type, ref_type, ref_id) 唯一索引，
 *    同一笔订单/充值单重复回调只会入账一次
 *  - 所有金额由服务端计算，绝不接受前端传入的余额或金额
 */
declare(strict_types=1);

final class BalanceService
{
    /**
     * 入账（充值 / 退款 / 后台加款）
     *
     * @param int    $userId
     * @param int    $amountCents 正数
     * @param string $type        recharge | refund | admin_adjust
     * @param string $refType     order | recharge | manual
     * @param string $refId       关联主键（订单号 / 充值单号），空串表示不参与幂等
     * @param string $orderNo     关联订单号（可空）
     * @param string $remark
     * @param string $operator    后台操作人（可空）
     * @return array{ok:bool, balance:int, duplicate:bool}
     */
    public static function credit(
        int $userId,
        int $amountCents,
        string $type,
        string $refType = '',
        string $refId = '',
        string $orderNo = '',
        string $remark = '',
        string $operator = ''
    ): array {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('入账金额必须为正数');
        }
        if (!in_array($type, ['recharge', 'refund', 'admin_adjust'], true)) {
            throw new InvalidArgumentException('入账类型不合法');
        }

        return Db::tx(function (PDO $pdo) use ($userId, $amountCents, $type, $refType, $refId, $orderNo, $remark, $operator): array {
            // 幂等短路：同一 ref 已入账过就直接返回当前余额
            if ($refId !== '') {
                $dup = $pdo->prepare(
                    'SELECT balance_after FROM balance_ledger
                     WHERE user_id = ? AND type = ? AND ref_type = ? AND ref_id = ? LIMIT 1'
                );
                $dup->execute([$userId, $type, $refType, $refId]);
                $after = $dup->fetchColumn();
                if ($after !== false) {
                    return ['ok' => true, 'balance' => (int)$after, 'duplicate' => true];
                }
            }

            $before = self::lockBalance($pdo, $userId);
            $after  = $before + $amountCents;

            $pdo->prepare(
                "UPDATE users SET balance_cents = :after, updated_at = datetime('now') WHERE id = :id"
            )->execute([':after' => $after, ':id' => $userId]);

            self::writeLedger($pdo, $userId, $type, $amountCents, $before, $after, $orderNo, $refType, $refId, $remark, $operator);

            return ['ok' => true, 'balance' => $after, 'duplicate' => false];
        });
    }

    /**
     * 出账（消费 / 后台扣款）
     *
     * @param int    $amountCents 正数（内部转成负数写流水）
     * @return array{ok:bool, balance:int, duplicate:bool, message:string}
     */
    public static function debit(
        int $userId,
        int $amountCents,
        string $type,
        string $refType = '',
        string $refId = '',
        string $orderNo = '',
        string $remark = '',
        string $operator = ''
    ): array {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('出账金额必须为正数');
        }
        if (!in_array($type, ['consume', 'admin_adjust'], true)) {
            throw new InvalidArgumentException('出账类型不合法');
        }

        return Db::tx(function (PDO $pdo) use ($userId, $amountCents, $type, $refType, $refId, $orderNo, $remark, $operator): array {
            if ($refId !== '') {
                $dup = $pdo->prepare(
                    'SELECT balance_after FROM balance_ledger
                     WHERE user_id = ? AND type = ? AND ref_type = ? AND ref_id = ? LIMIT 1'
                );
                $dup->execute([$userId, $type, $refType, $refId]);
                $after = $dup->fetchColumn();
                if ($after !== false) {
                    return ['ok' => true, 'balance' => (int)$after, 'duplicate' => true, 'message' => '已处理'];
                }
            }

            $before = self::lockBalance($pdo, $userId);
            if ($before < $amountCents) {
                return ['ok' => false, 'balance' => $before, 'duplicate' => false, 'message' => '余额不足'];
            }
            $after = $before - $amountCents;

            // 条件更新：并发下只有一个能成功，天然防扣成负数
            $upd = $pdo->prepare(
                "UPDATE users SET balance_cents = balance_cents - :amt, updated_at = datetime('now')
                 WHERE id = :id AND balance_cents >= :amt2"
            );
            $upd->execute([':amt' => $amountCents, ':amt2' => $amountCents, ':id' => $userId]);
            if ($upd->rowCount() !== 1) {
                return ['ok' => false, 'balance' => $before, 'duplicate' => false, 'message' => '余额不足'];
            }

            self::writeLedger($pdo, $userId, $type, -$amountCents, $before, $after, $orderNo, $refType, $refId, $remark, $operator);

            return ['ok' => true, 'balance' => $after, 'duplicate' => false, 'message' => 'ok'];
        });
    }

    /** 读取当前余额 */
    public static function balance(int $userId): int
    {
        $stmt = Db::conn()->prepare('SELECT balance_cents FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    /** 流水分页（用户中心 / 后台共用） */
    public static function ledger(int $userId, int $limit = 30, int $offset = 0): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $stmt = Db::conn()->prepare(
            'SELECT id, type, amount_cents, balance_before, balance_after, order_no, ref_type, ref_id, remark, created_at
             FROM balance_ledger WHERE user_id = :uid ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare('SELECT COUNT(*) FROM balance_ledger WHERE user_id = ?');
        $cnt->execute([$userId]);

        return [
            'items' => array_map(static fn($r) => [
                'id'             => (int)$r['id'],
                'type'           => (string)$r['type'],
                'type_label'     => self::typeLabel((string)$r['type']),
                'amount'         => (int)$r['amount_cents'],
                'balance_before' => (int)$r['balance_before'],
                'balance_after'  => (int)$r['balance_after'],
                'order_no'       => (string)$r['order_no'],
                'remark'         => (string)$r['remark'],
                'created_at'     => (string)$r['created_at'],
            ], $stmt->fetchAll()),
            'total' => (int)$cnt->fetchColumn(),
        ];
    }

    public static function typeLabel(string $t): string
    {
        return [
            'recharge'     => '充值',
            'consume'      => '消费',
            'refund'       => '退款',
            'admin_adjust' => '后台调整',
        ][$t] ?? $t;
    }

    // ---------------------------------------------------------------
    // 内部
    // ---------------------------------------------------------------

    /** 读余额并确认用户存在（事务内调用） */
    private static function lockBalance(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT balance_cents FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            throw new RuntimeException('用户不存在');
        }
        return (int)$v;
    }

    private static function writeLedger(
        PDO $pdo,
        int $userId,
        string $type,
        int $amountCents,
        int $before,
        int $after,
        string $orderNo,
        string $refType,
        string $refId,
        string $remark,
        string $operator
    ): void {
        $pdo->prepare(
            "INSERT INTO balance_ledger
             (user_id, type, amount_cents, balance_before, balance_after, order_no, ref_type, ref_id, remark, operator, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))"
        )->execute([
            $userId, $type, $amountCents, $before, $after,
            mb_substr($orderNo, 0, 32, 'UTF-8'),
            mb_substr($refType, 0, 20, 'UTF-8'),
            mb_substr($refId, 0, 64, 'UTF-8'),
            mb_substr($remark, 0, 200, 'UTF-8'),
            mb_substr($operator, 0, 40, 'UTF-8'),
        ]);
    }
}
