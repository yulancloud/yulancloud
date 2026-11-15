<?php
/**
 * 商品仓储 —— 所有 SQL 均为预处理语句，排序/筛选走白名单映射
 */
declare(strict_types=1);

final class ProductRepo
{
    /** 排序白名单 → 真实 SQL 片段（用户输入永不进入 SQL 文本） */
    private const SORTS = [
        'featured'   => 'p.sort ASC, p.id ASC',
        'price-asc'  => 'p.price_cents ASC, p.id ASC',
        'price-desc' => 'p.price_cents DESC, p.id ASC',
        // 评分优先：按真实评价的平均分排序，不再用商品表里写死的演示评分
        'rating'     => "COALESCE((SELECT AVG(r.stars) FROM reviews r WHERE r.product_id = p.id AND r.status = 'visible'), 0) DESC, p.sold DESC, p.id ASC",
        'newest'     => 'p.created_at DESC, p.id ASC',
    ];

    /** 列表：支持分类、关键词、排序、分页 */
    public static function list(string $cat, string $keyword, string $sort, int $limit, int $offset): array
    {
        $where  = ["p.status = 'active'"];
        $params = [];

        if ($cat !== '' && $cat !== 'all') {
            $where[] = 'p.cat_id = :cat';
            $params[':cat'] = $cat;
        }
        if ($keyword !== '') {
            // 用 LIKE 参数化；转义 % _ 防止用户构造通配符放大扫描
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $where[] = "(p.name LIKE :kw ESCAPE '\\' OR p.sub LIKE :kw ESCAPE '\\' OR p.description LIKE :kw ESCAPE '\\')";
            $params[':kw'] = '%' . $esc . '%';
        }

        $order = self::SORTS[$sort] ?? self::SORTS['featured'];
        // 评价数与平均分来自 reviews 表的真实可见评价（相关子查询，商品量小可接受）
        $sql = "SELECT p.*,
                 (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'visible') AS review_count,
                 (SELECT ROUND(AVG(r.stars), 1) FROM reviews r WHERE r.product_id = p.id AND r.status = 'visible') AS avg_stars
                FROM products p WHERE " . implode(' AND ', $where)
             . ' ORDER BY ' . $order . ' LIMIT :lim OFFSET :off';

        $stmt = Db::conn()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    public static function count(string $cat, string $keyword): int
    {
        $where  = ["p.status = 'active'"];
        $params = [];
        if ($cat !== '' && $cat !== 'all') {
            $where[] = 'p.cat_id = :cat';
            $params[':cat'] = $cat;
        }
        if ($keyword !== '') {
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $where[] = "(p.name LIKE :kw ESCAPE '\\' OR p.sub LIKE :kw ESCAPE '\\' OR p.description LIKE :kw ESCAPE '\\')";
            $params[':kw'] = '%' . $esc . '%';
        }
        $stmt = Db::conn()->prepare('SELECT COUNT(*) FROM products p WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** 单个商品（含颜色/规格/参数/保养/评价） */
    public static function find(string $id): ?array
    {
        $stmt = Db::conn()->prepare("SELECT * FROM products WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $p = self::shape($row);

        $stmt = Db::conn()->prepare('SELECT name, hex FROM product_colors WHERE product_id = :id ORDER BY sort, name');
        $stmt->execute([':id' => $id]);
        $p['colors'] = array_map(
            fn($r) => ['n' => $r['name'], 'v' => $r['hex']],
            $stmt->fetchAll()
        );

        $stmt = Db::conn()->prepare('SELECT name, extra_cents FROM product_sizes WHERE product_id = :id ORDER BY sort, name');
        $stmt->execute([':id' => $id]);
        $p['sizes'] = array_map(
            fn($r) => ['n' => $r['name'], 'd' => round(((int)$r['extra_cents']) / 100, 2), 'extra_cents' => (int)$r['extra_cents']],
            $stmt->fetchAll()
        );

        $stmt = Db::conn()->prepare('SELECT k, v FROM product_specs WHERE product_id = :id ORDER BY sort, rowid');
        $stmt->execute([':id' => $id]);
        $p['specs'] = array_map(fn($r) => [$r['k'], $r['v']], $stmt->fetchAll());

        $stmt = Db::conn()->prepare('SELECT body FROM product_care WHERE product_id = :id ORDER BY sort, rowid');
        $stmt->execute([':id' => $id]);
        $p['care'] = array_map(fn($r) => $r['body'], $stmt->fetchAll());

        // 评分与评价总数一律按真实可见评价计算，覆盖商品表里写死的演示值
        $stmt = Db::conn()->prepare(
            "SELECT COUNT(*) AS c, ROUND(AVG(stars), 1) AS a FROM reviews
             WHERE product_id = :id AND status = 'visible'"
        );
        $stmt->execute([':id' => $id]);
        $agg = $stmt->fetch();
        $p['review_count'] = (int)($agg['c'] ?? 0);
        $p['rating'] = ($agg['a'] ?? null) === null ? 0.0 : (float)$agg['a'];

        $stmt = Db::conn()->prepare(
            "SELECT author, days_ago, stars, body, likes, reply FROM reviews
             WHERE product_id = :id AND status = 'visible' ORDER BY id DESC LIMIT 20"
        );
        $stmt->execute([':id' => $id]);
        $p['reviews'] = array_map(fn($r) => [
            'n' => $r['author'], 't' => $r['days_ago'], 's' => (int)$r['stars'],
            'text' => $r['body'], 'likes' => (int)$r['likes'],
            'reply' => $r['reply'],
        ], $stmt->fetchAll());

        return $p;
    }

    /** 分类 + 各分类商品数（只算 active 商品，隐藏分类不展示） */
    public static function categories(): array
    {
        $rows = Db::conn()->query(
            "SELECT c.id, c.label, COUNT(p.id) AS n
             FROM categories c
             LEFT JOIN products p ON p.cat_id = c.id AND p.status = 'active'
             WHERE c.status = 'active'
             GROUP BY c.id, c.label, c.sort
             ORDER BY c.sort, c.id"
        )->fetchAll();

        $total = 0;
        $out = [];
        foreach ($rows as $r) {
            $total += (int)$r['n'];
            $out[] = ['id' => $r['id'], 'label' => $r['label'], 'count' => (int)$r['n']];
        }
        array_unshift($out, ['id' => 'all', 'label' => '全部', 'count' => $total]);
        return $out;
    }

    /** 站点文案块 */
    public static function block(string $key, mixed $default = null): mixed
    {
        $stmt = Db::conn()->prepare('SELECT value FROM site_blocks WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            return $default;
        }
        $decoded = json_decode((string)$v, true);
        return $decoded === null ? $v : $decoded;
    }

    /** 数据库行 → 前端契约（金额分转元） */
    private static function shape(array $r): array
    {
        // 评分只认真实评价：无评价就是 0，绝不回退到商品表写死的演示评分（4.9 之类）
        // 单商品接口由 find() 再按真实评价覆盖一次
        $rating = (array_key_exists('avg_stars', $r) && $r['avg_stars'] !== null)
            ? (float)$r['avg_stars'] : 0.0;
        return [
            'id'    => $r['id'],
            // 商品类型必须下发给前台：购物袋据此判断「全虚拟商品订单免运费」。
            // 之前漏了这个字段，前台 p.type 恒为 undefined，虚拟商品一律被当成实体收运费。
            'type'  => (string)($r['type'] ?? 'physical'),
            'yulan_account_limit' => (int)($r['yulan_account_limit'] ?? 0),
            'yulan_ip_restrict'   => (int)($r['yulan_ip_restrict'] ?? 0),
            'sku'   => $r['sku'],
            'name'  => $r['name'],
            'sub'   => $r['sub'],
            'cat'   => $r['cat_id'],
            'price' => round(((int)$r['price_cents']) / 100, 2),
            'was'   => $r['was_cents'] === null ? null : round(((int)$r['was_cents']) / 100, 2),
            'price_cents' => (int)$r['price_cents'],
            'badge' => $r['badge'],
            'img'   => $r['img'],
            'rating' => $rating,
            'review_count' => (int)($r['review_count'] ?? 0),
            'sold'  => (int)$r['sold'],
            'stock' => (int)$r['stock'],
            'desc'  => $r['description'],
            'story' => $r['story'],
        ];
    }
}
