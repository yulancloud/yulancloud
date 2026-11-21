<?php
/**
 * 发货服务
 *
 * 规则：
 *  - 虚拟商品（products.type = 'virtual'）：支付成功后按商品配置的发货模板
 *    生成发货内容，写入 order_deliveries，用户在我的订单里查看。
 *  - 实体商品（products.type = 'physical'）：不生成内容，订单进入「待发货」，
 *    由管理员在后台填写快递公司/运单号后置为「已发货」。
 *  - 一个订单可能同时含虚拟与实体商品：虚拟部分立即发货，实体部分等待人工。
 *
 * 模板格式（多行文本，每行「字段名：取值」）：
 *    IP：10.0.0.8
 *    账号：root
 *    密码：{random:12}
 *  取值支持占位符：
 *    {order_no}      订单号
 *    {date}          发货日期 YYYY-MM-DD
 *    {random:N}      N 位随机字符串（字母数字）
 *    {uuid}          随机 UUID v4
 *  也支持 product_delivery_fields 表里配置的结构化字段（后台可视化编辑），
 *  两者合并：结构化字段优先，模板文本作为补充。
 *
 * 安全：
 *  - 发货内容只对订单归属用户与管理员可见，接口层强制校验 user_id
 *  - 内容长度上限，避免被塞入超大文本
 *  - 幂等：同一 order_item 只生成一条 delivered 记录
 */
declare(strict_types=1);

final class DeliveryService
{
    private const MAX_CONTENT = 4000;

    /**
     * 为订单生成虚拟商品发货内容（支付成功后调用，事务内）
     *
     * @return int 生成的发货记录数
     */
    public static function deliverVirtualItems(PDO $pdo, int $orderId, string $orderNo): int
    {
        // 取订单内所有商品项 + 商品类型与模板
        $stmt = $pdo->prepare(
            "SELECT i.id AS item_id, i.product_id, i.name AS item_name, i.qty,
                    p.type AS ptype, p.delivery_template
             FROM order_items i
             LEFT JOIN products p ON p.id = i.product_id
             WHERE i.order_id = :oid ORDER BY i.id"
        );
        $stmt->execute([':oid' => $orderId]);
        $items = $stmt->fetchAll();

        $made = 0;
        foreach ($items as $it) {
            $ptype = (string)($it['ptype'] ?? 'physical');
            $kind = self::kindOf($ptype);

            // v1.4：魔方云（KVM）商品 —— 支付后调用上游创建云主机并交付连接信息
            if ($ptype === 'cloud') {
                if (self::deliverCloud($pdo, $orderId, $orderNo, $it)) {
                    $made++;
                }
                continue;
            }

            // 已存在该 item 的发货记录则跳过（幂等）
            $exists = $pdo->prepare(
                "SELECT 1 FROM order_deliveries WHERE order_id = ? AND order_item_id = ? AND status = 'delivered' LIMIT 1"
            );
            $exists->execute([$orderId, (int)$it['item_id']]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }

            if ($kind === 'physical') {
                // 实体商品：登记一条 pending 记录，等管理员发货
                $pdo->prepare(
                    "INSERT INTO order_deliveries
                     (order_id, order_no, order_item_id, product_id, product_name, kind, content, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'physical', '', 'pending', datetime('now'), datetime('now'))"
                )->execute([
                    $orderId, $orderNo, (int)$it['item_id'],
                    (string)$it['product_id'], (string)$it['item_name'],
                ]);
                continue;
            }

            // v1.4.1：欲蓝财务魔方若绑定了上游，账号/密码由上游生成，需在支付后开通（与 cloud 一致）
            if ($ptype === 'yulan') {
                $up = $pdo->prepare('SELECT upstream_id FROM products WHERE id = ?');
                $up->execute([(string)$it['product_id']]);
                $boundUpstream = (int)($up->fetchColumn() ?: 0) > 0;
                if ($boundUpstream) {
                    if (self::deliverUpstreamYulan($pdo, $orderId, $orderNo, $it)) {
                        $made++;
                    }
                    continue;
                }
            }

            // 存量兼容（未绑定上游）：交付「连接地址 + 账号」（下单时已用 Windows APP 建号）
            if ($kind === 'yulan') {
                $content = self::renderYulan($pdo, $orderId, (int)$it['item_id'], $orderNo);
                $pdo->prepare(
                    "INSERT INTO order_deliveries
                     (order_id, order_no, order_item_id, product_id, product_name, kind, content, status, delivered_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'yulan', ?, 'delivered', datetime('now'), datetime('now'), datetime('now'))"
                )->execute([
                    $orderId, $orderNo, (int)$it['item_id'],
                    (string)$it['product_id'], (string)$it['item_name'], $content,
                ]);
                $made++;
                continue;
            }

            // 虚拟商品：每个商品使用自己配置的发货内容（逐商品、互不影响）
            $content = self::renderContent($pdo, (string)$it['product_id'], (string)($it['delivery_template'] ?? ''), $orderNo);
            if ($content === '') {
                // 没配：给一个明确的占位提示，而不是空白（避免用户以为没发货）
                $content = "发货内容待补充\n请联系客服并提供订单号 {$orderNo}";
            }
            if (mb_strlen($content, 'UTF-8') > self::MAX_CONTENT) {
                $content = mb_substr($content, 0, self::MAX_CONTENT, 'UTF-8');
            }

            $pdo->prepare(
                "INSERT INTO order_deliveries
                 (order_id, order_no, order_item_id, product_id, product_name, kind, content, status, delivered_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'virtual', ?, 'delivered', datetime('now'), datetime('now'), datetime('now'))"
            )->execute([
                $orderId, $orderNo, (int)$it['item_id'],
                (string)$it['product_id'], (string)$it['item_name'], $content,
            ]);
            $made++;
        }

        return $made;
    }

    /** 商品类型 → 交付种类 */
    private static function kindOf(string $type): string
    {
        return match ($type) {
            'virtual' => 'virtual',
            'yulan'   => 'yulan',
            'cloud'   => 'cloud',
            default   => 'physical',
        };
    }

    /**
     * v1.4：支付后为魔方云商品开通云主机，落 cloud_vms + 发货内容。
     * 幂等：同一 item 已有 cloud 发货记录则跳过。
     */
    private static function deliverCloud(PDO $pdo, int $orderId, string $orderNo, array $it): bool
    {
        $itemId = (int)$it['item_id'];
        $productId = (string)$it['product_id'];

        // v1.4.1 幂等：已生成过 cloud 发货记录则跳过，防止重复支付回调重复开通云主机
        $exists = $pdo->prepare(
            "SELECT 1 FROM order_deliveries WHERE order_id = ? AND order_item_id = ? AND kind = 'cloud' LIMIT 1"
        );
        $exists->execute([$orderId, $itemId]);
        if ($exists->fetchColumn() !== false) {
            return true;
        }

        // 取商品的上游配置 / 上游产品 / 订单用户
        $p = $pdo->prepare('SELECT upstream_id, upstream_product_id FROM products WHERE id = ?');
        $p->execute([$productId]);
        $prod = $p->fetch() ?: [];
        $upstreamId = (int)($prod['upstream_id'] ?? 0);
        $upProductId = (string)($prod['upstream_product_id'] ?? '');
        $o = $pdo->prepare('SELECT user_id FROM orders WHERE id = ?');
        $o->execute([$orderId]);
        $userId = (int)($o->fetchColumn() ?: 0);

        if ($upstreamId <= 0) {
            $content = "云主机未配置上游，无法自动开通，请联系客服（订单号 {$orderNo}）";
            $ok = false;
            $inst = [];
        } else {
            if ($upProductId === '') {
                $upProductId = $productId;
            }
            $res = UpstreamService::provision($upstreamId, $upProductId, [
                'order_no' => $orderNo,
                'product_id' => $productId,
            ]);
            $ok = (bool)$res['ok'];
            $inst = $res['instance'] ?? [];
            $content = (string)$res['detail'];
            if ($ok) {
                $lines = [];
                foreach (['ip' => '连接地址', 'username' => '账号', 'os' => '系统',
                          'cpu' => 'CPU', 'mem' => '内存', 'region' => '地区'] as $k => $label) {
                    if (($inst[$k] ?? '') !== '') {
                        $lines[] = $label . '：' . $inst[$k];
                    }
                }
                if (($inst['password'] ?? '') !== '') {
                    $lines[] = '密码：' . $inst['password'] . '（仅此一份，请立即保存）';
                }
                $lines[] = "（订单号 {$orderNo}，可在用户中心「我的云主机」管理开关机）";
                $content = implode("\n", $lines);

                CloudVmService::createFromProvision(
                    $pdo, $orderId, $itemId, $productId, $userId > 0 ? $userId : null,
                    $upstreamId, (string)($inst['external_id'] ?? ''), $inst
                );
            }
        }

        $pdo->prepare(
            "INSERT INTO order_deliveries
             (order_id, order_no, order_item_id, product_id, product_name, kind, content, status, delivered_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'cloud', ?, ?, datetime('now'), datetime('now'), datetime('now'))"
        )->execute([
            $orderId, $orderNo, $itemId, $productId, (string)$it['item_name'],
            $content, $ok ? 'delivered' : 'pending',
        ]);
        return $ok;
    }

    /**
     * v1.4.1：支付后为「绑定上游的魔方财务商品」开通实例并交付连接信息。
     * 修复旧版「下单即开通、未付款就调上游且不可回滚」的问题，与 cloud 同样在支付成功后执行。
     * 幂等：同一 item 已有 yulan 发货记录则跳过。
     */
    private static function deliverUpstreamYulan(PDO $pdo, int $orderId, string $orderNo, array $it): bool
    {
        $itemId = (int)$it['item_id'];
        $productId = (string)$it['product_id'];

        // 幂等：已生成过 yulan 发货记录则跳过（防止重复支付通知重复开通）
        $exists = $pdo->prepare(
            "SELECT 1 FROM order_deliveries WHERE order_id = ? AND order_item_id = ? AND kind = 'yulan' LIMIT 1"
        );
        $exists->execute([$orderId, $itemId]);
        if ($exists->fetchColumn() !== false) {
            return true;
        }

        $p = $pdo->prepare('SELECT upstream_id, upstream_product_id FROM products WHERE id = ?');
        $p->execute([$productId]);
        $prod = $p->fetch() ?: [];
        $upstreamId = (int)($prod['upstream_id'] ?? 0);
        $upProductId = (string)($prod['upstream_product_id'] ?? '') ?: $productId;

        $ok = false;
        $inst = [];
        $detail = '未配置上游，无法自动开通';
        if (class_exists('UpstreamService') && $upstreamId > 0) {
            $res = UpstreamService::provision($upstreamId, $upProductId, [
                'order_no'   => $orderNo,
                'product_id' => $productId,
                'qty'        => (int)($it['qty'] ?? 1),
            ]);
            $ok = (bool)$res['ok'];
            $inst = $res['instance'] ?? [];
            $detail = (string)$res['detail'];
        }

        $contentLines = [];
        if ($ok) {
            foreach (['ip' => '连接地址', 'username' => '账号', 'os' => '系统',
                      'cpu' => 'CPU', 'mem' => '内存', 'region' => '地区'] as $k => $label) {
                if (($inst[$k] ?? '') !== '') {
                    $contentLines[] = $label . '：' . $inst[$k];
                }
            }
            if (($inst['password'] ?? '') !== '') {
                $contentLines[] = '密码：' . $inst['password'] . '（仅此一份，请立即保存）';
            }
            // 记一条 yulan_accounts（只存账号名，不含密码），保持限购/去重数据一致
            $account = (string)($inst['username'] ?? 'up-' . substr($orderNo, -8));
            $existsAcc = $pdo->prepare(
                "SELECT 1 FROM yulan_accounts WHERE order_id = ? AND order_item_id = ? LIMIT 1"
            );
            $existsAcc->execute([$orderId, $itemId]);
            if ($existsAcc->fetchColumn() === false) {
                $pdo->prepare(
                    "INSERT INTO yulan_accounts
                     (order_id, order_item_id, product_id, account, role, status, detail, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'user', 'created', ?, datetime('now'), datetime('now'))"
                )->execute([$orderId, $itemId, $productId, $account, $detail]);
            }
        } else {
            $contentLines[] = '上游开通未完成：' . $detail;
            $contentLines[] = '请联系客服并提供订单号 ' . $orderNo;
        }
        $content = implode("\n", $contentLines);

        $pdo->prepare(
            "INSERT INTO order_deliveries
             (order_id, order_no, order_item_id, product_id, product_name, kind, content, status, delivered_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'yulan', ?, ?, datetime('now'), datetime('now'), datetime('now'))"
        )->execute([
            $orderId, $orderNo, $itemId, $productId, (string)$it['item_name'],
            $content, $ok ? 'delivered' : 'pending',
        ]);
        return $ok;
    }

    /**
     * 欲蓝财务魔方交付内容：连接地址 + 账号（密码平台不存储）。
     * 账号创建失败则给出明确提示，引导联系客服。
     */
    private static function renderYulan(PDO $pdo, int $orderId, int $itemId, string $orderNo): string
    {
        $stmt = $pdo->prepare(
            "SELECT account, status, detail FROM yulan_accounts WHERE order_id = ? AND order_item_id = ?"
        );
        $stmt->execute([$orderId, $itemId]);
        $acc = $stmt->fetch();

        if ($acc === false || (string)$acc['status'] !== 'created') {
            return "欲蓝账号创建未完成，请联系客服并提供订单号 {$orderNo}\n状态："
                 . ((string)($acc['status'] ?? 'unknown')) . (isset($acc['detail']) ? '（' . $acc['detail'] . '）' : '');
        }

        // 连接地址：商品级配置优先（yulan_rdp_host / yulan_server_ip），回退全局设置
        $prod = $pdo->prepare(
            'SELECT yulan_rdp_host, yulan_server_ip, yulan_api_id FROM products
             WHERE id = (SELECT product_id FROM yulan_accounts WHERE order_id = ? AND order_item_id = ?)'
        );
        $prod->execute([$orderId, $itemId]);
        $host = YulanConnector::connectionAddress($prod->fetch() ?: []);
        $lines = [];
        if ($host !== '') {
            $lines[] = '连接地址：' . $host;
        }
        $lines[] = '账号：' . (string)$acc['account'];
        $lines[] = '（密码为您下单时设置的密码；平台出于安全不存储，请妥善保管）';
        return implode("\n", $lines);
    }

    /**
     * 渲染单个商品的发货内容
     * 结构化字段（product_delivery_fields）优先，模板文本补充
     */
    public static function renderContent(PDO $pdo, string $productId, string $template, string $orderNo): string
    {
        $lines = [];

        // 1) 结构化字段
        $stmt = $pdo->prepare(
            'SELECT label, value FROM product_delivery_fields WHERE product_id = ? ORDER BY sort, label'
        );
        $stmt->execute([$productId]);
        foreach ($stmt->fetchAll() as $f) {
            $label = trim((string)$f['label']);
            if ($label === '') {
                continue;
            }
            $lines[] = $label . '：' . self::expand((string)$f['value'], $orderNo);
        }

        // 2) 模板文本（每行「字段名：取值」或纯文本行）
        $template = trim($template);
        if ($template !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $template) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $lines[] = self::expand($line, $orderNo);
            }
        }

        $content = implode("\n", $lines);
        if (mb_strlen($content, 'UTF-8') > self::MAX_CONTENT) {
            $content = mb_substr($content, 0, self::MAX_CONTENT, 'UTF-8');
        }
        return $content;
    }

    /** 展开占位符 */
    public static function expand(string $text, string $orderNo): string
    {
        $text = str_replace(
            ['{order_no}', '{date}'],
            [$orderNo, date('Y-m-d')],
            $text
        );
        // {random:N}
        $text = preg_replace_callback('/\{random:(\d{1,3})\}/', static function (array $m): string {
            $n = max(1, min(64, (int)$m[1]));
            $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $out = '';
            for ($i = 0; $i < $n; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            return $out;
        }, $text) ?? $text;

        // {uuid}
        $text = str_replace('{uuid}', self::uuid4(), $text);

        return $text;
    }

    /** 订单的发货内容（用户中心 / 后台共用；调用方负责鉴权） */
    public static function forOrder(int $orderId): array
    {
        $stmt = Db::conn()->prepare(
            'SELECT id, order_item_id, product_id, product_name, kind, content, status, delivered_at, created_at
             FROM order_deliveries WHERE order_id = :oid ORDER BY id'
        );
        $stmt->execute([':oid' => $orderId]);

        return array_map(static fn($r) => [
            'id'           => (int)$r['id'],
            'product_id'   => (string)$r['product_id'],
            'product_name' => (string)$r['product_name'],
            'kind'         => (string)$r['kind'],
            'kind_label'   => match ((string)$r['kind']) {
                'virtual' => '虚拟商品',
                'yulan'   => '魔方财务（上游）',
                'cloud'   => '魔方云主机',
                default   => '实体商品',
            },
            'content'      => (string)$r['content'],
            'status'       => (string)$r['status'],
            'status_label' => [
                'pending'   => '待发货',
                'delivered' => '已发货',
                'revoked'   => '已撤回',
            ][(string)$r['status']] ?? (string)$r['status'],
            'delivered_at' => $r['delivered_at'],
            'created_at'   => (string)$r['created_at'],
        ], $stmt->fetchAll());
    }

    /** 管理员手动设置/修改某条发货内容 */
    public static function setContent(int $deliveryId, string $content, string $status = 'delivered'): array
    {
        if (!in_array($status, ['pending', 'delivered', 'revoked'], true)) {
            Response::fail('invalid_status', '发货状态不合法', 400);
        }
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? '';
        $content = trim($content);
        if (mb_strlen($content, 'UTF-8') > self::MAX_CONTENT) {
            Response::fail('content_too_long', '发货内容不能超过 ' . self::MAX_CONTENT . ' 个字符', 400);
        }

        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            "UPDATE order_deliveries
             SET content = :c, status = :s,
                 delivered_at = CASE WHEN :s2 = 'delivered' THEN COALESCE(delivered_at, datetime('now')) ELSE delivered_at END,
                 updated_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([':c' => $content, ':s' => $status, ':s2' => $status, ':id' => $deliveryId]);
        if ($stmt->rowCount() !== 1) {
            Response::fail('not_found', '发货记录不存在', 404);
        }
        return ['id' => $deliveryId, 'status' => $status];
    }

    /** 订单是否全部为虚拟商品（用于决定支付后订单状态） */
    public static function orderIsAllVirtual(PDO $pdo, int $orderId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(p.type,'physical') IN ('virtual','yulan','cloud') THEN 1 ELSE 0 END) AS virt
             FROM order_items i LEFT JOIN products p ON p.id = i.product_id
             WHERE i.order_id = ?"
        );
        $stmt->execute([$orderId]);
        $r = $stmt->fetch();
        if (!$r) {
            return false;
        }
        $total = (int)$r['total'];
        $virt  = (int)$r['virt'];
        return $total > 0 && $total === $virt;
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
