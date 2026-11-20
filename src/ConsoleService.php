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

    /* ============================================================
     * 控制台 —— 我的「欲蓝财务魔方」商品
     *
     * yulan_accounts 只存「账号名」不存密码。用户在这里能看到自己已购的
     * 欲蓝商品账号，进入详情后由网站把「改号命令」下发给 Windows APP，
     * APP 以管理员权限强制重置密码 / 改用户名（无需旧密码）。
     * 所有查询一律 JOIN orders 强制 user_id 过滤，杜绝越权改他人账号。
     * ============================================================ */

    /** 单条账号原始行（含归属校验），找不到一律 404，不区分「不存在/不是你的」 */
    private static function yulanOwnedRow(int $userId, int $accountId): array
    {
        $stmt = Db::conn()->prepare(
            "SELECT a.id, a.order_id, a.product_id, a.account, a.role, a.status,
                    a.detail, a.created_at, a.updated_at,
                    o.order_no, o.user_id,
                    p.name AS product_name,
                    p.yulan_rdp_host, p.yulan_server_ip, p.yulan_server_port,
                    p.yulan_server_user, p.yulan_server_pass, p.yulan_api_id
             FROM yulan_accounts a
             JOIN orders o ON o.id = a.order_id
             LEFT JOIN products p ON p.id = a.product_id
             WHERE a.id = ? AND o.user_id = ?"
        );
        $stmt->execute([$accountId, $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Response::fail('not_found', '商品不存在', 404);
        }
        return $row;
    }

    /** 把数据库行整理成前端可用的展示结构（绝不含密码） */
    private static function yulanPublic(array $r): array
    {
        return [
            'id'           => (int)$r['id'],
            'product_id'   => (string)$r['product_id'],
            'product_name' => (string)($r['product_name'] ?? '欲蓝财务魔方'),
            'order_no'     => (string)$r['order_no'],
            'account'      => (string)$r['account'],
            'role'         => (string)$r['role'],
            'role_label'   => $r['role'] === 'admin' ? '管理员' : '普通用户',
            'status'       => (string)$r['status'],
            'connection'   => YulanConnector::connectionAddress($r),
            'created_at'   => (string)$r['created_at'],
            'updated_at'   => (string)$r['updated_at'],
        ];
    }

    /** 控制台列表：当前用户名下所有已成功创建的欲蓝账号 */
    public static function yulanAccounts(int $userId): array
    {
        $stmt = Db::conn()->prepare(
            "SELECT a.id, a.order_id, a.product_id, a.account, a.role, a.status,
                    a.detail, a.created_at, a.updated_at,
                    o.order_no, o.user_id,
                    p.name AS product_name,
                    p.yulan_rdp_host, p.yulan_server_ip, p.yulan_server_port,
                    p.yulan_server_user, p.yulan_server_pass, p.yulan_api_id
             FROM yulan_accounts a
             JOIN orders o ON o.id = a.order_id
             LEFT JOIN products p ON p.id = a.product_id
             WHERE o.user_id = ? AND a.status = 'created'
             ORDER BY a.id DESC"
        );
        $stmt->execute([$userId]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = self::yulanPublic($r);
        }
        return ['items' => $items];
    }

    /** 控制台详情：单个欲蓝商品账号 */
    public static function yulanAccountDetail(int $userId, int $accountId): array
    {
        return ['item' => self::yulanPublic(self::yulanOwnedRow($userId, $accountId))];
    }

    /**
     * 修改欲蓝账号的用户名 / 密码。
     * 输入：new_account（可选，空=不改）、new_password（可选，空=不改），至少一个非空。
     * 密码不落库、不下发到前端；改名成功后同步更新 yulan_accounts.account。
     */
    public static function updateYulanAccount(int $userId, int $accountId, array $in): array
    {
        $pdo = Db::conn();
        $row = self::yulanOwnedRow($userId, $accountId);

        if ((string)$row['status'] !== 'created') {
            Response::fail('yulan_not_ready', '该账号尚未创建成功，暂不能修改', 409);
        }

        $newAccount  = trim((string)($in['new_account'] ?? ''));
        $newPassword = (string)($in['new_password'] ?? '');

        if ($newAccount === '' && $newPassword === '') {
            Response::fail('nothing_to_update', '请填写新用户名或新密码', 400);
        }
        if ($newAccount !== '' && $newAccount === (string)$row['account']) {
            Response::fail('yulan_same_account', '新用户名与当前用户名相同', 400);
        }
        // 复用建号时的同一套校验规则（账号名 1-20 位、密码 ≥6 位）
        if ($newAccount !== '') {
            $newAccount = YulanProvision::validateAccount($newAccount);
            // 同商品内改名不得与别人的已建账号撞名（排除自己）
            $dup = $pdo->prepare(
                "SELECT 1 FROM yulan_accounts
                 WHERE product_id = ? AND account = ? AND status = 'created' AND id <> ?"
            );
            $dup->execute([$row['product_id'], $newAccount, $accountId]);
            if ($dup->fetchColumn() !== false) {
                Response::fail('yulan_account_taken', "用户名「{$newAccount}」已被占用，请换一个", 409);
            }
        }
        if ($newPassword !== '') {
            $newPassword = YulanProvision::validatePassword($newPassword);
        }

        // v1.3：商品绑定的 API > 商品级服务器配置 > 全局设置（与建号时一致）
        $cfg = YulanConnector::resolveConfig(
            YulanConnector::configFromSettings(), $row
        );
        $res = YulanConnector::updateUser(
            $cfg,
            (string)$row['order_no'],
            (string)$row['product_id'],
            (string)$row['account'],
            $newAccount,
            $newPassword
        );

        if (!$res['ok']) {
            Response::fail('yulan_update_failed', '修改失败：' . ($res['detail'] ?: '欲蓝 APP 未响应'), 409);
        }

        // 改名成功 → 同步本地账号名；同时写一条不含明文密码的审计说明
        $finalAccount = $res['renamed'] ? $res['account'] : (string)$row['account'];
        $note = '控制台修改（' . date('Y-m-d H:i') . '）：'
              . ($res['password_changed'] ? '密码已重置' : '')
              . ($res['renamed'] ? (($res['password_changed'] ? '；' : '') . '用户名改为 ' . $finalAccount) : '');
        $pdo->prepare(
            "UPDATE yulan_accounts SET account = ?, detail = ?, updated_at = datetime('now') WHERE id = ?"
        )->execute([
            $finalAccount,
            trim((string)$row['detail']) . "\n" . $note,
            $accountId,
        ]);

        return [
            'item'             => self::yulanPublic(self::yulanOwnedRow($userId, $accountId)),
            'renamed'          => $res['renamed'],
            'password_changed' => $res['password_changed'],
            'message'          => $res['detail'] ?: '修改成功',
        ];
    }
}
