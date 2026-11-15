#!/usr/bin/env bash
# =============================================================
# 支付与自动发货 端到端测试
#
# 覆盖：
#   1. 余额支付成功（扣余额 + 写流水 + 自动发货 + 订单转已发货）
#   2. 余额不足明确提示（含差额）
#   3. 重复回调幂等（同一 notify 连发 3 次，只发货一次）
#   4. 未知订单号回调被拒
#   5. 已取消订单回调被拒（返回 success 但订单保持 cancelled）
#   6. 金额不符回调被拒
#   7. 通道不符回调被拒
#   8. 签名错误回调被拒
#   9. 虚拟商品发货内容在「我的订单」可见
#  10. 实体商品支付后保持「已支付（待发货）」，管理员发货后转「已发货」
#  11. 重复余额支付不会重复扣款
#  12. 超时关单归还库存
#
# 用法：bash tests/e2e_payment.sh [base_url]
# =============================================================
set -uo pipefail

BASE="${1:-http://127.0.0.1:8090}"
ORIGIN="$BASE"
PASS=0
FAIL=0
FAILED_NAMES=()

ok()   { PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); FAILED_NAMES+=("$1"); printf '  \033[31m✗\033[0m %s\n     %s\n' "$1" "${2:-}"; }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# 断言：字符串包含
assert_contains() { # name haystack needle
  if [[ "$2" == *"$3"* ]]; then ok "$1"; else bad "$1" "期望包含 [$3]，实际: ${2:0:300}"; fi
}
assert_eq() { # name actual expected
  if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1" "期望 [$3]，实际 [$2]"; fi
}

JAR=$(mktemp)
AJAR=$(mktemp)
trap 'rm -f "$JAR" "$AJAR"' EXIT

# 带同源头的请求（CSRF 校验要求 Origin/Referer 同源）
req() { # method path [json]
  local m="$1" p="$2" d="${3:-}"
  if [[ -n "$d" ]]; then
    curl -s -X "$m" "$BASE$p" -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" \
      -H 'Content-Type: application/json' -b "$JAR" -c "$JAR" -d "$d"
  else
    curl -s -X "$m" "$BASE$p" -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" \
      -b "$JAR" -c "$JAR"
  fi
}

areq() { # admin 请求
  local m="$1" p="$2" d="${3:-}"
  if [[ -n "$d" ]]; then
    curl -s -X "$m" "$BASE$p" -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" \
      -H 'Content-Type: application/json' -b "$AJAR" -c "$AJAR" -d "$d"
  else
    curl -s -X "$m" "$BASE$p" -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" \
      -b "$AJAR" -c "$AJAR"
  fi
}

jget() { # json key  (只取顶层/一层，够用)
  php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1];
    $cur=$d; foreach(explode(".",$k) as $seg){ if(!is_array($cur)||!array_key_exists($seg,$cur)){echo "";exit;} $cur=$cur[$seg]; }
    echo is_scalar($cur)?$cur:json_encode($cur,JSON_UNESCAPED_UNICODE);' "$1"
}

# 计算易支付签名（与服务端同算法：ksort + k=v& + KEY + md5）
epay_sign() { # key=val ... ; 环境变量 EPAY_KEY
  php -r '
    $p=[]; foreach(array_slice($argv,1) as $kv){ [$k,$v]=explode("=",$kv,2); if($k==="sign"||$k==="sign_type"||$v==="")continue; $p[$k]=$v; }
    ksort($p,SORT_STRING); $s=[]; foreach($p as $k=>$v){$s[]="$k=$v";}
    echo md5(implode("&",$s).getenv("EPAY_KEY"));' "$@"
}

_ENV_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/config/.env"
EPAY_KEY=$(grep -E '^EPAY_KEY=' "$_ENV_FILE" | cut -d= -f2- | tr -d '"')
EPAY_PID=$(grep -E '^EPAY_PID=' "$_ENV_FILE" | cut -d= -f2- | tr -d '"')
export EPAY_KEY

# 数据库路径：优先用环境变量，其次按脚本所在目录推断（不写死绝对路径，
# 否则换目录/换版本后测试会去改另一个库，导致「明明改了 p1 却还是实体商品」）
if [[ -z "${DB:-}" ]]; then
  _ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  DB="$_ROOT/data/shop.sqlite"
fi
q() { php -r '$p=new PDO("sqlite:".$argv[1]); $r=$p->query($argv[2]); if(!$r){echo "";exit;} $v=$r->fetchColumn(); echo $v===false?"":$v;' "$DB" "$1"; }
qx() { php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec($argv[2]);' "$DB" "$1"; }

# ---------------------------------------------------------------
head_ "0. 环境准备"
# 清空限流计数（重复跑测试时避免被自己的限流拦住）
qx "DELETE FROM rate_limits;"
# 把 p1 改成虚拟商品并配发货字段（IP/账号/密码），p2 保持实体
qx "UPDATE products SET type='virtual', delivery_template='' WHERE id='p1';"
qx "DELETE FROM product_delivery_fields WHERE product_id='p1';"
qx "INSERT INTO product_delivery_fields (product_id,label,value,sort) VALUES ('p1','IP','10.0.0.8',0),('p1','账号','root',1),('p1','密码','{random:12}',2);"
qx "UPDATE products SET type='physical' WHERE id='p2';"
qx "UPDATE products SET stock=50 WHERE id IN ('p1','p2');"
echo "  p1=虚拟(IP/账号/密码)  p2=实体"

# 注册并登录测试用户
U="e2e$(date +%s)$RANDOM"
R=$(req POST /api/console/register "{\"username\":\"$U\",\"password\":\"TestPass!2345\",\"display_name\":\"E2E\"}")
assert_contains "注册成功" "$R" '"ok":true'
R=$(req POST /api/console/login "{\"username\":\"$U\",\"password\":\"TestPass!2345\"}")
assert_contains "登录成功" "$R" '"ok":true'
UID_=$(q "SELECT id FROM users WHERE username='$U'")
echo "  测试用户 id=$UID_"

# 给用户充值 500 元（走后台调整，模拟已充值）
qx "UPDATE users SET balance_cents=50000 WHERE id=$UID_;"

# ---------------------------------------------------------------
head_ "1. 余额支付成功 + 自动发货"
R=$(req POST /api/orders '{"name":"张三","phone":"13800138000","address":"上海市静安区巨鹿路880号","items":[{"id":"p1","qty":1,"color":"","size":""}]}')
assert_contains "创建虚拟商品订单" "$R" '"ok":true'
ON1=$(echo "$R" | jget data.order_no)
TOT1=$(echo "$R" | jget data.total)
echo "  订单 $ON1 应付 $TOT1 分"

R=$(req POST "/api/console/orders/$ON1/pay-balance" '{}')
assert_contains "余额支付成功" "$R" '"ok":true'
assert_eq "订单状态=已发货(shipped)" "$(echo "$R" | jget data.status)" "shipped"
BAL_AFTER=$(echo "$R" | jget data.balance)
assert_eq "余额已扣减" "$BAL_AFTER" "$((50000-TOT1))"
assert_eq "订单表状态" "$(q "SELECT status FROM orders WHERE order_no='$ON1'")" "shipped"
assert_eq "余额流水已写(consume)" "$(q "SELECT type FROM balance_ledger WHERE order_no='$ON1'")" "consume"
assert_eq "流水记录变动前后余额" "$(q "SELECT balance_before||'->'||balance_after FROM balance_ledger WHERE order_no='$ON1'")" "50000->$((50000-TOT1))"

# 发货内容
R=$(req GET "/api/console/orders/$ON1")
assert_contains "我的订单可见发货内容" "$R" '10.0.0.8'
assert_contains "发货内容含账号" "$R" 'root'
assert_contains "发货内容含密码字段" "$R" '密码'
assert_eq "发货记录状态=delivered" "$(q "SELECT status FROM order_deliveries WHERE order_no='$ON1'")" "delivered"

# ---------------------------------------------------------------
head_ "2. 重复余额支付不重复扣款"
R=$(req POST "/api/console/orders/$ON1/pay-balance" '{}')
assert_contains "重复支付被拒(already_paid)" "$R" 'already_paid'
assert_eq "余额未被二次扣减" "$(q "SELECT balance_cents FROM users WHERE id=$UID_")" "$((50000-TOT1))"
assert_eq "流水只有一条" "$(q "SELECT COUNT(*) FROM balance_ledger WHERE order_no='$ON1'")" "1"

# ---------------------------------------------------------------
head_ "3. 余额不足明确提示"
# 把余额清零，再下一单
qx "UPDATE users SET balance_cents=0 WHERE id=$UID_;"
R=$(req POST /api/orders '{"name":"张三","phone":"13800138000","address":"上海市静安区巨鹿路880号","items":[{"id":"p1","qty":1,"color":"","size":""}]}')
ON2=$(echo "$R" | jget data.order_no)
TOT2=$(echo "$R" | jget data.total)
R=$(req POST "/api/console/orders/$ON2/pay-balance" '{}')
assert_contains "余额不足返回 insufficient_balance" "$R" 'insufficient_balance'
assert_contains "提示含「余额不足」" "$R" '余额不足'
assert_contains "提示含差额" "$R" '还差'
assert_eq "订单仍为待支付" "$(q "SELECT status FROM orders WHERE order_no='$ON2'")" "pending"
assert_eq "未产生流水" "$(q "SELECT COUNT(*) FROM balance_ledger WHERE order_no='$ON2'")" "0"

# ---------------------------------------------------------------
head_ "4. 在线支付发起（微信/支付宝）"
R=$(req POST "/api/console/orders/$ON2/pay" '{"channel":"wxpay"}')
assert_contains "微信支付返回网关表单" "$R" '"gateway"'
assert_contains "网关为 https" "$R" 'https://'
assert_contains "表单含 sign" "$R" '"sign"'
assert_contains "表单 type=wxpay" "$R" '"type":"wxpay"'
OTN=$(echo "$R" | jget data.out_trade_no)
assert_contains "商户单号格式正确" "$OTN" "$ON2-"

R=$(req POST "/api/console/orders/$ON2/pay" '{"channel":"alipay"}')
assert_contains "支付宝支付返回网关表单" "$R" '"type":"alipay"'

R=$(req POST "/api/console/orders/$ON2/pay" '{"channel":"qqpay"}')
assert_contains "拒绝 qqpay 通道" "$R" 'invalid_channel'

# ---------------------------------------------------------------
head_ "5. 回调：签名错误被拒"
NOTIFY="/api/pay/notify"
SIG=$(epay_sign "pid=$EPAY_PID" "trade_no=T2026001" "out_trade_no=$OTN" "type=wxpay" "money=$(php -r "echo number_format($TOT2/100,2,'.','');")" "trade_status=TRADE_SUCCESS")
R=$(curl -s "$BASE$NOTIFY?pid=$EPAY_PID&trade_no=T2026001&out_trade_no=$OTN&type=wxpay&money=$(php -r "echo number_format($TOT2/100,2,'.','');")&trade_status=TRADE_SUCCESS&sign=deadbeefdeadbeefdeadbeefdeadbeef")
assert_eq "错误签名返回 fail" "$R" "fail"
assert_eq "订单未被改状态" "$(q "SELECT status FROM orders WHERE order_no='$ON2'")" "pending"

head_ "6. 回调：金额不符被拒"
MONEY_BAD=$(php -r "echo number_format(($TOT2-100)/100,2,'.','');")
SIG=$(epay_sign "pid=$EPAY_PID" "trade_no=T2026002" "out_trade_no=$OTN" "type=wxpay" "money=$MONEY_BAD" "trade_status=TRADE_SUCCESS")
R=$(curl -s "$BASE$NOTIFY?pid=$EPAY_PID&trade_no=T2026002&out_trade_no=$OTN&type=wxpay&money=$MONEY_BAD&trade_status=TRADE_SUCCESS&sign=$SIG")
assert_eq "金额不符返回 fail" "$R" "fail"
assert_eq "订单仍待支付" "$(q "SELECT status FROM orders WHERE order_no='$ON2'")" "pending"

head_ "7. 回调：通道不符被拒"
MONEY_OK=$(php -r "echo number_format($TOT2/100,2,'.','');")
SIG=$(epay_sign "pid=$EPAY_PID" "trade_no=T2026003" "out_trade_no=$OTN" "type=alipay" "money=$MONEY_OK" "trade_status=TRADE_SUCCESS")
R=$(curl -s "$BASE$NOTIFY?pid=$EPAY_PID&trade_no=T2026003&out_trade_no=$OTN&type=alipay&money=$MONEY_OK&trade_status=TRADE_SUCCESS&sign=$SIG")
assert_eq "通道不符返回 fail" "$R" "fail"
assert_eq "订单仍待支付" "$(q "SELECT status FROM orders WHERE order_no='$ON2'")" "pending"

head_ "8. 回调：未知订单号被拒"
SIG=$(epay_sign "pid=$EPAY_PID" "trade_no=T2026004" "out_trade_no=SH99999999DEADBEEF-ABCDEF" "type=wxpay" "money=1.00" "trade_status=TRADE_SUCCESS")
R=$(curl -s "$BASE$NOTIFY?pid=$EPAY_PID&trade_no=T2026004&out_trade_no=SH99999999DEADBEEF-ABCDEF&type=wxpay&money=1.00&trade_status=TRADE_SUCCESS&sign=$SIG")
assert_eq "未知订单号返回 fail" "$R" "fail"

head_ "9. 回调：正确签名 → 支付成功 + 自动发货（GET 方式）"
TN5="T$(date +%s)$RANDOM"
SIG=$(epay_sign "pid=$EPAY_PID" "trade_no=$TN5" "out_trade_no=$OTN" "type=wxpay" "money=$MONEY_OK" "trade_status=TRADE_SUCCESS")
R=$(curl -s "$BASE$NOTIFY?pid=$EPAY_PID&trade_no=$TN5&out_trade_no=$OTN&type=wxpay&money=$MONEY_OK&trade_status=TRADE_SUCCESS&sign=$SIG")
assert_eq "GET 回调返回 success" "$R" "success"
assert_eq "订单转已发货" "$(q "SELECT status FROM orders WHERE order_no='$ON2'")" "shipped"
assert_eq "支付通道记录 wxpay" "$(q "SELECT pay_channel FROM orders WHERE order_no='$ON2'")" "wxpay"
assert_eq "发货内容已生成" "$(q "SELECT COUNT(*) FROM order_deliveries WHERE order_no='$ON2' AND status='delivered'")" "1"

head_ "10. 重复回调幂等（连发 3 次）"
for i in 1 2 3; do
  R=$(curl -s "$BASE$NOTIFY?pid=$EPAY_PID&trade_no=$TN5&out_trade_no=$OTN&type=wxpay&money=$MONEY_OK&trade_status=TRADE_SUCCESS&sign=$SIG")
  assert_eq "第 $i 次重复回调返回 success" "$R" "success"
done
assert_eq "发货记录仍只有 1 条" "$(q "SELECT COUNT(*) FROM order_deliveries WHERE order_no='$ON2'")" "1"
assert_eq "状态事件只有 1 次 paid" "$(q "SELECT COUNT(*) FROM order_events WHERE order_no='$ON2' AND to_status='paid'")" "1"

# ---------------------------------------------------------------
head_ "11. 已取消订单回调被拒"
R=$(req POST /api/orders '{"name":"张三","phone":"13800138000","address":"上海市静安区巨鹿路880号","items":[{"id":"p1","qty":1,"color":"","size":""}]}')
ON3=$(echo "$R" | jget data.order_no)
TOT3=$(echo "$R" | jget data.total)
R=$(req POST "/api/console/orders/$ON3/pay" '{"channel":"wxpay"}')
OTN3=$(echo "$R" | jget data.out_trade_no)
# 直接取消（模拟超时关单）
qx "UPDATE orders SET status='cancelled', closed_at=datetime('now') WHERE order_no='$ON3';"
M3=$(php -r "echo number_format($TOT3/100,2,'.','');")
SIG=$(epay_sign "pid=$EPAY_PID" "trade_no=T2026006" "out_trade_no=$OTN3" "type=wxpay" "money=$M3" "trade_status=TRADE_SUCCESS")
R=$(curl -s "$BASE$NOTIFY?pid=$EPAY_PID&trade_no=T2026006&out_trade_no=$OTN3&type=wxpay&money=$M3&trade_status=TRADE_SUCCESS&sign=$SIG")
assert_eq "已取消订单回调返回 success（停止重试）" "$R" "success"
assert_eq "订单保持 cancelled" "$(q "SELECT status FROM orders WHERE order_no='$ON3'")" "cancelled"
assert_eq "未生成发货内容" "$(q "SELECT COUNT(*) FROM order_deliveries WHERE order_no='$ON3'")" "0"

# ---------------------------------------------------------------
head_ "12. 实体商品：支付后待发货，管理员发货后转已发货"
qx "UPDATE users SET balance_cents=500000 WHERE id=$UID_;"
R=$(req POST /api/orders '{"name":"李四","phone":"13900139000","address":"北京市朝阳区建国路1号","items":[{"id":"p2","qty":1,"color":"","size":""}]}')
ON4=$(echo "$R" | jget data.order_no)
R=$(req POST "/api/console/orders/$ON4/pay-balance" '{}')
assert_contains "实体商品余额支付成功" "$R" '"ok":true'
assert_eq "实体订单状态=已支付(待发货)" "$(echo "$R" | jget data.status)" "paid"
assert_eq "实体订单 DB 状态" "$(q "SELECT status FROM orders WHERE order_no='$ON4'")" "paid"
assert_eq "实体发货记录为 pending" "$(q "SELECT status FROM order_deliveries WHERE order_no='$ON4'")" "pending"

# 管理员登录并发货
# 注意：路径必须相对本脚本定位，不能写死 /workspace/shop-php（那是旧版本目录，
# 写死会导致测试读错 .env、改错数据库，出现「假失败」）。
_ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ADMIN_PW=$(grep -E '^SHOP_ADMIN_PASSWORD=' "$_ROOT_DIR/config/.env" 2>/dev/null | cut -d= -f2- | tr -d '"')
ADMIN_USER=$(grep -E '^SHOP_ADMIN_USER=' "$_ROOT_DIR/config/.env" 2>/dev/null | cut -d= -f2- | tr -d '"')
ADMIN_USER="${ADMIN_USER:-admin}"
if [[ -z "$ADMIN_PW" ]]; then
  echo "  (跳过管理员发货：.env 未设 SHOP_ADMIN_PASSWORD)"
else
  R=$(areq POST /api/admin/login "{\"username\":\"$ADMIN_USER\",\"password\":\"$ADMIN_PW\"}")
  assert_contains "管理员登录" "$R" '"ok":true'
  R=$(areq POST "/api/admin/orders/$ON4/ship" '{"ship_company":"顺丰速运","ship_no":"SF1234567890"}')
  assert_contains "管理员发货成功" "$R" '"ok":true'
  assert_eq "订单转已发货" "$(q "SELECT status FROM orders WHERE order_no='$ON4'")" "shipped"
  assert_eq "运单号已记录" "$(q "SELECT ship_no FROM orders WHERE order_no='$ON4'")" "SF1234567890"
fi

# ---------------------------------------------------------------
head_ "13. 超时关单归还库存"
STOCK_BEFORE=$(q "SELECT stock FROM products WHERE id='p1'")
R=$(req POST /api/orders '{"name":"王五","phone":"13700137000","address":"广州市天河区体育西路1号","items":[{"id":"p1","qty":2,"color":"","size":""}]}')
ON5=$(echo "$R" | jget data.order_no)
assert_eq "下单后库存已扣减" "$(q "SELECT stock FROM products WHERE id='p1'")" "$((STOCK_BEFORE-2))"
qx "UPDATE orders SET created_at=datetime('now','-3 hours') WHERE order_no='$ON5';"
php "$_ROOT_DIR/bin/close_expired.php" > /tmp/close.log 2>&1
assert_eq "超时订单已取消" "$(q "SELECT status FROM orders WHERE order_no='$ON5'")" "cancelled"
assert_eq "库存已归还" "$(q "SELECT stock FROM products WHERE id='p1'")" "$STOCK_BEFORE"

# ---------------------------------------------------------------
head_ "14. 状态机事件链完整"
EV=$(q "SELECT group_concat(from_status||'>'||to_status,'|') FROM (SELECT from_status,to_status FROM order_events WHERE order_no='$ON1' ORDER BY id)")
assert_eq "虚拟订单事件链 pending>paid|paid>shipped" "$EV" "pending>paid|paid>shipped"

# ---------------------------------------------------------------
printf '\n\033[1m========== 结果 ==========\033[0m\n'
printf '  通过: \033[32m%d\033[0m   失败: \033[31m%d\033[0m\n' "$PASS" "$FAIL"
if (( FAIL > 0 )); then
  printf '  失败项:\n'
  for n in "${FAILED_NAMES[@]}"; do printf '    - %s\n' "$n"; done
  exit 1
fi
printf '  \033[32m全部通过\033[0m\n'
exit 0
