# 拾物集商城 v5.5 · PHP 8.5 + SQLite

一个可直接跑起来的个人售卖网站后端 + 前台 + 后台，**零第三方依赖**（不需要 MySQL、不需要 Composer、不需要 npm 构建）。

v5 在 v4（易支付 + admin 后台）基础上扩展了**账号体系、余额、订单归属、虚拟商品自动发货、站点文案后台可配**；
v5.1 接入**易支付 + 自动发货**；v5.3 补齐**网页安装向导**、修复 **/admin 404** 与 **/console「服务器返回异常」**，并给出三种部署方式的完整配置；
v5.4 修复**安装页「无法读取安装状态：HTTP 500」**（`preflight.php` 误把 PHP 8.5 当硬门槛），安装向导补上站点地址与易支付配置，并给安装状态接口加异常兜底；
**v5.5 修复线上「/api/* 返回 HTTP 200 但响应体是 PHP 源码」**（Nginx `^~ /api/` 用 `try_files` 把 `api/index.php` 当静态文件返回），
并把 `/api/*` 加固为**任何情况下只输出纯 JSON**，安装页解析失败时直接显示响应体前 200 字符。

> **PHP 版本**：最低 **8.1**，推荐 **8.5**。代码不使用任何已废弃语法/函数，8.1 ~ 8.5 均可运行。
> 线上若出现异常，**先看第 7.1.1 节的三条 curl 自检**，不要先怀疑 PHP 版本。

---

## 1. 三分钟跑起来

### 方式 A：网页安装向导（推荐，生产环境用这个）

```bash
tar xzf shop-php-sqlite-v5.5.tar.gz
cd shop-php

# 起服务（本地试跑；生产用 Nginx/Apache，见第 7 节）
php -S 127.0.0.1:8090 -t public public/router.php
```

浏览器打开 **http://127.0.0.1:8090/install** ，按向导走：

1. **环境检查** —— PHP 版本、`pdo_sqlite` / `json` / `mbstring`、`config/` 与 `data/` 可写、伪静态是否生效
2. **填写配置** —— 站点地址、易支付 PID / KEY / 网关、管理员账号与密码
3. **一键安装** —— 自动写 `config/.env`、建表（含 v5 增量表）、导入种子数据、生成管理员
4. **完成** —— 生成 `config/installed.lock` 并锁定

> 安装完成后再次访问 `/install` 会被直接拒绝（`403 already_installed`），防止被重复安装或改配置。
> 确需重装：手动删除 `config/installed.lock` 与 `data/shop.sqlite`。

### 方式 B：命令行安装（CI / 无浏览器环境）

```bash
php bin/init_db.php          # 建库 + 种子数据 + 管理员（密码只打印一次）
php -S 127.0.0.1:8090 -t public public/router.php
```

打开：

| 地址 | 说明 |
|---|---|
| http://127.0.0.1:8090/ | 前台商城 |
| http://127.0.0.1:8090/console | **用户中心**（注册 / 登录 / 我的订单 / 余额） |
| http://127.0.0.1:8090/admin | **管理后台** |
| http://127.0.0.1:8090/install | **安装向导** |

忘记管理员密码：

```bash
php bin/reset_admin.php
```

### 环境要求

- PHP **8.5**（推荐版本，已按 8.5 语法标准编写）
- **最低要求 PHP 8.1**：8.1 / 8.2 / 8.3 / 8.4 / 8.5 均可正常运行
- 扩展：`pdo_sqlite`、`json`、`mbstring`
- 可选：`sodium`（Argon2id 需要 PHP 编译时带 libsodium；没有则自动退化 bcrypt）

> ⚠️ **低于 PHP 8.1 无法运行**：代码使用了 8.1 的语法（`never`、`mixed`、枚举等）。
> 入口文件 `public/preflight.php` 会先做版本检查，不达标时输出一张可读的「PHP 版本过低」说明页（HTTP 503），
> 而不是抛出一段裸的 `Parse error`。
>
> ✅ **8.5 只是推荐版本，不是硬门槛**。v5.3 曾把 8.5 写成硬性最低要求，导致 8.1/8.2 的服务器上
> **每个请求都返回 HTTP 500**（安装页因此显示「无法读取安装状态：HTTP 500」）。v5.4 已修正：
> 硬门槛 = 8.1，8.5 仅在自检里作为「推荐」提示，不阻塞安装与运行。

检查：

```bash
php -v
php -m | grep -E 'pdo_sqlite|json|mbstring'
```

---

## 2. 目录结构

```
shop-php/
├── bin/
│   ├── init_db.php          # 建库 + 种子数据 + 创建管理员
│   ├── migrate.php          # v4 → v5 迁移（幂等，自动备份）
│   ├── reset_admin.php      # 重置管理员密码
│   └── close_expired.php    # 关闭超时未支付订单（建议 cron 每分钟）
├── config/
│   └── config.php           # 全部配置（读环境变量，无硬编码密钥）
├── data/
│   └── shop.sqlite          # 数据库（在 web 根之外，不可直接访问）
├── database/
│   ├── schema.sql           # 基础表结构（v4 + v5 字段）
│   ├── schema_v5.sql        # v5 增量表
│   └── Seeder.php           # 示例数据
├── docs/
│   └── API.md               # ★ 完整 API 契约文档
├── public/                  # ← web 根目录
│   ├── index.html           # 前台首页
│   ├── product.html         # 商品详情
│   ├── cart.html            # 购物袋 + 结算
│   ├── order.html           # 订单详情（匿名 token 通道）
│   ├── pay-result.html      # 支付返回页
│   ├── router.php           # 内置服务器路由
│   ├── api/index.php        # ★ 全部 API 入口
│   ├── assets/              # 前台样式与脚本
│   ├── console/             # ★ 用户中心（v5 新增）
│   │   ├── index.html
│   │   └── assets/{console.css,console.js}
│   └── admin/               # 管理后台
│       ├── index.html
│       ├── login.html
│       └── assets/{api.js,views.js,views-v5.js,app.js,admin.css}
├── src/
│   ├── Db.php               # PDO 封装（预处理、事务）
│   ├── Response.php         # 统一 JSON 响应
│   ├── Validator.php        # 输入校验
│   ├── RateLimiter.php      # 限流（SQLite 计数）
│   ├── SettingsService.php  # 站点设置（后台可改）
│   ├── ProductRepo.php      # 商品查询
│   ├── OrderService.php     # 订单（v5：强制登录 + 归属）
│   ├── PaymentService.php   # 易支付 + 余额支付 + 充值
│   ├── AdminAuth.php        # 后台鉴权
│   ├── AdminService.php     # 后台业务
│   ├── Audit.php            # 操作审计
│   ├── EPay.php             # 易支付签名/验签
│   ├── UserAuth.php         # ★ 前台鉴权（v5）
│   ├── BalanceService.php   # ★ 余额与流水（v5）
│   ├── DeliveryService.php  # ★ 发货内容（v5）
│   └── ConsoleService.php   # ★ 用户中心聚合（v5）
└── tests/
    └── e2e.sh               # 43 项端到端测试
```

---

## 3. v5 新增了什么

### 3.1 两套独立登录

| | 前台 `/console` | 后台 `/admin` |
|---|---|---|
| 表 | `users` / `user_sessions` | `admin_users` / `admin_sessions` |
| Cookie | `shiwu_user_sid` | `shiwu_admin_sid` |
| 密码 | Argon2id | Argon2id |
| 空闲超时 | 7 天 | 2 小时 |
| 绝对超时 | 30 天 | 12 小时 |

- 会话 token 32 字节随机，**数据库只存 SHA-256**，DB 泄露也无法冒充。
- Cookie：`HttpOnly` + `SameSite=Lax` + `Secure`（HTTPS 时）。
- 写操作强制校验 `Origin`/`Referer` 同源（CSRF 兜底）。
- 登录按 **IP + 账号** 双维度限流，失败信息不区分账号是否存在。

### 3.2 用户余额

- `users.balance_cents` 整数存「分」，`CHECK (balance_cents >= 0)`。
- `balance_ledger` 记录每一笔变动：类型、金额、**变动前后余额**、关联订单号、备注、操作人。
- 幂等：`(user_id, type, ref_type, ref_id)` 唯一索引，重复回调只入账一次。
- 扣款用条件更新 `WHERE balance_cents >= ?`，并发下不会扣成负数。

### 3.3 订单归属

- `POST /api/orders` **必须登录**，订单写入 `user_id`。
- 用户中心查询强制 `WHERE user_id = ?`，改订单号也看不到别人的单（返回 404，防枚举）。
- 多账号订单天然隔离，各自独立。
- 历史订单（v4 迁移而来）`user_id` 为 NULL，仍可用「订单号 + token」查询。

### 3.4 虚拟商品自动发货

商品增加两个字段：

- `type`：`virtual`（虚拟）/ `physical`（实体）
- `delivery_template`：发货内容模板

后台可为虚拟商品配置**多行字段**（可视化编辑）：

| 字段名 | 取值 |
|---|---|
| IP | 10.0.0.8 |
| 账号 | root |
| 密码 | `{random:12}` |

支付成功后，系统按模板生成内容写入 `order_deliveries`，用户在「我的订单」里直接看到并可一键复制。

**占位符**：

| 占位符 | 展开为 |
|---|---|
| `{order_no}` | 订单号 |
| `{date}` | 发货日期 |
| `{random:N}` | N 位随机字母数字 |
| `{uuid}` | 随机 UUID v4 |

实体商品：支付后登记为「待发货」，管理员在后台填快递公司 + 运单号后置为「已发货」。

### 3.5 站点文案后台可配

全部存 `site_settings` 表，前台 `/api/site` 实时读取，**不写死在 HTML 里**：

网站标题、英文名、首屏小标题/主标题/副标题、站点介绍、首页公告条、商品区标题、页脚说明、页脚版权、备案号、页脚联系方式、客服邮箱/微信/QQ、SEO 标题与描述、用户中心欢迎语。

---

## 4. 从 v4 升级

```bash
# 1) 备份（脚本也会自动备份一份）
cp data/shop.sqlite data/shop.sqlite.manual-bak

# 2) 先看要做什么（不改库）
php bin/migrate.php --dry-run

# 3) 执行
php bin/migrate.php
```

迁移内容：

1. 新建 6 张 v5 表
2. `products` 加 `type`（默认 `physical`）、`delivery_template`
3. `orders` 加 `user_id`（历史订单为 NULL）
4. 补索引

**幂等**：重复执行会输出「无需迁移」，不会重复加列。
**安全**：整个迁移在事务内，失败自动回滚；执行前自动备份到 `data/shop.sqlite.bak-时间戳`。

---

## 5. 配置

全部通过环境变量，**代码里没有任何真实密钥**。

```bash
# ---- 基础 ----
export SHOP_BASE_URL="https://shop.example.com"   # 站点根，用于回调地址
export SHOP_DB_PATH="/var/lib/shop/shop.sqlite"   # 数据库路径（建议在 web 根外）

# ---- 易支付 ----
export EPAY_ENABLED="true"
export EPAY_GATEWAY="https://pay.example.com/submit.php"   # 必须 https
export EPAY_PID="1001"
export EPAY_KEY="你的商户密钥"
export EPAY_SIGN_TYPE="MD5"                       # MD5 | SHA256
export EPAY_CHANNELS="wxpay,alipay"               # 只支持微信与支付宝
export EPAY_NOTIFY_URL="https://shop.example.com/api/pay/notify"
export EPAY_RETURN_URL="https://shop.example.com/api/pay/return"
export EPAY_EXPIRE_MINUTES="120"                  # 订单支付时限
export EPAY_AMOUNT_TOLERANCE_CENTS="0"            # 金额容差，0 = 必须完全相等

# ---- 前台会话 ----
export SHOP_USER_IDLE_TIMEOUT="604800"            # 7 天
export SHOP_USER_ABSOLUTE_TIMEOUT="2592000"       # 30 天

# ---- 充值限额（分） ----
export SHOP_RECHARGE_MIN_CENTS="100"              # 1 元
export SHOP_RECHARGE_MAX_CENTS="5000000"          # 5 万元
```

未配置易支付时，`/api/payment/info` 返回 `enabled: false`，前端会隐藏在线支付按钮并提示「在线支付未配置」，**余额支付仍可用**（由管理员后台加款）。

> **支付与自动发货的完整说明见 [`docs/PAYMENT.md`](docs/PAYMENT.md)**：签名规则、三种支付方式、回调处理顺序、订单状态机、虚拟/实体发货、错误码、风险清单。

---

## 6. 测试

```bash
# 起服务（另开一个终端）
php -S 127.0.0.1:8090 -t public public/router.php

# 重置数据库并设置测试管理员密码
php bin/init_db.php --reset
php -r '$c=require "config/config.php";$p=new PDO("sqlite:".$c["db_path"]);
$p->exec("UPDATE admin_users SET password_hash=\"".password_hash("AdminPass123",PASSWORD_ARGON2ID)."\" WHERE username=\"admin\"");'

# 跑端到端测试（43 项）
bash tests/e2e.sh
```

覆盖：

- 未登录下单被拒（401）
- 注册 / 弱密码拒绝 / 大小写不敏感查重
- 登录 / 错误密码 / 登录态探测
- 下单 / 幂等重复提交
- **越权**：B 账号查 A 账号订单 → 404
- 余额不足拒绝 / 后台加款 / 流水含变动前后余额
- 余额支付 / 重复支付拒绝
- **CSRF**：跨站 Origin 拒绝 / 无来源写请求拒绝
- 后台用户列表 / 商品发货模板保存与读回
- **虚拟商品下单 → 支付 → 自动发货**（IP/账号/密码三行，占位符已展开）
- 站点设置保存 → 前台实时读到
- 后台订单详情含发货内容
- 未登录访问 console / admin 接口被拒
- 停用用户 → 会话立即失效
- 修改密码 → 新密码可登录

---

## 7. 生产部署（三种方式，任选其一）

> **唯一铁律：站点根目录必须是 `public/`，不是项目根目录。**
> 写成项目根目录 → `/api/index.php` 变成 `/public/api/index.php` 找不到 → 全站 404。
> 这是线上 `/admin` 404 与 `/console`「服务器返回异常」的第一大原因。

### 7.1 Nginx + PHP-FPM（推荐）

完整可直接照抄的配置在 **`deploy/nginx-root.conf`**，核心是这几段（缺一段就出问题）：

```nginx
root /www/wwwroot/pay.example.com/public;   # ← 必须是 public/
index index.html index.php;

# 【核心 1】API：/api/ 前缀下**直接 fastcgi_pass**，不要用 try_files 兜底
#   ⚠️ 写成 try_files $uri $uri/ /api/index.php 会出大问题：
#      /api/index.php 是磁盘上真实存在的文件，try_files 第一个参数就命中，
#      Nginx 把它当**静态文件**返回 → 浏览器下载到 PHP 源码（Content-Type:
#      application/octet-stream），前端 JSON.parse 失败 → 「服务器返回异常」。
#      这就是 2026-11-14 线上故障的真实原因。
location ^~ /api/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/api/index.php;
    fastcgi_param SCRIPT_NAME     /api/index.php;
    fastcgi_param REQUEST_URI     $request_uri;
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;   # ← 改成你的 PHP 版本
}

# 【核心 2】后台：/admin → /admin/ → admin/index.html
location = /admin { return 301 /admin/; }
location ^~ /admin/ { try_files $uri $uri/ /admin/index.html =404; }

# 【核心 3】用户中心：/console → /console/ → console/index.html
location = /console { return 301 /console/; }
location ^~ /console/ { try_files $uri $uri/ /console/index.html =404; }

# 【核心 4】PHP：只放行入口文件，其余 .php 一律 404（源码泄露的最后一道闸）
location ~ \.php$ {
    location ~ ^/(api/index|router)\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param REQUEST_URI     $request_uri;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;   # ← 改成你的 PHP 版本
    }
    return 404;
}
```

> **为什么 `^~ /api/` 会抢走 `.php` 正则？**
> Nginx 的 location 优先级是：`=` 精确 > `^~` 前缀（命中即停，不再试正则）> 正则 `~` > 普通前缀。
> 所以 `location ^~ /api/` 一旦命中，`location ~ \.php$` 根本不会被考虑。
> 旧配置在 `^~ /api/` 里用 `try_files` 兜底到 `/api/index.php`，
> 而该文件真实存在 → 直接静态返回 → **PHP 源码泄露**。
> 修复方式就是让 `^~ /api/` 自己 `fastcgi_pass`，不再走静态兜底。

部署步骤：

```bash
tar xzf shop-php-sqlite-v5.5.tar.gz -C /www/wwwroot/pay.example.com
cp deploy/nginx-root.conf /etc/nginx/sites-available/shop.conf
# 改 root / server_name / fastcgi_pass 三处
ln -s /etc/nginx/sites-available/shop.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

宝塔面板：网站 → 设置 → 配置文件，把 `server{}` 内容替换成 `deploy/nginx-root.conf` 的内容。

#### 7.1.1 上线后必做的 3 条自检

```bash
# ① 状态码 + Content-Type + 响应体开头（最关键）
curl -i --max-time 10 https://pay.chengbuwan.com/api/install/status | head -40

# ② 只看响应体前 300 字符
curl -s https://pay.chengbuwan.com/api/install/status | head -c 300

# ③ 确认响应体是合法 JSON
curl -s https://pay.chengbuwan.com/api/install/status | python3 -m json.tool | head -20
```

**怎么判断是哪种故障：**

| 现象 | 判定 | 处理 |
|---|---|---|
| `Content-Type: application/octet-stream` 或 `text/x-php`，响应体以 `<?php` 开头 | **Nginx 把 .php 当静态文件返回了源码**（本次线上故障） | 按 7.1 改 `^~ /api/` 为 `fastcgi_pass`，并加「其余 .php 一律 404」 |
| `Content-Type: text/html`，响应体含 `<!DOCTYPE` 或 router 的 404 页面 | 请求兜底到了 `router.php` 的 HTML 分支，没进 API 分发 | 检查 `^~ /api/` 是否被别的 location 覆盖；确认 `api/index.php` 存在 |
| `Content-Type: application/json` 但 `ok:false` | 接口正常，是业务错误 | 看 `error.code` / `error.message` |
| 响应体开头有 `Warning:` / `Notice:` / `Deprecated:` | PHP 输出污染 | v5.5 已用 `display_errors=0` + 输出缓冲 + 错误转 JSON 兜住；仍出现请查 PHP 错误日志 |
| HTTP 404 且是 Nginx 自己的 404 页 | 伪静态没配 / 站点根不是 `public/` | 按 7.1 检查 `root` 与 `location` |

### 7.2 Apache（.htaccess）

`public/.htaccess` 已内置，**前提是 `AllowOverride All`**：

```apache
<Directory /var/www/shop/public>
    AllowOverride All
    Require all granted
</Directory>
```

```bash
a2enmod rewrite
systemctl restart apache2
```

`.htaccess` 做的事：`/api/*` → `api/index.php`；`/admin`、`/console`、`/install` → 对应 `index.html`；敏感文件 404。

### 7.3 PHP 内置服务器（本地开发 / 临时演示）

```bash
php -S 127.0.0.1:8090 -t public public/router.php
```

**必须带 `public/router.php`**。不带的话内置服务器只按静态文件找路径，`/admin`、`/api/*` 全部 404。

### 7.4 目录权限

```bash
chown -R root:www-data /var/www/shop
chmod -R 750 /var/www/shop
# 只有这两个目录需要写
chown -R www-data:www-data /var/www/shop/data /var/www/shop/config
chmod -R 770 /var/www/shop/data /var/www/shop/config
```

### 7.5 定时任务

```cron
# 每分钟关闭超时未支付订单并归还库存
* * * * * cd /var/www/shop && php bin/close_expired.php >> /var/log/shop-cron.log 2>&1

# 每天 03:00 备份（SQLite 在线备份，不锁库）
0 3 * * * /var/www/shop/bin/backup.sh >> /var/log/shop-backup.log 2>&1
```

### 7.6 systemd（可选，用于常驻 worker）

```ini
# /etc/systemd/system/shop-close-expired.service
[Unit]
Description=Shop close expired orders
[Service]
Type=oneshot
WorkingDirectory=/var/www/shop
ExecStart=/usr/bin/php bin/close_expired.php
User=www-data
```

```ini
# /etc/systemd/system/shop-close-expired.timer
[Unit]
Description=Run shop close-expired every minute
[Timer]
OnCalendar=*:0/1
Persistent=true
[Install]
WantedBy=timers.target
```

```bash
systemctl enable --now shop-close-expired.timer
```

### 7.7 PHP-FPM 调优（SQLite 场景）

```ini
pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
```

SQLite 已开 WAL（`Db.php`），读写可并发、写写串行。日订单量万级以内完全够用。

---

## 7.8 【重点】/admin 404 与「服务器返回异常」排查手册

线上实测到的两个真实故障，按这个顺序查：

### 症状 A：`/admin` 返回 404

| 排查项 | 怎么查 | 修复 |
|---|---|---|
| **1. 站点根目录不是 public/** | `curl -I https://你的域名/api/index.php` 若 404，说明路径不对 | Nginx `root` 改成 `.../public` |
| **2. `/api/` 没交给 PHP-FPM** | `curl -I https://你的域名/api/health` 返回 nginx 的 404；或直接返回 `<?php` 源码 | `location ^~ /api/` 内**直接 `fastcgi_pass`**，固定 `SCRIPT_FILENAME` 为 `api/index.php`。⚠️ 绝不要写 `try_files $uri ... /api/index.php` —— `$uri` 命中磁盘上真实存在的 `api/index.php` 时会把 PHP 源码当静态文件返回，这正是 v5.5 修的线上故障 |
| **3. 缺 `/admin/` 目录索引兜底** | `/admin` 301 到 `/admin/` 后 404 | 加 `location ^~ /admin/ { try_files $uri $uri/ /admin/index.html =404; }` |
| **4. 没走 router.php（内置服务器）** | 启动命令里没有 `public/router.php` | `php -S 127.0.0.1:8090 -t public public/router.php` |
| **5. Apache 没开 rewrite** | `.htaccess` 存在但无效 | `AllowOverride All` + `a2enmod rewrite` |
| **6. PHP 版本过低** | 访问 `/router.php` 看到 `Parse error` | 升级到 PHP 8.1 以上（推荐 8.5） |
| **7. 安装页报「无法读取安装状态：HTTP 500」** | `curl -i https://你的域名/api/install/status` 返回 500 | 见下方「症状 C」 |

### 症状 C：`/install` 提示「无法读取安装状态：HTTP 500」

**根因（v5.3 的真实 bug，v5.4 已修）**：`public/preflight.php` 把 **PHP 8.5 当成了硬性最低要求**。
服务器实际是 PHP 8.1/8.2 时，`preflight.php` 在**每个请求**的最前面就输出「PHP 版本过低」页并 `exit`，
状态码 500 —— 于是 `/api/install/status` 永远拿不到 JSON，安装页只能显示「无法读取安装状态：HTTP 500」。

**为什么切到 PHP 8.1 也没用**：因为 8.1 同样低于那个错误的 8.5 门槛，照样被拦。

**v5.4 的修复**：
- `preflight.php`：硬门槛改为 **8.1**，8.5 降级为「推荐」（仅提示，不阻塞）；版本过低时返回 **503** 而非 500
- `Installer::requirements()`：自检项拆成「≥ 8.1（最低要求，硬）」与「≥ 8.5（推荐，soft）」两条
- `/api/install/status`：整个状态构建包进 `try/catch`，任何异常都返回结构化 JSON
  （`{"ok":false,"error":{"code":"install_status_error","message":"..."}}`），不再冒泡成裸 500
- 安装页前端：先读 `res.text()` 再尝试 `JSON.parse`，5xx 时明确提示「服务器返回 HTTP 5xx（PHP 未能正常执行）」

**服务器上要执行的检查命令**：

```bash
# 1) 确认 PHP 实际版本（CLI 与 FPM 可能不同！）
php -v
php-fpm -v 2>/dev/null || /usr/sbin/php-fpm8.5 -v

# 2) 确认 SQLite 扩展已加载（pdo_sqlite 必需，sqlite3 建议）
php -m | grep -iE 'pdo_sqlite|sqlite3'

# 3) 确认 data/ 与 config/ 存在且对 PHP-FPM 运行用户可写
ls -ld data config
ls -l data/shop.sqlite config/.env config/installed.lock 2>/dev/null
# 修复（以 www-data 为例）：
#   chown -R www-data:www-data data config
#   chmod 770 data config

# 4) 看真实报错（500 的原始堆栈一定在这里）
tail -50 /var/log/nginx/error.log
tail -50 /var/log/php8.5-fpm.log   # 或 /var/log/php-fpm/error.log

# 5) 确认伪静态生效
nginx -t
curl -i https://你的域名/api/install/status   # 期望 200 + application/json
```

**必须满足的目录/扩展状态**：

| 项 | 要求 |
|---|---|
| PHP | ≥ 8.1（推荐 8.5）；CLI 与 FPM 版本要一致 |
| 扩展 | `pdo_sqlite` 必须启用；`json`、`mbstring` 必须启用 |
| `data/` | 必须存在，且对 PHP-FPM 运行用户可写（`chown www-data:www-data data && chmod 770 data`） |
| `config/` | 必须存在且可写（安装时要写 `.env` 与 `installed.lock`） |
| 站点根 | 必须指向 `public/`，且 `/api/*` 直接 `fastcgi_pass` 到 `api/index.php`（不能用 `try_files` 兜底） |

### 症状 B：`/console` 提示「服务器返回异常」

**根因**：前端 `fetch` 拿到的是 nginx 的 404 **HTML**，`res.json()` 解析失败，旧代码统一兜底成「服务器返回异常」，把真实原因（接口没被路由）掩盖了。

**v5.3 已修**：
- 前端先读 `res.text()` 再尝试 `JSON.parse`，解析失败时明确提示「接口地址不存在（HTTP 404）：服务器未把 /api/* 转发到 PHP，请检查伪静态配置」
- `401` 统一跳登录页，不再显示「服务器异常」
- 后端未登录统一返回 `401` + `{"ok":false,"error":{"code":"unauthorized",...}}`

**自查命令**：

```bash
curl -i https://你的域名/api/console/me
# 期望：HTTP/1.1 401 + Content-Type: application/json + {"ok":false,"error":{"code":"unauthorized"...}}
# 若返回 text/html 的 404 → 伪静态没配（见症状 A 第 2 条）
```

### 一键体检

```bash
bash tests/site_check.sh https://你的域名
```

会逐个请求前台页面、后台、用户中心、安装页、公开 API、未登录 401、敏感文件 404，列出异常项。

---

## 8. 安全说明

### 已实现并实测

| 风险 | 防护 |
|---|---|
| SQL 注入 | 全量 PDO 预处理 + `EMULATE_PREPARES=false`；排序字段白名单；LIKE 通配符转义 |
| 密码泄露 | Argon2id；`password_needs_rehash` 自动升级；不存明文 |
| 会话劫持 | token 32 字节随机；DB 只存 SHA-256；HttpOnly + SameSite + Secure |
| CSRF | SameSite=Lax + Origin/Referer 同源强校验 |
| 撞库爆破 | IP + 账号双维度限流；统一错误文案；不存在账号也跑哈希 |
| 水平越权 | 订单查询 SQL 强制 `user_id`；越权返回 404 |
| 垂直越权 | 前后台独立表/Cookie/路由；敏感操作 `requireOwner()` |
| 价格篡改 | 单价、规格加价、运费、折扣、总额全部服务端重算 |
| 超卖 | 事务内条件扣减 + `CHECK (stock >= 0)` |
| 重复下单 | `idempotency_key` 唯一索引 |
| 重复支付回调 | 状态机条件更新 + 流水唯一索引 |
| 余额负数 | 条件更新 + `CHECK (balance_cents >= 0)` |
| 支付金额篡改 | 回调金额比对 + `pid` 校验 + 渠道一致性 |
| 支付伪造 | 验签恒定时间比较；`sign_type` 用服务端配置 |
| 命令注入 | 业务代码与 Web 请求路径无 `exec`/`system`；仅 `bin/create_admin.php` 交互式读取密码时用了一次 `shell_exec('stty -echo')`（CLI 工具，不经 HTTP） |
| 路径穿越 | 路由白名单；`data/` 在 web 根外；敏感扩展名拦截 |
| SSRF | 网关地址强制 https + 域名白名单 |
| XSS | 前端 `textContent` / `esc()`；服务端只输出 JSON |
| 信息泄露 | 生产不返回堆栈；日志不含手机号/密码；IP 只存哈希 |
| 请求体攻击 | 强制 JSON（415）；64KB 上限（413）；JSON 深度 32 |
| 硬编码密钥 | 全部环境变量 |
| 依赖漏洞 | 零第三方运行时依赖 |

### 已知限制（未实现，需你决定是否要）

1. **支付未接入真实商户**：代码已完整实现易支付协议（签名、验签、回调、幂等），但需要你自己的商户号与密钥。未配置时在线支付按钮自动隐藏，页面如实提示。
2. **无邮箱验证 / 找回密码**：注册不验证邮箱，忘记密码需联系管理员重置（后台有「重置密码」按钮，生成一次性临时密码）。
3. **无退款流程**：`balance_ledger` 已支持 `refund` 类型，但后台没有退款按钮。需要时可在 `AdminService` 加一个方法调用 `BalanceService::credit(..., 'refund', ...)`。
4. **购物车仍在 localStorage**：未登录也能加购，登录后购物车不跨设备同步。要做跨设备购物车需再加 `cart_items` 表。
5. **无图片上传**：商品图片是 `public/images/` 下的静态文件，后台只能填路径。要做上传需加上传接口 + 文件校验。
6. **SQLite 单机**：适合单机、日订单万级以内。要横向扩展需换 PostgreSQL/MySQL（`Db.php` 是唯一需要改的地方）。
7. **未做压力测试**：只验证了 4 并发下单不超卖，未做百级并发压测。

---

## 9. 常见问题

**Q：`init_db.php` 报「数据库已存在」？**
A：加 `--reset` 会删库重建（**会丢数据**）。生产环境请用 `migrate.php`。

**Q：Argon2id 不可用？**
A：`php -m | grep sodium` 检查。没有 sodium 时自动退化 bcrypt（`PASSWORD_BCRYPT`），安全性仍可接受，但建议装 `php-sodium`。

**Q：登录后刷新就掉登录？**
A：检查 Cookie 是否被浏览器拒绝。HTTPS 下 `Secure` 生效；如果站点是 HTTP 但配置了 `SHOP_BASE_URL=https://...`，Cookie 会写不进去。

**Q：支付回调一直失败？**
A：看 `php-error.log` 里的 `[shop][notify]` 日志。常见原因：`EPAY_KEY` 不对、`EPAY_PID` 不匹配、`notify_url` 外网不可达、金额不一致。

**Q：虚拟商品支付后没看到发货内容？**
A：检查商品 `type` 是否为 `virtual`，以及是否配置了发货字段。没配模板时会写入「发货内容待补充，请联系客服并提供订单号 XXX」，不会空白。

**Q：如何给用户加余额？**
A：后台 → 用户管理 → 点用户 → 余额调整 → 填金额（元，负数为扣款）+ 必填原因。

---

## 10. 文档

- **API 契约**：[`docs/API.md`](docs/API.md) —— 全部接口的请求/响应/错误码/状态机/前端对接要点
- 本文档：部署、配置、升级、安全、限制

---
