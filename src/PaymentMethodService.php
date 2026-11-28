<?php
/**
 * 支付方式聚合服务（v1.6）
 *
 * 统一对外暴露本站支持的支付方式，并把「方式 id」映射到真正能用的支付通道。
 * 设计原则：
 *  1. 易支付（EPay）是底座：配置好 PID/KEY/网关后，微信、支付宝两条通道恒可用。
 *  2. 微信 H5 = 易支付 wxpay 通道 + device=mobile，不额外需要资质，作为独立方式展示。
 *  3. 微信官方 / 支付宝官方 / 支付宝当面付 = 直连方式，需要企业商户资质（appid+密钥）。
 *     未配置完整时 ready=false，前台置灰并提示；用户若强行选择，下单时自动回退到
 *     易支付对应通道（保证永远有可用支付，不出现「付不了款」的玩具体验）。
 *  4. 所有凭据只在此服务内部读取，publicInfo() 绝不返回任何密钥。
 */
declare(strict_types=1);

final class PaymentMethodService
{
    /**
     * 对外支付方式清单（含可用性）。前端据此渲染支付选项卡。
     *
     * @return array<int,array{id:string,label:string,group:string,ready:bool,channel:string,device:string,note:string}>
     */
    public static function publicInfo(): array
    {
        $s = SettingsService::all();
        $epay = PaymentService::publicInfo();
        $epayReady = !empty($epay['enabled']);
        $epayChannels = array_column($epay['channels'] ?? [], 'id');

        $methods = [];

        // 余额支付：登录用户恒可用（余额是否充足在下单时校验）
        $methods[] = self::m('balance', '余额支付', 'balance', true, 'balance', 'pc', '');

        // 易支付通道（微信 / 支付宝）：底座，配置好即可用
        if (in_array('wxpay', $epayChannels, true)) {
            $methods[] = self::m('epay_wxpay', '微信支付', 'epay', $epayReady, 'wxpay', 'pc',
                $epayReady ? '' : '易支付未配置');
        }
        if (in_array('alipay', $epayChannels, true)) {
            $methods[] = self::m('epay_alipay', '支付宝', 'epay', $epayReady, 'alipay', 'pc',
                $epayReady ? '' : '易支付未配置');
        }

        // 微信 H5：易支付 wxpay + device=mobile（无需额外资质）
        $wxH5On = self::flag($s, 'pay_wxpay_h5_enabled');
        $methods[] = self::m('wxpay_h5', '微信 H5', 'wechat', $epayReady && $wxH5On, 'wxpay', 'mobile',
            $wxH5On ? ($epayReady ? '微信内/手机浏览器支付' : '需先配置易支付') : '后台未开启');

        // 微信官方直连：需 appid + 商户号 + APIv3 密钥
        $wxOffOn = self::flag($s, 'pay_wxpay_official_enabled');
        $wxOffReady = $wxOffOn
            && ($s['pay_wxpay_official_appid'] ?? '') !== ''
            && ($s['pay_wxpay_official_mchid'] ?? '') !== ''
            && ($s['pay_wxpay_official_key'] ?? '') !== '';
        $methods[] = self::m('wxpay_official', '微信官方支付', 'wechat', $wxOffReady, 'wxpay', 'pc',
            self::officialNote($wxOffOn, $wxOffReady, '微信支付商户'));

        // 支付宝官方直连：需 appid + 应用私钥 + 支付宝公钥
        $aliOn = self::flag($s, 'pay_alipay_enabled');
        $aliReady = $aliOn
            && ($s['pay_alipay_appid'] ?? '') !== ''
            && ($s['pay_alipay_private_key'] ?? '') !== ''
            && ($s['pay_alipay_public_key'] ?? '') !== '';
        $methods[] = self::m('alipay_official', '支付宝', 'alipay', $aliReady, 'alipay', 'pc',
            self::officialNote($aliOn, $aliReady, '支付宝开放平台'));

        // 支付宝当面付：复用支付宝官方资质（appid+密钥）
        $f2fOn = self::flag($s, 'pay_alipay_f2f_enabled');
        $f2fReady = $f2fOn && $aliReady;
        $methods[] = self::m('alipay_f2f', '支付宝当面付', 'alipay', $f2fReady, 'alipay', 'qrcode',
            self::officialNote($f2fOn && $aliOn, $f2fReady, '扫码支付，需支付宝资质'));

        return $methods;
    }

    /**
     * 把对外方式 id 解析成实际可用的支付通道。
     * 未配置的直连方式自动回退到易支付对应通道——保证下单永不因「方式没配好」而卡死。
     *
     * @return array{channel:string,device:string,fallback:bool,label:string}
     */
    public static function resolve(string $methodId): array
    {
        $s = SettingsService::all();
        $map = [
            'balance'        => ['channel' => 'balance', 'device' => 'pc'],
            'epay_wxpay'     => ['channel' => 'wxpay',   'device' => 'pc'],
            'epay_alipay'    => ['channel' => 'alipay',  'device' => 'pc'],
            'wxpay_h5'       => ['channel' => 'wxpay',   'device' => 'mobile'],
            'wxpay_official' => ['channel' => 'wxpay',   'device' => 'pc'], // 未配置则回退易支付
            'alipay_official'=> ['channel' => 'alipay',  'device' => 'pc'], // 未配置则回退易支付
            'alipay_f2f'     => ['channel' => 'alipay',  'device' => 'qrcode'], // 未配置则回退易支付
        ];
        $base = $map[$methodId] ?? $map['epay_wxpay'];

        // 直连方式是否真的配置完整
        $directReady = false;
        if ($methodId === 'wxpay_official') {
            $directReady = self::flag($s, 'pay_wxpay_official_enabled')
                && ($s['pay_wxpay_official_appid'] ?? '') !== ''
                && ($s['pay_wxpay_official_mchid'] ?? '') !== ''
                && ($s['pay_wxpay_official_key'] ?? '') !== '';
        } elseif ($methodId === 'alipay_official' || $methodId === 'alipay_f2f') {
            $directReady = ($methodId === 'alipay_f2f' ? self::flag($s, 'pay_alipay_f2f_enabled') : self::flag($s, 'pay_alipay_enabled'))
                && ($s['pay_alipay_appid'] ?? '') !== ''
                && ($s['pay_alipay_private_key'] ?? '') !== ''
                && ($s['pay_alipay_public_key'] ?? '') !== '';
        }

        // 直连已配置 → 预留直连通道（当前版本统一走易支付落地，见 note）
        // 直连未配置 → 回退易支付对应通道
        $fallback = in_array($methodId, ['wxpay_official', 'alipay_official', 'alipay_f2f'], true) && !$directReady;
        return [
            'channel'  => $base['channel'],
            'device'   => $base['device'],
            'fallback' => $fallback,
            'label'    => self::label($methodId),
        ];
    }

    public static function label(string $id): string
    {
        return [
            'balance'         => '余额支付',
            'epay_wxpay'      => '微信支付',
            'epay_alipay'     => '支付宝',
            'wxpay_h5'        => '微信 H5',
            'wxpay_official'  => '微信官方支付',
            'alipay_official' => '支付宝',
            'alipay_f2f'      => '支付宝当面付',
        ][$id] ?? $id;
    }

    private static function m(string $id, string $label, string $group, bool $ready, string $channel, string $device, string $note): array
    {
        return [
            'id'      => $id,
            'label'   => $label,
            'group'   => $group,
            'ready'   => $ready,
            'channel' => $channel,
            'device'  => $device,
            'note'    => $note,
        ];
    }

    /** 三态开关：''=跟随(视为关)，'1'=开，'0'=关 */
    private static function flag(array $s, string $key): bool
    {
        return (string)($s[$key] ?? '') === '1';
    }

    private static function officialNote(bool $on, bool $ready, string $req): string
    {
        if (!$on) {
            return '后台未开启';
        }
        return $ready ? '已配置（当前版本统一经易支付落地）' : '资质未配置完整（需 ' . $req . '），将回退易支付';
    }
}
