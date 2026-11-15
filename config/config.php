<?php
/**
 * 全局配置 —— 所有可变项走环境变量（或 config/.env 文件），代码里不出现任何密钥/凭据。
 *
 * 配置加载顺序：真实环境变量 > config/.env > 本文件默认值
 * 生成 .env：cp config/.env.example config/.env  然后按需填写
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Env.php';
Env::load(__DIR__ . '/.env');

$bool = static function (string $key, bool $default = false): bool {
    $v = Env::get($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
};

$int = static function (string $key, int $default): int {
    $v = Env::get($key);
    return ($v === false || $v === '') ? $default : (int)$v;
};

$str = static function (string $key, string $default = ''): string {
    $v = Env::get($key);
    return ($v === false) ? $default : (string)$v;
};

return [
    // SQLite 数据库文件路径（默认放在项目 data/ 下，需可写）
    'db_path' => $str('SHOP_DB_PATH') ?: dirname(__DIR__) . '/data/shop.sqlite',

    // 运行环境：production 时关闭错误详情输出
    'env' => $str('SHOP_ENV', 'production'),

    // 允许的来源（同源部署留空即可；跨域部署时填具体域名，禁止用 *）
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', $str('SHOP_ALLOWED_ORIGINS'))
    ))),

    // 站点对外基址（生成支付回调地址用）。留空则按请求 Host 推断。
    // 生产环境建议显式设置，避免 Host 头伪造导致回调地址被改。
    'base_url' => rtrim($str('SHOP_BASE_URL'), '/'),

    // 订单查询凭证的有效期（天）
    'order_token_ttl_days' => $int('SHOP_ORDER_TOKEN_TTL_DAYS', 30),

    // ---------------- 支付：易支付（EPay 通用协议） ----------------
    // 商户号、密钥、网关地址全部可配置，不写死在代码里。
    'payment' => [
        // 总开关：没配置好时为 false，前端不显示支付按钮，订单停在 pending
        'enabled'  => $bool('EPAY_ENABLED', false),

        // 商户 ID（易支付后台的 PID）
        'pid'      => $str('EPAY_PID'),

        // 商户密钥（易支付后台的 KEY）。只用于本地签名/验签，绝不下发前端
        'key'      => $str('EPAY_KEY'),

        // 支付网关提交地址，例如 https://pay.example.com/submit.php
        // 兼容两种变量名：EPAY_GATEWAY（推荐）/ EPAY_GATEWAY_URL
        'gateway'  => $str('EPAY_GATEWAY') ?: $str('EPAY_GATEWAY_URL'),

        // 签名算法：易支付通用协议默认 MD5
        'sign_type' => strtoupper($str('EPAY_SIGN_TYPE', 'MD5')),

        // 可用支付渠道（易支付 type 参数）。本站只开放微信与支付宝两个通道，
        // 其他通道（qqpay/bank/jdpay/paypal/douyinpay）即使写进配置也会被 EPay::isChannel 拒绝。
        // 支持别名：wechat → wxpay，ali → alipay。
        'channels' => (static function (string $raw): array {
            $alias = ['wechat' => 'wxpay', 'weixin' => 'wxpay', 'ali' => 'alipay', 'alipay' => 'alipay', 'wxpay' => 'wxpay'];
            $out = [];
            foreach (explode(',', $raw) as $c) {
                $c = strtolower(trim($c));
                $c = $alias[$c] ?? $c;
                if (in_array($c, ['wxpay', 'alipay'], true) && !in_array($c, $out, true)) {
                    $out[] = $c;
                }
            }
            return $out ?: ['wxpay', 'alipay'];
        })($str('EPAY_CHANNELS', 'wxpay,alipay')),

        // 渠道显示名
        'channel_labels' => [
            'alipay' => '支付宝',
            'wxpay'  => '微信支付',
            'qqpay'  => 'QQ 钱包',
        ],

        // 异步通知 / 同步返回地址（留空则按 base_url 自动拼）
        'notify_url' => $str('EPAY_NOTIFY_URL'),
        'return_url' => $str('EPAY_RETURN_URL'),

        // 金额校验容差（分）。默认 0：回调金额必须与订单应付金额完全相等，
        // 防止「少付 1 分也算成功」被利用。确有网关舍入问题时才调大。
        'amount_tolerance_cents' => $int('EPAY_AMOUNT_TOLERANCE_CENTS', 0),

        // 未支付订单自动关单（分钟）：超时后回到 cancelled 并归还库存
        'expire_minutes' => $int('EPAY_EXPIRE_MINUTES', 120),
    ],

    // ---------------- 后台管理 ----------------
    'admin' => [
        // 初始管理员账号（仅首次 init_db 或 bin/create_admin.php 使用；之后请改密码）
        'initial_user'     => $str('SHOP_ADMIN_USER', 'admin'),
        'initial_password' => $str('SHOP_ADMIN_PASSWORD', ''),

        // 会话 Cookie 名
        'cookie_name' => 'shiwu_admin_sid',

        // 会话空闲超时（秒），默认 2 小时
        'idle_timeout' => $int('SHOP_ADMIN_IDLE_TIMEOUT', 7200),

        // 会话绝对超时（秒），默认 12 小时
        'absolute_timeout' => $int('SHOP_ADMIN_ABSOLUTE_TIMEOUT', 43200),

        // 登录失败锁定：窗口秒数 / 允许次数 / 锁定时长秒数
        'login_limit' => ['window' => 900, 'max' => 5, 'lock' => 900],

        // 允许后台访问的 IP/CIDR 白名单（留空 = 不限制）
        'ip_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', $str('SHOP_ADMIN_IP_ALLOWLIST'))
        ))),
    ],

    // ---------------- 前台用户（/console 用户中心） ----------------
    // 与后台 admin 完全独立：独立表、独立 Cookie、独立超时
    'user' => [
        // 会话 Cookie 名（与后台 shiwu_admin_sid 区分开）
        'cookie_name' => 'shiwu_user_sid',

        // 会话空闲超时（秒），默认 7 天
        'idle_timeout' => $int('SHOP_USER_IDLE_TIMEOUT', 604800),

        // 会话绝对超时（秒），默认 30 天
        'absolute_timeout' => $int('SHOP_USER_ABSOLUTE_TIMEOUT', 2592000),

        // 登录失败锁定：窗口秒数 / 允许次数
        'login_limit' => ['window' => 900, 'max' => 8],

        // 注册限流：窗口秒数 / 允许次数
        'register_limit' => ['window' => 600, 'max' => 5],

        // 单笔充值金额范围（分）：默认 1 元 ~ 50000 元
        'recharge_min_cents' => $int('SHOP_RECHARGE_MIN_CENTS', 100),
        'recharge_max_cents' => $int('SHOP_RECHARGE_MAX_CENTS', 5000000),
    ],

    // 限流：窗口秒数 / 窗口内最大请求数
    'rate_limit' => [
        'order'  => ['window' => 600, 'max' => 10],   // 下单：10 分钟 10 次
        'read'   => ['window' => 60,  'max' => 240],  // 读接口：1 分钟 240 次
        'lookup' => ['window' => 600, 'max' => 20],   // 订单查询：10 分钟 20 次
        'pay'    => ['window' => 600, 'max' => 30],   // 发起支付：10 分钟 30 次
        'notify' => ['window' => 60,  'max' => 120],  // 支付回调：1 分钟 120 次
        'admin'  => ['window' => 60,  'max' => 300],  // 后台操作：1 分钟 300 次
        // v5 新增
        'user_login_ip'   => ['window' => 900, 'max' => 20],  // 前台登录（按 IP）
        'user_login_acct' => ['window' => 900, 'max' => 8],   // 前台登录（按账号）
        'user_register'   => ['window' => 600, 'max' => 5],   // 前台注册（按 IP）
        'console'         => ['window' => 60,  'max' => 240], // 用户中心读接口
        'recharge'        => ['window' => 600, 'max' => 20],  // 发起充值
        'review'          => ['window' => 600, 'max' => 5],   // 前台发表评价（按 IP，防刷）
    ],

    // 业务规则（与前端展示保持一致，服务端为准）
    'rules' => [
        // 运费默认值（单位：分）。后台「运费设置」里填了值就以数据库为准，
        // 留空才回退到这里 —— 见 SettingsService::shippingRule()。
        'free_ship_threshold_cents' => 29900,  // 满 ¥299 包邮
        'ship_fee_cents'            => 1200,   // 否则 ¥12
        'discount_threshold_cents'  => 60000,  // 满 ¥600 打 95 折
        'discount_rate'             => 0.05,
        'max_qty_per_line'          => 5,
        'max_lines_per_order'       => 20,
        // v5：余额支付单笔上限（分），默认 100 万元，防误操作
        'balance_pay_max_cents'     => 100000000,
    ],
];
