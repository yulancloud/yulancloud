<?php
/**
 * 支付服务（易支付 EPay V1 通用协议）—— v5.1
 *
 * 职责：
 *  1. 发起在线支付（微信 / 支付宝）：服务端签名，返回网关表单，前端 POST 跳转
 *  2. 余额支付：同一事务内扣余额 + 写流水 + 订单转已支付 + 自动发货
 *  3. 异步回调（notify）：验签 → 商户号 → 通道 → 金额 → 订单存在性 → 状态机幂等推进
 *  4. 充值单：建单 → 支付 → 回调入账（幂等）
 *  5. 超时关单：pending 超过 expire_minutes 自动取消并归还库存
 *
 * 关键安全设计：
 *  - 验签：hash_equals 恒定时间比较；sign_type 只用服务端配置
 *  - 金额：回调金额必须与订单应付金额「完全相等」（容差默认 0 分，可配）
 *  - 通道：回调 type 必须与发起支付时的通道一致，且只允许 wxpay / alipay
 *  - 幂等：状态机条件更新（WHERE status = 'pending'），重复回调只成功一次
 *  - 未知订单号 / 已取消订单：拒绝并记日志，绝不改状态
 *  - 商户密钥绝不出现在任何响应里
 */
declare(strict_types=1);

final class PaymentService
{
    /** 读取支付配置并判断是否就绪（后台数据库配置优先，留空沿用 .env） */
    public static function config(): array
    {
        $cfg = (require dirname(__DIR__) . '/config/config.php')['payment'];
        $cfg = self::mergeAdminSettings($cfg);
        // 后台常把网关填成站点首页（https://pay.xxx.com/），在此统一补齐标准
        // /submit.php 入口。读取时规范化，已保存的旧配置无需重新保存即刻生效。
        $normalized = EPay::normalizeGateway((string)$cfg['gateway']);
        if ($normalized !== null) {
            $cfg['gateway'] = $normalized;
        }
        $cfg['ready'] = $cfg['enabled']
            && $cfg['pid'] !== ''
            && $cfg['key'] !== ''
            && EPay::sanitizeGateway($cfg['gateway']) !== null;
        return $cfg;
    }

    /**
     * 后台「站点设置 → 易支付配置」覆盖环境变量。
     * 约定：后台字段留空 = 沿用 config/.env（兼容已有部署）；填了才覆盖。
     * 数据库不可用时静默回退，支付回调绝不能因为读不到设置表就 500。
     */
    private static function mergeAdminSettings(array $cfg): array
    {
        try {
            $s = SettingsService::all();
        } catch (Throwable $e) {
            return $cfg;
        }
        $v = static function (string $k) use ($s): string {
            return trim((string)($s[$k] ?? ''));
        };

        $enabled = $v('epay_enabled');
        if ($enabled !== '') {
            $cfg['enabled'] = in_array(strtolower($enabled), ['1', 'true', 'yes', 'on'], true);
        }
        foreach ([
            'pid'        => 'epay_pid',
            'key'        => 'epay_key',
            'gateway'    => 'epay_gateway',
            'notify_url' => 'epay_notify_url',
            'return_url' => 'epay_return_url',
        ] as $cfgKey => $setKey) {
            $val = $v($setKey);
            if ($val !== '') {
                $cfg[$cfgKey] = $val;
            }
        }

        $expire = $v('epay_expire_minutes');
        if ($expire !== '' && ctype_digit($expire) && (int)$expire > 0) {
            $cfg['expire_minutes'] = min((int)$expire, 1440);
        }

        $rawChannels = $v('epay_channels');
        if ($rawChannels !== '') {
            $alias  = ['wechat' => 'wxpay', 'weixin' => 'wxpay', 'ali' => 'alipay'];
            $parsed = [];
            foreach (explode(',', $rawChannels) as $ch) {
                $ch = strtolower(trim($ch));
                $ch = $alias[$ch] ?? $ch;
                if (in_array($ch, ['wxpay', 'alipay'], true) && !in_array($ch, $parsed, true)) {
                    $parsed[] = $ch;
                }
            }
            if ($parsed !== []) {
                $cfg['channels'] = $parsed;
            }
        }
        return $cfg;
    }

    /** 对外暴露给前端的支付能力（绝不含密钥） */
    public static function publicInfo(): array
    {
        $c = self::config();
        $channels = [];
        foreach ($c['channels'] as $ch) {
            // 只放行白名单内的两个通道
            if (!EPay::isChannel($ch)) {
                continue;
            }
            $channels[] = ['id' => $ch, 'label' => EPay::channelLabel($ch)];
        }
        return [
            'enabled'        => (bool)$c['ready'],
            'channels'       => $c['ready'] ? $channels : [],
            'expire_minutes' => (int)$c['expire_minutes'],
            'reason'         => $c['ready'] ? '' : self::notReadyReason($c),
            // 诊断信息（不含密钥）：后台「易支付配置」据此显示实际提交地址与回调地址，
            // 店主可一眼核对网关是否指向 /submit.php、回调是不是外网可访问的地址。
            'gateway'        => $c['ready'] ? (string)EPay::sanitizeGateway($c['gateway']) : '',
            'notify_url'     => $c['ready'] ? self::notifyUrl($c) : '',
        ];
    }

    private static function notReadyReason(array $c): string
    {
        if (!$c['enabled']) {
            return '在线支付未启用（后台「站点设置 → 易支付配置」里开启）';
        }
        if ($c['pid'] === '') {
            return '缺少商户号 PID';
        }
        if ($c['key'] === '') {
            return '缺少商户密钥 KEY';
        }
        if (EPay::sanitizeGateway($c['gateway']) === null) {
            return '网关地址无效（必须是 https 地址）';
        }
        return '支付未就绪';
    }

    /** 站点基址（生成回调地址用） */
    public static function baseUrl(): string
    {
        $cfg = require dirname(__DIR__) . '/config/config.php';
        if ($cfg['base_url'] !== '') {
            return $cfg['base_url'];
        }

        // 反向代理 / 发布平台下 HTTP_HOST 与 X-Forwarded-Host 往往是内部沙箱地址，
        // 用它生成的 notify_url 支付平台根本回调不到 —— 表现为「钱付了订单没变」。
        // 运维显式声明的 SHOP_TRUSTED_ORIGINS（外网发布域名）优先作为站点基址。
        $trusted = (string)(getenv('SHOP_TRUSTED_ORIGINS') ?: '');
        if ($trusted !== '') {
            foreach (explode(',', $trusted) as $o) {
                $o = trim($o);
                $pu = parse_url($o);
                if (is_array($pu) && isset($pu['host']) && $pu['host'] !== ''
                    && in_array(strtolower((string)($pu['scheme'] ?? '')), ['http', 'https'], true)) {
                    return rtrim($o, '/');
                }
            }
        }

        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        // 代理透传了真实外部域名时优先采用（取最左侧一跳）
        $fwd = trim((string)(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0] ?? ''));
        if ($fwd !== '' && preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $fwd)) {
            $host = $fwd;
        }
        if (!preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        return $scheme . '://' . $host;
    }

    /** 异步通知地址（配置优先，否则按 base_url 拼） */
    public static function notifyUrl(array $c): string
    {
        return $c['notify_url'] !== '' ? $c['notify_url'] : self::baseUrl() . '/api/pay/notify';
    }

    // ===============================================================
    // 商品订单支付
    // ===============================================================

    /** 发起支付（订单号 + token，兼容匿名通道） */
    public static function start(string $orderNo, string $token, string $channel): array
    {
        $c = self::config();
        self::assertReady($c);
        self::assertChannel($c, $channel);

        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            'SELECT id, order_no, status, total_cents, paid_cents, created_at
             FROM orders WHERE order_no = :no AND access_hash = :ah'
        );
        $stmt->execute([':no' => $orderNo, ':ah' => hash('sha256', $token)]);
        $o = $stmt->fetch();
        if (!$o) {
            Response::fail('not_found', '订单不存在或凭证已失效', 404);
        }

        return self::buildOrderPayment($o, $channel, $c);
    }

    /** 发起支付（已登录用户，按 user_id 校验归属） */
    public static function startForUser(int $userId, string $orderNo, string $channel): array
    {
        $c = self::config();
        self::assertReady($c);
        self::assertChannel($c, $channel);

        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            'SELECT id, order_no, status, total_cents, paid_cents, created_at
             FROM orders WHERE order_no = :no AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':no' => $orderNo, ':uid' => $userId]);
        $o = $stmt->fetch();
        if (!$o) {
            Response::fail('not_found', '订单不存在', 404);
        }

        return self::buildOrderPayment($o, $channel, $c);
    }

    private static function assertReady(array $c): void
    {
        if (!$c['ready']) {
            Response::fail('payment_not_configured', '支付功能尚未配置，请联系店主', 503);
        }
    }

    private static function assertChannel(array $c, string $channel): void
    {
        if (!EPay::isChannel($channel) || !in_array($channel, $c['channels'], true)) {
            Response::fail('invalid_channel', '不支持的支付方式（仅支持微信支付与支付宝）', 400);
        }
    }

    /** 组装网关表单（订单校验 + 落 payments + 签名） */
    private static function buildOrderPayment(array $o, string $channel, array $c): array
    {
        $status = (string)$o['status'];
        if (in_array($status, [OrderStateMachine::PAID, OrderStateMachine::SHIPPED, OrderStateMachine::DONE], true)) {
            Response::fail('already_paid', '该订单已支付', 409);
        }
        if ($status === OrderStateMachine::CANCELLED) {
            Response::fail('order_cancelled', '该订单已关闭，无法支付', 409);
        }
        if ($status !== OrderStateMachine::PENDING) {
            Response::fail('order_not_payable', '当前订单状态不支持支付', 409);
        }
        if (self::isExpired($o)) {
            self::closeExpired((int)$o['id'], '发起支付时已超时');
            Response::fail('order_expired', '订单已超时关闭，请重新下单', 409);
        }

        $amount = (int)$o['total_cents'];
        if ($amount <= 0) {
            Response::fail('invalid_amount', '订单金额异常，无法支付', 409);
        }

        $pdo = Db::conn();
        // 商户单号：订单号 + 6 位随机后缀（同一订单可多次发起，各自独立流水）
        $outTradeNo = $o['order_no'] . '-' . strtoupper(bin2hex(random_bytes(3)));

        $pdo->prepare(
            "INSERT INTO payments (order_id, order_no, out_trade_no, channel, amount_cents, status, ip_hash)
             VALUES (?, ?, ?, ?, ?, 'created', ?)"
        )->execute([(int)$o['id'], $o['order_no'], $outTradeNo, $channel, $amount, RateLimiter::ipHash()]);

        $returnUrl = $c['return_url'] !== ''
            ? $c['return_url']
            : self::baseUrl() . '/api/pay/return';

        $form = EPay::buildSubmitForm(
            (string)EPay::sanitizeGateway($c['gateway']),
            (string)$c['pid'],
            (string)$c['key'],
            (string)$c['sign_type'],
            $channel,
            $outTradeNo,
            self::notifyUrl($c),
            $returnUrl,
            '订单 ' . $o['order_no'],
            $amount,
            EPay::detectDevice((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))
        );

        return $form + [
            'out_trade_no' => $outTradeNo,
            'amount'       => $amount,
            'channel'      => $channel,
        ];
    }

    /**
     * 余额支付（已登录用户）
     *
     * 全流程在一个事务内：
     *   校验订单归属与状态 → 扣余额（条件更新）→ 写流水 → 订单转已支付 → 自动发货
     * 任一步失败整体回滚，不会出现「扣了钱没发货」。
     */
    public static function payWithBalance(int $userId, string $orderNo): array
    {
        $cfg = require dirname(__DIR__) . '/config/config.php';
        $maxPay = (int)$cfg['rules']['balance_pay_max_cents'];

        return Db::tx(function (PDO $pdo) use ($userId, $orderNo, $maxPay): array {
            $stmt = $pdo->prepare(
                'SELECT id, order_no, status, total_cents, created_at FROM orders
                 WHERE order_no = :no AND user_id = :uid LIMIT 1'
            );
            $stmt->execute([':no' => $orderNo, ':uid' => $userId]);
            $o = $stmt->fetch();
            if (!$o) {
                Response::fail('not_found', '订单不存在', 404);
            }
            $status = (string)$o['status'];
            if (in_array($status, [OrderStateMachine::PAID, OrderStateMachine::SHIPPED, OrderStateMachine::DONE], true)) {
                Response::fail('already_paid', '该订单已支付', 409);
            }
            if ($status === OrderStateMachine::CANCELLED) {
                Response::fail('order_cancelled', '该订单已关闭，无法支付', 409);
            }
            if ($status !== OrderStateMachine::PENDING) {
                Response::fail('order_not_payable', '当前订单状态不支持支付', 409);
            }
            if (self::isExpired($o)) {
                Response::fail('order_expired', '订单已超时关闭，请重新下单', 409);
            }

            $amount = (int)$o['total_cents'];
            if ($amount <= 0) {
                Response::fail('invalid_amount', '订单金额异常', 409);
            }
            if ($amount > $maxPay) {
                Response::fail('amount_too_large', '该订单金额超出余额支付上限，请使用在线支付', 400);
            }

            // 余额不足：给出明确提示（含当前余额与差额）
            $before = (int)$pdo->query('SELECT balance_cents FROM users WHERE id = ' . (int)$userId)->fetchColumn();
            if ($before < $amount) {
                $short = $amount - $before;
                Response::fail(
                    'insufficient_balance',
                    '余额不足：当前余额 ¥' . number_format($before / 100, 2)
                    . '，本单需 ¥' . number_format($amount / 100, 2)
                    . '，还差 ¥' . number_format($short / 100, 2) . '，请先充值',
                    409,
                    ['balance' => $before, 'required' => $amount, 'short' => $short]
                );
            }

            // 扣余额（条件更新，防并发扣成负数）
            $upd = $pdo->prepare(
                "UPDATE users SET balance_cents = balance_cents - :amt, updated_at = datetime('now')
                 WHERE id = :id AND balance_cents >= :amt2"
            );
            $upd->execute([':amt' => $amount, ':amt2' => $amount, ':id' => $userId]);
            if ($upd->rowCount() !== 1) {
                Response::fail('insufficient_balance', '余额不足，请先充值', 409);
            }
            $after = $before - $amount;

            // 写流水（含变动前后余额与订单号）
            $pdo->prepare(
                "INSERT INTO balance_ledger
                 (user_id, type, amount_cents, balance_before, balance_after, order_no, ref_type, ref_id, remark, created_at)
                 VALUES (?, 'consume', ?, ?, ?, ?, 'order', ?, '余额支付', datetime('now'))"
            )->execute([$userId, -$amount, $before, $after, $orderNo, $orderNo]);

            // 订单转已支付 + 自动发货
            $advanced = self::markPaidAndDeliver($pdo, (int)$o['id'], $orderNo, $amount, 'balance', '', 'user:' . $userId);
            if (!$advanced) {
                // 并发下已被别的请求推进（例如同时点了在线支付回调），回滚本次扣款
                throw new RuntimeException('订单状态已变更，请刷新后查看');
            }

            $fresh = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
            $fresh->execute([(int)$o['id']]);

            return [
                'order_no' => $orderNo,
                'status'   => (string)$fresh->fetchColumn(),
                'paid'     => $amount,
                'balance'  => $after,
                'channel'  => 'balance',
            ];
        });
    }

    /**
     * 免费订单（¥0.00）直接支付成功路径
     *
     * 场景：商品标价 0.00 元时（如赠送的欲蓝财务魔方账号），不跳转易支付、不走余额扣款，
     * 用户点击「立即购买 / 去支付」即视为支付成功。绕过 buildOrderPayment / payWithBalance
     * 里 `amount <= 0` 的拦截，直接走唯一的「支付成功」入口完成发货与建号交付。
     *
     * 安全：仅对 total_cents == 0 的 pending 订单生效；归属校验 + 状态校验同在线支付。
     */
    public static function payFreeOrder(int $userId, string $orderNo): array
    {
        return Db::tx(function (PDO $pdo) use ($userId, $orderNo): array {
            $stmt = $pdo->prepare(
                'SELECT id, order_no, status, total_cents, created_at FROM orders
                 WHERE order_no = :no AND user_id = :uid LIMIT 1'
            );
            $stmt->execute([':no' => $orderNo, ':uid' => $userId]);
            $o = $stmt->fetch();
            if (!$o) {
                Response::fail('not_found', '订单不存在', 404);
            }
            $status = (string)$o['status'];
            if (in_array($status, [OrderStateMachine::PAID, OrderStateMachine::SHIPPED, OrderStateMachine::DONE], true)) {
                return ['order_no' => $orderNo, 'status' => $status, 'paid' => 0, 'channel' => 'free'];
            }
            if ($status === OrderStateMachine::CANCELLED) {
                Response::fail('order_cancelled', '该订单已关闭，无法支付', 409);
            }
            if ($status !== OrderStateMachine::PENDING) {
                Response::fail('order_not_payable', '当前订单状态不支持支付', 409);
            }
            if (self::isExpired($o)) {
                self::closeExpired((int)$o['id'], '免费订单发起支付时已超时');
                Response::fail('order_expired', '订单已超时关闭，请重新下单', 409);
            }
            $amount = (int)$o['total_cents'];
            if ($amount !== 0) {
                Response::fail('not_free_order', '该订单金额不为 0，请使用在线支付或余额支付', 409);
            }

            $advanced = self::markPaidAndDeliver($pdo, (int)$o['id'], $orderNo, 0, 'free', '', 'user:' . $userId);
            if (!$advanced) {
                throw new RuntimeException('订单状态已变更，请刷新后查看');
            }
            $fresh = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
            $fresh->execute([(int)$o['id']]);

            return [
                'order_no' => $orderNo,
                'status'   => (string)$fresh->fetchColumn(),
                'paid'     => 0,
                'channel'  => 'free',
            ];
        });
    }

    /**
     * 唯一的「支付成功」状态推进入口：pending → paid，并触发自动发货
     *
     * 幂等：状态机条件更新 WHERE status = 'pending'，并发下只有一个能成功。
     * 返回 true 表示本次真的推进了状态（调用方据此决定是否记日志）。
     */
    public static function markPaidAndDeliver(
        PDO $pdo,
        int $orderId,
        string $orderNo,
        int $paidCents,
        string $channel,
        string $tradeNo,
        string $actor = 'gateway'
    ): bool {
        $ok = OrderStateMachine::transition($pdo, $orderId, OrderStateMachine::PAID, [
            'from'   => OrderStateMachine::PENDING,
            'actor'  => $actor,
            'reason' => '支付成功（' . $channel . '）',
            'fields' => [
                'paid_at'      => gmdate('Y-m-d H:i:s'),
                'paid_cents'   => $paidCents,
                'pay_channel'  => mb_substr($channel, 0, 20, 'UTF-8'),
                'pay_trade_no' => mb_substr($tradeNo, 0, 64, 'UTF-8'),
            ],
        ]);
        if (!$ok) {
            return false;
        }

        // 自动发货：虚拟商品写发货内容；实体商品登记待发货
        DeliveryService::deliverVirtualItems($pdo, $orderId, $orderNo);

        // 虚拟商品全部发货完成 → 已支付 → 已发货（用户即可在「我的订单」看到发货内容）
        // 实体商品保持「已支付（待发货）」，等管理员填运单号
        if (DeliveryService::orderIsAllVirtual($pdo, $orderId)) {
            OrderStateMachine::transition($pdo, $orderId, OrderStateMachine::SHIPPED, [
                'from'   => OrderStateMachine::PAID,
                'actor'  => 'system',
                'reason' => '虚拟商品自动发货完成',
            ]);
        }

        return true;
    }

    // ===============================================================
    // 充值单
    // ===============================================================

    /** 发起充值：建充值单 + 返回网关表单 */
    public static function startRecharge(int $userId, int $amountCents, string $channel): array
    {
        $c   = self::config();
        $cfg = require dirname(__DIR__) . '/config/config.php';
        self::assertReady($c);
        self::assertChannel($c, $channel);

        $min = (int)$cfg['user']['recharge_min_cents'];
        $max = (int)$cfg['user']['recharge_max_cents'];
        if ($amountCents < $min || $amountCents > $max) {
            Response::fail(
                'invalid_amount',
                '充值金额需在 ' . number_format($min / 100, 2) . ' ~ ' . number_format($max / 100, 2) . ' 元之间',
                400
            );
        }

        $pdo = Db::conn();
        $rechargeNo = 'RC' . date('Ymd') . strtoupper(bin2hex(random_bytes(4)));
        $outTradeNo = $rechargeNo . '-' . strtoupper(bin2hex(random_bytes(3)));

        $pdo->prepare(
            "INSERT INTO recharge_orders (recharge_no, user_id, amount_cents, status, channel, out_trade_no)
             VALUES (?, ?, ?, 'pending', ?, ?)"
        )->execute([$rechargeNo, $userId, $amountCents, $channel, $outTradeNo]);

        $form = EPay::buildSubmitForm(
            (string)EPay::sanitizeGateway($c['gateway']),
            (string)$c['pid'],
            (string)$c['key'],
            (string)$c['sign_type'],
            $channel,
            $outTradeNo,
            self::notifyUrl($c),
            self::baseUrl() . '/console/#/balance',
            '账户充值 ' . $rechargeNo,
            $amountCents,
            EPay::detectDevice((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))
        );

        return $form + [
            'recharge_no' => $rechargeNo,
            'amount'      => $amountCents,
            'channel'     => $channel,
        ];
    }

    /** 充值单列表（用户中心） */
    public static function listRecharges(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $stmt = Db::conn()->prepare(
            'SELECT recharge_no, amount_cents, status, channel, paid_at, created_at
             FROM recharge_orders WHERE user_id = :uid ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare('SELECT COUNT(*) FROM recharge_orders WHERE user_id = ?');
        $cnt->execute([$userId]);

        return [
            'items' => array_map(static fn($r) => [
                'recharge_no'  => (string)$r['recharge_no'],
                'amount'       => (int)$r['amount_cents'],
                'status'       => (string)$r['status'],
                'status_label' => ['pending' => '待支付', 'paid' => '已到账', 'cancelled' => '已取消'][(string)$r['status']] ?? (string)$r['status'],
                'channel'      => (string)$r['channel'],
                'paid_at'      => $r['paid_at'],
                'created_at'   => (string)$r['created_at'],
            ], $stmt->fetchAll()),
            'total' => (int)$cnt->fetchColumn(),
        ];
    }

    // ===============================================================
    // 回调
    // ===============================================================

    /**
     * 处理异步通知（notify）
     *
     * 易支付 V1 的 notify 是 GET 请求；部分站点也会用 POST，
     * 因此这里接受任意来源的参数字组，由路由层把 $_GET / $_POST 合并后传入。
     *
     * @return array{ok:bool, message:string}
     */
    public static function handleNotify(array $params, string $ipHash): array
    {
        $c = self::config();
        if (!$c['ready']) {
            return ['ok' => false, 'message' => 'payment not configured'];
        }

        // 1) 必填字段 + 验签 + 状态 + 通道 + 金额格式
        $v = EPay::verifyNotify($params, (string)$c['key'], (string)$c['sign_type']);
        if (!$v['ok']) {
            self::logNotify(['result' => $v['reason']], $params, $ipHash);
            // 非成功状态（如 WAIT_BUYER_PAY）按「已收到、无需处理」返回 success，避免网关无限重试
            if ($v['reason'] === 'not_success') {
                return ['ok' => true, 'message' => 'ignored'];
            }
            return ['ok' => false, 'message' => $v['reason']];
        }

        // 2) 商户号必须是本站
        if (!hash_equals((string)$c['pid'], (string)$params['pid'])) {
            self::logNotify(['result' => 'bad_pid'], $params, $ipHash);
            return ['ok' => false, 'message' => 'pid error'];
        }

        $outTradeNo = (string)$params['out_trade_no'];
        if (!preg_match('/^[A-Z0-9]{8,40}-[A-F0-9]{6}$/', $outTradeNo)) {
            self::logNotify(['result' => 'bad_out_trade_no'], $params, $ipHash);
            return ['ok' => false, 'message' => 'out_trade_no error'];
        }

        $paidCents = EPay::toCents($params['money']);
        if ($paidCents === null) {
            self::logNotify(['result' => 'bad_money'], $params, $ipHash);
            return ['ok' => false, 'message' => 'money error'];
        }

        // 3) 按前缀分流：RC = 充值单，其余 = 商品订单
        if (str_starts_with($outTradeNo, 'RC')) {
            return self::handleRechargeNotify($params, $outTradeNo, $paidCents, $ipHash);
        }
        return self::handleOrderNotify($params, $outTradeNo, $paidCents, $ipHash);
    }

    /** 商品订单回调 */
    private static function handleOrderNotify(array $params, string $outTradeNo, int $paidCents, string $ipHash): array
    {
        $c = self::config();
        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            'SELECT pm.id AS pay_id, pm.order_id, pm.amount_cents AS pay_amount, pm.status AS pay_status,
                    pm.channel AS pay_channel, o.order_no, o.status AS order_status,
                    o.total_cents, o.paid_cents
             FROM payments pm JOIN orders o ON o.id = pm.order_id
             WHERE pm.out_trade_no = :otn LIMIT 1'
        );
        $stmt->execute([':otn' => $outTradeNo]);
        $row = $stmt->fetch();

        // 未知订单号：拒绝（可能是伪造或串站回调）
        if (!$row) {
            self::logNotify(['result' => 'unknown_out_trade_no', 'out_trade_no' => $outTradeNo], $params, $ipHash);
            return ['ok' => false, 'message' => 'unknown out_trade_no'];
        }

        // 通道必须与发起时一致
        $notifyChannel = (string)$params['type'];
        if ((string)$row['pay_channel'] !== '' && $notifyChannel !== (string)$row['pay_channel']) {
            self::logNotify([
                'result' => 'channel_mismatch',
                'expect' => $row['pay_channel'],
                'got'    => $notifyChannel,
            ], $params, $ipHash);
            return ['ok' => false, 'message' => 'channel mismatch'];
        }

        // 金额校验：必须与订单应付金额完全一致（容差默认 0）
        $expect = (int)$row['total_cents'];
        $tolerance = max(0, (int)$c['amount_tolerance_cents']);
        if (abs($paidCents - $expect) > $tolerance) {
            self::logNotify([
                'result' => 'amount_mismatch',
                'expect' => $expect,
                'paid'   => $paidCents,
            ], $params, $ipHash);
            return ['ok' => false, 'message' => 'amount mismatch'];
        }

        // 已支付：直接回 success，不重复写库、不重复发货
        if (in_array((string)$row['order_status'], [
            OrderStateMachine::PAID, OrderStateMachine::SHIPPED, OrderStateMachine::DONE,
        ], true)) {
            self::logNotify(['result' => 'duplicate_ok', 'order_no' => $row['order_no']], $params, $ipHash);
            return ['ok' => true, 'message' => 'already paid'];
        }

        // 已取消订单：拒绝推进，但记录「已付款却已取消」以便人工退款
        if ((string)$row['order_status'] === OrderStateMachine::CANCELLED) {
            self::recordPaymentSuccess($pdo, (int)$row['pay_id'], (string)$params['trade_no'], $params);
            self::logNotify([
                'result'   => 'paid_but_cancelled',
                'order_no' => $row['order_no'],
                'paid'     => $paidCents,
            ], $params, $ipHash);
            // 返回 success 让网关停止重试；订单保持 cancelled，由后台人工退款
            return ['ok' => true, 'message' => 'order cancelled, manual review'];
        }

        $tradeNo = mb_substr((string)$params['trade_no'], 0, 64, 'UTF-8');

        try {
            Db::tx(function (PDO $pdo) use ($row, $paidCents, $tradeNo, $params, $notifyChannel): void {
                $advanced = self::markPaidAndDeliver(
                    $pdo,
                    (int)$row['order_id'],
                    (string)$row['order_no'],
                    $paidCents,
                    $notifyChannel,
                    $tradeNo,
                    'gateway'
                );

                self::recordPaymentSuccess($pdo, (int)$row['pay_id'], $tradeNo, $params);

                if (!$advanced) {
                    error_log('[shop][notify] order not advanced (concurrent): ' . $row['order_no']);
                }
            });
        } catch (Throwable $e) {
            error_log('[shop][notify] ' . get_class($e) . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'internal error'];
        }

        self::logNotify(['result' => 'paid', 'order_no' => $row['order_no'], 'paid' => $paidCents], $params, $ipHash);
        return ['ok' => true, 'message' => 'success'];
    }

    /** 充值单回调 */
    private static function handleRechargeNotify(array $params, string $outTradeNo, int $paidCents, string $ipHash): array
    {
        $c = self::config();
        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            'SELECT id, recharge_no, user_id, amount_cents, status, channel
             FROM recharge_orders WHERE out_trade_no = :otn LIMIT 1'
        );
        $stmt->execute([':otn' => $outTradeNo]);
        $r = $stmt->fetch();
        if (!$r) {
            self::logNotify(['result' => 'unknown_recharge', 'out_trade_no' => $outTradeNo], $params, $ipHash);
            return ['ok' => false, 'message' => 'unknown recharge'];
        }

        $notifyChannel = (string)$params['type'];
        if ((string)$r['channel'] !== '' && $notifyChannel !== (string)$r['channel']) {
            self::logNotify(['result' => 'recharge_channel_mismatch'], $params, $ipHash);
            return ['ok' => false, 'message' => 'channel mismatch'];
        }

        $expect = (int)$r['amount_cents'];
        $tolerance = max(0, (int)$c['amount_tolerance_cents']);
        if (abs($paidCents - $expect) > $tolerance) {
            self::logNotify([
                'result' => 'recharge_amount_mismatch',
                'expect' => $expect,
                'paid'   => $paidCents,
            ], $params, $ipHash);
            return ['ok' => false, 'message' => 'amount mismatch'];
        }

        if ((string)$r['status'] === 'paid') {
            return ['ok' => true, 'message' => 'already paid'];
        }

        try {
            Db::tx(function (PDO $pdo) use ($r, $paidCents, $params): void {
                // 条件更新：只有 pending 才能转 paid（并发回调只有一个成功）
                $upd = $pdo->prepare(
                    "UPDATE recharge_orders
                     SET status = 'paid', paid_at = datetime('now'), trade_no = :tn, updated_at = datetime('now')
                     WHERE id = :id AND status = 'pending'"
                );
                $upd->execute([
                    ':tn' => mb_substr((string)$params['trade_no'], 0, 64, 'UTF-8'),
                    ':id' => (int)$r['id'],
                ]);
                if ($upd->rowCount() !== 1) {
                    return;
                }

                // 入账（幂等：ref_type=recharge + ref_id=充值单号）
                BalanceService::credit(
                    (int)$r['user_id'],
                    $paidCents,
                    'recharge',
                    'recharge',
                    (string)$r['recharge_no'],
                    '',
                    '在线充值',
                    ''
                );
            });
        } catch (Throwable $e) {
            error_log('[shop][notify][recharge] ' . get_class($e) . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'internal error'];
        }

        self::logNotify([
            'result'      => 'recharge_paid',
            'recharge_no' => $r['recharge_no'],
            'paid'        => $paidCents,
        ], $params, $ipHash);
        return ['ok' => true, 'message' => 'success'];
    }

    /**
     * 同步返回（return）：只做「解析订单号 + 跳转」，绝不改状态
     * 订单是否已支付只以 notify 为准，否则用户手工构造 URL 就能把订单标成已支付。
     */
    public static function handleReturn(array $query): ?string
    {
        $outTradeNo = (string)($query['out_trade_no'] ?? '');
        if (!preg_match('/^[A-Z0-9]{8,40}-[A-F0-9]{6}$/', $outTradeNo)) {
            return null;
        }
        $orderNo = substr($outTradeNo, 0, (int)strrpos($outTradeNo, '-'));
        return preg_match('/^[A-Z0-9]{8,32}$/', $orderNo) ? $orderNo : null;
    }

    /** 订单是否已超过支付时限 */
    public static function isExpired(array $order): bool
    {
        $c = self::config();
        $minutes = (int)$c['expire_minutes'];
        if ($minutes <= 0) {
            return false;
        }
        $created = strtotime((string)$order['created_at'] . ' UTC');
        if ($created === false) {
            return false;
        }
        return (time() - $created) > $minutes * 60;
    }

    /** 关闭超时未支付订单并归还库存（幂等：只对 pending 生效） */
    public static function closeExpired(int $orderId, string $reason): bool
    {
        return Db::tx(function (PDO $pdo) use ($orderId, $reason): bool {
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = :id AND status = 'pending'");
            $stmt->execute([':id' => $orderId]);
            if ($stmt->fetchColumn() === false) {
                return false;
            }

            // 先归还库存，再改状态（顺序保证不会「已取消但库存没回」）
            $items = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id = :id');
            $items->execute([':id' => $orderId]);
            $restore = $pdo->prepare(
                "UPDATE products SET stock = stock + :q, sold = MAX(0, sold - :q2), updated_at = datetime('now')
                 WHERE id = :pid"
            );
            foreach ($items->fetchAll() as $it) {
                $restore->execute([
                    ':q'   => (int)$it['qty'],
                    ':q2'  => (int)$it['qty'],
                    ':pid' => (string)$it['product_id'],
                ]);
            }

            $ok = OrderStateMachine::transition($pdo, $orderId, OrderStateMachine::CANCELLED, [
                'from'   => OrderStateMachine::PENDING,
                'actor'  => 'system',
                'reason' => $reason,
                'fields' => ['admin_note' => mb_substr($reason, 0, 200, 'UTF-8')],
            ]);
            if (!$ok) {
                // 状态已被别人推进（例如刚好支付成功），回滚库存归还
                throw new RuntimeException('order state changed');
            }

            $pdo->prepare("UPDATE payments SET status = 'closed' WHERE order_id = :id AND status = 'created'")
                ->execute([':id' => $orderId]);

            return true;
        });
    }

    /** 批量关单 */
    public static function closeAllExpired(int $limit = 200): int
    {
        $c = self::config();
        $minutes = (int)$c['expire_minutes'];
        if ($minutes <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        $stmt = Db::conn()->prepare(
            "SELECT id FROM orders WHERE status = 'pending' AND created_at < :cut ORDER BY id LIMIT :lim"
        );
        $stmt->bindValue(':cut', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $n = 0;
        foreach ($stmt->fetchAll() as $r) {
            try {
                if (self::closeExpired((int)$r['id'], '超时未支付，自动关单')) {
                    $n++;
                }
            } catch (Throwable $e) {
                // 并发下状态已变，跳过
                continue;
            }
        }
        return $n;
    }

    /**
     * 记录支付流水为成功
     *
     * 容错要点：同一网关交易号可能已经落在另一条流水上（用户对同一订单
     * 多次发起支付、或网关重放旧通知），此时唯一索引会冲突。
     * 流水标记失败不应影响「订单已支付」这一事实，因此降级为只记状态+原始通知。
     */
    private static function recordPaymentSuccess(PDO $pdo, int $payId, string $tradeNo, array $params): void
    {
        $tradeNo = mb_substr($tradeNo, 0, 64, 'UTF-8');
        $raw = self::truncateRaw($params);

        try {
            $stmt = $pdo->prepare(
                "UPDATE payments SET status = 'success', trade_no = :tn,
                     raw_notify = :raw, paid_at = datetime('now')
                 WHERE id = :id AND status <> 'success'"
            );
            $stmt->execute([':tn' => $tradeNo, ':raw' => $raw, ':id' => $payId]);
            return;
        } catch (PDOException $e) {
            error_log('[shop][notify] payments.trade_no conflict, fallback: ' . $e->getMessage());
        }

        // 降级：不写 trade_no（避免唯一冲突），但仍标记成功并保留原始通知
        try {
            $pdo->prepare(
                "UPDATE payments SET status = 'success', raw_notify = :raw, paid_at = datetime('now')
                 WHERE id = :id AND status <> 'success'"
            )->execute([':raw' => $raw . ($tradeNo !== '' ? ' [trade_no=' . $tradeNo . ']' : ''), ':id' => $payId]);
        } catch (Throwable $e) {
            error_log('[shop][notify] payments fallback update failed: ' . $e->getMessage());
        }
    }

    /** 原始通知只留前 2KB */
    private static function truncateRaw(array $params): string
    {
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }
        return mb_substr($json, 0, 2000, 'UTF-8');
    }

    /** 回调审计：只写日志文件，不写库（避免被刷爆） */
    private static function logNotify(array $meta, array $params, string $ipHash): void
    {
        $safe = [
            'meta'         => $meta,
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'trade_no'     => (string)($params['trade_no'] ?? ''),
            'trade_status' => (string)($params['trade_status'] ?? ''),
            'money'        => (string)($params['money'] ?? ''),
            'type'         => (string)($params['type'] ?? ''),
            'ip_hash'      => substr($ipHash, 0, 16),
        ];
        error_log('[shop][notify] ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
