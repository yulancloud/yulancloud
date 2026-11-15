# 拾物集商城 v5 · API 契约文档

> 版本：5.0 · 最后更新：2026-11-13
> 基础路径：`{BASE}/api`（`{BASE}` 为站点根，子目录部署时自动带前缀）
> 所有接口返回 JSON，`Content-Type: application/json; charset=utf-8`

---

## 0. 通用约定

### 0.1 统一响应包

成功：

```json
{ "ok": true, "data": { } }
```

失败：

```json
{ "ok": false, "error": { "code": "invalid_input", "message": "手机号格式不正确" } }
```

- `error.code` 是稳定的机器可读标识，前端按它分支；`error.message` 是可直接展示的中文文案。
- 生产环境**不返回**堆栈、SQL、文件路径。

### 0.2 HTTP 状态码

| 状态码 | 含义 | 典型 code |
|---|---|---|
| 200 | 成功 | — |
| 201 | 创建成功 | — |
| 400 | 参数不合法 | `invalid_input` / `invalid_amount` |
| 401 | 未登录 / 会话失效 | `unauthorized` |
| 403 | 来源不合法（CSRF） | `csrf_failed` |
| 404 | 资源不存在（含越权） | `not_found` |
| 405 | 方法不允许 | `method_not_allowed` |
| 409 | 状态冲突 | `out_of_stock` / `already_paid` / `insufficient_balance` |
| 413 | 请求体过大（>64KB） | `payload_too_large` |
| 415 | 非 JSON 请求体 | `unsupported_media_type` |
| 429 | 触发限流 | `rate_limited` |
| 503 | 支付未配置 | `payment_not_configured` |

### 0.3 鉴权：两套完全独立的会话

| | 前台用户中心 | 后台管理 |
|---|---|---|
| 入口 | `/console` | `/admin` |
| 数据表 | `users` / `user_sessions` | `admin_users` / `admin_sessions` |
| Cookie | `shiwu_user_sid` | `shiwu_admin_sid` |
| 接口前缀 | `/api/console/*` | `/api/admin/*` |

- 会话 token 为 32 字节随机数（hex 64 位），**数据库只存 SHA-256**。
- Cookie 属性：`HttpOnly; SameSite=Lax; Path=/; Secure`（HTTPS 时）。
- 前台：空闲 7 天 / 绝对 30 天双超时；后台：空闲 2 小时 / 绝对 12 小时。
- 两套会话互不通用：管理员账号不能登录前台，前台用户也不能进后台。

### 0.4 CSRF

所有 `POST / PUT / DELETE` 请求必须满足其一：

1. 带 `Origin` 头且与当前站点同源（推荐，浏览器自动带）；
2. 无 `Origin` 时校验 `Referer` 同源。

不满足返回 `403 csrf_failed`。`GET / HEAD / OPTIONS` 不校验。

> 前端无需手动加 token：同源 `fetch` 浏览器会自动带 `Origin`。
> 若用 curl 调试，记得加 `-H 'Origin: http://你的域名'`。

### 0.5 限流

| 场景 | 窗口 | 上限 | 维度 |
|---|---|---|---|
| 前台注册 | 10 分钟 | 5 次 | IP |
| 前台登录 | 15 分钟 | 20 次 | IP |
| 前台登录 | 15 分钟 | 8 次 | 账号 |
| 下单 | 10 分钟 | 10 次 | IP |
| 发起支付 | 10 分钟 | 30 次 | IP |
| 发起充值 | 10 分钟 | 20 次 | IP |
| 用户中心读接口 | 1 分钟 | 240 次 | IP |
| 后台操作 | 1 分钟 | 300 次 | IP |
| 支付回调 | 1 分钟 | 120 次 | IP |

超限返回 `429`，响应头带 `Retry-After`。

### 0.6 金额

**所有金额字段一律为整数「分」**，字段名以 `_cents` 结尾。
前端展示时除以 100。服务端从不接受前端传来的价格/总额。

---

## 1. 公共接口

### GET `/api/health`

健康检查。

```json
{ "ok": true, "data": { "status": "ok", "installed": true, "version": "5.0", "time": "2026-11-13T14:00:00+00:00" } }
```

### GET `/api/site`

站点公开配置（后台可改，实时生效）。

```json
{
  "ok": true,
  "data": {
    "site_name": "拾物集",
    "site_name_en": "SHIWU",
    "hero_eyebrow": "八件器物",
    "hero_title": "少而耐，用得顺手",
    "hero_subtitle": "来自六个小工坊的日用器物",
    "site_intro": "拾物集只做少数几种日用器物……",
    "home_notice": "",
    "home_products_title": "在售器物",
    "footer_note": "手工制作，略有差异",
    "footer_copyright": "© 2026 拾物集",
    "footer_icp": "",
    "footer_contact": "",
    "support_email": "",
    "support_wechat": "",
    "support_qq": "",
    "meta_title": "拾物集 · 日用器物",
    "meta_description": "八件日用器物，来自六个小工坊。",
    "console_welcome": "欢迎回来"
  }
}
```

### GET `/api/catalog`

商品列表。

| 参数 | 类型 | 说明 |
|---|---|---|
| `cat` | string | 分类 slug，`all` 或省略为全部 |
| `q` | string | 搜索关键词（≤40 字，LIKE 已转义） |
| `sort` | string | `featured`(默认) / `price-asc` / `price-desc` / `new` / `sold` |
| `page` | int | 页码，从 1 开始 |
| `per` | int | 每页条数，1-48，默认 12 |

```json
{
  "ok": true,
  "data": {
    "items": [{
      "id": "p1", "sku": "SW-001", "name": "白瓷马克杯", "sub": "景德镇 · 手作",
      "cat": "tableware", "price": 12800, "was": 15800, "badge": "新品",
      "img": "images/p1.webp", "rating": 4.8, "sold": 126, "stock": 12,
      "type": "physical"
    }],
    "total": 8, "page": 1, "per": 12, "pages": 1
  }
}
```

### GET `/api/products/{id}`

商品详情（含颜色、规格、参数、保养、评价）。

```json
{
  "ok": true,
  "data": {
    "id": "p1", "name": "白瓷马克杯", "price": 12800, "stock": 12,
    "type": "physical",
    "colors": [{ "name": "月白", "hex": "#f2efe6" }],
    "sizes": [{ "name": "350ml", "extra": 0 }, { "name": "500ml", "extra": 2000 }],
    "specs": [{ "k": "容量", "v": "350ml" }],
    "care": ["可用洗碗机"],
    "reviews": [{ "name": "林**", "rating": 5, "text": "手感很好", "date": "2026-10-02" }]
  }
}
```

> `type`：`virtual` = 虚拟商品（支付后自动发货），`physical` = 实体商品（人工发货）。

### GET `/api/categories`

```json
{ "ok": true, "data": { "items": [{ "slug": "tableware", "name": "食器", "count": 3 }] } }
```

### GET `/api/payment/info`

支付能力探测（不含任何密钥）。

```json
{ "ok": true, "data": { "enabled": true, "channels": [{ "id": "alipay", "label": "支付宝" }], "expire_minutes": 30, "reason": "" } }
```

---

## 2. 前台用户中心 `/api/console/*`

### 2.1 POST `/api/console/register`

注册并自动登录。

请求：

```json
{ "username": "alice", "password": "Passw0rd123", "display_name": "爱丽丝", "email": "a@b.com" }
```

| 字段 | 必填 | 规则 |
|---|---|---|
| `username` | 是 | 3-32 位，`[A-Za-z0-9_.-]`，大小写不敏感唯一 |
| `password` | 是 | ≥8 位，必须同时含字母和数字，≤200 |
| `display_name` | 否 | ≤40 字，不含 `<>{}` 与反斜杠；空则用用户名 |
| `email` | 否 | 合法邮箱，≤120 |

响应 `201`：

```json
{ "ok": true, "data": { "user": { "id": 1, "username": "alice", "display_name": "爱丽丝", "balance_cents": 0, "status": "active", "created_at": "2026-11-13 14:00:00", "last_login_at": null } } }
```

错误：`400 register_failed`（用户名已占用 / 密码太弱 / 格式错误）。

### 2.2 POST `/api/console/login`

```json
{ "username": "alice", "password": "Passw0rd123" }
```

成功 `200`：`{ "ok": true, "data": { "user": { ... } } }`

失败 `401 login_failed`，message 统一为「用户名或密码错误」（不区分账号是否存在）。
账号被停用返回 `401`，message「该账号已被停用，请联系客服」。

### 2.3 POST `/api/console/logout`

需登录。销毁当前会话并清 Cookie。`{ "ok": true, "data": { "ok": true } }`

### 2.4 GET `/api/console/me`

探测登录态。未登录返回 `401 unauthorized`（前端据此显示登录页）。

```json
{ "ok": true, "data": { "user": { ... }, "payment": { "enabled": true, "channels": [...] } } }
```

### 2.5 GET `/api/console/overview`

用户中心首页聚合。

```json
{
  "ok": true,
  "data": {
    "user": { "id": 1, "username": "alice", "balance_cents": 50000, "...": "" },
    "balance": 50000,
    "stats": { "total_orders": 3, "pending_orders": 1, "paid_orders": 2, "spent_cents": 25600, "delivered_items": 2 },
    "payment": { "enabled": true, "channels": [] }
  }
}
```

### 2.6 GET `/api/console/orders`

我的订单列表。**只返回当前登录用户的订单。**

| 参数 | 说明 |
|---|---|
| `status` | 可选：`pending` / `paid` / `shipped` / `done` / `cancelled` |
| `limit` | 1-50，默认 20 |
| `offset` | 默认 0 |

```json
{
  "ok": true,
  "data": {
    "items": [{
      "order_no": "SH20261113A1B2C3D4",
      "status": "paid", "status_label": "已付款",
      "total": 12800, "item_qty": 1,
      "pay_channel": "balance",
      "created_at": "2026-11-13 14:00:00", "paid_at": "2026-11-13 14:01:00",
      "has_delivery": true
    }],
    "total": 1
  }
}
```

### 2.7 GET `/api/console/orders/{order_no}`

订单详情（含发货内容）。

**越权保护**：SQL 层强制 `user_id = 当前用户`。别人的订单返回 `404 not_found`（与「不存在」不可区分，防枚举）。

```json
{
  "ok": true,
  "data": {
    "order_no": "SH20261113A1B2C3D4",
    "status": "done", "status_label": "已完成",
    "subtotal": 12800, "ship": 0, "discount": 0, "total": 12800,
    "note": "", "buyer_name": "爱丽丝", "buyer_phone": "138****8000",
    "buyer_address": "上海市静安区…",
    "created_at": "2026-11-13 14:00:00", "paid_at": "2026-11-13 14:01:00",
    "pay_channel": "balance", "shipped_at": null, "ship_company": "", "ship_no": "",
    "pay": { "channel": "balance", "amount": 12800, "status": "success", "paid_at": "..." },
    "items": [{ "id": "p1", "name": "白瓷马克杯", "sku": "SW-001", "color": "月白", "size": "350ml", "unit": 12800, "qty": 1, "line": 12800 }],
    "deliveries": [{
      "id": 1, "product_id": "p1", "product_name": "白瓷马克杯",
      "kind": "virtual", "kind_label": "虚拟商品",
      "content": "IP：10.0.0.8\n账号：root\n密码：Xk9mQ2pLr7Tn",
      "status": "delivered", "status_label": "已发货",
      "delivered_at": "2026-11-13 14:01:00", "created_at": "2026-11-13 14:01:00"
    }]
  }
}
```

### 2.8 POST `/api/console/orders/{order_no}/pay`

发起在线支付（易支付）。返回网关表单参数，前端自动 POST 到 `gateway`。

```json
{ "channel": "alipay" }
```

```json
{
  "ok": true,
  "data": {
    "gateway": "https://pay.example.com/submit.php",
    "method": "POST",
    "fields": { "pid": "1001", "type": "alipay", "out_trade_no": "SH2026...-A1B2C3", "money": "128.00", "sign": "...", "sign_type": "MD5" },
    "out_trade_no": "SH2026...-A1B2C3",
    "amount": 12800
  }
}
```

错误：`409 already_paid` / `409 order_expired` / `409 order_cancelled` / `503 payment_not_configured`。

### 2.9 POST `/api/console/orders/{order_no}/pay-balance`

余额支付。**全流程单事务**：扣余额 → 写流水 → 订单转 paid → 自动发货。

请求体：`{}`（无需参数）

```json
{ "ok": true, "data": { "order_no": "SH2026...", "status": "paid", "paid": 12800, "balance": 37200, "channel": "balance" } }
```

错误：
- `409 insufficient_balance` 余额不足
- `409 already_paid` 已支付
- `409 order_expired` 已超时
- `400 amount_too_large` 超出余额支付上限

### 2.10 GET `/api/console/balance/ledger`

余额流水（含变动前后余额）。

```json
{
  "ok": true,
  "data": {
    "items": [{
      "id": 2, "type": "consume", "type_label": "消费",
      "amount": -12800, "balance_before": 50000, "balance_after": 37200,
      "order_no": "SH2026...", "remark": "余额支付",
      "created_at": "2026-11-13 14:01:00"
    }],
    "total": 1
  }
}
```

`type` 取值：`recharge` 充值 / `consume` 消费 / `refund` 退款 / `admin_adjust` 后台调整。

### 2.11 POST `/api/console/balance/recharge`

发起充值（易支付）。

```json
{ "amount_cents": 10000, "channel": "alipay" }
```

金额范围由服务端配置（默认 1 元 ~ 50000 元）。

```json
{ "ok": true, "data": { "recharge_no": "RC20261113A1B2C3D4", "gateway": "https://...", "method": "POST", "fields": {}, "amount": 10000 } }
```

### 2.12 GET `/api/console/balance/recharges`

充值记录。

```json
{ "ok": true, "data": { "items": [{ "recharge_no": "RC2026...", "amount": 10000, "status": "paid", "status_label": "已到账", "channel": "alipay", "paid_at": "...", "created_at": "..." }], "total": 1 } }
```

### 2.13 PUT `/api/console/profile`

修改昵称 / 邮箱。

```json
{ "display_name": "新昵称", "email": "new@example.com" }
```

```json
{ "ok": true, "data": { "user": {} } }
```

### 2.14 POST `/api/console/password`

修改密码。成功后**注销该账号其他所有会话**（当前会话保留）。

```json
{ "old_password": "Passw0rd123", "new_password": "NewPassw0rd9" }
```

```json
{ "ok": true, "data": { "message": "密码已更新" } }
```

错误 `400 password_change_failed`（当前密码错误 / 新密码太弱 / 与旧密码相同）。

---

## 3. 后台管理 `/api/admin/*`

> 全部需要后台会话（`shiwu_admin_sid`）。`requireOwner()` 的接口仅限 `owner` 角色。

### 3.1 认证

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/admin/login` | `{username, password}` → 下发后台 Cookie |
| POST | `/api/admin/logout` | 登出 |
| GET | `/api/admin/me` | 当前管理员信息 |

### 3.2 仪表盘与商品

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/admin/dashboard` | 统计概览 |
| GET | `/api/admin/products` | 商品列表（`q` / `cat` / `status` / `limit` / `offset`） |
| POST | `/api/admin/products` | 新建商品 |
| PUT | `/api/admin/products/{id}` | 更新商品 |
| DELETE | `/api/admin/products/{id}` | 删除商品 |
| POST | `/api/admin/products/{id}/status` | `{status}`：`active`/`hidden`/`soldout` |
| POST | `/api/admin/products/{id}/stock` | `{stock}` 设置库存 |
| GET | `/api/admin/categories` | 分类列表 |
| POST | `/api/admin/categories` | 新建分类 |
| PUT | `/api/admin/categories/{id}` | 更新分类 |
| DELETE | `/api/admin/categories/{id}` | 删除分类 |

### 3.3 商品发货配置（v5 新增）

#### GET `/api/admin/products/{id}/delivery`

```json
{
  "ok": true,
  "data": {
    "product_id": "p1",
    "name": "云服务器 2C4G",
    "type": "virtual",
    "template": "使用说明：登录后请立即修改密码",
    "fields": [
      { "label": "IP", "value": "10.0.0.8", "sort": 0 },
      { "label": "账号", "value": "root", "sort": 1 },
      { "label": "密码", "value": "{random:12}", "sort": 2 }
    ],
    "placeholders": ["{order_no}", "{date}", "{random:12}", "{uuid}"]
  }
}
```

#### PUT `/api/admin/products/{id}/delivery`

```json
{
  "type": "virtual",
  "template": "使用说明：登录后请立即修改密码",
  "fields": [
    { "label": "IP", "value": "10.0.0.8" },
    { "label": "账号", "value": "root" },
    { "label": "密码", "value": "{random:12}" }
  ]
}
```

| 字段 | 规则 |
|---|---|
| `type` | `virtual` / `physical` |
| `template` | ≤4000 字，每行一条，支持占位符 |
| `fields` | ≤20 行；`label` ≤30 字且不可重复、不含 `<>{}`；`value` ≤500 字 |

**占位符**（发货时展开）：

| 占位符 | 展开为 |
|---|---|
| `{order_no}` | 订单号 |
| `{date}` | 发货日期 `YYYY-MM-DD` |
| `{random:N}` | N 位随机字母数字（1-64） |
| `{uuid}` | 随机 UUID v4 |

响应：`{ "ok": true, "data": { "product_id": "p1", "type": "virtual", "fields": 3 } }`

### 3.4 订单

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/admin/orders` | 订单列表（`status` / `q` / `limit` / `offset`） |
| GET | `/api/admin/orders/{order_no}` | 订单详情（含 `user`、`payments`、`deliveries`） |
| POST | `/api/admin/orders/{order_no}/status` | `{status}` 手动改状态 |
| POST | `/api/admin/orders/{order_no}/ship` | 实体商品发货（见下） |
| GET | `/api/admin/orders/{order_no}/deliveries` | 该订单的发货内容列表 |
| PUT | `/api/admin/deliveries/{id}` | 修改 / 补发某条发货内容 |

#### POST `/api/admin/orders/{order_no}/ship`

```json
{ "ship_company": "顺丰速运", "ship_no": "SF1234567890" }
```

- 仅 `paid` / `shipped` 状态可发货。
- 运单号 `[A-Za-z0-9-]{4,60}`。
- 同时把该订单 `physical` 类型的 `pending` 发货记录置为 `delivered`，内容写入快递信息。

```json
{ "ok": true, "data": { "order_no": "SH2026...", "status": "shipped", "ship_company": "顺丰速运", "ship_no": "SF1234567890" } }
```

#### PUT `/api/admin/deliveries/{id}`

```json
{ "content": "IP：10.0.0.9\n账号：root\n密码：NewPass123", "status": "delivered" }
```

`status`：`pending` / `delivered` / `revoked`。`content` ≤4000 字。

### 3.5 用户管理（v5 新增）

#### GET `/api/admin/users`

| 参数 | 说明 |
|---|---|
| `q` | 用户名 / 昵称 / 邮箱模糊搜索（≤40 字，LIKE 已转义） |
| `status` | `active` / `disabled` |
| `limit` | 1-100，默认 30 |
| `offset` | 默认 0 |

```json
{
  "ok": true,
  "data": {
    "items": [{
      "id": 1, "username": "alice", "email": "", "display_name": "爱丽丝",
      "balance_cents": 37200, "status": "active",
      "order_count": 3, "spent_cents": 25600,
      "created_at": "2026-11-13 14:00:00", "last_login_at": "2026-11-13 15:00:00"
    }],
    "total": 1
  }
}
```

#### GET `/api/admin/users/{id}`

用户详情：`{ user, orders[], ledger[] }`。

#### POST `/api/admin/users/{id}/status`

```json
{ "status": "disabled" }
```

停用会**立即删除该用户所有会话**（强制下线）。

#### POST `/api/admin/users/{id}/reset-password`

生成 12 位一次性临时密码，设置 `must_change_password = 1`，并踢掉所有会话。

```json
{ "ok": true, "data": { "id": 1, "temp_password": "Xk9mQ2pLr7Tn", "message": "临时密码只显示这一次…" } }
```

> 临时密码**只在本次响应返回一次**，服务端不存明文。

#### POST `/api/admin/users/{id}/balance`

余额调整（加款 / 扣款）。

```json
{ "delta_cents": 10000, "remark": "客服补偿" }
```

| 字段 | 规则 |
|---|---|
| `delta_cents` | 整数，正数加款、负数扣款，不可为 0，绝对值 ≤1 亿元 |
| `remark` | **必填**，≤200 字，用于对账 |

```json
{ "ok": true, "data": { "id": 1, "delta": 10000, "balance": 47200 } }
```

错误 `409 insufficient_balance`（扣款后为负）。

#### GET `/api/admin/users/{id}/ledger`

该用户余额流水（`limit` / `offset`）。

### 3.6 评价 / 站点设置 / 审计

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/admin/reviews` | 评价列表 |
| POST | `/api/admin/reviews/{id}/status` | `{status}`：`visible` / `hidden` |
| DELETE | `/api/admin/reviews/{id}` | 删除评价 |
| GET | `/api/admin/settings` | 读取全部站点设置 |
| PUT | `/api/admin/settings` | `{settings: {key: value}}` 批量保存 |
| POST | `/api/admin/settings/reset` | 恢复默认值 |
| GET | `/api/admin/audit` | 操作审计日志（`action` / `limit` / `offset`） |

#### 可配置的站点文案键

| 键 | 说明 | 上限 |
|---|---|---|
| `site_name` | 网站标题 | 40 |
| `site_name_en` | 英文名 | 40 |
| `hero_eyebrow` | 首屏小标题 | 40 |
| `hero_title` | 首屏主标题 | 80 |
| `hero_subtitle` | 首屏副标题 | 200 |
| `site_intro` | 站点介绍（关于我们） | 2000 |
| `home_notice` | 首页公告条（空则隐藏） | 300 |
| `home_products_title` | 首页商品区标题 | 40 |
| `footer_note` | 页脚说明 | 200 |
| `footer_copyright` | 页脚版权 | 120 |
| `footer_icp` | 备案号 | 60 |
| `footer_contact` | 页脚联系方式 | 300 |
| `support_email` | 客服邮箱 | 120 |
| `support_wechat` | 客服微信 | 60 |
| `support_qq` | 客服 QQ | 60 |
| `meta_title` | SEO 标题 | 120 |
| `meta_description` | SEO 描述 | 200 |
| `console_welcome` | 用户中心欢迎语 | 60 |

保存后前台 `/api/site` 立即返回新值，无需重启。

---

## 4. 支付回调

### GET / POST `/api/pay/notify`

易支付异步通知入口。**由网关调用，不是前端接口。**

> 易支付 V1 的异步通知是 **GET** 请求；部分站点也会用 POST，因此两种方法都接受（并兜底解析 JSON body）。

处理流程：

1. 必填字段齐全（`pid` / `trade_no` / `out_trade_no` / `type` / `money` / `trade_status` / `sign`）
2. 验签（`MD5` / `SHA256`，`hash_equals` 恒定时间比较；算法只用服务端配置）
3. 校验 `trade_status == TRADE_SUCCESS`（其他状态返回 `success` 让网关停止重试）
4. 校验 `type ∈ {wxpay, alipay}`（只支持微信与支付宝）
5. 校验 `pid` 等于本站商户号
6. 按 `out_trade_no` 前缀分流：
   - `RC...` → 充值单，入账余额
   - 其他 → 商品订单，推进状态 + 自动发货
7. 校验回调 `type` 与发起支付时的通道一致
8. 校验金额与应付一致（容差默认 0，必须完全相等）
9. 幂等：条件更新 `WHERE status = 'pending'`，重复回调只成功一次
10. 未知订单号 / 已取消订单：拒绝推进（已取消但已付款的记日志等人工退款）

成功返回纯文本 `success`；失败返回 `fail`（网关会重试）。

### GET `/api/pay/return`

同步跳回。**只解析订单号并跳转，绝不改状态**（状态只由 notify 推进）。

---

## 5. 订单状态机

```
                    ┌──────────────┐
                    │   pending    │ 待付款
                    └──────┬───────┘
         支付成功 / 余额支付 │        超时未支付
                    ┌──────┴───────┐
                    ▼              ▼
              ┌──────────┐   ┌───────────┐
              │   paid   │   │ cancelled │ 已取消（库存归还）
              └────┬─────┘   └───────────┘
   全部虚拟商品自动发货│      实体商品管理员发货
                    ▼              ▼
              ┌──────────┐   ┌───────────┐
              │ shipped  │   │  shipped  │ 已发货
              └────┬─────┘   └─────┬─────┘
                   └──────┬────────┘
                          ▼
                    ┌──────────┐
                    │   done   │ 已完成
                    └──────────┘
```

- `pending → paid`：唯一入口 `PaymentService::markPaidAndDeliver()`，条件更新保证只推进一次。
- 支付成功后立即调用 `DeliveryService::deliverVirtualItems()`：
  - 虚拟商品 → 按模板生成内容，`status = delivered`
  - 实体商品 → 登记 `pending` 记录，等管理员发货，订单保持 `paid`（待发货）
- 若订单**全部**为虚拟商品，`markPaidAndDeliver()` 紧接着把状态推进到 **`shipped`**（**不是 `done`**）——用户此时即可在「我的订单」看到发货内容。`shipped → done` 由用户确认收货或后台标记完成触发。

---

## 6. 前端对接要点

### 6.1 会话

```js
// 探测登录态
fetch('/api/console/me', { credentials: 'same-origin' })
  .then(r => r.ok ? r.json() : Promise.reject(r.status));
```

- 必须带 `credentials: 'same-origin'`（或 `include`），否则 Cookie 不发送。
- 不要试图读 Cookie：`HttpOnly` 下 JS 读不到，这是设计。

### 6.2 写请求

```js
fetch('/api/console/orders', {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(payload)
});
```

浏览器自动带 `Origin`，服务端据此做 CSRF 校验。**不要**手动伪造 `Origin`。

### 6.3 下单幂等

```js
headers: { 'Idempotency-Key': crypto.randomUUID() }
```

同一用户 + 同一 key 重复提交返回同一订单（`duplicate: true`），不会重复扣库存。

### 6.4 在线支付跳转

```js
const { gateway, method, fields } = data;
const form = document.createElement('form');
form.method = method; form.action = gateway;
Object.entries(fields).forEach(([k, v]) => {
  const i = document.createElement('input');
  i.type = 'hidden'; i.name = k; i.value = v;
  form.appendChild(i);
});
document.body.appendChild(form);
form.submit();
```

### 6.5 错误处理

```js
if (!res.ok) {
  const { error } = await res.json();
  if (error.code === 'unauthorized') location.href = '/console/';
  else if (error.code === 'insufficient_balance') showRecharge();
  else toast(error.message);
}
```

---

## 7. 安全清单（已实现并实测）

| 风险 | 防护 |
|---|---|
| SQL 注入 | 全量 PDO 预处理，`EMULATE_PREPARES=false`；排序字段白名单；LIKE 通配符转义 |
| 密码泄露 | Argon2id（不可用退化 bcrypt）；`password_needs_rehash` 自动升级；不存明文 |
| 会话劫持 | token 32 字节随机；DB 只存 SHA-256；HttpOnly + SameSite=Lax + Secure |
| CSRF | SameSite=Lax + Origin/Referer 同源强校验（写操作） |
| 撞库 / 爆破 | 登录按 IP + 账号双维度限流；失败信息不区分账号是否存在；不存在时也跑哈希 |
| 水平越权 | 订单查询 SQL 强制 `user_id`；越权返回 404 而非 403（防枚举） |
| 垂直越权 | 前台/后台独立表、独立 Cookie、独立路由前缀；敏感操作 `requireOwner()` |
| 价格篡改 | 单价、规格加价、运费、折扣、总额全部服务端重算 |
| 超卖 | 事务内 `UPDATE ... WHERE stock >= ?` + `CHECK (stock >= 0)` |
| 重复下单 | `idempotency_key` 唯一索引 |
| 重复支付回调 | 状态机条件更新 + 流水 `(user_id, type, ref_type, ref_id)` 唯一索引 |
| 余额扣成负数 | 条件更新 `WHERE balance_cents >= ?` + `CHECK (balance_cents >= 0)` |
| 支付金额篡改 | 回调金额与应付比对（容差可配）；`pid` 校验；渠道一致性校验 |
| 支付伪造 | 验签恒定时间比较；`sign_type` 用服务端配置，不信请求 |
| 命令注入 | 无 `exec`/`shell_exec`/`system` 调用 |
| 路径穿越 | 路由白名单；`data/` 在 web 根之外；敏感扩展名拦截 |
| SSRF | 网关地址强制 https 且域名白名单校验 |
| XSS | 前端全部 `textContent` / `esc()`；服务端输出 JSON 不拼 HTML |
| 敏感信息泄露 | 生产不返回堆栈；日志不含手机号/密码；IP 只存哈希 |
| 请求体攻击 | 强制 `application/json`（否则 415）；64KB 上限（413）；JSON 深度 32 |
| 硬编码密钥 | 全部走环境变量；仓库内无任何真实密钥 |
| 依赖漏洞 | 零第三方依赖（无 Composer / npm 运行时依赖） |

---

## 8. 变更记录

### v5.0（2026-11-13）

新增：
- 前台用户体系（注册 / 登录 / 会话 / 改密 / 资料）
- 用户余额 + 余额流水（充值 / 消费 / 退款 / 后台调整）
- 订单归属用户，强制登录下单，按用户隔离查询
- 商品类型（虚拟 / 实体）+ 发货内容模板 + 自动发货
- 订单发货内容表，用户中心可查看、可复制
- 充值单与充值回调
- 余额支付
- 后台用户管理（列表 / 详情 / 停用 / 重置密码 / 余额调整 / 流水）
- 后台商品发货模板可视化配置
- 后台订单发货内容编辑与实体商品发货
- 站点文案扩展（站点介绍 / 公告 / 商品区标题 / 客服联系方式）

变更：
- `POST /api/orders` 现在**必须登录**（v4 为匿名下单）
- 订单查询主通道改为 `/api/console/orders/{no}`（按 user_id）
- 订单号 + token 匿名查询保留为兼容通道

### v4.0

- 易支付接入、后台管理、订单状态机、限流、审计
