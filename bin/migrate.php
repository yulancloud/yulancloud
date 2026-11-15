<?php
/**
 * v4 → v5 数据库迁移（幂等，可重复执行）
 *
 *   php bin/migrate.php            # 执行迁移
 *   php bin/migrate.php --dry-run  # 只检查，不改库
 *
 * 迁移内容：
 *   1. 新建 v5 表：users / user_sessions / balance_ledger / recharge_orders /
 *      order_deliveries / product_delivery_fields
 *   2. products 增加 type（默认 physical）、delivery_template
 *   3. orders 增加 user_id（历史订单为 NULL，仍可用订单号+token 查询）
 *   4. 补索引
 *
 * 安全：
 *   - 每一步先检查是否已存在（PRAGMA table_info / sqlite_master），重复执行无副作用
 *   - 整个迁移在一个事务内，失败自动回滚
 *   - 迁移前自动备份数据库文件（data/shop.sqlite.bak-YYYYmmddHHMMSS）
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Db.php';

$cfg    = require dirname(__DIR__) . '/config/config.php';
$dbPath = $cfg['db_path'];
$dryRun = in_array('--dry-run', $argv ?? [], true);

if (!is_file($dbPath)) {
    fwrite(STDERR, "数据库文件不存在：{$dbPath}\n请先执行 php bin/init_db.php\n");
    exit(1);
}

echo "数据库：{$dbPath}\n";
echo $dryRun ? "模式：DRY RUN（不修改）\n\n" : "模式：执行迁移\n\n";

// ---------- 备份 ----------
if (!$dryRun) {
    $bak = $dbPath . '.bak-' . date('YmdHis');
    if (!copy($dbPath, $bak)) {
        fwrite(STDERR, "备份失败，已中止（避免无备份迁移）\n");
        exit(1);
    }
    echo "已备份到：{$bak}\n";
}

$pdo = Db::conn();

/** 表是否存在 */
function tableExists(PDO $pdo, string $t): bool
{
    $s = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
    $s->execute([$t]);
    return $s->fetchColumn() !== false;
}

/** 列是否存在 */
function columnExists(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($s->fetchAll() as $r) {
        if (strcasecmp((string)$r['name'], $col) === 0) {
            return true;
        }
    }
    return false;
}

/** products.type 的 CHECK 是否已允许 'yulan'（旧库未升级时为 false） */
function productsTypeAllowsYulan(PDO $pdo): bool
{
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn();
    if ($sql === false) {
        return false;
    }
    return strpos((string)$sql, 'yulan') !== false;
}

/** order_deliveries.kind 的 CHECK 是否已允许 'yulan'（旧 v5 表只允许 virtual/physical） */
function deliveriesKindAllowsYulan(PDO $pdo): bool
{
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='order_deliveries'")->fetchColumn();
    if ($sql === false) {
        return true; // 表尚不存在：稍后由 schema_v5.sql 以新结构创建
    }
    return strpos((string)$sql, 'yulan') !== false;
}

/**
 * 重建 order_deliveries 表以放开 kind 的 CHECK（v5.5 新增 yulan 类型发货内容）。
 * 保留全部历史数据；旧表若缺少 v5.5 新列，按默认值补齐。
 */
function rebuildDeliveriesForYulan(PDO $pdo): void
{
    // 新表列 → 旧表缺列时的填充值（order_item_id 可空，其余 NOT NULL DEFAULT ''）
    $newCols = [
        'id'            => null,
        'order_id'      => null,
        'order_no'      => null,
        'order_item_id' => 'NULL',
        'product_id'    => "''",
        'product_name'  => "''",
        'kind'          => null,
        'content'       => null,
        'status'        => null,
        'delivered_at'  => null,
        'created_at'    => null,
        'updated_at'    => null,
    ];
    $selParts = [];
    foreach ($newCols as $c => $fill) {
        $selParts[] = ($fill === null || columnExists($pdo, 'order_deliveries', $c))
            ? $c
            : "{$fill} AS {$c}";
    }
    $cols = implode(', ', array_keys($newCols));
    $sel  = implode(', ', $selParts);

    $pdo->exec('PRAGMA legacy_alter_table=ON');
    $pdo->exec('PRAGMA foreign_keys=OFF');
    $pdo->exec('ALTER TABLE order_deliveries RENAME TO __deliveries_old');
    $pdo->exec(<<<'SQL'
CREATE TABLE order_deliveries (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_no     TEXT NOT NULL,
  order_item_id INTEGER,
  product_id   TEXT NOT NULL DEFAULT '',
  product_name TEXT NOT NULL DEFAULT '',
  kind         TEXT NOT NULL DEFAULT 'virtual'
               CHECK (kind IN ('virtual', 'physical', 'yulan')),
  content      TEXT NOT NULL DEFAULT '',
  status       TEXT NOT NULL DEFAULT 'delivered'
               CHECK (status IN ('pending', 'delivered', 'revoked')),
  delivered_at TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );
    // 同样对 created_at/updated_at 做 NULL 兜底
    $selSafe = str_replace(
        ['created_at', 'updated_at'],
        ["COALESCE(created_at, datetime('now'))", "COALESCE(updated_at, datetime('now'))"],
        $sel
    );
    $pdo->exec("INSERT INTO order_deliveries ({$cols}) SELECT {$selSafe} FROM __deliveries_old");
    $pdo->exec('DROP TABLE __deliveries_old');
    $pdo->exec('PRAGMA legacy_alter_table=OFF');
    $pdo->exec('PRAGMA foreign_keys=ON');
    // 重建索引（ALTER 会丢弃原表索引）
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_deliveries_order ON order_deliveries (order_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_deliveries_no    ON order_deliveries (order_no)');
}

/**
 * 重建 products 表以放开 type 的 CHECK（旧库 type 列已存在但只允许 virtual/physical）。
 * 保留全部数据，并补上 yulan 专属两列（默认 0 / 0）。
 */
function rebuildProductsForYulan(PDO $pdo): void
{
    $cols = 'id, sku, name, sub, cat_id, price_cents, was_cents, badge, img, rating, sold, '
          . 'stock, description, story, type, delivery_template, status, sort, created_at, updated_at';
    // 【重要】legacy_alter_table=ON：默认（OFF）模式下 SQLite 的 RENAME 会把
    // 所有子表（product_colors / reviews / product_delivery_fields 等）里指向本表
    // 的外键引用同步改写成旧表名 __products_old，随后旧表被 DROP，子表外键就指向
    // 了不存在的表——之后任何写 products 的语句都会报
    // "no such table: main.__products_old"（2026-11-14 线上实锤，保存商品即 500）。
    // legacy 模式下 RENAME 不改写任何引用，子表继续指向 products（新表随后创建）。
    $pdo->exec('PRAGMA legacy_alter_table=ON');
    $pdo->exec('PRAGMA foreign_keys=OFF');
    $pdo->exec('ALTER TABLE products RENAME TO __products_old');
    $pdo->exec(<<<'SQL'
CREATE TABLE products (
  id          TEXT PRIMARY KEY,
  sku         TEXT NOT NULL UNIQUE,
  name        TEXT NOT NULL,
  sub         TEXT NOT NULL DEFAULT '',
  cat_id      TEXT NOT NULL REFERENCES categories(id) ON UPDATE CASCADE,
  price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
  was_cents   INTEGER CHECK (was_cents IS NULL OR was_cents >= price_cents),
  badge       TEXT NOT NULL DEFAULT '',
  img         TEXT NOT NULL DEFAULT '',
  rating      REAL NOT NULL DEFAULT 0 CHECK (rating >= 0 AND rating <= 5),
  sold        INTEGER NOT NULL DEFAULT 0 CHECK (sold >= 0),
  stock       INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
  description TEXT NOT NULL DEFAULT '',
  story       TEXT NOT NULL DEFAULT '',
  flaws       TEXT NOT NULL DEFAULT '',
  type        TEXT NOT NULL DEFAULT 'physical' CHECK (type IN ('virtual', 'physical', 'yulan')),
  delivery_template TEXT NOT NULL DEFAULT '',
  yulan_account_limit INTEGER NOT NULL DEFAULT 0 CHECK (yulan_account_limit >= 0),
  yulan_ip_restrict   INTEGER NOT NULL DEFAULT 0 CHECK (yulan_ip_restrict IN (0, 1)),
  yulan_server_ip   TEXT NOT NULL DEFAULT '',
  yulan_server_port TEXT NOT NULL DEFAULT '',
  yulan_server_user TEXT NOT NULL DEFAULT '',
  yulan_server_pass TEXT NOT NULL DEFAULT '',
  yulan_rdp_host    TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden', 'soldout')),
  sort        INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );
    // 历史数据的 created_at/updated_at 可能为 NULL（旧库无默认值时），COALESCE 兜底；
    // 旧库若缺 yulan 各列，按默认值补齐（列顺序与 INSERT 列清单严格一一对应）
    $pdo->exec("INSERT INTO products ({$cols}, flaws, yulan_account_limit, yulan_ip_restrict,
                yulan_server_ip, yulan_server_port, yulan_server_user, yulan_server_pass, yulan_rdp_host)
                SELECT
                  id, sku, name, sub, cat_id, price_cents, was_cents, badge, img, rating, sold,
                  stock, description, story, type,
                  COALESCE(delivery_template, ''),
                  status, sort,
                  COALESCE(created_at, datetime('now')),
                  COALESCE(updated_at, datetime('now')),
                  COALESCE(flaws, ''),
                  COALESCE(yulan_account_limit, 0),
                  COALESCE(yulan_ip_restrict, 0),
                  COALESCE(yulan_server_ip, ''), COALESCE(yulan_server_port, ''),
                  COALESCE(yulan_server_user, ''), COALESCE(yulan_server_pass, ''),
                  COALESCE(yulan_rdp_host, '')
                FROM __products_old");
    $pdo->exec('DROP TABLE __products_old');
    $pdo->exec('PRAGMA legacy_alter_table=OFF');
    $pdo->exec('PRAGMA foreign_keys=ON');
    // 重建索引（ALTER 会丢弃原表索引）
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_cat    ON products (cat_id, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_status ON products (status, sort)');
}

/**
 * 修复「早期迁移遗留损伤」：rebuildProductsForYulan 旧版本在重建 products 时，
 * SQLite 默认的 RENAME 会把子表（product_colors / product_sizes / product_specs /
 * product_care / reviews / product_delivery_fields）里 REFERENCES products 的外键
 * 改写成 REFERENCES "__products_old"，而旧表随后被 DROP，导致这些子表的外键指向
 * 不存在的表——之后任何写 products 的语句都会报
 * "no such table: main.__products_old"（表现为后台保存商品/发货配置 500）。
 *
 * 本函数扫描 sqlite_master，凡 DDL 中引用了 __products_old / __deliveries_old 的
 * 子表，按原 DDL（替换为正确表名）重建并搬回数据、重建其索引。幂等：无损伤时不做任何事。
 */
function repairChildTablesAfterRename(PDO $pdo): void
{
    $map = ['__products_old' => 'products', '__deliveries_old' => 'order_deliveries'];
    $rows = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $broken = [];
    foreach ($rows as $r) {
        foreach ($map as $old => $new) {
            if (strpos((string)$r['sql'], $old) !== false) {
                $broken[] = ['name' => (string)$r['name'], 'sql' => (string)$r['sql'], 'old' => $old, 'new' => $new];
                break;
            }
        }
    }
    if ($broken === []) {
        return;
    }

    foreach ($broken as $b) {
        $name = $b['name'];
        $tmp  = "__repair_{$name}";
        echo "检测到表 {$name} 的外键引用了已删除的 {$b['old']}，自动修复……\n";

        // 记录该表的普通索引（RENAME 会丢索引，稍后重建）
        $idx = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name = ? AND sql IS NOT NULL");
        $idx->execute([$name]);
        $idxSqls = $idx->fetchAll(PDO::FETCH_COLUMN);

        $corrected = str_replace($b['old'], $b['new'], $b['sql']);

        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec('PRAGMA legacy_alter_table=ON');
        $pdo->exec("ALTER TABLE \"{$name}\" RENAME TO \"{$tmp}\"");
        $pdo->exec($corrected);
        $pdo->exec("INSERT INTO \"{$name}\" SELECT * FROM \"{$tmp}\"");
        $pdo->exec("DROP TABLE \"{$tmp}\"");
        $pdo->exec('PRAGMA legacy_alter_table=OFF');
        $pdo->exec('PRAGMA foreign_keys=ON');
        foreach ($idxSqls as $isql) {
            $pdo->exec($isql);
        }
        echo "✓ {$name} 已修复（数据完整保留）\n";
    }
}

$steps = [];

// ---------- 1. v5 表 ----------
$v5Tables = ['users', 'user_sessions', 'balance_ledger', 'recharge_orders', 'order_deliveries', 'product_delivery_fields'];
$missing = array_values(array_filter($v5Tables, static fn($t) => !tableExists($pdo, $t)));
if ($missing !== []) {
    $steps[] = '创建 v5 表：' . implode(', ', $missing);
} else {
    echo "✓ v5 表已全部存在\n";
}

// ---------- 2. products 新列 ----------
if (!columnExists($pdo, 'products', 'type')) {
    $steps[] = "products 增加列 type（默认 'physical'）";
} elseif (!productsTypeAllowsYulan($pdo)) {
    $steps[] = "products.type 的 CHECK 扩展为支持 'yulan'（重建表）";
} else {
    echo "✓ products.type 已支持 yulan\n";
}
if (!columnExists($pdo, 'products', 'delivery_template')) {
    $steps[] = "products 增加列 delivery_template";
} else {
    echo "✓ products.delivery_template 已存在\n";
}
if (!columnExists($pdo, 'products', 'yulan_account_limit')) {
    $steps[] = "products 增加列 yulan_account_limit（欲蓝账号限购数）";
} else {
    echo "✓ products.yulan_account_limit 已存在\n";
}
if (!columnExists($pdo, 'products', 'yulan_ip_restrict')) {
    $steps[] = "products 增加列 yulan_ip_restrict（欲蓝 IP 限制开关）";
} else {
    echo "✓ products.yulan_ip_restrict 已存在\n";
}
// v5.5：商品级欲蓝服务器配置（每个欲蓝商品可指向自己的 Windows 服务器）
foreach (['yulan_server_ip', 'yulan_server_port', 'yulan_server_user', 'yulan_server_pass', 'yulan_rdp_host'] as $c) {
    if (!columnExists($pdo, 'products', $c)) {
        $steps[] = "products 增加列 {$c}（商品级欲蓝服务器配置）";
    } else {
        echo "✓ products.{$c} 已存在\n";
    }
}

// v1.1：商品缺点 / 已知不足（后台可逐件填写，前台详情页展示）
if (!columnExists($pdo, 'products', 'flaws')) {
    $steps[] = "products 增加列 flaws（商品缺点 / 已知不足）";
} else {
    echo "✓ products.flaws 已存在\n";
}

// ---------- 2.5 yulan_accounts 表 ----------
if (!tableExists($pdo, 'yulan_accounts')) {
    $steps[] = '创建 yulan_accounts 表（欲蓝已建账号流水）';
} else {
    echo "✓ yulan_accounts 表已存在\n";
}

// ---------- 2.6 order_deliveries.kind CHECK 扩展 ----------
if (!deliveriesKindAllowsYulan($pdo)) {
    $steps[] = "order_deliveries.kind 的 CHECK 扩展为支持 'yulan'（重建表，保留数据）";
} else {
    echo "✓ order_deliveries.kind 已支持 yulan\n";
}

// ---------- 3. orders.user_id ----------
if (!columnExists($pdo, 'orders', 'user_id')) {
    $steps[] = 'orders 增加列 user_id（历史订单为 NULL）';
} else {
    echo "✓ orders.user_id 已存在\n";
}

if ($steps === []) {
    // 即使"无需迁移"，也要修复早期迁移遗留的外键损伤（幂等）
    try {
        repairChildTablesAfterRename($pdo);
    } catch (Throwable $e) {
        fwrite(STDERR, "外键损伤修复失败：" . $e->getMessage() . "\n");
        exit(1);
    }
    echo "\n无需迁移，数据库已是 v5 结构。\n";
    exit(0);
}

echo "\n待执行步骤：\n";
foreach ($steps as $i => $s) {
    echo '  ' . ($i + 1) . ". {$s}\n";
}

if ($dryRun) {
    echo "\nDRY RUN 结束，未修改数据库。\n";
    exit(0);
}

// ---------- 执行 ----------
$schemaV5 = file_get_contents(dirname(__DIR__) . '/database/schema_v5.sql');
if ($schemaV5 === false) {
    fwrite(STDERR, "读取 database/schema_v5.sql 失败\n");
    exit(1);
}

try {
    $pdo->beginTransaction();

    // 重建类步骤不能在事务内做（SQLite 事务内无法切换 foreign_keys pragma，
    // 且 RENAME/DROP 父表会触发外键检查），先记下标志，提交后在事务外执行。
    $needRebuildProducts   = false;
    $needRebuildDeliveries = false;

    // v5 表（CREATE TABLE IF NOT EXISTS，天然幂等）
    $pdo->exec($schemaV5);

    // products 新列
    if (!columnExists($pdo, 'products', 'type')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN type TEXT NOT NULL DEFAULT 'physical' CHECK (type IN ('virtual','physical','yulan'))");
    } elseif (!productsTypeAllowsYulan($pdo)) {
        // 旧库：type 列已存在但 CHECK 只允许 virtual/physical，需重建表以放开 yulan
        $needRebuildProducts = true;
    }
    if (!columnExists($pdo, 'products', 'delivery_template')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN delivery_template TEXT NOT NULL DEFAULT ''");
    }
    if (!columnExists($pdo, 'products', 'yulan_account_limit')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN yulan_account_limit INTEGER NOT NULL DEFAULT 0 CHECK (yulan_account_limit >= 0)");
    }
    if (!columnExists($pdo, 'products', 'yulan_ip_restrict')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN yulan_ip_restrict INTEGER NOT NULL DEFAULT 0 CHECK (yulan_ip_restrict IN (0, 1))");
    }
    // v5.5：商品级欲蓝服务器配置（每个商品可指向自己的 Windows 服务器，留空回退全局）
    foreach ([
        'yulan_server_ip'   => "TEXT NOT NULL DEFAULT ''",
        'yulan_server_port' => "TEXT NOT NULL DEFAULT ''",
        'yulan_server_user' => "TEXT NOT NULL DEFAULT ''",
        'yulan_server_pass' => "TEXT NOT NULL DEFAULT ''",
        'yulan_rdp_host'    => "TEXT NOT NULL DEFAULT ''",
    ] as $col => $def) {
        if (!columnExists($pdo, 'products', $col)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$col} {$def}");
        }
    }

    // v1.1：商品缺点 / 已知不足（多行文本，每行一条；留空则前台隐藏该栏）
    if (!columnExists($pdo, 'products', 'flaws')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN flaws TEXT NOT NULL DEFAULT ''");
    }

    // yulan_accounts 表
    if (!tableExists($pdo, 'yulan_accounts')) {
        $pdo->exec(<<<'SQL'
CREATE TABLE yulan_accounts (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_item_id   INTEGER NOT NULL,
  product_id      TEXT NOT NULL,
  account         TEXT NOT NULL,
  role            TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
  status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'created', 'failed')),
  detail          TEXT NOT NULL DEFAULT '',
  created_at      TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_yulan_accounts_order  ON yulan_accounts (order_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_yulan_accounts_product ON yulan_accounts (product_id, account)');
    }

    // order_deliveries.kind CHECK 扩展（旧 v5 表只允许 virtual/physical，写 yulan 发货内容会触发约束失败）
    if (tableExists($pdo, 'order_deliveries') && !deliveriesKindAllowsYulan($pdo)) {
        $needRebuildDeliveries = true;
    }

    // orders.user_id（SQLite 的 ADD COLUMN 不能加外键约束，这里只加列 + 索引）
    if (!columnExists($pdo, 'orders', 'user_id')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN user_id INTEGER');
        // v5.5：补 done_at（状态机推进到 done 时要写这一列）
        if (!columnExists($pdo, 'orders', 'done_at')) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN done_at TEXT');
        }
        // 用户名大小写不敏感唯一索引（与全新安装保持一致）
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_username_nocase ON users (username COLLATE NOCASE)');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id, id DESC)');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "迁移失败，已回滚：" . $e->getMessage() . "\n");
    exit(1);
}

// ---------- 重建类步骤（事务外 + 临时关闭外键检查） ----------
try {
    // 先修复早期迁移遗留的外键损伤（幂等，无损伤时跳过）
    repairChildTablesAfterRename($pdo);
    if ($needRebuildProducts) {
        echo "重建 products 表（放开 type CHECK，保留数据）……\n";
        // 孤儿库兜底：products 新表带 REFERENCES categories，插入前确保该表存在
        if (!tableExists($pdo, 'categories')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id TEXT PRIMARY KEY, label TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','hidden')))");
        }
        rebuildProductsForYulan($pdo);
    }
    if ($needRebuildDeliveries) {
        echo "重建 order_deliveries 表（放开 kind CHECK，保留数据）……\n";
        rebuildDeliveriesForYulan($pdo);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "重建失败（可从 .bak 备份恢复后重试）：" . $e->getMessage() . "\n");
    exit(1);
}

echo "\n迁移完成。\n";
echo "提示：历史订单 user_id 为 NULL，仍可通过「订单号 + token」查询；\n";
echo "      新订单必须登录后创建，自动写入 user_id。\n";
