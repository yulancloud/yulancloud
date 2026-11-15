#!/usr/bin/env bash
# =============================================================
# 欲蓝财务魔方 v5.5 —— 端到端测试（API 层）
#
# 覆盖：
#   1. 后台 yulan 服务器配置（口令只写不读，不回显）
#   2. yulan 商品创建（限购 / IP 限制配置）
#   3. 下单建号（真连 Windows APP dry-run 模式，验证 HTTP 契约与角色决策）
#   4. ¥0 免费商品：免运费 + free-pay 直接支付成功（yulan 与虚拟各一）
#   5. 交付内容：连接地址 + 账号（不含密码）；yulan_accounts 只存账号
#   6. 账号限购（单用户）/ IP 限制（防开小号）/ 账号去重
#   7. 参数校验：非法账号名 / 弱密码
#   8. mock 模式：yulan_mock=1 时命令写入 data/yulan_mock_queue.jsonl
#   9. migrate.php 对已含 yulan 结构的库幂等执行
#
# 用法：bash tests/e2e_yulan.sh
# 依赖：php、python3、curl、jq（均已在环境内）
# =============================================================
set -u
cd "$(dirname "$0")/.."

ROOT="$(pwd)"
BASE="http://127.0.0.1:8101"
APP_PORT=8321
TOKEN="test-secret-123"
ADMIN_PW="test-admin-123"
TESTDB="/tmp/yulan_e2e/shop.sqlite"
PASS=0; FAIL=0

# ---------- 断言工具 ----------
ok()   { PASS=$((PASS+1)); echo "  ✅ $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  ❌ $1"; }
assert_eq() { # assert_eq 描述 期望 实际
  if [ "$2" = "$3" ]; then ok "$1"; else bad "$1（期望 [$2]，实际 [$3]）"; fi
}
assert_contains() { # assert_contains 描述 子串 整串
  if echo "$3" | grep -qF "$2"; then ok "$1"; else bad "$1（未包含 [$2]）"; fi
}
assert_not_contains() {
  if echo "$3" | grep -qF "$2"; then bad "$1（不应包含 [$2]，却出现了）"; else ok "$1"; fi
}
jq_get() { echo "$1" | jq -r "$2" 2>/dev/null; }

# ---------- 清理 ----------
LOCK_BAK="/tmp/yulan_installed.lock.bak"
cleanup() {
  [ -f "$LOCK_BAK" ] && cp "$LOCK_BAK" config/installed.lock && rm -f "$LOCK_BAK"
  for pid in "${PHP_PID:-}" "${APP_PID:-}" "${PHP2_PID:-}" "${APP2_PID:-}"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null
  done
  wait 2>/dev/null
}
trap cleanup EXIT

echo "=================================================="
echo " 准备测试环境"
echo "=================================================="
cp config/installed.lock "$LOCK_BAK" 2>/dev/null   # init_db 会重写安装锁，测完恢复
rm -rf /tmp/yulan_e2e && mkdir -p /tmp/yulan_e2e
rm -f data/yulan_mock_queue.jsonl logs/yulan_cube.log 2>/dev/null

# 1) 初始化独立测试库（不动 data/shop.sqlite）
echo "[1] 初始化测试数据库..."
SHOP_DB_PATH="$TESTDB" SHOP_ADMIN_PASSWORD="$ADMIN_PW" php bin/init_db.php >/tmp/yulan_init.log 2>&1 \
  || { echo "init_db 失败："; cat /tmp/yulan_init.log; exit 1; }
echo "  测试库就绪：$TESTDB"

# 2) 启动 PHP 内置服务器
echo "[2] 启动 PHP 测试服务器（:8101）..."
SHOP_DB_PATH="$TESTDB" SHOP_ENV=development \
  php -d disable_functions=putenv -S 127.0.0.1:8101 -t public public/router.php \
  >/tmp/yulan_php.log 2>&1 &
PHP_PID=$!
sleep 1

# 3) 启动 Windows APP（dry-run：不真正执行 net 命令；admin_products 决定角色）
echo "[3] 启动欲蓝财务魔方 APP（dry-run，:$APP_PORT）..."
cat > /tmp/yulan_e2e/app_config.json <<EOF
{
  "listen_host": "127.0.0.1",
  "listen_port": $APP_PORT,
  "token": "$TOKEN",
  "default_role": "user",
  "admin_products": ["yulan-cube-admin"],
  "server_ip": "203.0.113.10"
}
EOF
python3 windows_app/yulan_cube_app.py --config /tmp/yulan_e2e/app_config.json \
  --allow-non-admin --dry-run --no-console >/tmp/yulan_app.log 2>&1 &
APP_PID=$!
sleep 1.2
curl -s "http://127.0.0.1:$APP_PORT/api/v1/health" >/dev/null || { echo "APP 未启动"; exit 1; }

ADMIN_JAR=/tmp/yulan_e2e/admin.jar
U1_JAR=/tmp/yulan_e2e/u1.jar
U2_JAR=/tmp/yulan_e2e/u2.jar

echo ""
echo "=================================================="
echo " T1 后台管理员登录"
echo "=================================================="
R=$(curl -s -c "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/login" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"admin\",\"password\":\"$ADMIN_PW\"}")
assert_eq "管理员登录成功" "true" "$(jq_get "$R" '.ok')"

echo ""
echo "=================================================="
echo " T2 欲蓝服务器配置（口令只写不读）"
echo "=================================================="
R=$(curl -s -b "$ADMIN_JAR" -X PUT -H "Origin: $BASE" "$BASE/api/admin/settings" \
  -H "Content-Type: application/json" \
  -d '{"settings":{"yulan_server_ip":"127.0.0.1","yulan_server_port":"8321","yulan_server_user":"yulan-op","yulan_server_pass":"'"$TOKEN"'","yulan_rdp_host":"rdp.yulan.example.com","yulan_mock":"0"}}')
assert_eq "保存 yulan 服务器配置" "true" "$(jq_get "$R" '.ok')"

R=$(curl -s -b "$ADMIN_JAR" "$BASE/api/admin/settings")
S=$(jq_get "$R" '.data.settings // .data')
assert_eq "yulan_server_pass 值为空（口令绝不回显）" "" "$(echo "$S" | jq -r '.yulan_server_pass')"
assert_eq "返回 yulan_server_pass_set=true" "true" "$(echo "$S" | jq -r '.yulan_server_pass_set')"

echo ""
echo "=================================================="
echo " T3 创建欲蓝商品（¥0 限购件 / ¥500 管理员件）与 ¥0 虚拟商品"
echo "=================================================="
CAT=$(curl -s "$BASE/api/categories" | jq -r '[.data.categories[] | select(.id != "all")][0].id')
[ -z "$CAT" ] && { echo "未取到分类"; exit 1; }
mkprod() { # id price
  curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/products" \
    -H "Content-Type: application/json" \
    -d "{\"id\":\"$1\",\"sku\":\"$1\",\"name\":\"$1\",\"cat_id\":\"$CAT\",\"price_cents\":$2,\"stock\":99,\"sub\":\"测试\"}"
}
R=$(mkprod yulan-cube-free 0);   assert_eq "创建 ¥0 欲蓝商品"   "true" "$(jq_get "$R" '.ok')"
R=$(mkprod yulan-cube-admin 500); assert_eq "创建 ¥500 欲蓝商品" "true" "$(jq_get "$R" '.ok')"
R=$(mkprod virt-free 0);          assert_eq "创建 ¥0 虚拟商品"   "true" "$(jq_get "$R" '.ok')"

setdlv() { # id type limit ipr
  curl -s -b "$ADMIN_JAR" -X PUT -H "Origin: $BASE" "$BASE/api/admin/products/$1/delivery" \
    -H "Content-Type: application/json" \
    -d "{\"type\":\"$2\",\"template\":\"\",\"yulan_account_limit\":$3,\"yulan_ip_restrict\":$4}"
}
R=$(setdlv yulan-cube-free yulan 1 1)
assert_eq "¥0 欲蓝商品设为 yulan 类型（限购1 + IP限制）" "true" "$(jq_get "$R" '.ok')"
assert_eq "回读 yulan_account_limit=1" "1" "$(jq_get "$R" '.data.yulan_account_limit // .yulan_account_limit')"
R=$(setdlv yulan-cube-admin yulan 0 0)
assert_eq "¥500 欲蓝商品设为 yulan 类型（不限购）" "true" "$(jq_get "$R" '.ok')"
R=$(setdlv virt-free virtual 0 0)
assert_eq "¥0 虚拟商品设为 virtual 类型" "true" "$(jq_get "$R" '.ok')"

echo ""
echo "=================================================="
echo " T4 前台用户注册 / 登录"
echo "=================================================="
reg() { curl -s -X POST -H "Origin: $BASE" "$BASE/api/console/register" -H "Content-Type: application/json" \
  -d "{\"username\":\"$1\",\"password\":\"UserPass123\",\"display_name\":\"$1\"}"; }
R=$(reg u1test); assert_eq "注册用户 u1test" "true" "$(jq_get "$R" '.ok')"
R=$(reg u2test); assert_eq "注册用户 u2test" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -c "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/console/login" -H "Content-Type: application/json" \
  -d '{"username":"u1test","password":"UserPass123"}')
assert_eq "u1test 登录" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -c "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/console/login" -H "Content-Type: application/json" \
  -d '{"username":"u2test","password":"UserPass123"}')
assert_eq "u2test 登录" "true" "$(jq_get "$R" '.ok')"

echo ""
echo "=================================================="
echo " T5 报价：欲蓝商品免运费"
echo "=================================================="
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders/quote" -H "Content-Type: application/json" \
  -d '{"items":[{"id":"yulan-cube-free","qty":1}]}')
assert_eq "yulan 商品报价 ok" "true" "$(jq_get "$R" '.ok')"
assert_eq "yulan 订单运费为 0" "0" "$(jq_get "$R" '.data.ship // .ship')"

echo ""
echo "=================================================="
echo " T6 ¥0 欲蓝商品下单（建号走真实 HTTP 契约 → APP dry-run）"
echo "=================================================="
ORDER_BODY='{"name":"张三","phone":"13800138000","address":"北京市朝阳区测试路 1 号","note":"","items":[{"id":"yulan-cube-free","qty":1,"account":"alice","password":"Secret123"}]}'
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" -d "$ORDER_BODY")
assert_eq "下单成功" "true" "$(jq_get "$R" '.ok')"
FREE_NO=$(jq_get "$R" '.data.order_no // .order_no')
assert_eq "订单金额为 0（免费）" "0" "$(jq_get "$R" '.data.total // .total')"
echo "  订单号：$FREE_NO"

R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders/$FREE_NO/free-pay" -H "Content-Type: application/json" -d '{}')
assert_eq "¥0 订单 free-pay 直接支付成功" "true" "$(jq_get "$R" '.ok')"

R=$(curl -s -b "$U1_JAR" "$BASE/api/console/orders/$FREE_NO")
assert_eq "订单状态已推进（免运费自动发货）" "shipped" "$(jq_get "$R" '.data.status // .status')"
D=$(jq_get "$R" '.data.deliveries[0] // .deliveries[0]')
assert_eq "交付 kind=yulan" "yulan" "$(echo "$D" | jq -r '.kind')"
assert_eq "交付标签=欲蓝财务魔方" "欲蓝财务魔方" "$(echo "$D" | jq -r '.kind_label')"
assert_contains "交付内容含连接地址" "rdp.yulan.example.com" "$(echo "$D" | jq -r '.content')"
assert_contains "交付内容含账号 alice" "alice" "$(echo "$D" | jq -r '.content')"
assert_not_contains "交付内容【不】含明文密码" "Secret123" "$(echo "$D" | jq -r '.content')"

echo ""
echo "=================================================="
echo " T7 yulan_accounts 落库检查（角色由 APP 决定 = user）"
echo "=================================================="
R=$(php -r "
\$pdo = new PDO('sqlite:$TESTDB');
\$r = \$pdo->query('SELECT account, role, status FROM yulan_accounts ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
echo json_encode(\$r, JSON_UNESCAPED_UNICODE);")
assert_eq "yulan_accounts.account=alice" "alice" "$(echo "$R" | jq -r '.account')"
assert_eq "APP 决策角色 role=user" "user" "$(echo "$R" | jq -r '.role')"
assert_eq "建号状态 status=created" "created" "$(echo "$R" | jq -r '.status')"

ALLTEXT=$(php -r "
\$pdo = new PDO('sqlite:$TESTDB');
foreach (['yulan_accounts','orders','order_items','order_deliveries'] as \$t) {
  foreach (\$pdo->query(\"SELECT * FROM \$t\", PDO::FETCH_ASSOC) as \$row) echo json_encode(\$row);
}")
assert_not_contains "整库不含用户密码明文（服务器不存储密码）" "Secret123" "$ALLTEXT"

echo ""
echo "=================================================="
echo " T8 限购 / IP 限制 / 账号去重"
echo "=================================================="
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"张三","phone":"13800138000","address":"北京市朝阳区测试路 1 号","items":[{"id":"yulan-cube-free","qty":1,"account":"alice2","password":"Secret123"}]}')
assert_eq "u1 再买同商品被限购拦截" "yulan_limit" "$(jq_get "$R" '.error.code')"

R=$(curl -s -b "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"李四","phone":"13900139000","address":"上海市浦东新区测试路 2 号","items":[{"id":"yulan-cube-free","qty":1,"account":"alice3","password":"Secret123"}]}')
assert_eq "u2 同 IP 购买被 IP 限制拦截（防开小号）" "yulan_ip_limit" "$(jq_get "$R" '.error.code')"

R=$(curl -s -b "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"李四","phone":"13900139000","address":"上海市浦东新区测试路 2 号","items":[{"id":"yulan-cube-admin","qty":1,"account":"carol","password":"Secret456"}]}')
assert_eq "u2 购买 ¥500 欲蓝商品（不限购）下单成功" "true" "$(jq_get "$R" '.ok')"
PAID_NO=$(jq_get "$R" '.data.order_no // .order_no')

R=$(curl -s -b "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"李四","phone":"13900139000","address":"上海市浦东新区测试路 2 号","items":[{"id":"yulan-cube-admin","qty":1,"account":"carol","password":"Secret456"}]}')
assert_eq "同商品重复账号被去重拦截" "yulan_account_taken" "$(jq_get "$R" '.error.code')"

R=$(php -r "
\$pdo = new PDO('sqlite:$TESTDB');
\$r = \$pdo->query(\"SELECT role, status FROM yulan_accounts WHERE account='carol'\")->fetch(PDO::FETCH_ASSOC) ?: null;
echo json_encode(\$r, JSON_UNESCAPED_UNICODE);")
assert_eq "APP 决策管理员商品 → role=admin" "admin" "$(echo "$R" | jq -r '.role')"
assert_eq "carol 建号状态=created" "created" "$(echo "$R" | jq -r '.status')"

R=$(curl -s -b "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders/$PAID_NO/free-pay" -H "Content-Type: application/json" -d '{}')
assert_eq "¥500 订单 free-pay 被拒绝（仅限 0 元单）" "false" "$(jq_get "$R" '.ok')"

echo ""
echo "=================================================="
echo " T9 参数校验（非法账号名 / 弱密码）"
echo "=================================================="
R=$(curl -s -b "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"李四","phone":"13900139000","address":"上海市浦东新区测试路 2 号","items":[{"id":"yulan-cube-admin","qty":1,"account":"bad name!","password":"Secret456"}]}')
assert_eq "非法账号名被拒绝" "yulan_invalid_account" "$(jq_get "$R" '.error.code')"

R=$(curl -s -b "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"李四","phone":"13900139000","address":"上海市浦东新区测试路 2 号","items":[{"id":"yulan-cube-admin","qty":1,"account":"dave","password":"123"}]}')
assert_eq "弱密码（<6 位）被拒绝" "yulan_invalid_password" "$(jq_get "$R" '.error.code')"

echo ""
echo "=================================================="
echo " T10 ¥0 虚拟商品：免费直付同样生效"
echo "=================================================="
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"张三","phone":"13800138000","address":"北京市朝阳区测试路 1 号","items":[{"id":"virt-free","qty":1}]}')
assert_eq "¥0 虚拟商品下单成功" "true" "$(jq_get "$R" '.ok')"
VIRT_NO=$(jq_get "$R" '.data.order_no // .order_no')
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders/$VIRT_NO/free-pay" -H "Content-Type: application/json" -d '{}')
assert_eq "¥0 虚拟订单 free-pay 成功" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$U1_JAR" "$BASE/api/console/orders/$VIRT_NO")
assert_eq "订单状态=shipped" "shipped" "$(jq_get "$R" '.data.status // .status')"

echo ""
echo "=================================================="
echo " T11 mock 模式（yulan_mock=1）：命令写入队列文件"
echo "=================================================="
R=$(curl -s -b "$ADMIN_JAR" -X PUT -H "Origin: $BASE" "$BASE/api/admin/settings" -H "Content-Type: application/json" \
  -d '{"settings":{"yulan_mock":"1"}}')
assert_eq "切换 mock 模式" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"李四","phone":"13900139000","address":"上海市浦东新区测试路 2 号","items":[{"id":"yulan-cube-admin","qty":1,"account":"eve","password":"MockPass123"}]}')
assert_eq "mock 模式下单成功" "true" "$(jq_get "$R" '.ok')"
if [ -f data/yulan_mock_queue.jsonl ] && grep -q '"account":"eve"' data/yulan_mock_queue.jsonl; then
  ok "模拟队列文件已记录建号命令"
else
  bad "模拟队列文件未记录"
fi

echo ""
echo "=================================================="
echo " T12 migrate.php 幂等（已含 yulan 的库重复执行不报错）"
echo "=================================================="
if SHOP_DB_PATH="$TESTDB" php bin/migrate.php >/tmp/yulan_migrate.log 2>&1; then
  ok "migrate.php 对已升级库幂等通过"
else
  bad "migrate.php 执行失败"; cat /tmp/yulan_migrate.log
fi

echo ""
echo "=================================================="
echo " T13 migrate.php 修复旧结构库（kind/type CHECK 无 yulan 的 v5 库）"
echo "=================================================="
OLDDB=/tmp/yulan_e2e/old.sqlite
rm -f "$OLDDB" "$OLDDB"-wal "$OLDDB"-shm
php -r "
\$pdo = new PDO('sqlite:$OLDDB');
\$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, order_no TEXT)');
\$pdo->exec(\"CREATE TABLE order_deliveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_no TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'virtual' CHECK (kind IN ('virtual','physical')),
  content TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'delivered' CHECK (status IN ('pending','delivered','revoked')),
  delivered_at TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)\");
\$pdo->exec(\"INSERT INTO orders (order_no) VALUES ('OLD01')\");
\$pdo->exec(\"INSERT INTO order_deliveries (order_id, order_no, kind, content) VALUES (1, 'OLD01', 'virtual', '历史发货内容')\");
\$pdo->exec(\"CREATE TABLE products (id TEXT PRIMARY KEY, sku TEXT, name TEXT, sub TEXT DEFAULT '', cat_id TEXT, price_cents INTEGER DEFAULT 0, was_cents INTEGER, badge TEXT DEFAULT '', img TEXT DEFAULT '', rating REAL DEFAULT 0, sold INTEGER DEFAULT 0, stock INTEGER DEFAULT 0, description TEXT DEFAULT '', story TEXT DEFAULT '', type TEXT NOT NULL DEFAULT 'physical' CHECK (type IN ('virtual','physical')), delivery_template TEXT DEFAULT '', status TEXT DEFAULT 'active', sort INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)\");
\$pdo->exec(\"INSERT INTO products (id, sku, name, cat_id, type) VALUES ('p1', 'P1', '旧商品', 'c1', 'virtual')\");
\$pdo->exec(\"CREATE TABLE product_delivery_fields (
  product_id TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  label TEXT NOT NULL, value TEXT NOT NULL DEFAULT '', sort INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, label))\");
\$pdo->exec(\"INSERT INTO product_delivery_fields (product_id, label, value) VALUES ('p1', '卡号', 'OLD-123')\");
" && ok "旧结构库已构造（kind/type CHECK 均无 yulan，含历史数据 + 子表外键）"
if SHOP_DB_PATH="$OLDDB" php bin/migrate.php >/tmp/yulan_migrate_old.log 2>&1; then
  ok "migrate.php 对旧结构库迁移成功"
else
  bad "migrate.php 修复旧库失败"; tail -5 /tmp/yulan_migrate_old.log
fi
KINDOK=$(php -r "
\$pdo = new PDO('sqlite:$OLDDB');
\$sql = \$pdo->query(\"SELECT sql FROM sqlite_master WHERE name='order_deliveries'\")->fetchColumn();
\$psql = \$pdo->query(\"SELECT sql FROM sqlite_master WHERE name='products'\")->fetchColumn();
\$row = \$pdo->query('SELECT kind, content FROM order_deliveries')->fetch(PDO::FETCH_ASSOC);
\$prod = \$pdo->query(\"SELECT type FROM products WHERE id='p1'\")->fetchColumn();
echo (strpos(\$sql,'yulan')!==false && strpos(\$psql,'yulan')!==false
      && \$row && \$row['kind']==='virtual' && \$row['content']==='历史发货内容'
      && \$prod==='virtual') ? 'yes' : 'no';")
assert_eq "两张表 CHECK 已放开 + 历史数据完整保留" "yes" "$KINDOK"
# RENAME 不得把子表外键改写成 __products_old（2026-11-14 线上事故：改写后写 products 即 500）
OLDREF=$(php -r "
\$pdo = new PDO('sqlite:$OLDDB');
\$n = \$pdo->query(\"SELECT COUNT(*) FROM sqlite_master WHERE sql LIKE '%__products_old%' OR sql LIKE '%__deliveries_old%' OR sql LIKE '%__repair_%'\")->fetchColumn();
\$f = \$pdo->query(\"SELECT sql FROM sqlite_master WHERE name='product_delivery_fields'\")->fetchColumn();
echo (\$n == 0 && strpos((string)\$f, 'REFERENCES products') !== false) ? 'yes' : 'no';")
assert_eq "子表外键仍指向 products（未被 RENAME 改写成旧表名）" "yes" "$OLDREF"
FIELDDATA=$(php -r "
\$pdo = new PDO('sqlite:$OLDDB');
\$r = \$pdo->query(\"SELECT value FROM product_delivery_fields WHERE product_id='p1' AND label='卡号'\")->fetchColumn();
echo \$r === 'OLD-123' ? 'yes' : 'no';")
assert_eq "子表历史数据在重建后完整保留" "yes" "$FIELDDATA"

echo ""
echo "=================================================="
echo " T14 商品级服务器配置（每个商品指向自己的 Windows 服务器）"
echo "=================================================="
# 独立环境：第二个库 + 第二个 PHP 服务器(:8104) + 第二个 APP(:8322，专用口令)
SRVDB=/tmp/yulan_e2e/srv.sqlite
rm -f "$SRVDB" "$SRVDB"-wal "$SRVDB"-shm
SHOP_DB_PATH="$SRVDB" SHOP_ADMIN_PASSWORD="$ADMIN_PW" php bin/init_db.php >/dev/null 2>&1 \
  || bad "T14 测试库初始化失败"
SHOP_DB_PATH="$SRVDB" SHOP_ENV=development \
  php -d disable_functions=putenv -S 127.0.0.1:8104 -t public public/router.php >/tmp/yulan_php2.log 2>&1 &
PHP2_PID=$!
cat > /tmp/yulan_e2e/app2_config.json <<EOF
{"listen_host":"127.0.0.1","listen_port":8322,"token":"product-pass-456","default_role":"user","admin_products":[]}
EOF
python3 windows_app/yulan_cube_app.py --config /tmp/yulan_e2e/app2_config.json \
  --allow-non-admin --dry-run --no-console >/tmp/yulan_app2.log 2>&1 &
APP2_PID=$!
sleep 1.2

A2JAR=/tmp/yulan_e2e/a2.jar
U3JAR=/tmp/yulan_e2e/u3.jar
B2="http://127.0.0.1:8104"
R=$(curl -s -c "$A2JAR" -X POST -H "Origin: $B2" "$B2/api/admin/login" -H "Content-Type: application/json" \
  -d "{\"username\":\"admin\",\"password\":\"$ADMIN_PW\"}")
assert_eq "T14 管理员登录" "true" "$(jq_get "$R" '.ok')"

# 商品表单直接选类型（type=yulan 随 saveProduct 提交）
CAT2=$(curl -s "$B2/api/categories" | jq -r '[.data.categories[] | select(.id != "all")][0].id')
R=$(curl -s -b "$A2JAR" -X POST -H "Origin: $B2" "$B2/api/admin/products" -H "Content-Type: application/json" \
  -d "{\"id\":\"yulan-cube-b\",\"sku\":\"yulan-cube-b\",\"name\":\"yulan-cube-b\",\"cat_id\":\"$CAT2\",\"price_cents\":0,\"stock\":99,\"type\":\"yulan\"}")
assert_eq "创建商品时直接选择「欲蓝财务魔方」类型" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$A2JAR" "$B2/api/admin/products/yulan-cube-b")
assert_eq "回读商品类型 = yulan" "yulan" "$(jq_get "$R" '.data.type // .type')"

# 商品级服务器配置：专属 IP/端口/口令/连接地址（与全局无关）
R=$(curl -s -b "$A2JAR" -X PUT -H "Origin: $B2" "$B2/api/admin/products/yulan-cube-b/delivery" -H "Content-Type: application/json" \
  -d '{"type":"yulan","template":"","fields":[],"yulan_account_limit":2,"yulan_ip_restrict":0,"yulan_server_ip":"127.0.0.1","yulan_server_port":"8322","yulan_server_user":"op-b","yulan_server_pass":"product-pass-456","yulan_rdp_host":"rdp.product-b.example.com"}')
assert_eq "保存商品级服务器配置" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$A2JAR" "$B2/api/admin/products/yulan-cube-b/delivery")
D2=$(jq_get "$R" '.data // .')
assert_eq "回读商品级 IP" "127.0.0.1" "$(echo "$D2" | jq -r '.yulan_server_ip')"
assert_eq "回读商品级端口" "8322" "$(echo "$D2" | jq -r '.yulan_server_port')"
assert_not_contains "商品级口令绝不下发（响应无该键）" '"yulan_server_pass":' "$R"
assert_eq "商品级口令状态=已配置" "true" "$(echo "$D2" | jq -r '.yulan_server_pass_set')"

curl -s -c "$U3JAR" -X POST "$B2/api/console/register" -H "Content-Type: application/json" \
  -d '{"username":"u3test","password":"UserPass123"}' >/dev/null
R=$(curl -s -c "$U3JAR" -X POST "$B2/api/console/login" -H "Content-Type: application/json" \
  -d '{"username":"u3test","password":"UserPass123"}')
assert_eq "T14 用户登录" "true" "$(jq_get "$R" '.ok')"

R=$(curl -s -b "$U3JAR" -X POST -H "Origin: $B2" "$B2/api/orders" -H "Content-Type: application/json" \
  -d '{"name":"王五","phone":"13700137000","address":"广州市天河区测试路 3 号","items":[{"id":"yulan-cube-b","qty":1,"account":"grace","password":"Secret789"}]}')
assert_eq "下单走商品级服务器（IP+端口+商品级口令连 APP 成功）" "true" "$(jq_get "$R" '.ok')"
NO3=$(jq_get "$R" '.data.order_no // .order_no')
R=$(curl -s -b "$U3JAR" -X POST -H "Origin: $B2" "$B2/api/orders/$NO3/free-pay" -H "Content-Type: application/json" -d '{}')
assert_eq "商品级 ¥0 订单免费直付" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$U3JAR" "$B2/api/console/orders/$NO3")
D3=$(jq_get "$R" '.data.deliveries[0] // .deliveries[0]')
assert_contains "交付内容用商品级连接地址" "rdp.product-b.example.com" "$(echo "$D3" | jq -r '.content')"
assert_not_contains "交付内容不含商品级口令" "product-pass-456" "$(echo "$D3" | jq -r '.content')"

echo ""
echo "=================================================="
echo " 测试结果：通过 $PASS 项，失败 $FAIL 项"
echo "=================================================="
[ $FAIL -eq 0 ] && echo "🎉 全部通过" || exit 1
