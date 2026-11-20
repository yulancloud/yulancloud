<?php
/**
 * 欲蓝财务魔方 —— 建号编排（限购 / 去重 / 调用连接器）
 *
 * 流程（在订单事务内调用，失败即整体回滚）：
 *   1. 校验账号名（Windows 用户名规则）与密码强度
 *   2. 账号限购（单用户）+ 可选的 IP 维度限制（防开小号）
 *   3. 同商品内账号去重（避免 net user 重复建号失败）
 *   4. 调用 YulanConnector 向 Windows APP 下达建号命令
 *   5. 记录到 yulan_accounts（只存账号名，绝不存密码）
 *
 * 安全：密码仅在调用时由订单流程临时传入，本服务不落盘、不下发、不记录明文。
 */
declare(strict_types=1);

final class YulanProvision
{
    /** Windows 用户名规则：1-20 位，仅字母数字与 . _ -，不含空格与非法字符 */
    private const ACCOUNT_RE = '/^[A-Za-z0-9._-]{1,20}$/';

    /**
     * 为整张订单里所有欲蓝商品建号（订单事务内调用）。
     * @param array<int,array{product_id:string,account:string,password:string,qty:int,item_id:int}> $lines
     * @return int 成功创建的账号数
     */
    public static function provisionOrder(
        PDO $pdo,
        int $orderId,
        string $orderNo,
        int $userId,
        string $ipHash,
        array $lines
    ): int {
        if ($lines === []) {
            return 0;
        }
        $cfg  = YulanConnector::configFromSettings();
        $made = 0;

        foreach ($lines as $ln) {
            $productId = (string)$ln['product_id'];
            $account   = (string)$ln['account'];
            $password  = (string)$ln['password'];
            $qty       = (int)$ln['qty'];
            $itemId    = (int)$ln['item_id'];

            self::validateAccount($account);
            self::validatePassword($password);

            // 该商品的限购 / IP 限制 / 商品级服务器配置（商品未配置则回退全局）
            $stmt = $pdo->prepare(
                'SELECT yulan_account_limit, yulan_ip_restrict,
                        yulan_server_ip, yulan_server_port, yulan_server_user, yulan_server_pass, yulan_rdp_host,
                        yulan_api_id, upstream_id, upstream_product_id
                 FROM products WHERE id = ?'
            );
            $stmt->execute([$productId]);
            $p = $stmt->fetch();
            $limit = $p ? (int)$p['yulan_account_limit'] : 0;
            $ipr   = $p ? (int)$p['yulan_ip_restrict'] : 0;

            self::checkLimit($pdo, $userId, $ipHash, $productId, $qty, $limit, $ipr);
            self::assertAccountFree($pdo, $productId, $account);

            $upstreamId = $p ? (int)($p['upstream_id'] ?? 0) : 0;
            $status = 'failed';
            $role = 'user';
            $detail = '';

            if ($upstreamId > 0 && class_exists('UpstreamService')) {
                // v1.4：绑定了上游 → 对接上游开通（魔方财务对接上游模式）
                $upProductId = $p ? (string)($p['upstream_product_id'] ?? '') : '';
                if ($upProductId === '') {
                    $upProductId = $productId; // 未指定上游产品时用本商品 ID
                }
                $res = UpstreamService::provision($upstreamId, $upProductId, [
                    'account' => $account,
                    'order_no' => $orderNo,
                    'qty' => $qty,
                ]);
                $status = $res['ok'] ? 'created' : 'failed';
                $detail = (string)$res['detail'];
                // 上游交付信息（IP / 一次性密码）写入发货内容，平台不长期持有明文密码
                if ($res['ok'] && !empty($res['instance'])) {
                    $inst = $res['instance'];
                    $lines = [];
                    if (($inst['ip'] ?? '') !== '') {
                        $lines[] = '连接地址：' . $inst['ip'];
                    }
                    $lines[] = '账号：' . ($inst['username'] ?? $account);
                    if (($inst['password'] ?? '') !== '') {
                        $lines[] = '密码：' . $inst['password'] . '（仅此一份，请立即保存）';
                    }
                    $pdo->prepare(
                        "INSERT INTO order_deliveries
                         (order_id, order_no, order_item_id, product_id, product_name, kind, content, status, delivered_at, created_at, updated_at)
                         VALUES (?, ?, ?, ?, (SELECT name FROM products WHERE id = ?), 'yulan', ?, 'delivered', datetime('now'), datetime('now'), datetime('now'))"
                    )->execute([
                        $orderId, $orderNo, $itemId, $productId, $productId, implode("\n", $lines),
                    ]);
                }
            } else {
                // 兼容存量：未绑定上游时回退旧的 Windows APP 建号链路
                $itemCfg = YulanConnector::resolveConfig($cfg, $p ?: []);
                $res = YulanConnector::createUser($itemCfg, $orderNo, $productId, $account, $password);
                $status = $res['ok'] ? 'created' : 'failed';
                $role = $res['role'];
                $detail = (string)$res['detail'];
            }

            $pdo->prepare(
                "INSERT INTO yulan_accounts
                 (order_id, order_item_id, product_id, account, role, status, detail, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))"
            )->execute([
                $orderId, $itemId, $productId, $account, $role, $status, $detail,
            ]);

            if ($status !== 'created') {
                Response::fail('yulan_provision_failed', '开通失败：' . $detail, 409);
            }
            $made++;
        }

        return $made;
    }

    /** 单用户 / 单 IP 限购校验（limit=0 表示不限购） */
    public static function checkLimit(
        PDO $pdo,
        int $userId,
        string $ipHash,
        string $productId,
        int $addQty,
        int $limit,
        int $ipRestrict
    ): void {
        if ($limit <= 0) {
            return;
        }
        $owned = self::ownedQty($pdo, $productId, 'o.user_id = ?', [$userId]);
        if ($owned + $addQty > $limit) {
            Response::fail(
                'yulan_limit',
                "该欲蓝商品每个账号限购 {$limit} 件，您已购买 {$owned} 件",
                409,
                ['owned' => $owned, 'limit' => $limit]
            );
        }
        if ($ipRestrict === 1) {
            $ipOwned = self::ownedQty($pdo, $productId, 'o.ip_hash = ?', [$ipHash]);
            if ($ipOwned + $addQty > $limit) {
                Response::fail(
                    'yulan_ip_limit',
                    "该欲蓝商品单 IP 限购 {$limit} 件，当前 IP 已购买 {$ipOwned} 件",
                    409,
                    ['owned' => $ipOwned, 'limit' => $limit]
                );
            }
        }
    }

    /** 同商品内账号去重（已成功建号则不可再用） */
    public static function assertAccountFree(PDO $pdo, string $productId, string $account): void
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM yulan_accounts WHERE product_id = ? AND account = ? AND status = 'created'"
        );
        $stmt->execute([$productId, $account]);
        if ($stmt->fetchColumn() !== false) {
            Response::fail('yulan_account_taken', "账号「{$account}」已被占用，请换一个账号名", 409);
        }
    }

    public static function validateAccount(string $account): string
    {
        $account = trim($account);
        if ($account === '' || !preg_match(self::ACCOUNT_RE, $account)) {
            Response::fail(
                'yulan_invalid_account',
                '账号名需为 1-20 位字母、数字、点、下划线或连字符，且不含空格',
                400
            );
        }
        return $account;
    }

    public static function validatePassword(string $password): string
    {
        if (!is_string($password) || mb_strlen($password, 'UTF-8') < 6) {
            Response::fail('yulan_invalid_password', '欲蓝账号密码至少 6 位', 400);
        }
        return $password;
    }

    /** 已购买数量（该用户/该 IP 已支付订单中该欲蓝商品的数量总和） */
    private static function ownedQty(PDO $pdo, string $productId, string $where, array $params): int
    {
        $sql = "SELECT COALESCE(SUM(i.qty), 0)
                FROM order_items i JOIN orders o ON o.id = i.order_id
                WHERE i.product_id = ? AND {$where}
                  AND o.status IN ('paid', 'shipped', 'done')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$productId], $params));
        return (int)$stmt->fetchColumn();
    }
}
