# 支付与自动发货（v5.1）

本文档说明 v5.1 新增的**支付接入**与**自动发货**实现：易支付（微信 / 支付宝）、余额支付、异步回调、订单状态机、虚拟商品自动发货、实体商品待发货。

> 协议依据：易支付 V1 旧版通用协议 <https://pay.liohg.top/doc/v1_legacy_api.html>
> 只接入 **微信支付（wxpay）** 与 **支付宝（alipay）** 两个通道，其他通道（qqpay / bank / jdpay / paypal / douyinpay）在服务端被硬性拒绝。

---

## 1. 配置（零硬编码密钥）

所有密钥只从 `config/.env`（或环境变量）读取，代码里没有任何真实密钥。

```dotenv
# ---- 易支付 ----
EPAY_ENABLED=true
EPAY_GATEWAY=https://pay.liohg.top/submit.php   # 必须 https；也可写 EPAY_GATEWAY_URL
EPAY_PID=1001                                   # 商户 ID
EPAY_KEY=你的商户密钥                            # 只存服务端，绝不下发前端
EPAY_SIGN_TYPE=MD5                              # MD5（默认）| SHA256
EPAY_CHANNELS=wxpay,alipay                      # 只允许这两个；wechat 是 wxpay 的别名
EPAY_NOTIFY_URL=                                # 留空则自动用 {SHOP_BASE_URL}/api/pay/notify
EPAY_RETURN_URL=                                # 留空则自动用 {SHOP_BASE_URL}/api/pay/return
EPAY_EXPIRE_MINUTES=120                         # 订单支付时限（分钟）
EPAY_AMOUNT_TOLERANCE_CENTS=0                   # 金额容差，默认 0 = 必须完全相等

# ---- 站点 ----
SHOP_BASE_URL=https://shop.example.com          # 生成回调地址用，生产必须填
```

**就绪判定**：`EPAY_ENABLED=true` 且 `EPAY_PID`、`EPAY_KEY` 非空且网关是合法 https 地址。
未就绪时 `/api/payment/info` 返回 `enabled:false` 并给出具体原因（缺哪个变量），前端隐藏在线支付按钮，**余额支付仍可用**。

---

## 2. 签名与验签

易支付 V1 签名规则（`src/EPay.php`）：

1. 取所有参数，**剔除 `sign`、`sign_type` 与空值**
2. 按参数名 **ASCII 升序**（`ksort(..., SORT_STRING)`）
3. 拼成 `k=v&k=v`（值**不做** urlencode）
4. 末尾直接拼商户密钥：`sign = md5(拼接串 + KEY)`，小写

```php
$params = ['pid'=>'1001','type'=>'wxpay','out_trade_no'=>'SH...-A1B2C3',
           'money'=>'180.00','trade_status'=>'TRADE_SUCCESS', ...];
$sign = EPay::sign($params, $key, 'MD5');
```

安全细节：

| 风险 | 防护 |
|---|---|
| 时序侧信道猜签名 | `hash_equals()` 恒定时间比较 |
| 用 `sign_type` 降级攻击 | 只用服务端配置的算法，忽略请求里的 `sign_type` |
| 数组注入打乱签名串 | 只接受标量参数，数组/对象直接跳过 |
| 伪协议跳转（`javascript:`/`data:`） | `EPay::sanitizeGateway()` 强制 https + 合法主机名 + 禁止 URL 内嵌凭据 |
| 金额精度 | `EPay::toCents()` 严格正则解析，拒绝负数/超长小数 |

---

## 3. 三种支付方式

### 3.1 微信 / 支付宝（跳转易支付）

```
前端选通道 → POST /api/console/orders/{no}/pay {channel}
          → 服务端校验订单归属/状态/超时 → 落 payments 流水
          → 返回 {gateway, method:"POST", fields:{...含 sign}}
          → 前端构造隐藏表单 POST 到网关 → 用户完成支付
```

- 商户单号 `out_trade_no = 订单号 + "-" + 6位随机十六进制`，同一订单可多次发起，各自独立流水
- 密钥只在服务端参与签名，前端只拿到已签好的字段
- 前端 `submitToGateway()` 会再次校验 `gateway` 必须是 `https://` 才提交

### 3.2 余额支付（本地闭环）

```
POST /api/console/orders/{no}/pay-balance
```

**单事务内**完成，任一步失败整体回滚：

1. 校验订单归属（`user_id`）、状态必须是 `pending`、未超时
2. 校验金额 ≤ `config/config.php` 的 `rules.balance_pay_max_cents`（硬编码 `100000000` 分＝100 万元）。⚠️ **没有** `SHOP_BALANCE_PAY_MAX_CENTS` 这个环境变量，改动上限要直接改 `config.php`
3. 余额不足 → 返回 `insufficient_balance`，消息含**当前余额 / 应付 / 差额**：
   `余额不足：当前余额 ¥0.00，本单需 ¥180.00，还差 ¥180.00，请先充值`
4. `UPDATE users SET balance_cents = balance_cents - ? WHERE id = ? AND balance_cents >= ?`
   —— 条件更新 + `rowCount` 校验，并发下不会扣成负数
5. 写 `balance_ledger`（`type=consume`，记录 `balance_before` / `balance_after` / `order_no`）
6. 状态机 `pending → paid`，并触发自动发货

### 3.3 充值

```
POST /api/console/balance/recharge {amount_cents, channel}
```

建 `recharge_orders`（`recharge_no` 以 `RC` 开头）→ 返回网关表单 → 回调入账。
入账用 `ref_type=recharge + ref_id=充值单号` 幂等，重复回调不会重复加钱。

---

## 4. 异步回调（notify）

**易支付 V1 的异步通知是 GET 请求**，部分站点也会用 POST，因此 `/api/pay/notify` 同时接受 GET 与 POST（并兜底解析 JSON body）。

处理顺序（`PaymentService::handleNotify`）：

```
1. 必填字段齐全？        pid / trade_no / out_trade_no / type / money / trade_status / sign
2. 验签通过？            失败 → 返回 fail，记日志
3. trade_status == TRADE_SUCCESS？  否则返回 success（ignored），避免网关无限重试
4. type ∈ {wxpay, alipay}？          否则 fail
5. pid == 本站商户号？               否则 fail
6. out_trade_no 格式合法？           否则 fail
7. 按前缀分流：RC* → 充值单；其余 → 商品订单
```

商品订单继续：

```
8.  payments 表能查到该 out_trade_no？   查不到 → fail（未知订单号）
9.  回调 type == 发起时的通道？          不一致 → fail（通道校验）
10. 回调金额 == 订单应付金额？           超出容差 → fail（金额校验）
11. 订单已是 paid/shipped/done？         直接返回 success（幂等，不重复发货）
12. 订单是 cancelled？                   返回 success 停止重试，但订单保持 cancelled，
                                         流水标记 success 并记 paid_but_cancelled 日志，等人工退款
13. 事务内：状态机 pending→paid + 自动发货 + 流水置 success
```

**返回值**：成功返回纯文本 `success`；失败返回 `fail`（网关会重试）。

**审计**：每次回调都写 `[shop][notify]` 日志（结果、单号、金额、通道、IP 哈希），不写库以免被刷爆。

**同步返回（return）**：`/api/pay/return` **只解析订单号并跳转，绝不改状态**——否则用户手工构造 URL 就能把订单标成已支付。订单是否已支付只以 notify 为准。

---

## 5. 订单状态机

`src/OrderStateMachine.php` 是全站唯一的订单状态推进入口。

```
pending（待支付）
   ├─ paid      支付成功（网关回调 / 余额支付 / 后台确认）
   └─ cancelled 用户取消 / 超时关单

paid（已支付 / 待发货）
   ├─ shipped   已发货（虚拟商品自动发货后 / 实体商品管理员填运单号后）
   ├─ done      已完成
   └─ cancelled 退款后关单（归还库存）

shipped（已发货）
   ├─ done      确认收货 / 管理员标记完成
   └─ cancelled 退款后关单

done / cancelled 为终态
```

**幂等实现**：每次推进都是条件更新

```sql
UPDATE orders SET status = :to, updated_at = datetime('now')
WHERE id = :id AND status = :from
```

并发下只有一个请求 `rowCount = 1`，其余返回 `false`（不报错、不重复发货）。
每次成功推进写一条 `order_events`（`from_status` / `to_status` / `actor` / `reason`），可查完整流转链。

**超时关单**：`bin/close_expired.php`（建议 cron 每分钟跑）

```bash
* * * * * php /path/to/shop-php/bin/close_expired.php >> /var/log/shop-close.log 2>&1
```

关单顺序：**先归还库存，再改状态**（保证不会「已取消但库存没回」）；若状态已被并发推进则回滚库存归还。

---

## 6. 自动发货

### 6.1 虚拟商品

支付成功（`pending → paid`）后立即：

1. 读 `product_delivery_fields`（管理员在后台配的多行字段，如 IP / 账号 / 密码）
2. 按模板渲染，支持占位符：`{order_no}` `{date}` `{random:12}` `{uuid}`
3. 写入 `order_deliveries`（`status=delivered`，记录 `delivered_at`）
4. 若订单内**全部**是虚拟商品 → 状态机 `paid → shipped`

用户在「我的订单」点「发货内容」即可看到，每行可单独复制：

```
发货内容                    已发货
┌────────────────────────────────────┐
│ IP：10.0.0.8                  [复制] │
│ 账号：root                    [复制] │
│ 密码：2hbdsrwhsqZh            [复制] │
└────────────────────────────────────┘
发货时间：2026-11-13 15:46:42
```

### 6.2 实体商品

支付成功后**保持 `paid`（已支付 / 待发货）**，`order_deliveries` 记为 `pending`。
管理员在后台填快递公司 + 运单号后置为 `shipped`：

```
POST /api/admin/orders/{no}/ship {ship_company, ship_no}
```

用户订单页显示「实体商品已付款，等待店主发货」，发货后显示运单号。

### 6.3 混合订单

同一订单既有虚拟又有实体：虚拟部分立即发货，订单整体保持 `paid`（待实体发货），
管理员发货后转 `shipped`。

---

## 7. 接口一览

| 方法 | 路径 | 鉴权 | 说明 |
|---|---|---|---|
| GET | `/api/payment/info` | 公开 | 支付能力（enabled / channels / expire_minutes），**不含密钥** |
| POST | `/api/orders/{no}/pay` | 订单号 + token | 匿名通道发起支付 |
| POST | `/api/console/orders/{no}/pay` | 用户登录 | 已登录用户发起支付 |
| POST | `/api/console/orders/{no}/pay-balance` | 用户登录 | 余额支付 |
| POST | `/api/console/balance/recharge` | 用户登录 | 发起充值 |
| GET | `/api/console/balance/recharges` | 用户登录 | 充值记录 |
| GET/POST | `/api/pay/notify` | 网关签名 | 异步回调（返回纯文本 success/fail） |
| GET | `/api/pay/return` | 公开 | 同步跳回（只跳转，不改状态） |
| POST | `/api/admin/orders/{no}/ship` | 管理员 | 实体商品发货 |

### 错误码

| code | HTTP | 含义 |
|---|---|---|
| `payment_not_configured` | 503 | 易支付未配置 |
| `invalid_channel` | 400 | 通道不在 wxpay/alipay 内 |
| `already_paid` | 409 | 订单已支付 |
| `order_cancelled` | 409 | 订单已关闭 |
| `order_expired` | 409 | 订单超时 |
| `insufficient_balance` | 409 | 余额不足（含 balance/required/short） |
| `amount_too_large` | 400 | 超出余额支付上限 |
| `invalid_amount` | 400/409 | 金额异常 |

---

## 8. 测试

```bash
php -S 127.0.0.1:8090 -t public public/router.php   # 另开终端
bash tests/e2e_payment.sh                            # 59 项断言
```

覆盖：余额支付成功 + 自动发货、余额不足提示、重复回调幂等（连发 3 次只发货一次）、
未知订单号拒绝、已取消订单拒绝、金额不符拒绝、通道不符拒绝、签名错误拒绝、
发货内容可见、实体商品待发货 → 管理员发货、重复余额支付不重复扣款、超时关单归还库存、状态机事件链。

**未验证**：没有真实商户密钥，未与真实易支付网关做真实扣款与回调联调；
回调逻辑用本地按同算法生成的签名做了完整验证。生产上线前请用真实商户号跑一笔 0.01 元订单确认。

---

## 9. 风险与防护清单

| 风险 | 防护 |
|---|---|
| 伪造回调 | 先验签再做任何业务判断；`hash_equals` 恒定时间 |
| 重复回调重复发货 | 状态机条件更新（`WHERE status = 'pending'`，并发下只有一个回调能推进）；`order_deliveries` **只有普通索引** `idx_deliveries_order`，没有唯一约束，去重依赖 `markPaidAndDeliver()` 只被成功调用一次 |
| 金额篡改（少付） | 回调金额与订单应付**完全相等**（容差默认 0） |
| 通道串用 | 回调 `type` 必须等于发起时通道 |
| 未知/已取消订单 | 拒绝推进；已取消但已付款的记日志等人工退款 |
| 并发扣款透支 | `UPDATE ... WHERE balance_cents >= ?` + `rowCount` |
| 重复扣款 | 订单状态机 + 余额流水 `ref_type/ref_id` 幂等 |
| 密钥泄露 | 只在服务端 `.env`；`/api/payment/info` 不含密钥；日志脱敏 |
| 明文传输 | 网关地址强制 https |
| 超时订单占用库存 | cron 关单归还库存 |
