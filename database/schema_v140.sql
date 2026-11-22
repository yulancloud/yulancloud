-- =============================================================
-- 欲蓝财务 YULAN · SQLite 数据库结构 v1.4 增量
-- 新增：魔方云(KVM) / 魔方财务对接上游 / 工单系统
-- 金额一律以「分」为整数存储
--
-- 执行：全新安装由 init_db.php 自动执行；已有库由 php bin/migrate.php 幂等升级
-- =============================================================

PRAGMA foreign_keys = ON;

-- ---------- v1.4：上游（统一承载「魔方财务对接上游」与「魔方云 KVM」） ----------
-- kind: finance=对接上游财务系统(代理产品) / cloud=魔方云 KVM 云服务器
-- api_key / password 只写不读：对外只回传是否已配置，不回传明文
CREATE TABLE IF NOT EXISTS upstreams (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,                        -- 自定义名称，如「上游A-魔方财务」
  kind          TEXT NOT NULL DEFAULT 'finance'
                CHECK (kind IN ('finance', 'cloud')),
  base_url      TEXT NOT NULL DEFAULT '',             -- 接口地址（ip 或域名，可含 https://）
  api_user      TEXT NOT NULL DEFAULT '',             -- 账号
  api_key       TEXT NOT NULL DEFAULT '',             -- API 密钥 / 密码（只写不读）
  financial_id  TEXT NOT NULL DEFAULT '',             -- 财务标识（魔方云接口用）
  account_type  TEXT NOT NULL DEFAULT 'agent',        -- 账号类型：agent=代理商 / 普通
  use_https     INTEGER NOT NULL DEFAULT 0 CHECK (use_https IN (0, 1)),
  enabled       INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
  -- v1.5：上游协议类型（v10=新魔方云/财务 v10 / finance=老财务 / whmcs=WHMCS 代理）
  api_type      TEXT NOT NULL DEFAULT 'v10'
                CHECK (api_type IN ('v10','finance','whmcs')),
  -- v1.4.1：协议可配置（v1.5 默认路径已改为魔方 v10 真实端点）
  auth_mode     TEXT NOT NULL DEFAULT 'bearer'
                CHECK (auth_mode IN ('bearer','basic','query','none')),  -- 鉴权方式
  path_products TEXT NOT NULL DEFAULT '/api/v1/product',               -- 拉取产品路径（魔方 v10 真实）
  path_provision_cloud   TEXT NOT NULL DEFAULT '/console/v1/cart',       -- 云主机开通（v10 加入购物车）
  path_provision_finance TEXT NOT NULL DEFAULT '/cart/add_to_shop',      -- 财务上游下单（老财务）
  path_action   TEXT NOT NULL DEFAULT '/provision/default',              -- 实例操作路径（v10 真实）
  price_is_cents INTEGER NOT NULL DEFAULT 0 CHECK (price_is_cents IN (0,1)), -- 上游价格单位：0=元(×100) 1=分
  remark        TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_upstreams_kind ON upstreams (kind, enabled, id);

-- ---------- v1.4：上游产品目录（从上游同步或手动录入，供商品绑定） ----------
CREATE TABLE IF NOT EXISTS upstream_products (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  upstream_id   INTEGER NOT NULL REFERENCES upstreams(id) ON DELETE CASCADE,
  external_id   TEXT NOT NULL DEFAULT '',             -- 上游产品 ID
  name          TEXT NOT NULL,
  spec          TEXT NOT NULL DEFAULT '',             -- 规格/配置描述（CPU/内存/带宽等）
  price_cents   INTEGER NOT NULL DEFAULT 0 CHECK (price_cents >= 0),
  status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','hidden')),
  synced_at     TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (upstream_id, external_id)
);
CREATE INDEX IF NOT EXISTS idx_upstream_products_up ON upstream_products (upstream_id, id);

-- ---------- v1.4：云主机 / 上游开通实例 ----------
-- 只存连接信息（IP / 账号）与状态；密码由上游生成并一次性写入发货内容，平台不长期持有明文
CREATE TABLE IF NOT EXISTS cloud_vms (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id       INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_item_id  INTEGER NOT NULL DEFAULT 0,
  product_id     TEXT NOT NULL DEFAULT '',
  user_id        INTEGER,
  upstream_id    INTEGER NOT NULL DEFAULT 0,
  external_id    TEXT NOT NULL DEFAULT '',            -- 上游实例 ID
  instance_name  TEXT NOT NULL DEFAULT '',
  ip             TEXT NOT NULL DEFAULT '',
  os             TEXT NOT NULL DEFAULT '',
  cpu            TEXT NOT NULL DEFAULT '',
  mem            TEXT NOT NULL DEFAULT '',
  disk           TEXT NOT NULL DEFAULT '',
  bandwidth      TEXT NOT NULL DEFAULT '',
  region         TEXT NOT NULL DEFAULT '',
  status         TEXT NOT NULL DEFAULT 'pending'
                 CHECK (status IN ('pending','creating','running','stopped','failed','cancelled')),
  detail         TEXT NOT NULL DEFAULT '',
  created_at     TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_cloud_vms_order ON cloud_vms (order_id);
CREATE INDEX IF NOT EXISTS idx_cloud_vms_user  ON cloud_vms (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_cloud_vms_prod  ON cloud_vms (product_id, id DESC);

-- ---------- v1.4：工单 ----------
CREATE TABLE IF NOT EXISTS tickets (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_no   TEXT NOT NULL UNIQUE,                   -- TK + 日期 + 随机
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  order_no    TEXT NOT NULL DEFAULT '',               -- 可选关联订单
  category    TEXT NOT NULL DEFAULT 'other',          -- account/payment/product/technical/other
  title       TEXT NOT NULL,
  priority    TEXT NOT NULL DEFAULT 'normal' CHECK (priority IN ('low','normal','high')),
  status      TEXT NOT NULL DEFAULT 'open'
              CHECK (status IN ('open','processing','replied','closed')),
  last_reply_at TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_tickets_user   ON tickets (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets (status, id DESC);

-- ---------- v1.4：工单回复 ----------
CREATE TABLE IF NOT EXISTS ticket_replies (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id   INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  role        TEXT NOT NULL CHECK (role IN ('user','admin')),
  author      TEXT NOT NULL DEFAULT '',               -- 昵称 / 管理员账号
  body        TEXT NOT NULL,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ticket_replies_ticket ON ticket_replies (ticket_id, id);
