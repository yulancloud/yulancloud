<?php
/**
 * 订单服务（v5：账号体系）
 *
 * 与 v4 的差异：
 *  1. 必须登录才能下单：create() 的第一个参数是当前登录用户（由路由层 UserAuth::require() 取得）
 *  2. 订单写入 user_id，订单归属明确
 *  3. 查询订单只按 user_id 过滤，不再依赖「订单号 + token」；
 *     订单号 + token 的匿名查询保留为兼容通道（老链接仍可用），但同样校验 token
 *  4. 支付成功后自动发货（虚拟商品），实体商品进入待发货
 *
 * 安全与正确性要点（沿用 v4 并加强）：
 *  - 价格、运费、折扣、库存全部由服务端根据数据库计算，绝不接受前端价格
 *  - 库存扣减在单个事务内用条件 UPDATE ... WHERE stock >= ? 完成，避免超卖
 *  - 幂等键唯一索引，重复提交返回同一订单
 *  - 订单查询强制 user_id 匹配，杜绝水平越权（改订单号也看不到别人的单）
 *  - 手机号等个人信息不写日志；IP 仅存哈希
 */
declare(strict_types=1);

final class OrderService
{
    /**
     * 创建订单（必须登录）
     *
     * @param array       $user           当前登录用户（含 id）
     * @param array       $in             请求体
     * @param string|null $idempotencyKey
     */
    /**
     * 金额预估（只读：不写库、不扣库存、不需要登录）。
     *
     * 为什么需要：购物袋此前靠 window.SHOP.products 里的 type 自行判断「是否全虚拟」，
     * 而商品列表可能被浏览器缓存、分页或静态兜底数据污染，一旦拿不到 type 就会把
     * 虚拟商品当成实体收运费。改由服务端按数据库真实 type 计算，前端只展示结果，
     * 与下单 create() 使用同一套规则来源，前后端金额永远一致。
     */
    public static function quote(array $in): array
    {
        if (!class_exists('SettingsService', false)) {
            require_once dirname(__DIR__) . '/src/SettingsService.php';
        }
        $cfg  = require dirname(__DIR__) . '/config/config.php';
        $rule = array_merge($cfg['rules'], SettingsService::shippingRule());

        $items = $in['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            Response::fail('empty_cart', '购物袋是空的', 400);
        }
        if (count($items) > $rule['max_lines_per_order']) {
            Response::fail('too_many_lines', '单次下单商品种类过多', 400);
        }

        $pdo = Db::conn();
        $subtotal = 0;
        $allVirtual = true;
        $lines = [];
        foreach ($items as $raw) {
            if (!is_array($raw)) {
                Response::fail('invalid_item', '商品数据格式不正确', 400);
            }
            $pid  = Validator::productId($raw['id'] ?? null);
            $qty  = Validator::int($raw['qty'] ?? null, 1, $rule['max_qty_per_line'], 1);
            $size = Validator::text($raw['size'] ?? '', 0, 30, '规格');

            $stmt = $pdo->prepare(
                "SELECT id, name, price_cents, type, stock FROM products
                 WHERE id = :id AND status = 'active'"
            );
            $stmt->execute([':id' => $pid]);
            $p = $stmt->fetch();
            if (!$p) {
                Response::fail('product_unavailable', '部分商品已下架，请刷新后重试', 409);
            }

            // 规格加价与 create() 完全一致：只认数据库，无规格商品忽略「—」占位值
            $extra = 0;
            if ($size !== '') {
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM product_sizes WHERE product_id = :id');
                $cnt->execute([':id' => $pid]);
                if ((int)$cnt->fetchColumn() === 0) {
                    $size = '';
                } else {
                    $st = $pdo->prepare('SELECT extra_cents FROM product_sizes WHERE product_id = :id AND name = :n');
                    $st->execute([':id' => $pid, ':n' => $size]);
                    $e = $st->fetchColumn();
                    if ($e === false) {
                        Response::fail('invalid_size', '所选规格不存在，请重新选择', 400);
                    }
                    $extra = (int)$e;
                }
            }

            $type = (string)($p['type'] ?? 'physical');
            if ($type === 'physical') {
                $allVirtual = false;
            }
            $unit = (int)$p['price_cents'] + $extra;
            $line = $unit * $qty;
            $subtotal += $line;
            $lines[] = [
                'id' => $pid, 'qty' => $qty, 'type' => $type,
                'unit_cents' => $unit, 'line_cents' => $line,
            ];
        }

        $ship = $allVirtual
            ? 0
            : ($subtotal >= $rule['free_ship_threshold_cents'] ? 0 : $rule['ship_fee_cents']);
        $discount = $subtotal >= $rule['discount_threshold_cents']
            ? (int)round($subtotal * $rule['discount_rate'])
            : 0;

        return [
            'subtotal'    => $subtotal,
            'ship'        => $ship,
            'discount'    => $discount,
            'total'       => $subtotal + $ship - $discount,
            'all_virtual' => $allVirtual,
            'items'       => $lines,
            'rule'        => [
                'ship_fee_cents'            => $rule['ship_fee_cents'],
                'free_ship_threshold_cents' => $rule['free_ship_threshold_cents'],
            ],
        ];
    }

    public static function create(array $user, array $in, ?string $idempotencyKey = null): array
    {
        // 运费规则以后台「运费设置」为准（数据库值覆盖 config.php 默认值）。
        // 服务端始终自己重算运费，绝不采信前端传来的金额。
        if (!class_exists('SettingsService', false)) {
            require_once dirname(__DIR__) . '/src/SettingsService.php';
        }
        $cfg  = require dirname(__DIR__) . '/config/config.php';
        $rule = array_merge($cfg['rules'], SettingsService::shippingRule());
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            Response::fail('unauthorized', '请先登录后再下单', 401);
        }

        $name    = Validator::personName($in['name'] ?? null);
        $phone   = Validator::phone($in['phone'] ?? null);
        $address = Validator::address($in['address'] ?? null);
        $note    = Validator::note($in['note'] ?? null);

        $items = $in['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            Response::fail('empty_cart', '购物袋是空的', 400);
        }
        if (count($items) > $rule['max_lines_per_order']) {
            Response::fail('too_many_lines', '单次下单商品种类过多', 400);
        }

        // 幂等短路：同一用户 + 同一 key 直接返回已有订单
        if ($idempotencyKey !== null) {
            $existing = self::findByIdempotency($userId, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }
        // 落库时给用户前缀：idempotency_key 是全局 UNIQUE，
        // 不加前缀会让不同用户复用同一个 key 时撞唯一约束、下单直接 500。
        $storeKey = $idempotencyKey === null ? null : 'u' . $userId . '-' . $idempotencyKey;

        return Db::tx(function (PDO $pdo) use ($items, $name, $phone, $address, $note, $rule, $storeKey, $userId) {
        $subtotal = 0;
        $lines = [];
        $yulanRaw = [];   // 欲蓝商品下单时提交的账号/密码，待建号使用（密码仅临时持有，不落盘）

        foreach ($items as $raw) {
                if (!is_array($raw)) {
                    Response::fail('invalid_item', '商品数据格式不正确', 400);
                }
                $pid   = Validator::productId($raw['id'] ?? null);
                $qty   = Validator::int($raw['qty'] ?? null, 1, $rule['max_qty_per_line'], 1);
                $color = Validator::text($raw['color'] ?? '', 0, 30, '颜色');
                $size  = Validator::text($raw['size'] ?? '', 0, 30, '规格');

                $stmt = $pdo->prepare(
                    "SELECT id, sku, name, price_cents, stock, type FROM products
                     WHERE id = :id AND status = 'active'"
                );
                $stmt->execute([':id' => $pid]);
                $p = $stmt->fetch();
                if (!$p) {
                    Response::fail('product_unavailable', '部分商品已下架，请刷新后重试', 409);
                }

                // 规格加价必须从数据库查，不能相信前端传来的 extra。
                // 商品若压根没配置规格/颜色（虚拟商品很常见），详情页会用占位符「—」
                // 展示，旧购物车里也会把这个值带过来；此时应忽略该字段而不是拿去查表
                // 报「所选规格不存在」。有配置时仍严格校验，规格加价绝不被篡改。
                $extra = 0;
                if ($size !== '') {
                    $cnt = $pdo->prepare('SELECT COUNT(*) FROM product_sizes WHERE product_id = :id');
                    $cnt->execute([':id' => $pid]);
                    if ((int)$cnt->fetchColumn() === 0) {
                        $size = '';                 // 无规格商品：忽略占位值，不加价
                    } else {
                        $st = $pdo->prepare('SELECT extra_cents FROM product_sizes WHERE product_id = :id AND name = :n');
                        $st->execute([':id' => $pid, ':n' => $size]);
                        $e = $st->fetchColumn();
                        if ($e === false) {
                            Response::fail('invalid_size', '所选规格不存在，请重新选择', 400);
                        }
                        $extra = (int)$e;
                    }
                }
                if ($color !== '') {
                    $cnt = $pdo->prepare('SELECT COUNT(*) FROM product_colors WHERE product_id = :id');
                    $cnt->execute([':id' => $pid]);
                    if ((int)$cnt->fetchColumn() === 0) {
                        $color = '';                // 无颜色商品：同上
                    } else {
                        $st = $pdo->prepare('SELECT 1 FROM product_colors WHERE product_id = :id AND name = :n');
                        $st->execute([':id' => $pid, ':n' => $color]);
                        if ($st->fetchColumn() === false) {
                            Response::fail('invalid_color', '所选颜色不存在，请重新选择', 400);
                        }
                    }
                }

                // 条件扣减：只有库存足够才更新成功，彻底堵死并发超卖
                $upd = $pdo->prepare(
                    "UPDATE products SET stock = stock - :q, sold = sold + :q2
                     WHERE id = :id AND status = 'active' AND stock >= :q3"
                );
                $upd->execute([':q' => $qty, ':q2' => $qty, ':q3' => $qty, ':id' => $pid]);
                if ($upd->rowCount() !== 1) {
                    Response::fail('out_of_stock', '「' . $p['name'] . '」库存不足', 409);
                }

                $unit = (int)$p['price_cents'] + $extra;
                $lineTotal = $unit * $qty;
                $subtotal += $lineTotal;

                $lines[] = [
                    'product_id' => $p['id'],
                    'sku'        => $p['sku'],
                    'name'       => $p['name'],
                    'color'      => $color,
                    'size'       => $size,
                    'unit_cents' => $unit,
                    'qty'        => $qty,
                    'line_cents' => $lineTotal,
                    'type'       => (string)($p['type'] ?? 'physical'),
                ];
                // 欲蓝商品：下单时必须提供账号与密码（首页/购物车会收集），由网站向 Windows APP 建号
                if ((string)($p['type'] ?? 'physical') === 'yulan') {
                    if (!isset($raw['account']) || !isset($raw['password'])
                        || !is_string($raw['account']) || !is_string($raw['password'])) {
                        Response::fail('yulan_missing_cred', '欲蓝商品需填写账号与密码', 400);
                    }
                    $yulanRaw[] = [
                        'product_id' => $p['id'],
                        'account'    => $raw['account'],
                        'password'   => $raw['password'],
                        'qty'        => $qty,
                        'line_idx'   => count($lines) - 1,
                    ];
                }
            }

            // 全虚拟 / 欲蓝商品无需物流，不收运费
            $allVirtual = true;
            foreach ($lines as $l) {
                if ($l['type'] === 'physical') { $allVirtual = false; break; }
            }
            // 服务端计算运费与折扣
            $ship = $allVirtual
                ? 0
                : ($subtotal >= $rule['free_ship_threshold_cents'] ? 0 : $rule['ship_fee_cents']);
            $discount = $subtotal >= $rule['discount_threshold_cents']
                ? (int)round($subtotal * $rule['discount_rate'])
                : 0;
            $total = $subtotal + $ship - $discount;

            $orderNo = 'SH' . date('Ymd') . strtoupper(bin2hex(random_bytes(4)));
            $token   = bin2hex(random_bytes(16));
            $tokenHash = hash('sha256', $token);

            $ins = $pdo->prepare(
                'INSERT INTO orders
                 (order_no, access_hash, user_id, buyer_name, buyer_phone, buyer_address, note,
                  subtotal_cents, ship_cents, discount_cents, total_cents, idempotency_key, ip_hash)
                 VALUES (:no, :ah, :uid, :nm, :ph, :ad, :nt, :sub, :ship, :disc, :tot, :ik, :ip)'
            );
            $ins->execute([
                ':no' => $orderNo, ':ah' => $tokenHash, ':uid' => $userId,
                ':nm' => $name, ':ph' => $phone,
                ':ad' => $address, ':nt' => $note, ':sub' => $subtotal, ':ship' => $ship,
                ':disc' => $discount, ':tot' => $total, ':ik' => $storeKey,
                ':ip' => RateLimiter::ipHash(),
            ]);
            $orderId = (int)$pdo->lastInsertId();

            $item = $pdo->prepare(
                'INSERT INTO order_items
                 (order_id, product_id, sku, name, color, size, unit_cents, qty, line_cents)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $itemIds = [];
            foreach ($lines as $l) {
                $item->execute([
                    $orderId, $l['product_id'], $l['sku'], $l['name'], $l['color'],
                    $l['size'], $l['unit_cents'], $l['qty'], $l['line_cents'],
                ]);
                $itemIds[] = (int)$pdo->lastInsertId();
            }

            // 欲蓝商品：下单即向 Windows APP 下达建号命令（账号已成功创建才算下单成功）
            if ($yulanRaw !== []) {
                $yulanLines = array_map(static function (array $yr) use ($itemIds): array {
                    return [
                        'product_id' => $yr['product_id'],
                        'account'    => $yr['account'],
                        'password'   => $yr['password'],
                        'qty'        => $yr['qty'],
                        'item_id'    => $itemIds[$yr['line_idx']],
                    ];
                }, $yulanRaw);
                YulanProvision::provisionOrder($pdo, $orderId, $orderNo, $userId, RateLimiter::ipHash(), $yulanLines);
            }

            return [
                'order_no' => $orderNo,
                'token'    => $token,          // 仅此一次返回明文，前端可保存用于匿名查单
                'status'   => 'pending',
                'subtotal' => $subtotal,
                'ship'     => $ship,
                'discount' => $discount,
                'total'    => $total,
                'all_virtual' => $allVirtual,
                'items'    => array_map(fn($l) => [
                    'id' => $l['product_id'], 'name' => $l['name'], 'sku' => $l['sku'],
                    'color' => $l['color'], 'size' => $l['size'], 'type' => $l['type'],
                    'unit' => $l['unit_cents'], 'qty' => $l['qty'], 'line' => $l['line_cents'],
                ], $lines),
                'created_at' => date('c'),
            ];
        });
    }

    /**
     * 按用户查询订单（用户中心用；强制 user_id 过滤）
     * @return array|null 不属于该用户时返回 null（不区分「不存在」与「不是你的」）
     */
    public static function findForUser(int $userId, string $orderNo): ?array
    {
        $stmt = Db::conn()->prepare(
            'SELECT * FROM orders WHERE order_no = :no AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':no' => $orderNo, ':uid' => $userId]);
        $o = $stmt->fetch();
        if (!$o) {
            return null;
        }
        return self::shapeOrder($o, true);
    }

    /** 用户订单列表（分页） */
    public static function listForUser(int $userId, int $limit = 20, int $offset = 0, string $status = ''): array
    {
        $limit  = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $where = ['o.user_id = :uid'];
        $params = [':uid' => $userId];
        if ($status !== '' && in_array($status, ['pending', 'paid', 'shipped', 'done', 'cancelled'], true)) {
            $where[] = 'o.status = :st';
            $params[':st'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = Db::conn()->prepare(
            "SELECT o.*, (SELECT COALESCE(SUM(qty),0) FROM order_items i WHERE i.order_id = o.id) AS item_qty
             FROM orders o WHERE {$whereSql} ORDER BY o.id DESC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare("SELECT COUNT(*) FROM orders o WHERE {$whereSql}");
        $cnt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $o) {
            $items[] = [
                'order_no'    => (string)$o['order_no'],
                'status'      => (string)$o['status'],
                'status_label' => self::statusLabel((string)$o['status']),
                'total'       => (int)$o['total_cents'],
                'item_qty'    => (int)$o['item_qty'],
                'pay_channel' => (string)$o['pay_channel'],
                'created_at'  => (string)$o['created_at'],
                'paid_at'     => $o['paid_at'],
                'has_delivery' => self::hasDelivery((int)$o['id']),
            ];
        }

        return ['items' => $items, 'total' => (int)$cnt->fetchColumn()];
    }

    /**
     * 匿名查询（订单号 + token，兼容 v4 老链接）
     * 仍然校验 token 哈希，且 token 有有效期
     */
    public static function lookup(string $orderNo, string $token): ?array
    {
        $cfg = require dirname(__DIR__) . '/config/config.php';
        $stmt = Db::conn()->prepare(
            'SELECT * FROM orders WHERE order_no = :no AND access_hash = :ah'
        );
        $stmt->execute([':no' => $orderNo, ':ah' => hash('sha256', $token)]);
        $o = $stmt->fetch();
        if (!$o) {
            return null;
        }

        // token 有效期
        $ttlDays = (int)$cfg['order_token_ttl_days'];
        if ($ttlDays > 0) {
            $created = strtotime((string)$o['created_at'] . ' UTC');
            if ($created !== false && (time() - $created) > $ttlDays * 86400) {
                return null;
            }
        }

        return self::shapeOrder($o, true);
    }

    /** 订单详情整形（含商品、支付、发货内容） */
    private static function shapeOrder(array $o, bool $withDelivery): array
    {
        $pdo = Db::conn();

        $stmt = $pdo->prepare(
            'SELECT product_id, name, sku, color, size, unit_cents, qty, line_cents
             FROM order_items WHERE order_id = :id ORDER BY id'
        );
        $stmt->execute([':id' => $o['id']]);
        $items = $stmt->fetchAll();

        $pay = $pdo->prepare(
            'SELECT channel, amount_cents, status, paid_at FROM payments
             WHERE order_id = :id ORDER BY id DESC LIMIT 1'
        );
        $pay->execute([':id' => $o['id']]);
        $lastPay = $pay->fetch() ?: null;

        $out = [
            'order_no'   => (string)$o['order_no'],
            'status'     => (string)$o['status'],
            'status_label' => self::statusLabel((string)$o['status']),
            'subtotal'   => (int)$o['subtotal_cents'],
            'ship'       => (int)$o['ship_cents'],
            'discount'   => (int)$o['discount_cents'],
            'total'      => (int)$o['total_cents'],
            'note'       => (string)$o['note'],
            'buyer_name' => (string)$o['buyer_name'],
            'buyer_phone' => (string)$o['buyer_phone'],
            'buyer_address' => (string)$o['buyer_address'],
            'created_at' => (string)$o['created_at'],
            'paid_at'    => $o['paid_at'],
            'pay_channel'=> (string)$o['pay_channel'],
            'shipped_at' => $o['shipped_at'],
            'ship_company' => (string)$o['ship_company'],
            'ship_no'    => (string)$o['ship_no'],
            'pay'        => $lastPay === null ? null : [
                'channel' => (string)$lastPay['channel'],
                'amount'  => (int)$lastPay['amount_cents'],
                'status'  => (string)$lastPay['status'],
                'paid_at' => $lastPay['paid_at'],
            ],
            'items'      => array_map(fn($r) => [
                'id' => (string)$r['product_id'], 'name' => (string)$r['name'], 'sku' => (string)$r['sku'],
                'color' => (string)$r['color'], 'size' => (string)$r['size'],
                'unit' => (int)$r['unit_cents'], 'qty' => (int)$r['qty'],
                'line' => (int)$r['line_cents'],
            ], $items),
        ];

        if ($withDelivery) {
            $out['deliveries'] = DeliveryService::forOrder((int)$o['id']);
        }

        return $out;
    }

    public static function statusLabel(string $s): string
    {
        return [
            'pending'   => '待付款',
            'paid'      => '已付款',
            'shipped'   => '已发货',
            'done'      => '已完成',
            'cancelled' => '已取消',
        ][$s] ?? $s;
    }

    private static function hasDelivery(int $orderId): bool
    {
        $stmt = Db::conn()->prepare(
            "SELECT 1 FROM order_deliveries WHERE order_id = ? AND status = 'delivered' LIMIT 1"
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchColumn() !== false;
    }

    private static function findByIdempotency(int $userId, string $key): ?array
    {
        // 兼容两种落库格式：v5.5 之前存原始 key，之后存 'u{uid}-{key}'。
        $stmt = Db::conn()->prepare(
            'SELECT order_no, status, subtotal_cents, ship_cents, discount_cents, total_cents
             FROM orders WHERE (idempotency_key = :k OR idempotency_key = :k2) AND user_id = :uid
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':k' => $key, ':k2' => 'u' . $userId . '-' . $key, ':uid' => $userId]);
        $o = $stmt->fetch();
        if (!$o) {
            return null;
        }
        return [
            'order_no'  => (string)$o['order_no'],
            'status'    => (string)$o['status'],
            'subtotal'  => (int)$o['subtotal_cents'],
            'ship'      => (int)$o['ship_cents'],
            'discount'  => (int)$o['discount_cents'],
            'total'     => (int)$o['total_cents'],
            'duplicate' => true,
        ];
    }
}
