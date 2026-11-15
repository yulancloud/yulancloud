<?php
/**
 * 后台管理业务层
 *
 * 所有 SQL 均为预处理语句；所有排序/状态字段走白名单映射；
 * 所有写操作在事务内完成，并写审计日志。
 * 金额一律「分」出入，前台看到的「元」只在展示层换算。
 */
declare(strict_types=1);

final class AdminService
{
    // ===============================================================
    // 概览
    // ===============================================================
    public static function dashboard(): array
    {
        $pdo = Db::conn();

        $q = static fn (string $sql): int => (int)$pdo->query($sql)->fetchColumn();

        $today = gmdate('Y-m-d');
        $orders = [
            'total'     => $q('SELECT COUNT(*) FROM orders'),
            'pending'   => $q("SELECT COUNT(*) FROM orders WHERE status = 'pending'"),
            'paid'      => $q("SELECT COUNT(*) FROM orders WHERE status = 'paid'"),
            'shipped'   => $q("SELECT COUNT(*) FROM orders WHERE status IN ('shipped','done')"),
            'cancelled' => $q("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'"),
            'today'     => $q("SELECT COUNT(*) FROM orders WHERE date(created_at) = '" . $today . "'"),
        ];

        // 营收只统计真实收到钱的（paid_cents 落库），不拿订单总额冒充营收
        $revenue = [
            'today_cents' => $q("SELECT COALESCE(SUM(paid_cents),0) FROM orders WHERE status IN ('paid','shipped','done') AND date(paid_at) = '" . $today . "'"),
            'total_cents' => $q("SELECT COALESCE(SUM(paid_cents),0) FROM orders WHERE status IN ('paid','shipped','done')"),
        ];

        $products = [
            'total'  => $q('SELECT COUNT(*) FROM products'),
            'active' => $q("SELECT COUNT(*) FROM products WHERE status = 'active'"),
            'hidden' => $q("SELECT COUNT(*) FROM products WHERE status = 'hidden'"),
            'soldout'=> $q("SELECT COUNT(*) FROM products WHERE status = 'soldout'"),
            'low_stock' => $q("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock <= 3"),
            'out_of_stock' => $q('SELECT COUNT(*) FROM products WHERE stock = 0'),
        ];

        $reviews = [
            'total'  => $q('SELECT COUNT(*) FROM reviews'),
            'hidden' => $q("SELECT COUNT(*) FROM reviews WHERE status = 'hidden'"),
        ];

        $recentOrders = $pdo->query(
            'SELECT order_no, buyer_name, total_cents, status, pay_channel, created_at
             FROM orders ORDER BY id DESC LIMIT 8'
        )->fetchAll();

        $lowStock = $pdo->query(
            "SELECT id, name, stock FROM products WHERE status = 'active' AND stock <= 3 ORDER BY stock ASC, id LIMIT 8"
        )->fetchAll();

        // 近 7 天（含今天）订单数，用于后台极简折线
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(CASE WHEN status IN (\'paid\',\'shipped\',\'done\') THEN paid_cents ELSE 0 END),0) FROM orders WHERE date(created_at) = ?');
            $stmt->execute([$d]);
            $r = $stmt->fetch(PDO::FETCH_NUM);
            $trend[] = ['date' => $d, 'orders' => (int)$r[0], 'cents' => (int)$r[1]];
        }

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'products' => $products,
            'reviews' => $reviews,
            'recent_orders' => $recentOrders,
            'low_stock' => $lowStock,
            'trend' => $trend,
            'payment' => PaymentService::publicInfo(),
            'schema_version' => self::schemaVersion(),
        ];
    }

    private static function schemaVersion(): string
    {
        $v = Db::conn()->query("SELECT value FROM schema_meta WHERE key = 'version'")->fetchColumn();
        return $v === false ? 'unknown' : (string)$v;
    }

    // ===============================================================
    // 商品
    // ===============================================================
    private const PRODUCT_SORTS = [
        'sort'       => 'p.sort ASC, p.id ASC',
        'newest'     => 'p.id DESC',
        'price-asc'  => 'p.price_cents ASC, p.id ASC',
        'price-desc' => 'p.price_cents DESC, p.id ASC',
        'stock-asc'  => 'p.stock ASC, p.id ASC',
        'sold-desc'  => 'p.sold DESC, p.id ASC',
        'name'       => 'p.name ASC',
    ];

    public static function products(array $q): array
    {
        $where  = ['1 = 1'];
        $params = [];

        $kw = trim((string)($q['q'] ?? ''));
        if ($kw !== '') {
            $kw = mb_substr($kw, 0, 40, 'UTF-8');
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw);
            $where[] = "(p.name LIKE :kw ESCAPE '\\' OR p.sku LIKE :kw ESCAPE '\\' OR p.sub LIKE :kw ESCAPE '\\')";
            $params[':kw'] = '%' . $esc . '%';
        }

        $cat = (string)($q['cat'] ?? '');
        if ($cat !== '' && $cat !== 'all' && preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $cat)) {
            $where[] = 'p.cat_id = :cat';
            $params[':cat'] = $cat;
        }

        $status = (string)($q['status'] ?? '');
        if (in_array($status, ['active', 'hidden', 'soldout'], true)) {
            $where[] = 'p.status = :st';
            $params[':st'] = $status;
        }

        // 支持按商品类型筛选（后台「虚拟商品发货」菜单据此只列虚拟商品）
        $type = (string)($q['type'] ?? '');
        if (in_array($type, ['virtual', 'physical'], true)) {
            $where[] = 'p.type = :tp';
            $params[':tp'] = $type;
        }

        $sortKey = (string)($q['sort'] ?? 'sort');
        $order = self::PRODUCT_SORTS[$sortKey] ?? self::PRODUCT_SORTS['sort'];

        $limit  = max(1, min(100, (int)($q['limit'] ?? 30)));
        $offset = max(0, min(1000000, (int)($q['offset'] ?? 0)));

        $sql = 'SELECT p.id, p.sku, p.name, p.sub, p.cat_id, p.price_cents, p.was_cents, p.badge,
                       p.img, p.rating, p.sold, p.stock, p.status, p.type, p.sort, p.created_at, p.updated_at
                FROM products p WHERE ' . implode(' AND ', $where)
             . ' ORDER BY ' . $order . ' LIMIT :lim OFFSET :off';

        $stmt = Db::conn()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare('SELECT COUNT(*) FROM products p WHERE ' . implode(' AND ', $where));
        $cnt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => (int)$cnt->fetchColumn()];
    }

    /** 商品编辑用完整数据（含隐藏商品） */
    public static function productFull(string $id): ?array
    {
        $pdo = Db::conn();
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $p = $stmt->fetch();
        if (!$p) {
            return null;
        }

        $p['colors'] = $pdo->prepare('SELECT name, hex, sort FROM product_colors WHERE product_id = ? ORDER BY sort, name');
        $p['colors']->execute([$id]);
        $p['colors'] = $p['colors']->fetchAll();

        $p['sizes'] = $pdo->prepare('SELECT name, extra_cents, sort FROM product_sizes WHERE product_id = ? ORDER BY sort, name');
        $p['sizes']->execute([$id]);
        $p['sizes'] = $p['sizes']->fetchAll();

        $p['specs'] = $pdo->prepare('SELECT k, v, sort FROM product_specs WHERE product_id = ? ORDER BY sort, rowid');
        $p['specs']->execute([$id]);
        $p['specs'] = $p['specs']->fetchAll();

        $p['care'] = $pdo->prepare('SELECT body, sort FROM product_care WHERE product_id = ? ORDER BY sort, rowid');
        $p['care']->execute([$id]);
        $p['care'] = $p['care']->fetchAll();

        return $p;
    }

    /**
     * 新建 / 更新商品
     * 校验：ID 格式、SKU 唯一、分类存在、价格非负、划线价 >= 现价、库存非负
     */
    public static function saveProduct(array $in, bool $isNew): array
    {
        $pdo = Db::conn();

        $id = $isNew ? self::slug((string)($in['id'] ?? '')) : (string)($in['id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
            Response::fail('invalid_id', '商品编号只能用小写字母、数字和连字符（1-32 位）', 400);
        }

        $name = self::text($in['name'] ?? '', 1, 80, '商品名称');
        $sub  = self::text($in['sub'] ?? '', 0, 120, '副标题');
        $sku  = self::text($in['sku'] ?? '', 1, 40, 'SKU');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $sku)) {
            Response::fail('invalid_sku', 'SKU 只能用字母、数字、点、下划线、连字符', 400);
        }
        $catId = (string)($in['cat_id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $catId)) {
            Response::fail('invalid_category', '请选择分类', 400);
        }
        $catExists = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
        $catExists->execute([$catId]);
        if ($catExists->fetchColumn() === false) {
            Response::fail('invalid_category', '所选分类不存在', 400);
        }

        $price = self::money($in['price_cents'] ?? null, '售价', 0, 100000000);
        $wasRaw = $in['was_cents'] ?? null;
        $was = ($wasRaw === null || $wasRaw === '') ? null : self::money($wasRaw, '划线价', 0, 100000000);
        if ($was !== null && $was < $price) {
            Response::fail('invalid_was_price', '划线价不能低于售价', 400);
        }

        $badge = self::text($in['badge'] ?? '', 0, 12, '角标');
        $img   = self::text($in['img'] ?? '', 0, 300, '图片地址');
        if ($img !== '' && !self::safeImagePath($img)) {
            Response::fail('invalid_img', '图片地址只能是站内相对路径或 https 链接', 400);
        }
        $desc  = self::text($in['description'] ?? '', 0, 4000, '商品描述');
        $story = self::text($in['story'] ?? '', 0, 4000, '品牌故事');
        // v1.1：商品缺点 / 已知不足（每行一条，前台详情页逐条展示）
        $flaws = self::text($in['flaws'] ?? '', 0, 2000, '商品缺点');
        $stock = self::intVal($in['stock'] ?? 0, 0, 1000000, '库存');
        $sort  = self::intVal($in['sort'] ?? 0, -100000, 100000, '排序');
        $status = (string)($in['status'] ?? 'active');
        if (!in_array($status, ['active', 'hidden', 'soldout'], true)) {
            Response::fail('invalid_status', '商品状态不合法', 400);
        }
        // v5.5：商品表单可直接选择类型（实体 / 虚拟 / 欲蓝财务魔方）。
        // 未携带 type 时保持原值（编辑场景兼容旧客户端）；新建默认 physical。
        $type = null;
        if (array_key_exists('type', $in)) {
            $type = (string)($in['type'] ?? 'physical');
            if (!in_array($type, ['virtual', 'physical', 'yulan'], true)) {
                Response::fail('invalid_type', '商品类型只能是实体商品 / 虚拟商品 / 欲蓝财务魔方', 400);
            }
        }
        $rating = (float)($in['rating'] ?? 0);
        $rating = max(0.0, min(5.0, $rating));

        // 唯一性检查（给出明确提示，而不是抛 SQL 异常）
        $dup = $pdo->prepare('SELECT id FROM products WHERE sku = :sku AND id <> :id');
        $dup->execute([':sku' => $sku, ':id' => $id]);
        if ($dup->fetchColumn() !== false) {
            Response::fail('duplicate_sku', 'SKU 已被其他商品占用', 409);
        }
        if ($isNew) {
            $exists = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
            $exists->execute([$id]);
            if ($exists->fetchColumn() !== false) {
                Response::fail('duplicate_id', '商品编号已存在', 409);
            }
        }

        // 规格子表（颜色 / 规格 / 参数 / 保养）
        $colors = self::normalizeColors($in['colors'] ?? []);
        $sizes  = self::normalizeSizes($in['sizes'] ?? []);
        $specs  = self::normalizeSpecs($in['specs'] ?? []);
        $care   = self::normalizeCare($in['care'] ?? []);

        // 仅在请求显式携带子表字段时才整体替换，避免「只改价格」把颜色/规格/参数/保养清空。
        $hasChildren = array_key_exists('colors', $in) || array_key_exists('sizes', $in)
            || array_key_exists('specs', $in) || array_key_exists('care', $in);

        Db::tx(function (PDO $pdo) use (
            $isNew, $id, $sku, $name, $sub, $catId, $price, $was, $badge, $img,
            $rating, $stock, $desc, $story, $flaws, $status, $sort, $type, $colors, $sizes, $specs, $care, $hasChildren
        ): void {
            if ($isNew) {
                $pdo->prepare(
                    "INSERT INTO products
                     (id, sku, name, sub, cat_id, price_cents, was_cents, badge, img, rating, sold, stock,
                      description, story, type, status, sort, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))"
                )->execute([
                    $id, $sku, $name, $sub, $catId, $price, $was, $badge, $img,
                    $rating, $stock, $desc, $story, $type ?? 'physical', $status, $sort,
                ]);
            } else {
                // 更新时不覆盖 sold（销量由下单流程维护）；type 未携带时保持原值
                $pdo->prepare(
                    "UPDATE products SET sku = ?, name = ?, sub = ?, cat_id = ?, price_cents = ?,
                        was_cents = ?, badge = ?, img = ?, rating = ?, stock = ?, description = ?,
                        story = ?, status = ?, sort = ?,
                        type = COALESCE(?, type), updated_at = datetime('now')
                     WHERE id = ?"
                )->execute([
                    $sku, $name, $sub, $catId, $price, $was, $badge, $img, $rating,
                    $stock, $desc, $story, $status, $sort, $type, $id,
                ]);
                // UPDATE 影响 0 行可能是「值没变」，也可能是「商品不存在」，必须区分
                $chk = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
                $chk->execute([$id]);
                if ($chk->fetchColumn() === false) {
                    Response::fail('not_found', '商品不存在', 404);
                }
            }
            // v1.1：商品缺点 / 已知不足。用独立 UPDATE 而不是塞进上面的 INSERT/UPDATE，
            // 避免改动既有占位符序列；旧库未跑迁移时该列不存在，先探测后写入，
            // 保证「只升级源码、暂未迁移」的站点也能正常保存商品。
            $hasFlawsCol = false;
            foreach ($pdo->query('PRAGMA table_info(products)')->fetchAll() as $col) {
                if (strcasecmp((string)($col['name'] ?? ''), 'flaws') === 0) {
                    $hasFlawsCol = true;
                    break;
                }
            }
            if ($hasFlawsCol) {
                $pdo->prepare('UPDATE products SET flaws = ? WHERE id = ?')->execute([$flaws, $id]);
            }
            if ($hasChildren) {
                self::replaceChildren($id, $colors, $sizes, $specs, $care);
            }
        });

        return ['id' => $id];
    }

    /** 仅改状态（上架 / 下架 / 售罄标记） */
    public static function setProductStatus(string $id, string $status): void
    {
        if (!in_array($status, ['active', 'hidden', 'soldout'], true)) {
            Response::fail('invalid_status', '商品状态不合法', 400);
        }
        $stmt = Db::conn()->prepare(
            "UPDATE products SET status = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() !== 1) {
            Response::fail('not_found', '商品不存在', 404);
        }
    }

    /** 调库存（增加/减少，带下限保护） */
    public static function adjustStock(string $id, int $delta): array
    {
        if ($delta === 0 || abs($delta) > 100000) {
            Response::fail('invalid_delta', '库存调整值不合法', 400);
        }
        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            "UPDATE products SET stock = stock + :d, updated_at = datetime('now')
             WHERE id = :id AND stock + :d2 >= 0"
        );
        $stmt->execute([':d' => $delta, ':d2' => $delta, ':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            $exists = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
            $exists->execute([$id]);
            $cur = $exists->fetchColumn();
            if ($cur === false) {
                Response::fail('not_found', '商品不存在', 404);
            }
            Response::fail('invalid_stock', '库存不能小于 0（当前 ' . (int)$cur . '）', 409);
        }
        $s = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $s->execute([$id]);
        return ['stock' => (int)$s->fetchColumn()];
    }

    /** 删除商品：有订单引用时不允许硬删（会破坏订单快照），建议改为下架 */
    public static function deleteProduct(string $id): void
    {
        $pdo = Db::conn();
        $used = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = ?');
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            Response::fail('product_in_use', '该商品已有订单记录，不能删除。请改为「下架」以保留历史订单。', 409);
        }
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            Response::fail('not_found', '商品不存在', 404);
        }
    }

    // ===============================================================
    // 分类
    // ===============================================================
    public static function categories(): array
    {
        return Db::conn()->query(
            "SELECT c.id, c.label, c.sort, c.status,
                    (SELECT COUNT(*) FROM products p WHERE p.cat_id = c.id) AS product_count,
                    (SELECT COUNT(*) FROM products p WHERE p.cat_id = c.id AND p.status = 'active') AS active_count
             FROM categories c ORDER BY c.sort, c.id"
        )->fetchAll();
    }

    public static function saveCategory(array $in, bool $isNew): array
    {
        $pdo = Db::conn();
        $id = $isNew ? self::slug((string)($in['id'] ?? '')) : (string)($in['id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
            Response::fail('invalid_id', '分类编号只能用小写字母、数字和连字符', 400);
        }
        if ($id === 'all') {
            Response::fail('reserved_id', '「all」是保留字，请换一个编号', 400);
        }
        $label = self::text($in['label'] ?? '', 1, 20, '分类名称');
        $sort  = self::intVal($in['sort'] ?? 0, -100000, 100000, '排序');
        $status = (string)($in['status'] ?? 'active');
        if (!in_array($status, ['active', 'hidden'], true)) {
            Response::fail('invalid_status', '分类状态不合法', 400);
        }

        if ($isNew) {
            $exists = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
            $exists->execute([$id]);
            if ($exists->fetchColumn() !== false) {
                Response::fail('duplicate_id', '分类编号已存在', 409);
            }
            $pdo->prepare('INSERT INTO categories (id, label, sort, status) VALUES (?, ?, ?, ?)')
                ->execute([$id, $label, $sort, $status]);
        } else {
            $stmt = $pdo->prepare('UPDATE categories SET label = ?, sort = ?, status = ? WHERE id = ?');
            $stmt->execute([$label, $sort, $status, $id]);
            if ($stmt->rowCount() === 0) {
                // rowCount 为 0 也可能是因为值没变，需确认存在性
                $exists = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
                $exists->execute([$id]);
                if ($exists->fetchColumn() === false) {
                    Response::fail('not_found', '分类不存在', 404);
                }
            }
        }
        return ['id' => $id];
    }

    public static function deleteCategory(string $id): void
    {
        $pdo = Db::conn();
        $n = $pdo->prepare('SELECT COUNT(*) FROM products WHERE cat_id = ?');
        $n->execute([$id]);
        $count = (int)$n->fetchColumn();
        if ($count > 0) {
            Response::fail('category_in_use', '该分类下还有 ' . $count . ' 件商品，请先移走或删除商品', 409);
        }
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            Response::fail('not_found', '分类不存在', 404);
        }
    }

    // ===============================================================
    // 订单
    // ===============================================================
    private const ORDER_SORTS = [
        'newest' => 'o.id DESC',
        'oldest' => 'o.id ASC',
        'amount' => 'o.total_cents DESC, o.id DESC',
    ];

    public static function orders(array $q): array
    {
        $where = ['1 = 1'];
        $params = [];

        $kw = trim((string)($q['q'] ?? ''));
        if ($kw !== '') {
            $kw = mb_substr($kw, 0, 40, 'UTF-8');
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw);
            // 手机号按完整值搜索（LIKE 会泄露部分匹配，这里要求精确或后八位）
            $where[] = "(o.order_no LIKE :kw ESCAPE '\\' OR o.buyer_name LIKE :kw ESCAPE '\\' OR o.buyer_phone = :phone)";
            $params[':kw'] = '%' . $esc . '%';
            $params[':phone'] = $kw;
        }

        $status = (string)($q['status'] ?? '');
        if (in_array($status, ['pending', 'paid', 'shipped', 'done', 'cancelled'], true)) {
            $where[] = 'o.status = :st';
            $params[':st'] = $status;
        }

        $channel = (string)($q['channel'] ?? '');
        if ($channel !== '' && preg_match('/^[a-z]{2,10}$/', $channel)) {
            $where[] = 'o.pay_channel = :ch';
            $params[':ch'] = $channel;
        }

        $sortKey = (string)($q['sort'] ?? 'newest');
        $order = self::ORDER_SORTS[$sortKey] ?? self::ORDER_SORTS['newest'];

        $limit  = max(1, min(100, (int)($q['limit'] ?? 30)));
        $offset = max(0, min(1000000, (int)($q['offset'] ?? 0)));

        $sql = 'SELECT o.id, o.order_no, o.buyer_name, o.buyer_phone, o.total_cents, o.status,
                       o.pay_channel, o.pay_trade_no, o.paid_at, o.created_at,
                       (SELECT COALESCE(SUM(qty),0) FROM order_items i WHERE i.order_id = o.id) AS item_qty
                FROM orders o WHERE ' . implode(' AND ', $where)
             . ' ORDER BY ' . $order . ' LIMIT :lim OFFSET :off';

        $stmt = Db::conn()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare('SELECT COUNT(*) FROM orders o WHERE ' . implode(' AND ', $where));
        $cnt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => (int)$cnt->fetchColumn()];
    }

    /** 订单详情（后台可见完整收货信息与支付流水） */
    public static function orderDetail(string $orderNo): ?array
    {
        $pdo = Db::conn();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_no = ?');
        $stmt->execute([$orderNo]);
        $o = $stmt->fetch();
        if (!$o) {
            return null;
        }
        unset($o['access_hash']);   // 凭证哈希不对后台暴露

        $items = $pdo->prepare(
            'SELECT product_id, sku, name, color, size, unit_cents, qty, line_cents
             FROM order_items WHERE order_id = ? ORDER BY id'
        );
        $items->execute([(int)$o['id']]);
        $o['items'] = $items->fetchAll();

        $pays = $pdo->prepare(
            'SELECT id, out_trade_no, channel, amount_cents, trade_no, status, fail_reason, created_at, paid_at
             FROM payments WHERE order_id = ? ORDER BY id DESC'
        );
        $pays->execute([(int)$o['id']]);
        $o['payments'] = $pays->fetchAll();

        // v5：发货内容（虚拟商品自动生成 / 实体商品待发货）
        $o['deliveries'] = DeliveryService::forOrder((int)$o['id']);

        // v5：订单归属用户
        if (!empty($o['user_id'])) {
            $u = $pdo->prepare('SELECT id, username, display_name, balance_cents FROM users WHERE id = ?');
            $u->execute([(int)$o['user_id']]);
            $o['user'] = $u->fetch() ?: null;
        } else {
            $o['user'] = null;
        }

        return $o;
    }

    /**
     * 订单状态流转（白名单状态机）
     *
     * pending → cancelled / paid(人工标记)
     * paid    → shipped / cancelled(退款后人工关单)
     * shipped → done
     * done    → 终态
     * cancelled → 终态
     *
     * 关键：取消订单必须归还库存（且只能归还一次），这就是为什么用条件更新锁住旧状态。
     */
    public static function transitionOrder(string $orderNo, string $to, array $extra = []): array
    {
        $allowed = [
            'pending'   => ['cancelled', 'paid'],
            'paid'      => ['shipped', 'done', 'cancelled'],
            'shipped'   => ['done', 'cancelled'],
            'done'      => [],
            'cancelled' => [],
        ];

        $result = Db::tx(function (PDO $pdo) use ($orderNo, $to, $allowed, $extra): array {
            $stmt = $pdo->prepare('SELECT id, status, total_cents, paid_cents, pay_channel, user_id FROM orders WHERE order_no = ?');
            $stmt->execute([$orderNo]);
            $o = $stmt->fetch();
            if (!$o) {
                Response::fail('not_found', '订单不存在', 404);
            }
            $from = (string)$o['status'];
            if (!in_array($to, $allowed[$from] ?? [], true)) {
                Response::fail('invalid_transition', '订单不能从「' . self::statusLabel($from) . '」变为「' . self::statusLabel($to) . '」', 409);
            }

            $orderId = (int)$o['id'];

            // 取消：先归还库存，再改状态（顺序保证不会出现「已取消但库存没回」
            if ($to === 'cancelled') {
                $items = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ?');
                $items->execute([$orderId]);
                $restore = $pdo->prepare(
                    "UPDATE products SET stock = stock + :q, sold = MAX(0, sold - :q2), updated_at = datetime('now')
                     WHERE id = :pid"
                );
                foreach ($items->fetchAll() as $it) {
                    $restore->execute([':q' => (int)$it['qty'], ':q2' => (int)$it['qty'], ':pid' => (string)$it['product_id']]);
                }
            }

            // 人工确认收款必须走统一的支付成功入口：否则虚拟商品永远不发货、
            // payments 流水与 order_events 都是空的。
            if ($to === 'paid') {
                $advanced = PaymentService::markPaidAndDeliver(
                    $pdo, $orderId, $orderNo, (int)$o['total_cents'], 'manual', '', 'admin'
                );
                if (!$advanced) {
                    Response::fail('concurrent_update', '订单状态已被其他人修改，请刷新后重试', 409);
                }
                if (array_key_exists('admin_note', $extra)) {
                    $pdo->prepare('UPDATE orders SET admin_note = ? WHERE id = ?')
                        ->execute([self::text($extra['admin_note'], 0, 200, '后台备注'), $orderId]);
                }
                return ['order_no' => $orderNo, 'from' => $from, 'to' => $to];
            }

            $fields = ['status = :st', "updated_at = datetime('now')"];
            $bind = [':st' => $to, ':no' => $orderNo];

            if ($to === 'cancelled') {
                $fields[] = "closed_at = COALESCE(closed_at, datetime('now'))";
            }
            if ($to === 'done') {
                $fields[] = "done_at = COALESCE(done_at, datetime('now'))";
            }
            if ($to === 'shipped') {
                $company = self::text($extra['ship_company'] ?? '', 0, 40, '快递公司');
                $shipNo  = self::text($extra['ship_no'] ?? '', 0, 64, '运单号');
                if ($shipNo !== '' && !preg_match('/^[A-Za-z0-9\-]{4,64}$/', $shipNo)) {
                    Response::fail('invalid_ship_no', '运单号格式不正确', 400);
                }
                $fields[] = 'shipped_at = datetime(\'now\')';
                $fields[] = 'ship_company = :sc';
                $fields[] = 'ship_no = :sn';
                $bind[':sc'] = $company;
                $bind[':sn'] = $shipNo;
            }
            if ($to === 'paid') {
                // 人工确认收款（例如线下转账）：写入 paid_cents，营收统计才准确
                $fields[] = "paid_at = COALESCE(paid_at, datetime('now'))";
                $fields[] = 'paid_cents = COALESCE(paid_cents, total_cents)';
                $fields[] = "pay_channel = CASE WHEN pay_channel = '' THEN 'manual' ELSE pay_channel END";
            }
            if (array_key_exists('admin_note', $extra)) {
                $fields[] = 'admin_note = :an';
                $bind[':an'] = self::text($extra['admin_note'], 0, 200, '后台备注');
            }

            // 条件更新：WHERE status = 旧状态，防并发双击把库存归还两次
            $sql = 'UPDATE orders SET ' . implode(', ', $fields) . ' WHERE order_no = :no AND status = :from';
            $bind[':from'] = $from;
            $upd = $pdo->prepare($sql);
            $upd->execute($bind);
            if ($upd->rowCount() !== 1) {
                Response::fail('concurrent_update', '订单状态已被其他人修改，请刷新后重试', 409);
            }

            if ($to === 'cancelled') {
                $pdo->prepare("UPDATE payments SET status = 'closed' WHERE order_id = ? AND status = 'created'")
                    ->execute([$orderId]);

                // 已收款订单被关单：必须把实收金额退回用户余额，否则钱扣了、单没了。
                // 用 (type=refund, ref_type=order, ref_id=订单号) 保证幂等，重复关单不会重复退款。
                $paid = (int)($o['paid_cents'] ?? 0);
                if ($paid <= 0) { $paid = (int)$o['total_cents']; }
                $uid = (int)($o['user_id'] ?? 0);
                if ($paid > 0 && $uid > 0) {
                    BalanceService::credit(
                        $uid, $paid, 'refund', 'order', $orderNo, $orderNo,
                        '订单取消自动退款', 'system'
                    );
                }
            }

            return ['order_no' => $orderNo, 'from' => $from, 'to' => $to];
        });

        return $result;
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

    // ===============================================================
    // 评价
    // ===============================================================
    public static function reviews(array $q): array
    {
        $where = ['1 = 1'];
        $params = [];

        $pid = (string)($q['product_id'] ?? '');
        if ($pid !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $pid)) {
            $where[] = 'r.product_id = :pid';
            $params[':pid'] = $pid;
        }
        $status = (string)($q['status'] ?? '');
        if (in_array($status, ['visible', 'hidden'], true)) {
            $where[] = 'r.status = :st';
            $params[':st'] = $status;
        }
        $kw = trim((string)($q['q'] ?? ''));
        if ($kw !== '') {
            $kw = mb_substr($kw, 0, 40, 'UTF-8');
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw);
            $where[] = "(r.body LIKE :kw ESCAPE '\\' OR r.author LIKE :kw ESCAPE '\\')";
            $params[':kw'] = '%' . $esc . '%';
        }

        $limit  = max(1, min(100, (int)($q['limit'] ?? 30)));
        $offset = max(0, min(1000000, (int)($q['offset'] ?? 0)));

        $sql = 'SELECT r.id, r.product_id, p.name AS product_name, r.author, r.days_ago, r.stars,
                       r.body, r.likes, r.reply, r.status, r.created_at
                FROM reviews r LEFT JOIN products p ON p.id = r.product_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT :lim OFFSET :off';

        $stmt = Db::conn()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare('SELECT COUNT(*) FROM reviews r WHERE ' . implode(' AND ', $where));
        $cnt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => (int)$cnt->fetchColumn()];
    }

    public static function saveReview(array $in, ?int $id): array
    {
        $pdo = Db::conn();
        $productId = (string)($in['product_id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $productId)) {
            Response::fail('invalid_product', '请选择商品', 400);
        }
        $exists = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
        $exists->execute([$productId]);
        if ($exists->fetchColumn() === false) {
            Response::fail('invalid_product', '商品不存在', 400);
        }
        $author = self::text($in['author'] ?? '', 1, 30, '评价人');
        $body   = self::text($in['body'] ?? '', 1, 600, '评价内容');
        $stars  = self::intVal($in['stars'] ?? 5, 1, 5, '评分');
        $daysAgo = self::text($in['days_ago'] ?? '', 0, 20, '时间标记');
        $status = (string)($in['status'] ?? 'visible');
        if (!in_array($status, ['visible', 'hidden'], true)) {
            Response::fail('invalid_status', '评价状态不合法', 400);
        }

        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO reviews (product_id, author, days_ago, stars, body, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$productId, $author, $daysAgo, $stars, $body, $status]);
            return ['id' => (int)$pdo->lastInsertId()];
        }

        $stmt = $pdo->prepare(
            "UPDATE reviews SET product_id = ?, author = ?, days_ago = ?, stars = ?, body = ?, status = ?,
                 updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$productId, $author, $daysAgo, $stars, $body, $status, $id]);
        if ($stmt->rowCount() === 0) {
            $c = $pdo->prepare('SELECT 1 FROM reviews WHERE id = ?');
            $c->execute([$id]);
            if ($c->fetchColumn() === false) {
                Response::fail('not_found', '评价不存在', 404);
            }
        }
        return ['id' => $id];
    }

    public static function setReviewStatus(int $id, string $status): void
    {
        if (!in_array($status, ['visible', 'hidden'], true)) {
            Response::fail('invalid_status', '评价状态不合法', 400);
        }
        $stmt = Db::conn()->prepare("UPDATE reviews SET status = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) {
            $c = Db::conn()->prepare('SELECT 1 FROM reviews WHERE id = ?');
            $c->execute([$id]);
            if ($c->fetchColumn() === false) {
                Response::fail('not_found', '评价不存在', 404);
            }
        }
    }

    public static function deleteReview(int $id): void
    {
        $stmt = Db::conn()->prepare('DELETE FROM reviews WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            Response::fail('not_found', '评价不存在', 404);
        }
    }

    // ===============================================================
    // 内部工具
    // ===============================================================

    /** 统一替换子表（颜色/规格/参数/保养），整体替换比 diff 更不易出错 */
    private static function replaceChildren(string $pid, array $colors, array $sizes, array $specs, array $care): void
    {
        $pdo = Db::conn();
        $pdo->prepare('DELETE FROM product_colors WHERE product_id = ?')->execute([$pid]);
        $pdo->prepare('DELETE FROM product_sizes  WHERE product_id = ?')->execute([$pid]);
        $pdo->prepare('DELETE FROM product_specs  WHERE product_id = ?')->execute([$pid]);
        $pdo->prepare('DELETE FROM product_care   WHERE product_id = ?')->execute([$pid]);

        $ic = $pdo->prepare('INSERT INTO product_colors (product_id, name, hex, sort) VALUES (?, ?, ?, ?)');
        foreach ($colors as $i => $c) {
            $ic->execute([$pid, $c['name'], $c['hex'], $i]);
        }
        $is = $pdo->prepare('INSERT INTO product_sizes (product_id, name, extra_cents, sort) VALUES (?, ?, ?, ?)');
        foreach ($sizes as $i => $s) {
            $is->execute([$pid, $s['name'], $s['extra_cents'], $i]);
        }
        $ip = $pdo->prepare('INSERT INTO product_specs (product_id, k, v, sort) VALUES (?, ?, ?, ?)');
        foreach ($specs as $i => $s) {
            $ip->execute([$pid, $s['k'], $s['v'], $i]);
        }
        $ir = $pdo->prepare('INSERT INTO product_care (product_id, body, sort) VALUES (?, ?, ?)');
        foreach ($care as $i => $c) {
            $ir->execute([$pid, $c, $i]);
        }
    }

    private static function normalizeColors(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach (array_slice($raw, 0, 12) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = self::text($c['name'] ?? '', 1, 30, '颜色名');
            // 只接受 #rgb / #rrggbb，绝不让任意字符串进 CSS
            $hex = strtolower(trim((string)($c['hex'] ?? '')));
            if (!preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $hex)) {
                $hex = '#cccccc';
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['name' => $name, 'hex' => $hex];
        }
        return $out;
    }

    private static function normalizeSizes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach (array_slice($raw, 0, 12) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $name = self::text($s['name'] ?? '', 1, 30, '规格名');
            $extra = self::intVal($s['extra_cents'] ?? 0, 0, 10000000, '规格加价');
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['name' => $name, 'extra_cents' => $extra];
        }
        return $out;
    }

    private static function normalizeSpecs(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 30) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $k = self::text($s['k'] ?? '', 1, 30, '参数名');
            $v = self::text($s['v'] ?? '', 1, 200, '参数值');
            $out[] = ['k' => $k, 'v' => $v];
        }
        return $out;
    }

    private static function normalizeCare(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 20) as $c) {
            if (is_array($c)) {
                $c = $c['body'] ?? '';
            }
            if (!is_string($c)) {
                continue;
            }
            $t = trim($c);
            if ($t === '') {
                continue;
            }
            $out[] = self::text($t, 1, 300, '保养说明');
        }
        return $out;
    }

    /** 图片地址：只允许站内相对路径或 https 绝对地址（防 javascript:/data: 注入） */
    private static function safeImagePath(string $v): bool
    {
        if (preg_match('#^https://[A-Za-z0-9.\-]+(?:/[\w\-.~%/?=&]*)?$#', $v)) {
            return true;
        }
        if (preg_match('#^/?(?:images|assets)/[\w\-./]+\.(?:webp|jpg|jpeg|png|avif|gif)$#i', $v)) {
            return !str_contains($v, '..');
        }
        if (preg_match('#^[a-z0-9\-_]+/[\w\-./]+\.(?:webp|jpg|jpeg|png|avif|gif)$#i', $v)) {
            return !str_contains($v, '..');
        }
        return false;
    }

    /** 文本校验：去控制字符、限长 */
    private static function text(mixed $v, int $min, int $max, string $label): string
    {
        if ($v === null) {
            $v = '';
        }
        if (!is_string($v)) {
            Response::fail('invalid_text', $label . '格式不正确', 400);
        }
        // 去控制字符，但保留 \n \t（多行描述需要）
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $v = trim($v);
        $len = mb_strlen($v, 'UTF-8');
        if ($len < $min) {
            Response::fail('text_required', $label . '不能为空', 400);
        }
        if ($len > $max) {
            Response::fail('text_too_long', $label . '不能超过 ' . $max . ' 个字符', 400);
        }
        return $v;
    }

    // ===============================================================
    // v5：用户管理 / 余额 / 发货模板
    // ===============================================================

    /** 用户列表（分页 + 关键词） */
    public static function users(array $q): array
    {
        $limit  = max(1, min(100, (int)($q['limit'] ?? 30)));
        $offset = max(0, (int)($q['offset'] ?? 0));
        $kw     = trim((string)($q['q'] ?? ''));
        $status = (string)($q['status'] ?? '');

        $where = [];
        $bind  = [];
        if ($kw !== '') {
            $where[] = "(username LIKE :kw ESCAPE '\\' OR display_name LIKE :kw2 ESCAPE '\\' OR email LIKE :kw3 ESCAPE '\\')";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($kw, 0, 40, 'UTF-8')) . '%';
            $bind[':kw'] = $like;
            $bind[':kw2'] = $like;
            $bind[':kw3'] = $like;
        }
        if (in_array($status, ['active', 'disabled'], true)) {
            $where[] = 'status = :st';
            $bind[':st'] = $status;
        }
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

        $stmt = Db::conn()->prepare(
            "SELECT u.id, u.username, u.email, u.display_name, u.balance_cents, u.status,
                    u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
                    (SELECT COALESCE(SUM(COALESCE(o.paid_cents, o.total_cents)),0) FROM orders o
                      WHERE o.user_id = u.id AND o.status IN ('paid','shipped','done')) AS spent_cents
             FROM users u WHERE {$whereSql} ORDER BY u.id DESC LIMIT :lim OFFSET :off"
        );
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSql}");
        $cnt->execute($bind);

        return [
            'items' => array_map(static fn($r) => [
                'id'            => (int)$r['id'],
                'username'      => (string)$r['username'],
                'email'         => (string)$r['email'],
                'display_name'  => (string)$r['display_name'],
                'balance_cents' => (int)$r['balance_cents'],
                'status'        => (string)$r['status'],
                'order_count'   => (int)$r['order_count'],
                'spent_cents'   => (int)$r['spent_cents'],
                'created_at'    => (string)$r['created_at'],
                'last_login_at' => $r['last_login_at'],
            ], $stmt->fetchAll()),
            'total' => (int)$cnt->fetchColumn(),
        ];
    }

    /** 用户详情：资料 + 余额 + 最近订单 + 最近流水 */
    public static function userDetail(int $userId): ?array
    {
        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            'SELECT id, username, email, display_name, balance_cents, status, created_at,
                    last_login_at, password_changed_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            return null;
        }

        $orders = $pdo->prepare(
            'SELECT order_no, status, total_cents, created_at, paid_at
             FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 20'
        );
        $orders->execute([$userId]);

        return [
            'user' => [
                'id'            => (int)$u['id'],
                'username'      => (string)$u['username'],
                'email'         => (string)$u['email'],
                'display_name'  => (string)$u['display_name'],
                'balance_cents' => (int)$u['balance_cents'],
                'status'        => (string)$u['status'],
                'created_at'    => (string)$u['created_at'],
                'last_login_at' => $u['last_login_at'],
                'password_changed_at' => $u['password_changed_at'],
            ],
            'orders' => array_map(static fn($r) => [
                'order_no'   => (string)$r['order_no'],
                'status'     => (string)$r['status'],
                'status_label' => OrderService::statusLabel((string)$r['status']),
                'total'      => (int)$r['total_cents'],
                'created_at' => (string)$r['created_at'],
                'paid_at'    => $r['paid_at'],
            ], $orders->fetchAll()),
            'ledger' => BalanceService::ledger($userId, 20, 0)['items'],
        ];
    }

    /** 启用 / 停用用户（停用同时踢掉所有会话） */
    public static function setUserStatus(int $userId, string $status): array
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            Response::fail('invalid_status', '用户状态不合法', 400);
        }
        $pdo = Db::conn();
        $stmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$status, $userId]);
        if ($stmt->rowCount() !== 1) {
            Response::fail('not_found', '用户不存在', 404);
        }
        if ($status === 'disabled') {
            $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);
        }
        return ['id' => $userId, 'status' => $status];
    }

    /** 重置用户密码：生成一次性临时密码，强制下次登录修改 */
    public static function resetUserPassword(int $userId): array
    {
        $pdo = Db::conn();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            Response::fail('not_found', '用户不存在', 404);
        }

        // 临时密码：12 位，含大小写字母与数字，避免易混淆字符
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $tmp = '';
        for ($i = 0; $i < 12; $i++) {
            $tmp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        Db::tx(function (PDO $pdo) use ($userId, $tmp): void {
            $pdo->prepare(
                "UPDATE users SET password_hash = ?, must_change_password = 1,
                     password_changed_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
            )->execute([UserAuth::hash($tmp), $userId]);
            $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);
        });

        return [
            'id' => $userId,
            'temp_password' => $tmp,
            'message' => '临时密码只显示这一次，请立即转交用户；用户下次登录后需修改密码',
        ];
    }

    /**
     * 余额调整（加款 / 扣款）
     * 必须写备注，便于对账；扣款不得扣成负数。
     */
    public static function adjustBalance(int $userId, array $in, string $operator): array
    {
        $delta = $in['delta_cents'] ?? null;
        if (!is_int($delta) && !(is_string($delta) && preg_match('/^-?\d{1,12}$/', $delta))) {
            Response::fail('invalid_amount', '调整金额必须是整数（分，可为负）', 400);
        }
        $delta = (int)$delta;
        if ($delta === 0) {
            Response::fail('invalid_amount', '调整金额不能为 0', 400);
        }
        if (abs($delta) > 100000000) {
            Response::fail('invalid_amount', '单次调整金额过大', 400);
        }

        $remark = trim((string)($in['remark'] ?? ''));
        if ($remark === '') {
            Response::fail('remark_required', '请填写调整原因（备注）', 400);
        }
        if (mb_strlen($remark, 'UTF-8') > 200) {
            Response::fail('remark_too_long', '备注不能超过 200 个字符', 400);
        }

        if ($delta > 0) {
            $r = BalanceService::credit($userId, $delta, 'admin_adjust', 'manual', '', '', $remark, $operator);
        } else {
            $r = BalanceService::debit($userId, -$delta, 'admin_adjust', 'manual', '', '', $remark, $operator);
            if (!$r['ok']) {
                Response::fail('insufficient_balance', '该用户余额不足，无法扣减', 409);
            }
        }

        return ['id' => $userId, 'delta' => $delta, 'balance' => $r['balance']];
    }

    /** 商品发货配置（类型 + 结构化字段 + 模板文本） */
    public static function productDelivery(string $productId): array
    {
        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            'SELECT id, name, type, delivery_template, yulan_account_limit, yulan_ip_restrict,
                    yulan_server_ip, yulan_server_port, yulan_server_user, yulan_rdp_host,
                    yulan_server_pass
             FROM products WHERE id = ?'
        );
        $stmt->execute([$productId]);
        $p = $stmt->fetch();
        if (!$p) {
            Response::fail('not_found', '商品不存在', 404);
        }

        $f = $pdo->prepare('SELECT label, value, sort FROM product_delivery_fields WHERE product_id = ? ORDER BY sort, label');
        $f->execute([$productId]);

        return [
            'product_id'             => (string)$p['id'],
            'name'                   => (string)$p['name'],
            'type'                   => (string)$p['type'],
            'template'               => (string)$p['delivery_template'],
            'yulan_account_limit'    => (int)$p['yulan_account_limit'],
            'yulan_ip_restrict'      => (int)$p['yulan_ip_restrict'],
            // 商品级服务器配置（口令只写不读：只返回「是否已配置」状态）
            'yulan_server_ip'        => (string)$p['yulan_server_ip'],
            'yulan_server_port'      => (string)$p['yulan_server_port'],
            'yulan_server_user'      => (string)$p['yulan_server_user'],
            'yulan_rdp_host'         => (string)$p['yulan_rdp_host'],
            'yulan_server_pass_set'  => ((string)$p['yulan_server_pass']) !== '',
            'fields'              => array_map(static fn($r) => [
                'label' => (string)$r['label'],
                'value' => (string)$r['value'],
                'sort'  => (int)$r['sort'],
            ], $f->fetchAll()),
            'placeholders' => ['{order_no}', '{date}', '{random:12}', '{uuid}'],
        ];
    }

    /** 保存商品发货配置 */
    public static function saveProductDelivery(string $productId, array $in): array
    {
        $pdo = Db::conn();
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        if ($stmt->fetchColumn() === false) {
            Response::fail('not_found', '商品不存在', 404);
        }

        $type = (string)($in['type'] ?? 'physical');
        if (!in_array($type, ['virtual', 'physical', 'yulan'], true)) {
            Response::fail('invalid_type', '商品类型只能是 virtual / physical / yulan', 400);
        }

        $template = (string)($in['template'] ?? '');
        $template = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $template) ?? '';
        $template = trim($template);
        if (mb_strlen($template, 'UTF-8') > 4000) {
            Response::fail('template_too_long', '发货模板不能超过 4000 个字符', 400);
        }

        $fields = $in['fields'] ?? [];
        if (!is_array($fields)) {
            Response::fail('invalid_fields', '字段列表格式不正确', 400);
        }
        if (count($fields) > 20) {
            Response::fail('too_many_fields', '发货字段最多 20 行', 400);
        }

        $clean = [];
        $seen = [];
        foreach ($fields as $i => $f) {
            if (!is_array($f)) {
                Response::fail('invalid_fields', '字段格式不正确', 400);
            }
            $label = trim((string)($f['label'] ?? ''));
            $value = (string)($f['value'] ?? '');
            if ($label === '') {
                continue;   // 空行忽略
            }
            if (mb_strlen($label, 'UTF-8') > 30) {
                Response::fail('label_too_long', '字段名不能超过 30 个字符', 400);
            }
            if (preg_match('/[<>{}\\]/', $label)) {
                Response::fail('invalid_label', '字段名包含不允许的字符', 400);
            }
            if (mb_strlen($value, 'UTF-8') > 500) {
                Response::fail('value_too_long', '字段取值不能超过 500 个字符', 400);
            }
            if (isset($seen[$label])) {
                Response::fail('duplicate_label', '字段名「' . $label . '」重复', 400);
            }
            $seen[$label] = true;
            $clean[] = ['label' => $label, 'value' => $value, 'sort' => (int)$i];
        }

        // 欲蓝专属配置
        $limit = self::intVal($in['yulan_account_limit'] ?? 0, 0, 1000000, '账号限购数量');
        $ipr   = self::intVal($in['yulan_ip_restrict'] ?? 0, 0, 1, 'IP 限制开关');

        // v5.5：商品级欲蓝服务器配置（留空 = 用全局默认；口令只写不读）
        $srvIp   = self::text($in['yulan_server_ip'] ?? '', 0, 64, '欲蓝服务器 IP');
        $srvPort = trim((string)($in['yulan_server_port'] ?? ''));
        if ($srvPort !== '' && !preg_match('/^\d{1,5}$/', $srvPort)) {
            Response::fail('invalid_yulan_port', '欲蓝服务器端口只能是数字（1-65535）', 400);
        }
        if ($srvPort !== '' && ((int)$srvPort < 1 || (int)$srvPort > 65535)) {
            Response::fail('invalid_yulan_port', '欲蓝服务器端口范围 1-65535', 400);
        }
        $srvUser    = self::text($in['yulan_server_user'] ?? '', 0, 64, '欲蓝服务器账号');
        $srvRdpHost = self::text($in['yulan_rdp_host'] ?? '', 0, 128, '欲蓝连接地址');
        // 口令：不回显。传入非空字符串 → 覆盖；传 '__CLEAR__' → 清空；留空 → 保持不变
        $srvPassSet = false;
        $srvPass    = null;
        if (array_key_exists('yulan_server_pass', $in)) {
            $rawPass = (string)$in['yulan_server_pass'];
            if ($rawPass === '__CLEAR__') {
                $srvPassSet = true;
                $srvPass    = '';
            } elseif ($rawPass !== '') {
                if (mb_strlen($rawPass, 'UTF-8') < 6) {
                    Response::fail('invalid_yulan_pass', '欲蓝服务器口令至少 6 位', 400);
                }
                $srvPassSet = true;
                $srvPass    = $rawPass;
            }
        }

        Db::tx(function (PDO $pdo) use (
            $productId, $type, $template, $clean, $limit, $ipr,
            $srvIp, $srvPort, $srvUser, $srvRdpHost, $srvPassSet, $srvPass
        ): void {
            $pdo->prepare(
                "UPDATE products
                 SET type = ?, delivery_template = ?, yulan_account_limit = ?, yulan_ip_restrict = ?,
                     yulan_server_ip = ?, yulan_server_port = ?, yulan_server_user = ?, yulan_rdp_host = ?,
                     updated_at = datetime('now')
                 WHERE id = ?"
            )->execute([$type, $template, $limit, $ipr, $srvIp, $srvPort, $srvUser, $srvRdpHost, $productId]);
            // 口令仅在明确提交时更新（只写不读，绝不回显）
            if ($srvPassSet) {
                $pdo->prepare("UPDATE products SET yulan_server_pass = ?, updated_at = datetime('now') WHERE id = ?")
                    ->execute([(string)$srvPass, $productId]);
            }
            $pdo->prepare('DELETE FROM product_delivery_fields WHERE product_id = ?')->execute([$productId]);
            $ins = $pdo->prepare('INSERT INTO product_delivery_fields (product_id, label, value, sort) VALUES (?, ?, ?, ?)');
            foreach ($clean as $f) {
                $ins->execute([$productId, $f['label'], $f['value'], $f['sort']]);
            }
        });

        return [
            'product_id'             => $productId,
            'type'                   => $type,
            'fields'                 => count($clean),
            'yulan_account_limit'    => $limit,
            'yulan_ip_restrict'      => $ipr,
            'yulan_server_ip'        => $srvIp,
            'yulan_server_port'      => $srvPort,
            'yulan_server_user'      => $srvUser,
            'yulan_rdp_host'         => $srvRdpHost,
            'yulan_server_pass_set'  => true,
        ];
    }

    /** 实体商品发货：填快递信息并置为已发货 */
    public static function shipOrder(string $orderNo, array $in): array
    {
        $company = trim((string)($in['ship_company'] ?? ''));
        $shipNo  = trim((string)($in['ship_no'] ?? ''));
        if ($company === '' || mb_strlen($company, 'UTF-8') > 40) {
            Response::fail('invalid_company', '请填写快递公司（不超过 40 字）', 400);
        }
        if ($shipNo === '' || mb_strlen($shipNo, 'UTF-8') > 60) {
            Response::fail('invalid_ship_no', '请填写运单号（不超过 60 字）', 400);
        }
        if (!preg_match('/^[A-Za-z0-9\-]{4,60}$/', $shipNo)) {
            Response::fail('invalid_ship_no', '运单号只能包含字母、数字和短横线', 400);
        }

        $pdo = Db::conn();
        $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE order_no = ?');
        $stmt->execute([$orderNo]);
        $o = $stmt->fetch();
        if (!$o) {
            Response::fail('not_found', '订单不存在', 404);
        }
        if ((string)$o['status'] !== 'paid') {
            Response::fail('invalid_status', '只有已付款、且尚未发货的订单可以发货', 409);
        }

        Db::tx(function (PDO $pdo) use ($orderNo, $company, $shipNo, $o): void {
            // 走统一状态机：条件更新 + 写 order_events，且已 shipped 的订单不能再被覆盖运单号。
            $ok = OrderStateMachine::transition($pdo, (int)$o['id'], OrderStateMachine::SHIPPED, [
                'from'   => OrderStateMachine::PAID,
                'actor'  => 'admin',
                'reason' => '实体商品发货',
                'fields' => ['ship_company' => $company, 'ship_no' => $shipNo],
            ]);
            if (!$ok) {
                Response::fail('concurrent_update', '订单状态已变更或已发货，请刷新后重试', 409);
            }

            $pdo->prepare(
                "UPDATE order_deliveries SET status = 'delivered',
                     content = CASE WHEN content = '' THEN :c ELSE content END,
                     delivered_at = COALESCE(delivered_at, datetime('now')), updated_at = datetime('now')
                 WHERE order_id = :oid AND kind = 'physical' AND status = 'pending'"
            )->execute([
                ':c' => '快递公司：' . $company . "\n" . '运单号：' . $shipNo,
                ':oid' => (int)$o['id'],
            ]);
        });

        return ['order_no' => $orderNo, 'status' => 'shipped', 'ship_company' => $company, 'ship_no' => $shipNo];
    }

    private static function intVal(mixed $v, int $min, int $max, string $label): int
    {
        if (is_string($v)) {
            $v = trim($v);
        }
        if ($v === '' || $v === null) {
            $v = 0;
        }
        if (!is_numeric($v)) {
            Response::fail('invalid_number', $label . '必须是数字', 400);
        }
        $n = (int)$v;
        if ($n < $min || $n > $max) {
            Response::fail('out_of_range', $label . '必须在 ' . $min . ' ~ ' . $max . ' 之间', 400);
        }
        return $n;
    }

    private static function money(mixed $v, string $label, int $min, int $max): int
    {
        if (!is_numeric($v)) {
            Response::fail('invalid_money', $label . '必须是数字（单位：分）', 400);
        }
        $n = (int)round((float)$v);
        if ($n < $min || $n > $max) {
            Response::fail('out_of_range', $label . '超出允许范围', 400);
        }
        return $n;
    }

    /** 生成合法 slug：只保留 a-z0-9-，非 ASCII 一律丢弃（编号必须可安全进 URL） */
    private static function slug(string $v): string
    {
        $v = strtolower(trim($v));
        $v = preg_replace('/[^a-z0-9\-]+/', '-', $v) ?? '';
        $v = trim($v, '-');
        return mb_substr($v, 0, 32, 'UTF-8');
    }
}
