#!/usr/bin/env bash
# =============================================================
# 欲蓝财务 v1.4.1 补丁版 —— 端到端测试（API 层）
#
# 覆盖本补丁修复点：
#   T1 迁移幂等 + v1.4.1 新列存在 + health 版本
#   T2 上游 CRUD：协议可配置字段回显、api_key 只写不读、SSRF 拒绝内网地址
#   T3 mock 模式同步产品（价格单位）
#   T4 核心修复：绑定上游的 yulan 商品下单无需账号密码；支付后由上游开通并交付
#   T5 支付幂等：重复 free-pay 不重复开通
#   T6 cloud 商品支付后开通
#   T7 工单链路 + 权限隔离
#
# 用法：bash tests/e2e_v141.sh
# =============================================================
set -u
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
BASE="http://127.0.0.1:8107"
ADMIN_PW="test-admin-141"
TESTDB="/tmp/yulan_e2e141/shop.sqlite"
PASS=0; FAIL=0

ok(){ PASS=$((PASS+1)); echo "  ✅ $1"; }
bad(){ FAIL=$((FAIL+1)); echo "  ❌ $1"; }
assert_eq(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1（期望[$2] 实际[$3]）"; fi; }
assert_contains(){ if echo "$3" | grep -qF "$2"; then ok "$1"; else bad "$1（未包含[$2]）"; fi; }
assert_not_contains(){ if echo "$3" | grep -qF "$2"; then bad "$1（不应含[$2]）"; else ok "$1"; fi; }
jq_get(){ echo "$1" | jq -r "$2" 2>/dev/null; }

LOCK_BAK="/tmp/yulan141_installed.lock.bak"
cleanup(){
  [ -f "$LOCK_BAK" ] && cp "$LOCK_BAK" config/installed.lock && rm -f "$LOCK_BAK"
  [ -n "${PHP_PID:-}" ] && kill "$PHP_PID" 2>/dev/null
  wait 2>/dev/null
}
trap cleanup EXIT

echo "=================================================="
echo " 准备测试环境"
echo "=================================================="
cp config/installed.lock "$LOCK_BAK" 2>/dev/null
rm -rf /tmp/yulan_e2e141 && mkdir -p /tmp/yulan_e2e141
rm -f data/upstream_mock_queue.jsonl 2>/dev/null

echo "[1] 初始化独立测试库..."
SHOP_DB_PATH="$TESTDB" SHOP_ADMIN_PASSWORD="$ADMIN_PW" php bin/init_db.php >/tmp/y141_init.log 2>&1 \
  || { echo "init_db 失败:"; cat /tmp/y141_init.log; exit 1; }
echo "  测试库就绪"

echo "[2] 迁移幂等复跑..."
SHOP_DB_PATH="$TESTDB" php bin/migrate.php >/tmp/y141_mig.log 2>&1 \
  && ok "migrate.php 幂等通过" || { bad "migrate 失败"; cat /tmp/y141_mig.log; }

echo "[3] 启动测试服务器 :8107..."
SHOP_DB_PATH="$TESTDB" SHOP_ENV=development \
  php -d disable_functions=putenv -S 127.0.0.1:8107 -t public public/router.php \
  >/tmp/y141_php.log 2>&1 &
PHP_PID=$!
sleep 1.2
curl -s "$BASE/api/health" >/dev/null || { echo "服务器未启动"; cat /tmp/y141_php.log; exit 1; }

ADMIN_JAR=/tmp/yulan_e2e141/admin.jar
U1_JAR=/tmp/yulan_e2e141/u1.jar

echo ""
echo "=================================================="
echo " T1 health 版本 / 迁移新列"
echo "=================================================="
R=$(curl -s "$BASE/api/health")
assert_eq "health version=1.4.1" "1.4.1" "$(jq_get "$R" '.data.version')"
assert_eq "health build=20261121a" "20261121a" "$(jq_get "$R" '.data.build')"
COLS=$(SHOP_DB_PATH="$TESTDB" php -r '
require "src/Db.php";
$pdo=Db::conn();
$cols=[];
foreach($pdo->query("PRAGMA table_info(upstreams)")->fetchAll() as $c){$cols[]=$c["name"];}
foreach(["auth_mode","path_products","path_provision_cloud","path_provision_finance","path_action","price_is_cents"] as $need){
  echo (in_array($need,$cols,true)?"1":"0");
}')
assert_eq "upstreams 6 个新列齐全" "111111" "$COLS"

echo ""
echo "=================================================="
echo " T2 上游 CRUD（协议可配置 / 密钥只写不读 / SSRF）"
echo "=================================================="
R=$(curl -s -c "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/login" \
  -H "Content-Type: application/json" -d "{\"username\":\"admin\",\"password\":\"$ADMIN_PW\"}")
assert_eq "管理员登录" "true" "$(jq_get "$R" '.ok')"

R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/upstreams" \
  -H "Content-Type: application/json" -d '{
    "name":"测试魔方云","kind":"cloud","base_url":"vm.example.com","api_user":"agent01",
    "api_key":"secret-key-8888","use_https":1,"auth_mode":"basic","price_is_cents":1,
    "path_products":"/api/cloud/products","path_provision_cloud":"/api/cloud/open",
    "path_provision_finance":"/api/fin/order","path_action":"/api/vm/{id}/{action}"}')
assert_eq "创建上游成功" "true" "$(jq_get "$R" '.ok')"
UPID=$(jq_get "$R" '.data.item.id // .data.id // .id')
assert_eq "回显 auth_mode=basic" "basic" "$(jq_get "$R" '.data.item.auth_mode // .auth_mode')"
assert_eq "回显 price_is_cents=1" "1" "$(jq_get "$R" '.data.item.price_is_cents // .price_is_cents')"
assert_eq "回显自定义开通路径" "/api/cloud/open" "$(jq_get "$R" '.data.item.path_provision_cloud // .path_provision_cloud')"
assert_not_contains "api_key 明文不回显" 'secret-key-8888' "$R"
assert_eq "api_key_set=true" "true" "$(jq_get "$R" '.data.item.api_key_set // .api_key_set')"

R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/upstreams" \
  -H "Content-Type: application/json" -d '{"name":"内网非法","kind":"cloud","base_url":"127.0.0.1","api_key":"abcdef12"}')
assert_eq "SSRF：拒绝内网地址" "false" "$(jq_get "$R" '.ok')"

echo ""
echo "=================================================="
echo " T3 mock 模式同步产品（价格单位=分，直接用）"
echo "=================================================="
curl -s -b "$ADMIN_JAR" -X PUT -H "Origin: $BASE" "$BASE/api/admin/settings" \
  -H "Content-Type: application/json" -d '{"upstream_mock":"1"}' >/dev/null
R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/upstreams/$UPID/products/sync" -d '{}')
assert_eq "同步产品成功" "true" "$(jq_get "$R" '.ok')"
CNT=$(jq_get "$R" '.data.count // .count')
if [ "$CNT" -ge 1 ] 2>/dev/null; then ok "同步到 $CNT 个产品"; else bad "同步产品数异常 [$CNT]"; fi

echo ""
echo "=================================================="
echo " T4 核心修复：上游 yulan 商品下单免账号密码，支付后开通"
echo "=================================================="
# 建一个云上游（finance 类型）供 yulan 商品绑定
R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/upstreams" \
  -H "Content-Type: application/json" -d '{
    "name":"测试财务上游","kind":"finance","base_url":"fin.example.com","api_user":"fin01",
    "api_key":"fin-secret-99","use_https":0}')
FIN_ID=$(jq_get "$R" '.data.item.id // .data.id // .id')
# 取一个有效分类
CAT141=$(curl -s "$BASE/api/categories" | jq -r '[.data.categories[] | select(.id != "all")][0].id // empty')
if [ -z "$CAT141" ]; then
  CAT141="testcat"
  curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/categories" \
    -H "Content-Type: application/json" -d '{"id":"testcat","label":"测试分类"}' >/dev/null
fi
# 建一个 yulan 商品并绑定上游
R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/products" \
  -H "Content-Type: application/json" -d "{
    \"id\":\"up-yulan-1\",\"sku\":\"UPY001\",\"name\":\"上游财务产品\",\"sub\":\"测试\",
    \"price_cents\":0,\"cat_id\":\"$CAT141\",\"status\":\"active\",\"stock\":100}")
if [ "$(jq_get "$R" '.ok')" != "true" ]; then echo "    [诊断] CAT141=[$CAT141] 商品创建响应: $R"; fi
assert_eq "创建 yulan 商品" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$ADMIN_JAR" -X PUT -H "Origin: $BASE" "$BASE/api/admin/products/up-yulan-1/delivery" \
  -H "Content-Type: application/json" -d "{\"type\":\"yulan\",\"upstream_id\":$FIN_ID,\"upstream_product_id\":\"up-p-1001\"}")
assert_eq "yulan 商品绑定上游" "true" "$(jq_get "$R" '.ok')"

# 注册并登录用户
curl -s -X POST -H "Origin: $BASE" "$BASE/api/console/register" -H "Content-Type: application/json" \
  -d '{"username":"v141u1","password":"UserPass123","display_name":"v141u1"}' >/dev/null
R=$(curl -s -c "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/console/login" \
  -H "Content-Type: application/json" -d '{"username":"v141u1","password":"UserPass123"}')
assert_eq "用户登录" "true" "$(jq_get "$R" '.ok')"

# 关键：下单【不传 account/password】，上游模式应允许
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" \
  -H "Content-Type: application/json" -d '{
    "name":"张三","phone":"13800138000","address":"上海市静安区巨鹿路880号2楼","note":"",
    "items":[{"id":"up-yulan-1","qty":1}]}')
assert_eq "上游 yulan 商品下单无需账号密码" "true" "$(jq_get "$R" '.ok')"
ONO=$(jq_get "$R" '.data.order_no // .order_no')

# 下单阶段不应产生云主机/交付（开通已挪到支付后）
MOCKQ=/tmp/yulan_e2e141/mockq.txt
cp data/upstream_mock_queue.jsonl "$MOCKQ" 2>/dev/null || : > "$MOCKQ"
if grep -q '"action": "provision"\|"action":"provision"' "$MOCKQ" 2>/dev/null; then
  bad "下单阶段不应调用上游开通"
else
  ok "下单阶段未调用上游开通（时机已修正）"
fi

# free-pay 支付
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders/$ONO/free-pay" -d '{}' 2>/dev/null)
if [ "$(jq_get "$R" '.ok')" != "true" ]; then
  R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/console/orders/$ONO/free-pay" -d '{}')
fi
assert_eq "¥0 订单支付成功" "true" "$(jq_get "$R" '.ok')"

# 支付后应触发开通
if grep -q 'provision' data/upstream_mock_queue.jsonl 2>/dev/null; then
  ok "支付后调用了上游开通（provision）"
else
  bad "支付后未触发上游开通"
fi

# 校验交付内容
R=$(curl -s -b "$U1_JAR" "$BASE/api/console/orders/$ONO")
CONTENT=$(echo "$R" | jq -r '.data.deliveries[0].content // .deliveries[0].content // empty')
if [ -n "$CONTENT" ]; then
  assert_contains "交付内容含连接地址" "连接地址" "$CONTENT"
  assert_contains "交付内容含密码" "密码" "$CONTENT"
else
  bad "未找到交付内容"
fi

echo ""
echo "=================================================="
echo " T5 支付幂等：重复 free-pay 不重复开通"
echo "=================================================="
BEFORE=$(grep -c 'provision' data/upstream_mock_queue.jsonl 2>/dev/null || echo 0)
curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders/$ONO/free-pay" -d '{}' >/dev/null 2>&1
curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/console/orders/$ONO/free-pay" -d '{}' >/dev/null 2>&1
AFTER=$(grep -c 'provision' data/upstream_mock_queue.jsonl 2>/dev/null || echo 0)
assert_eq "重复支付未重复开通" "$BEFORE" "$AFTER"

echo ""
echo "=================================================="
echo " T6 cloud 商品支付后开通"
echo "=================================================="
R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/products" \
  -H "Content-Type: application/json" -d "{
    \"id\":\"up-cloud-1\",\"sku\":\"UPC001\",\"name\":\"上游云主机\",\"sub\":\"测试\",
    \"price_cents\":0,\"cat_id\":\"$CAT141\",\"status\":\"active\",\"stock\":100}")
assert_eq "创建 cloud 商品" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$ADMIN_JAR" -X PUT -H "Origin: $BASE" "$BASE/api/admin/products/up-cloud-1/delivery" \
  -H "Content-Type: application/json" -d "{\"type\":\"cloud\",\"upstream_id\":$UPID,\"upstream_product_id\":\"vm-plan-2\"}")
assert_eq "cloud 商品绑定上游" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" \
  -H "Content-Type: application/json" -d '{
    "name":"张三","phone":"13800138000","address":"上海市静安区巨鹿路880号2楼","note":"",
    "items":[{"id":"up-cloud-1","qty":1}]}')
assert_eq "cloud 商品下单成功" "true" "$(jq_get "$R" '.ok')"
CNO=$(jq_get "$R" '.data.order_no // .order_no')
curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders/$CNO/free-pay" -d '{}' >/dev/null 2>&1
R=$(curl -s -b "$U1_JAR" "$BASE/api/console/cloud-vms")
CNT=$(jq_get "$R" '.data.items | length // .items | length')
if [ "$CNT" -ge 1 ] 2>/dev/null; then ok "用户中心可见云主机（$CNT 台）"; else bad "云主机未生成 [$CNT]"; fi

echo ""
echo "=================================================="
echo " T7 工单链路 + 权限隔离"
echo "=================================================="
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/console/tickets" \
  -H "Content-Type: application/json" -d '{"category":"technical","title":"云主机无法连接","body":"支付后无法SSH登录，请协助","priority":"high"}')
assert_eq "创建工单" "true" "$(jq_get "$R" '.ok')"
TID=$(jq_get "$R" '.data.id // .id')
R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/tickets/$TID/reply" \
  -H "Content-Type: application/json" -d '{"body":"您好，已为您重置密码，请重试。"}')
assert_eq "管理员回复工单" "true" "$(jq_get "$R" '.ok')"
R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/tickets/$TID/status" \
  -H "Content-Type: application/json" -d '{"status":"processing"}')
assert_eq "管理员改状态" "true" "$(jq_get "$R" '.ok')"

# 用户2 不应看到用户1 的工单
curl -s -X POST -H "Origin: $BASE" "$BASE/api/console/register" -H "Content-Type: application/json" \
  -d '{"username":"v141u2","password":"UserPass123","display_name":"v141u2"}' >/dev/null
U2_JAR=/tmp/yulan_e2e141/u2.jar
curl -s -c "$U2_JAR" -X POST -H "Origin: $BASE" "$BASE/api/console/login" \
  -H "Content-Type: application/json" -d '{"username":"v141u2","password":"UserPass123"}' >/dev/null
R=$(curl -s -b "$U2_JAR" "$BASE/api/console/tickets/$TID")
if [ "$(echo "$R" | jq -r '.ok')" = "false" ]; then ok "跨用户访问工单被拒绝"; else bad "跨用户竟能访问工单详情: $R"; fi

echo ""
echo "=================================================="
echo " T8 存量兼容：未绑定上游的 yulan 仍需账号密码"
echo "=================================================="
# 关闭 mock（让 YulanConnector 走真实 HTTP 会失败；这里只验证「下单时校验账号密码」这一分支）
R=$(curl -s -b "$ADMIN_JAR" -X POST -H "Origin: $BASE" "$BASE/api/admin/products" \
  -H "Content-Type: application/json" -d "{
    \"id\":\"legacy-yulan\",\"sku\":\"LEGACY001\",\"name\":\"存量魔方商品\",\"sub\":\"测试\",
    \"price_cents\":0,\"cat_id\":\"$CAT141\",\"status\":\"active\",\"stock\":100}")
assert_eq "创建存量 yulan 商品" "true" "$(jq_get "$R" '.ok')"
curl -s -b "$ADMIN_JAR" -X PUT -H "Origin: $BASE" "$BASE/api/admin/products/legacy-yulan/delivery" \
  -H "Content-Type: application/json" -d '{"type":"yulan","template":"","fields":[]}' >/dev/null
# 不传账号密码 → 应被拒绝
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" \
  -H "Content-Type: application/json" -d '{
    "name":"张三","phone":"13800138000","address":"上海市静安区巨鹿路880号2楼","note":"",
    "items":[{"id":"legacy-yulan","qty":1}]}')
assert_eq "存量 yulan 缺账号密码被拒绝" "false" "$(jq_get "$R" '.ok')"
# 传账号密码 → 应放行到建号阶段（mock 未开，建号会失败但不影响下单校验断言）
R=$(curl -s -b "$U1_JAR" -X POST -H "Origin: $BASE" "$BASE/api/orders" \
  -H "Content-Type: application/json" -d '{
    "name":"张三","phone":"13800138000","address":"上海市静安区巨鹿路880号2楼","note":"",
    "items":[{"id":"legacy-yulan","qty":1,"account":"legacyuser","password":"Secret123"}]}')
CODE=$(echo "$R" | jq -r '.error.code // empty')
if [ "$(jq_get "$R" '.ok')" = "true" ] || [ "$CODE" = "yulan_provision_failed" ]; then
  ok "存量 yulan 带账号密码通过下单校验（建号失败属预期：APP 未启动）"
else
  bad "存量 yulan 带账号密码仍被拒: $R"
fi

echo ""
echo "=================================================="
echo " 结果：PASS=$PASS FAIL=$FAIL"
echo "=================================================="
[ "$FAIL" -eq 0 ]
