<?php
/**
 * 数据库初始化（命令行）
 *
 *   php bin/init_db.php            # 建表（v1.2 起默认「干净安装」：无演示分类/商品，幂等）
 *   php bin/init_db.php --reset    # 删库重建（会清空订单）
 *   php bin/init_db.php --with-demo # 额外灌入演示分类/商品（想看示例站时用）
 *
 * 与网页安装向导的关系：
 *   - 网页 /install 走 /api/install（同一套 Seeder），同样默认干净安装
 *   - 命令行装的站同样会写 config/installed.lock，因此两条路径不会互相打架，
 *     装完之后 /install 一律提示「已安装」并拒绝再次执行。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/Response.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/Audit.php';
require_once dirname(__DIR__) . '/src/AdminAuth.php';
require_once dirname(__DIR__) . '/src/Installer.php';
require_once dirname(__DIR__) . '/database/Seeder.php';

$cfg = require dirname(__DIR__) . '/config/config.php';
$dbPath   = $cfg['db_path'];
$reset    = in_array('--reset', $argv ?? [], true);
$withDemo = in_array('--with-demo', $argv ?? [], true);

if (!is_dir(dirname($dbPath)) && !mkdir(dirname($dbPath), 0770, true) && !is_dir(dirname($dbPath))) {
    fwrite(STDERR, "无法创建目录 " . dirname($dbPath) . "\n");
    exit(1);
}

if ($reset && is_file($dbPath)) {
    // WAL 模式下的伴生文件一并清理
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    echo "已删除旧数据库\n";
}

$isNew = !is_file($dbPath);

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec('PRAGMA foreign_keys = ON');

// ---------- 建表 ----------
$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "读取 schema.sql 失败\n");
    exit(1);
}
$pdo->exec($schema);
echo "表结构已就绪\n";

// ---------- v5 增量表（账号 / 余额 / 发货 / 充值） ----------
$schemaV5 = file_get_contents(dirname(__DIR__) . '/database/schema_v5.sql');
if ($schemaV5 === false) {
    fwrite(STDERR, "读取 schema_v5.sql 失败\n");
    exit(1);
}
$pdo->exec($schemaV5);
echo "v5 表结构已就绪（users / user_sessions / balance_ledger / recharge_orders / order_deliveries / product_delivery_fields）\n";

// ---------- v1.4 增量表（上游 / 云主机 / 工单） ----------
$schemaV140 = file_get_contents(dirname(__DIR__) . '/database/schema_v140.sql');
if ($schemaV140 === false) {
    fwrite(STDERR, "读取 schema_v140.sql 失败\n");
    exit(1);
}
$pdo->exec($schemaV140);
echo "v1.4 表结构已就绪（upstreams / upstream_products / cloud_vms / tickets / ticket_replies）\n";

// ---------- 灌数据（委托给 Seeder，与安装向导共用同一实现） ----------
// v1.2：默认干净安装（不灌演示分类/商品），仅 --with-demo 时灌入示例数据。
try {
    Seeder::run($pdo, $isNew || $reset, $withDemo);
} catch (Throwable $e) {
    fwrite(STDERR, "灌数据失败：" . $e->getMessage() . "\n");
    exit(1);
}

// ---------- 初始管理员 ----------
// 密码优先取 SHOP_ADMIN_PASSWORD；没配就随机生成并打印一次（绝不用 admin/123456 这类弱默认值）
$adminCfg = $cfg['admin'];
$generatedPw = null;
$adminExisted = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0;
try {
    $generatedPw = AdminAuth::ensureInitialAdmin(
        (string)$adminCfg['initial_user'],
        (string)$adminCfg['initial_password']
    );
} catch (Throwable $e) {
    fwrite(STDERR, "创建管理员失败：" . $e->getMessage() . "\n");
    exit(1);
}

// ---------- 写安装锁（命令行装的站也不允许再从网页重装） ----------
if (Installer::hasAdmin()) {
    $lockOk = Installer::writeLock((string)$adminCfg['initial_user']);
    if (!$lockOk) {
        fwrite(STDERR, "⚠️  安装锁写入失败（config/ 不可写），网页安装入口可能会被误认为未安装\n");
    }
}

$counts = [
    '分类' => (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
    '商品' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    '颜色' => (int)$pdo->query('SELECT COUNT(*) FROM product_colors')->fetchColumn(),
    '规格' => (int)$pdo->query('SELECT COUNT(*) FROM product_sizes')->fetchColumn(),
    '参数' => (int)$pdo->query('SELECT COUNT(*) FROM product_specs')->fetchColumn(),
    '评价' => (int)$pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn(),
    '订单' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    '用户' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    '余额流水' => (int)$pdo->query('SELECT COUNT(*) FROM balance_ledger')->fetchColumn(),
];

echo "种子数据已写入：\n";
foreach ($counts as $k => $v) {
    echo "  {$k}: {$v}\n";
}
echo "数据库文件：{$dbPath}\n";

if ($generatedPw !== null) {
    echo "\n";
    echo "============================================================\n";
    echo "  已创建后台管理员（密码只显示这一次，请立即抄走）\n";
    echo "    账号：{$adminCfg['initial_user']}\n";
    echo "    密码：{$generatedPw}\n";
    echo "  登录地址：/admin/\n";
    echo "  首次登录后请立即修改密码。\n";
    echo "============================================================\n";
} elseif ($adminExisted) {
    echo "\n后台管理员已存在，未做修改。如需重置：php bin/create_admin.php --reset\n";
} else {
    echo "\n已创建后台管理员：账号 {$adminCfg['initial_user']}（密码取自 SHOP_ADMIN_PASSWORD）\n";
    echo "登录地址：/admin/\n";
}

// ---------------------------------------------------------------------------
// CLI 以 root 安装时，把数据库文件与运行时目录的属主一并移交给 Web 服务用户。
// 只移交安装锁是不够的：data/shop.sqlite 若仍是 root:root 0644，PHP-FPM 读得到
// 却写不了，表现为「前台能浏览、一下单 / 注册 / 支付就 500」。
// 非 root 运行时 handoffToWebUser() 会直接返回，不会改动任何权限。
// ---------------------------------------------------------------------------
Installer::handoffToWebUser($dbPath);
Installer::handoffToWebUser(dirname($dbPath));
Installer::handoffToWebUser(dirname(Installer::lockPath()));
// 命令行安装的站不需要网页安装密钥，顺手清理可能残留的令牌文件
Installer::consumeInstallToken();

echo "\n完成。启动服务：php -S 127.0.0.1:8090 -t public public/router.php\n";
