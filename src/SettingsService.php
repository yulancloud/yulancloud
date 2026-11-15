<?php
/**
 * 站点设置（后台可改的文案）
 *
 * 设计要点：
 *  - key-value 存储，新增一项文案不需要改表结构
 *  - 白名单：只允许下面 DEFAULTS 里声明的 key 被写入，防止后台被塞进任意键
 *  - 长度上限：每项都有 max，避免把整页 HTML 塞进数据库
 *  - 前台只读：/api/site 只返回这些公开文案，绝不返回任何凭据
 *  - 输出到页面时前端一律用 textContent 赋值（不拼 innerHTML），从根上避免存储型 XSS
 */
declare(strict_types=1);

final class SettingsService
{
    /**
     * 允许的键 + 默认值 + 长度上限
     * 默认值就是「没配置过」时前台显示的内容，与旧版写死在 HTML 里的文案一致。
     */
    private const DEFAULTS = [
        // 站点名（顶栏品牌、<title> 前缀）
        'site_name'        => ['default' => '拾物集', 'max' => 40],
        // 英文名（品牌副标）
        'site_name_en'     => ['default' => 'SHIWU', 'max' => 40],
        // 首页主标题（流式大字）
        'hero_title'       => ['default' => '只做少数几种，做得久一点。', 'max' => 80],
        // 首页副标题 / 导语
        'hero_subtitle'    => ['default' => '八件日用器物，来自六个小工坊。我们不追新款，只把同一件东西反复改到不用再改——让人用得顺手，也舍得一直用。', 'max' => 300],
        // 首页眉标（EST. 2019 · 上海静安）
        'hero_eyebrow'     => ['default' => 'EST. 2019 · 上海静安', 'max' => 60],
        // 页脚说明
        'footer_note'      => ['default' => '少而耐的日用器物。上海静安 · 巨鹿路 880 号 2 楼，周三至周日 12:00–20:00 可到店。', 'max' => 300],
        // 页脚版权
        'footer_copyright' => ['default' => '© 2026 拾物集 SHIWU', 'max' => 160],
        // 备案号
        'footer_icp'       => ['default' => '沪 ICP 备 0000000 号', 'max' => 80],
        // 客服邮箱
        'support_email'    => ['default' => 'support@shiwu.example', 'max' => 120],
        // 浏览器标签页标题后缀
        'meta_title'       => ['default' => '拾物集 SHIWU · 少而耐的日用器物', 'max' => 120],
        // 站点描述（meta description）
        'meta_description' => ['default' => '八件日用器物，来自六个小工坊。少而耐，用得顺手。', 'max' => 200],

        // ---------- v5 新增：站点介绍与前台文案 ----------
        // 站点介绍（关于我们，可多行）
        'site_intro'       => ['default' => '拾物集只做少数几种日用器物。我们不追新款，只把同一件东西反复改到不用再改。', 'max' => 2000],
        // 首页公告条（留空则不显示）
        'home_notice'      => ['default' => '', 'max' => 300],
        // 首页商品区标题
        'home_products_title' => ['default' => '在售器物', 'max' => 40],
        // 页脚联系信息
        'footer_contact'   => ['default' => '', 'max' => 300],
        // 用户中心欢迎语
        'console_welcome'  => ['default' => '欢迎回来', 'max' => 60],
        // 客服微信 / QQ（留空则不显示）
        'support_wechat'   => ['default' => '', 'max' => 60],
        'support_qq'       => ['default' => '', 'max' => 60],

        // ---------- 易支付（后台可直接配置；留空 = 沿用 config/.env 环境变量） ----------
        // 安全：这些键绝不进入 publicPayload()，前台 /api/site 拿不到任何支付凭据。
        // epay_enabled 三态：''=跟随 .env，'1'=强制启用，'0'=强制关闭。
        'epay_enabled'        => ['default' => '', 'max' => 8],
        'epay_pid'            => ['default' => '', 'max' => 64],
        'epay_key'            => ['default' => '', 'max' => 128],
        'epay_gateway'        => ['default' => '', 'max' => 300],
        'epay_channels'       => ['default' => '', 'max' => 80],
        'epay_notify_url'     => ['default' => '', 'max' => 300],
        'epay_return_url'     => ['default' => '', 'max' => 300],
        'epay_expire_minutes' => ['default' => '', 'max' => 6],

        // ---------- 运费规则（后台可直接改；单位「元」，留空 = 沿用 config/config.php 默认） ----------
        // 全虚拟商品订单恒免运费（无需物流），不受这两项影响，见 OrderService::create()。
        // 金额以「元」填写是为了店主直观，服务端统一换算成「分」参与计算，避免浮点误差。
        'ship_fee'            => ['default' => '', 'max' => 12],
        'free_ship_threshold' => ['default' => '', 'max' => 12],

        // ---------- 欲蓝财务魔方：Windows 服务器连接配置（后台「欲蓝服务器」菜单配置） ----------
        // 安全：yulan_server_pass 与 epay_key 一样「只写不读」，绝不进入 publicPayload / adminPayload。
        // 网站据此连接 Windows 上的「欲蓝财务魔方 APP」，下单时向其下达建号命令。
        'yulan_server_ip'   => ['default' => '', 'max' => 64],   // Windows 服务器 IP / 域名
        'yulan_server_port' => ['default' => '8321', 'max' => 6], // APP 监听端口
        'yulan_server_user' => ['default' => '', 'max' => 64],   // 连接鉴权账号（APP 侧校验）
        'yulan_server_pass' => ['default' => '', 'max' => 128],  // 连接鉴权口令（只写不读）
        'yulan_rdp_host'    => ['default' => '', 'max' => 128],  // 交付给用户的「连接地址」（留空则用 yulan_server_ip）
        'yulan_mock'        => ['default' => '0', 'max' => 1],   // 1 = 模拟模式（不真连 APP，写入队列文件，便于测试）

        // ---------- 虚拟商品自动发货：逐商品配置 ----------
        // 每个虚拟商品的发货内容存于各自商品的 delivery_template / product_delivery_fields，
        // 由 DeliveryService::renderContent() 渲染；后台「虚拟商品发货」菜单逐个设置，互不影响。
    ];

    /** 全部默认值（未初始化数据库时也能用） */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $meta) {
            $out[$k] = $meta['default'];
        }
        return $out;
    }

    /** 允许的键列表 */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * 读取全部设置（数据库值覆盖默认值）
     * 数据库不可用时静默回退默认值 —— 前台不能因为设置表读不到就白屏。
     */
    public static function all(): array
    {
        $out = self::defaults();
        try {
            $rows = Db::conn()->query('SELECT key, value FROM site_settings')->fetchAll();
        } catch (Throwable $e) {
            error_log('[shop][settings] read failed: ' . $e->getMessage());
            return $out;
        }
        foreach ($rows as $r) {
            $k = (string)$r['key'];
            if (array_key_exists($k, $out)) {
                $out[$k] = (string)$r['value'];
            }
        }
        return $out;
    }

    /** 单个设置 */
    public static function get(string $key): string
    {
        $all = self::all();
        return $all[$key] ?? '';
    }

    /**
     * 批量保存（只接受白名单键）
     *
     * @param array $input 形如 ['site_name' => '...', ...]
     * @return array{updated:int, settings:array}
     * @throws InvalidArgumentException 校验失败时抛出，由调用方转 400
     */
    public static function save(array $input): array
    {
        $clean = [];
        foreach ($input as $k => $v) {
            if (!is_string($k) || !array_key_exists($k, self::DEFAULTS)) {
                continue;   // 未知键直接忽略，不报错也不写入
            }
            if (!is_string($v)) {
                throw new InvalidArgumentException('设置项「' . $k . '」必须是文本');
            }
            // 去掉控制字符（保留换行与制表），再 trim
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
            $v = trim($v);
            // 商户密钥「只写不读」：后台 GET 从不返回明文，因此留空表示不修改原密钥，
            // 避免打开设置页再点保存就把已配好的密钥覆盖成空。
            // 显式填 '__CLEAR__' 才清空（对应后台「清除密钥」按钮）。
            if ($k === 'epay_key') {
                if ($v === '') {
                    continue;
                }
                if ($v === '__CLEAR__') {
                    $v = '';
                }
            }
            // 欲蓝服务器口令同样「只写不读」：留空 = 不改动；'__CLEAR__' = 清空。
            if ($k === 'yulan_server_pass') {
                if ($v === '') {
                    continue;
                }
                if ($v === '__CLEAR__') {
                    $v = '';
                }
                if (mb_strlen($v, 'UTF-8') < 6) {
                    throw new InvalidArgumentException('设置项「' . $k . '」至少 6 位字符');
                }
            }
            // yulan_mock 只允许 0/1
            if ($k === 'yulan_mock' && !in_array($v, ['0', '1', ''], true)) {
                throw new InvalidArgumentException('设置项「' . $k . '」只能是 0 或 1');
            }
            // 运费两项必须是非负金额（最多两位小数）。留空表示沿用服务器默认值，
            // 非法输入直接拒绝写入，避免脏数据让服务端算出离谱的运费。
            if ($k === 'ship_fee' || $k === 'free_ship_threshold') {
                if ($v !== '' && !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $v)) {
                    throw new InvalidArgumentException(
                        '设置项「' . $k . '」必须是不超过两位小数的非负金额（元），留空表示沿用服务器默认'
                    );
                }
                if ($v !== '' && (float)$v > 999999) {
                    throw new InvalidArgumentException('设置项「' . $k . '」金额过大');
                }
            }
            $max = self::DEFAULTS[$k]['max'];
            if (mb_strlen($v, 'UTF-8') > $max) {
                throw new InvalidArgumentException('设置项「' . $k . '」不能超过 ' . $max . ' 个字符');
            }
            $clean[$k] = $v;
        }

        if ($clean === []) {
            return ['updated' => 0, 'settings' => self::adminPayload()];
        }

        Db::tx(function (PDO $pdo) use ($clean): void {
            $stmt = $pdo->prepare(
                "INSERT INTO site_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')"
            );
            foreach ($clean as $k => $v) {
                $stmt->execute([$k, $v]);
            }
        });

        return ['updated' => count($clean), 'settings' => self::adminPayload()];
    }

    /** 恢复默认文案（只清空文案键；易支付与运费配置属于业务参数，不受影响） */
    public static function reset(): array
    {
        Db::conn()->exec(
            "DELETE FROM site_settings
             WHERE key NOT LIKE 'epay_%'
               AND key NOT LIKE 'ship_%'
               AND key <> 'free_ship_threshold'
               AND key NOT LIKE 'virtual_%'
               AND key NOT LIKE 'yulan_%'"
        );
        return self::adminPayload();
    }

    /**
     * 当前生效的运费规则（单位：分），供服务端下单与前台展示共用同一套数值。
     *
     * 后台两项留空时回退 config/config.php 的默认值，保证旧部署升级后行为不变；
     * 数据库读不到（未安装 / 表缺失）时同样安全回退，前台不会因此白屏。
     * 包邮门槛为 0 表示「不设门槛」——任何实体商品订单都不自动包邮。
     */
    public static function shippingRule(): array
    {
        $cfg = require dirname(__DIR__) . '/config/config.php';
        $fallback = $cfg['rules'];
        $s = self::all();

        $fee       = self::yuanToCents((string)($s['ship_fee'] ?? ''));
        $threshold = self::yuanToCents((string)($s['free_ship_threshold'] ?? ''));

        return [
            'ship_fee_cents'            => $fee       ?? (int)$fallback['ship_fee_cents'],
            'free_ship_threshold_cents' => $threshold ?? (int)$fallback['free_ship_threshold_cents'],
        ];
    }

    /** '12' / '8.5' → 1200 / 850；空串或非法值返回 null（由调用方回退默认） */
    private static function yuanToCents(string $v): ?int
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $v)) {
            return null;
        }
        return (int)round((float)$v * 100);
    }

    /**
     * 后台设置页读取：商户密钥永不下发（只写不读），另给「是否已配置」状态。
     * 这样密钥既不会出现在浏览器响应/缓存/代理日志里，店主又能改、能停用。
     */
    public static function adminPayload(): array
    {
        $s = self::all();
        $s['epay_key_set'] = ($s['epay_key'] !== '');
        $s['epay_key'] = '';
        // 欲蓝服务器口令同样「只写不读」，只回传是否已配置，绝不回传明文
        $s['yulan_server_pass_set'] = ($s['yulan_server_pass'] !== '');
        $s['yulan_server_pass'] = '';
        // 后台「运费设置」页据此展示最终生效值（含留空回退后的默认值）
        $s['shipping'] = self::shippingRule();
        return $s;
    }

    /** 前台公开数据（不含任何内部信息） */
    public static function publicPayload(): array
    {
        $s = self::all();
        $rule = self::shippingRule();
        return [
            'site_name'        => $s['site_name'],
            'site_name_en'     => $s['site_name_en'],
            'hero_title'       => $s['hero_title'],
            'hero_subtitle'    => $s['hero_subtitle'],
            'hero_eyebrow'     => $s['hero_eyebrow'],
            'footer_note'      => $s['footer_note'],
            'footer_copyright' => $s['footer_copyright'],
            'footer_icp'       => $s['footer_icp'],
            'footer_contact'   => $s['footer_contact'],
            'support_email'    => $s['support_email'],
            'support_wechat'   => $s['support_wechat'],
            'support_qq'       => $s['support_qq'],
            'meta_title'       => $s['meta_title'],
            'meta_description' => $s['meta_description'],
            'site_intro'       => $s['site_intro'],
            'home_notice'      => $s['home_notice'],
            'home_products_title' => $s['home_products_title'],
            'console_welcome'  => $s['console_welcome'],
            // 运费规则（分）：前台购物袋据此预览运费，与服务端下单计算完全一致
            'ship_fee_cents'            => $rule['ship_fee_cents'],
            'free_ship_threshold_cents' => $rule['free_ship_threshold_cents'],
        ];
    }
}
