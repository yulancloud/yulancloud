<?php
/**
 * 易支付（EPay V1 旧版通用协议）—— 签名 / 验签 / 网关表单 / 订单查询
 *
 * 协议要点（https://pay.liohg.top/doc/v1_legacy_api.html）：
 *   1. 所有参数按参数名 ASCII 升序排序（a-z）
 *   2. 剔除 sign、sign_type 以及空值参数
 *   3. 拼成 k=v&k=v（值不做 urlencode）
 *   4. 末尾直接拼上商户密钥 KEY，MD5 后取小写：sign = md5(拼接串 + KEY)
 *   5. 异步通知（notify_url）是 GET 请求，收到后必须返回纯文本 success
 *
 * 安全要点：
 *  - 验签用 hash_equals（防时序侧信道猜签名）
 *  - 签名算法只用服务端配置，绝不信任请求里的 sign_type（否则可降级）
 *  - 只接受标量参数，数组/对象一律跳过，防 array 注入把签名串搞乱
 *  - 网关地址必须 https，禁止 javascript:/data: 等伪协议
 *  - 只允许微信支付（wxpay）与支付宝（alipay）两个通道
 */
declare(strict_types=1);

final class EPay
{
    /** 本站只开放这两个通道，其他通道（qqpay/bank/jdpay/paypal/douyinpay）一律拒绝 */
    public const CHANNELS = [
        'wxpay'  => '微信支付',
        'alipay' => '支付宝',
    ];

    /** 允许的通道 id 列表 */
    public static function channelIds(): array
    {
        return array_keys(self::CHANNELS);
    }

    /** 通道是否合法 */
    public static function isChannel(string $ch): bool
    {
        return isset(self::CHANNELS[$ch]);
    }

    /** 通道显示名 */
    public static function channelLabel(string $ch): string
    {
        return self::CHANNELS[$ch] ?? strtoupper($ch);
    }

    /**
     * 生成签名
     *
     * @param array  $params   参与签名的参数（可含 sign/sign_type，会被自动剔除）
     * @param string $key      商户密钥
     * @param string $signType MD5（默认）或 SHA256
     */
    public static function sign(array $params, string $key, string $signType = 'MD5'): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if ($k === 'sign' || $k === 'sign_type') {
                continue;
            }
            // 只接受标量；数组/对象直接跳过，避免签名串被构造
            if (!is_scalar($v)) {
                continue;
            }
            $v = (string)$v;
            if ($v === '') {
                continue;
            }
            $filtered[$k] = $v;
        }

        ksort($filtered, SORT_STRING);
        $pairs = [];
        foreach ($filtered as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $payload = implode('&', $pairs);

        return match (strtoupper($signType)) {
            'SHA256' => hash('sha256', $payload . $key),
            default  => md5($payload . $key),
        };
    }

    /** 校验签名（恒定时间比较） */
    public static function verify(array $params, string $key, string $signType = 'MD5'): bool
    {
        $given = $params['sign'] ?? null;
        if (!is_string($given) || $given === '') {
            return false;
        }
        $given = strtolower(trim($given));
        if (!preg_match('/^[a-f0-9]{32}$|^[a-f0-9]{64}$/', $given)) {
            return false;
        }
        $expect = self::sign($params, $key, $signType);
        return hash_equals($expect, $given);
    }

    /**
     * 异步通知的完整校验（必填字段 + 签名 + 状态 + 通道 + 金额格式）
     *
     * @return array{ok:bool, reason:string}
     */
    public static function verifyNotify(array $params, string $key, string $signType = 'MD5'): array
    {
        // 1) 必填字段（缺一个就拒绝，避免用残缺参数绕过后续判断）
        foreach (['pid', 'trade_no', 'out_trade_no', 'type', 'money', 'trade_status', 'sign'] as $f) {
            if (!isset($params[$f]) || !is_scalar($params[$f]) || (string)$params[$f] === '') {
                return ['ok' => false, 'reason' => 'missing_' . $f];
            }
        }

        // 2) 验签（先验签，再做任何业务判断）
        if (!self::verify($params, $key, $signType)) {
            return ['ok' => false, 'reason' => 'bad_sign'];
        }

        // 3) 交易状态：只有 TRADE_SUCCESS 才算成功
        if ((string)$params['trade_status'] !== 'TRADE_SUCCESS') {
            return ['ok' => false, 'reason' => 'not_success'];
        }

        // 4) 通道白名单
        if (!self::isChannel((string)$params['type'])) {
            return ['ok' => false, 'reason' => 'bad_channel'];
        }

        // 5) 金额必须是合法「元」字符串
        if (self::toCents($params['money']) === null) {
            return ['ok' => false, 'reason' => 'bad_money'];
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * 校验网关地址：必须 https、有主机名、不含账号密码
     * 返回规范化后的 URL，非法则返回 null。
     */
    public static function sanitizeGateway(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) {
            return null;
        }
        $p = parse_url($url);
        if (!is_array($p)) {
            return null;
        }
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host   = (string)($p['host'] ?? '');
        if ($scheme !== 'https') {
            return null;                       // 支付请求绝不走明文 HTTP
        }
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            return null;
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return null;                       // 禁止把凭据塞进 URL
        }
        return $url;
    }

    /**
     * 规范化支付网关地址。
     *
     * 店主很容易把「易支付站点首页」当成网关地址填进来（如 https://pay.xxx.com/），
     * 但页面跳转支付的入口是 /submit.php —— POST 到首页只会显示网站主页、不会进收银台。
     *
     * 规则：先按 sanitizeGateway() 做 https / 主机名校验；路径为空或仅 "/" 时自动补
     * 标准 /submit.php；已带自定义路径（如 /pay/submit.php）原样保留，尊重特殊部署。
     */
    public static function normalizeGateway(string $url): ?string
    {
        $url = self::sanitizeGateway($url);
        if ($url === null) {
            return null;
        }
        $p = parse_url($url);
        if (!is_array($p)) {
            return null;
        }
        $path = (string)($p['path'] ?? '');
        if ($path === '' || $path === '/') {
            $scheme = strtolower((string)($p['scheme'] ?? 'https'));
            $host   = (string)($p['host'] ?? '');
            $port   = isset($p['port']) ? ':' . (int)$p['port'] : '';
            return $scheme . '://' . $host . $port . '/submit.php';
        }
        return $url;
    }

    /** 金额：分 → 易支付要求的「元，两位小数」字符串 */
    public static function money(int $cents): string
    {
        return number_format(max(0, $cents) / 100, 2, '.', '');
    }

    /** 「元」字符串 → 分（严格解析，拒绝非数字/负数/超长小数） */
    public static function toCents(mixed $money): ?int
    {
        if (!is_scalar($money)) {
            return null;
        }
        $s = trim((string)$money);
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $s)) {
            return null;
        }
        return (int)round((float)$s * 100);
    }

    /**
     * 组装「页面跳转支付」表单（submit.php）
     *
     * @return array{gateway:string, method:string, fields:array<string,string>}
     */
    public static function buildSubmitForm(
        string $gateway,
        string $pid,
        string $key,
        string $signType,
        string $channel,
        string $outTradeNo,
        string $notifyUrl,
        string $returnUrl,
        string $name,
        int $amountCents,
        string $device = 'pc'
    ): array {
        $params = [
            'pid'          => $pid,
            'type'         => $channel,
            'out_trade_no' => $outTradeNo,
            'notify_url'   => $notifyUrl,
            'return_url'   => $returnUrl,
            'name'         => mb_substr($name, 0, 60, 'UTF-8'),
            'money'        => self::money($amountCents),
            'device'       => $device,
        ];
        $params['sign']      = self::sign($params, $key, $signType);
        $params['sign_type'] = $signType;

        return [
            'gateway' => $gateway,
            'method'  => 'POST',
            'fields'  => $params,
        ];
    }

    /** 根据 UA 推断 device 参数（pc / mobile / wechat / alipay） */
    public static function detectDevice(string $ua): string
    {
        $ua = strtolower($ua);
        if (str_contains($ua, 'micromessenger')) {
            return 'wechat';
        }
        if (str_contains($ua, 'alipayclient')) {
            return 'alipay';
        }
        if (preg_match('/android|iphone|ipad|ipod|mobile/', $ua) === 1) {
            return 'mobile';
        }
        return 'pc';
    }

    /**
     * 主动查询单个订单（对账用）：api.php?act=order
     *
     * @return array|null 网关返回的订单数据；查询失败返回 null
     */
    public static function queryOrder(string $apiBase, string $pid, string $key, string $outTradeNo): ?array
    {
        $apiBase = trim($apiBase);
        if ($apiBase === '' || !preg_match('#^https://#i', $apiBase)) {
            return null;
        }
        // 商户 KEY 绝不拼进 URL（会进代理/网关日志），改为按协议签名。
        $query = [
            'act'          => 'order',
            'pid'          => $pid,
            'out_trade_no' => $outTradeNo,
        ];
        $query['sign']      = self::sign($query, $key, 'MD5');
        $query['sign_type'] = 'MD5';
        $url = rtrim($apiBase, '/') . '/api.php?' . http_build_query($query);

        $raw = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 8], 'ssl' => ['verify_peer' => true]]);
            $raw = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true, 8);
        if (!is_array($data) || (int)($data['code'] ?? 0) !== 1) {
            return null;
        }
        return $data;
    }
}
