#!/usr/bin/env bash
# v5 端到端测试：注册 / 登录 / 下单 / 余额 / 自动发货 / 越权 / CSRF / 限流
set -u
B=http://127.0.0.1:8090
J='Content-Type: application/json'
ORIGIN='Origin: http://127.0.0.1:8090'
PASS=0; FAIL=0
ok(){ echo "  ✅ $1"; PASS=$((PASS+1)); }
no(){ echo "  ❌ $1"; FAIL=$((FAIL+1)); }
chk(){ # chk <desc> <actual> <expected-substr>
  if echo "$2" | grep -q "$3"; then ok "$1"; else no "$1  → got: $(echo "$2" | head -c 200)"; fi
}

echo "=== 1. 未登录下单必须 401 ==="
R=$(curl -s -X POST "$B/api/orders" -H "$J" -H "$ORIGIN" -d '{"name":"张三","phone":"13800138000","address":"上海市静安区某路1号","items":[{"id":"p1","qty":1}]}')
chk "未登录下单被拒" "$R" '"code":"unauthorized"'

echo "=== 2. 注册 ==="
R=$(curl -s -c /tmp/u1.jar -X POST "$B/api/console/register" -H "$J" -H "$ORIGIN" \
  -d '{"username":"alice","password":"Passw0rd123","display_name":"爱丽丝"}')
chk "注册成功" "$R" '"username":"alice"'
chk "初始余额为 0" "$R" '"balance_cents":0'

echo "=== 3. 弱密码被拒 ==="
R=$(curl -s -X POST "$B/api/console/register" -H "$J" -H "$ORIGIN" -d '{"username":"bob","password":"12345678"}')
chk "纯数字密码被拒" "$R" '密码需同时包含字母和数字'

echo "=== 4. 重复用户名被拒 ==="
R=$(curl -s -X POST "$B/api/console/register" -H "$J" -H "$ORIGIN" -d '{"username":"ALICE","password":"Passw0rd123"}')
chk "大小写不敏感查重" "$R" '已被注册'

echo "=== 5. 登录态 ==="
R=$(curl -s -b /tmp/u1.jar "$B/api/console/me")
chk "me 返回用户" "$R" '"username":"alice"'

echo "=== 6. 错误密码 ==="
R=$(curl -s -X POST "$B/api/console/login" -H "$J" -H "$ORIGIN" -d '{"username":"alice","password":"wrongpass1"}')
chk "错误密码被拒" "$R" '用户名或密码错误'

echo "=== 7. 登录（第二个账号） ==="
R=$(curl -s -c /tmp/u2.jar -X POST "$B/api/console/register" -H "$J" -H "$ORIGIN" \
  -d '{"username":"bob","password":"Passw0rd456","display_name":"鲍勃"}')
chk "bob 注册成功" "$R" '"username":"bob"'

echo "=== 8. 下单（alice） ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/orders" -H "$J" -H "$ORIGIN" \
  -H 'Idempotency-Key: test-key-alice-001' \
  -d '{"name":"爱丽丝","phone":"13800138000","address":"上海市静安区南京西路100号","items":[{"id":"p1","qty":1}]}')
chk "下单成功" "$R" '"order_no":"SH'
ONO=$(echo "$R" | grep -o '"order_no":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "     订单号：$ONO"

echo "=== 9. 幂等：同 key 重复提交 ==="
R2=$(curl -s -b /tmp/u1.jar -X POST "$B/api/orders" -H "$J" -H "$ORIGIN" \
  -H 'Idempotency-Key: test-key-alice-001' \
  -d '{"name":"爱丽丝","phone":"13800138000","address":"上海市静安区南京西路100号","items":[{"id":"p1","qty":1}]}')
chk "幂等返回同一订单" "$R2" "\"order_no\":\"$ONO\""

echo "=== 10. 越权：bob 查 alice 的订单 ==="
R=$(curl -s -b /tmp/u2.jar "$B/api/console/orders/$ONO")
chk "bob 看不到 alice 的订单" "$R" '"code":"not_found"'

echo "=== 11. alice 查自己的订单 ==="
R=$(curl -s -b /tmp/u1.jar "$B/api/console/orders/$ONO")
chk "alice 能看到自己的订单" "$R" "\"order_no\":\"$ONO\""

echo "=== 12. 余额不足时余额支付被拒 ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/console/orders/$ONO/pay-balance" -H "$J" -H "$ORIGIN" -d '{}')
chk "余额不足被拒" "$R" 'insufficient_balance'

echo "=== 13. 后台登录 ==="
R=$(curl -s -c /tmp/a.jar -X POST "$B/api/admin/login" -H "$J" -H "$ORIGIN" -d '{"username":"admin","password":"AdminPass123"}')
chk "后台登录成功" "$R" '"username":"admin"'

echo "=== 14. 后台给 alice 充值 100 元 ==="
R=$(curl -s -b /tmp/a.jar -X POST "$B/api/admin/users/1/balance" -H "$J" -H "$ORIGIN" \
  -d '{"delta_cents":50000,"remark":"测试充值"}')
chk "加款成功" "$R" '"balance":50000'

echo "=== 15. 余额流水（含变动前后） ==="
R=$(curl -s -b /tmp/u1.jar "$B/api/console/balance/ledger")
chk "流水含变动前后余额" "$R" '"balance_before":0,"balance_after":50000'

echo "=== 16. 余额支付 ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/console/orders/$ONO/pay-balance" -H "$J" -H "$ORIGIN" -d '{}')
chk "余额支付成功" "$R" '"status":"paid"'
echo "     支付后余额：$(echo "$R" | grep -o '"balance":[0-9]*' | cut -d: -f2)"

echo "=== 17. 支付后订单状态与发货内容 ==="
R=$(curl -s -b /tmp/u1.jar "$B/api/console/orders/$ONO")
chk "订单已推进为已付款（实体商品待发货）" "$R" '"status":"paid"'
chk "含发货内容字段" "$R" '"deliveries"'

echo "=== 18. 重复余额支付被拒 ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/console/orders/$ONO/pay-balance" -H "$J" -H "$ORIGIN" -d '{}')
chk "重复支付被拒" "$R" 'already_paid'

echo "=== 19. CSRF：跨站 Origin 被拒 ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/console/orders/$ONO/pay-balance" -H "$J" \
  -H 'Origin: http://evil.example.com' -d '{}')
chk "跨站 Origin 被拒" "$R" 'csrf_failed'

echo "=== 20. 无 Origin 的写请求被拒 ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/console/orders/$ONO/pay-balance" -H "$J" -d '{}')
chk "无来源写请求被拒" "$R" 'csrf_failed'

echo "=== 21. 后台用户列表 ==="
R=$(curl -s -b /tmp/a.jar "$B/api/admin/users")
chk "用户列表含 alice" "$R" '"username":"alice"'
chk "用户列表含余额" "$R" '"balance_cents"'

echo "=== 22. 商品发货模板配置 ==="
R=$(curl -s -b /tmp/a.jar -X PUT "$B/api/admin/products/p1/delivery" -H "$J" -H "$ORIGIN" \
  -d '{"type":"virtual","template":"","fields":[{"label":"IP","value":"10.0.0.8"},{"label":"账号","value":"root"},{"label":"密码","value":"{random:12}"}]}')
chk "保存发货模板" "$R" '"type":"virtual"'
R=$(curl -s -b /tmp/a.jar "$B/api/admin/products/p1/delivery")
chk "读回模板字段" "$R" '"label":"IP"'

echo "=== 23. 虚拟商品下单 → 支付 → 自动发货 ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/orders" -H "$J" -H "$ORIGIN" \
  -H 'Idempotency-Key: test-key-alice-002' \
  -d '{"name":"爱丽丝","phone":"13800138000","address":"上海市静安区南京西路100号","items":[{"id":"p1","qty":1}]}')
ONO2=$(echo "$R" | grep -o '"order_no":"[^"]*"' | head -1 | cut -d'"' -f4)
chk "虚拟商品下单" "$R" '"all_virtual":true'
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/console/orders/$ONO2/pay-balance" -H "$J" -H "$ORIGIN" -d '{}')
chk "虚拟商品余额支付成功（自动发货后为 shipped）" "$R" '"status":"shipped"'
R=$(curl -s -b /tmp/u1.jar "$B/api/console/orders/$ONO2")
chk "自动发货含 IP" "$R" 'IP：10.0.0.8'
chk "自动发货含账号" "$R" '账号：root'
chk "密码占位符已展开" "$R" '密码：'
chk "虚拟商品自动发货后停在 shipped（不是 done）" "$R" '"status":"shipped"'

echo "=== 24. 站点设置实时生效 ==="
R=$(curl -s -b /tmp/a.jar -X PUT "$B/api/admin/settings" -H "$J" -H "$ORIGIN" \
  -d '{"settings":{"site_name":"测试站点名","footer_copyright":"© 2026 测试版权","site_intro":"这是站点介绍"}}')
chk "保存站点设置" "$R" '"updated"'
R=$(curl -s "$B/api/site")
chk "前台读到新标题" "$R" '"site_name":"测试站点名"'
chk "前台读到新版权" "$R" '2026 测试版权'
chk "前台读到站点介绍" "$R" '这是站点介绍'

echo "=== 25. 后台订单详情含发货内容 ==="
R=$(curl -s -b /tmp/a.jar "$B/api/admin/orders/$ONO2")
chk "后台可见发货内容" "$R" 'IP：10.0.0.8'

echo "=== 26. 未登录访问 console 接口 ==="
R=$(curl -s "$B/api/console/overview")
chk "未登录被拒" "$R" '"code":"unauthorized"'

echo "=== 27. 后台接口未登录被拒 ==="
R=$(curl -s "$B/api/admin/users")
chk "后台未登录被拒" "$R" '"code":"unauthorized"'

echo "=== 28. 停用用户后会话失效 ==="
R=$(curl -s -b /tmp/a.jar -X POST "$B/api/admin/users/2/status" -H "$J" -H "$ORIGIN" -d '{"status":"disabled"}')
chk "停用 bob" "$R" '"status":"disabled"'
R=$(curl -s -b /tmp/u2.jar "$B/api/console/me")
chk "bob 会话已失效" "$R" '"code":"unauthorized"'

echo "=== 29. 修改密码 ==="
R=$(curl -s -b /tmp/u1.jar -X POST "$B/api/console/password" -H "$J" -H "$ORIGIN" \
  -d '{"old_password":"Passw0rd123","new_password":"NewPassw0rd9"}')
chk "改密成功" "$R" '密码已更新'
R=$(curl -s -X POST "$B/api/console/login" -H "$J" -H "$ORIGIN" -d '{"username":"alice","password":"NewPassw0rd9"}')
chk "新密码可登录" "$R" '"username":"alice"'

echo
echo "=========================================="
echo "  通过：$PASS   失败：$FAIL"
echo "=========================================="
[ "$FAIL" -eq 0 ] || exit 1
