<?php
/**
 * 欲蓝财务魔方 —— Windows APP 连接器
 *
 * 职责：把「建号命令」安全地送到运行在 Windows 服务器上的「欲蓝财务魔方 APP」。
 *
 * 通信契约（HTTP JSON）：
 *   POST http://{yulan_server_ip}:{yulan_server_port}/api/v1/create-user
 *   Authorization: Bearer {yulan_server_pass}
 *   Body: {"order_no":"...","product_id":"...","account":"用户名","password":"密码"}
 *   响应: {"ok":true,"role":"user|admin","detail":"..."} 或 {"ok":false,"error":"..."}
 *
 * 命令由 Windows APP 在服务器上执行（APP 决定该账号是普通用户还是管理员）：
 *   - 普通用户：net user 用户名 密码 /add
 *   - 管理员：额外执行 net localgroup administrators 用户名 /add
 *   - 两者都要执行：net localgroup "Remote Desktop Users" 用户名 /add
 *
 * 安全基线：
 *   - 口令只在请求头发送，绝不写进日志、响应或任何可下发的 payload
 *   - 模拟模式（yulan_mock=1）：不真正连接，仅把命令写入 data/yulan_mock_queue.jsonl，便于测试
 *   - 口令由调用方（订单流程）临时持有，本类不负责存储
 */
declare(strict_types=1);

final class YulanConnector
{
    /**
     * 向 Windows APP 下达建号命令。
     * @return array{ok:bool,role:string,detail:string}
     */
    public static function createUser(array $cfg, string $orderNo, string $productId, string $account, string $password): array
    {
        if (($cfg['mock'] ?? '0') === '1') {
            return self::mockCreate($orderNo, $productId, $account, $password);
        }

        $ip   = trim((string)($cfg['ip'] ?? ''));
        $port = (int)($cfg['port'] ?? 0);
        $token = (string)($cfg['pass'] ?? '');
        if ($ip === '' || $port <= 0) {
            return ['ok' => false, 'role' => 'user', 'detail' => '欲蓝服务器地址未配置（请在后台「欲蓝服务器」填写 IP 与端口）'];
        }

        $url  = 'http://' . $ip . ':' . $port . '/api/v1/create-user';
        $body = json_encode([
            'order_no'   => $orderNo,
            'product_id' => $productId,
            'account'    => $account,
            'password'   => $password,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = self::httpPost($url, $body, $token);
        if ($response === null) {
            return ['ok' => false, 'role' => 'user', 'detail' => self::connectHint($url, '无法建立连接')];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            // v1.3：返回内容不是 JSON —— 最常见原因是「服务器 IP」填成了网站域名，
            // 请求打到了网站（返回 HTML 404/首页），而不是 Windows 上的 APP。给出针对性提示。
            $head = strtolower(substr(ltrim($response), 0, 256));
            if (strpos($head, '<!doctype') === 0 || strpos($head, '<html') !== false) {
                return ['ok' => false, 'role' => 'user', 'detail' => self::connectHint(
                    $url,
                    '目标地址返回的是网页而非 APP 响应——很可能把「服务器 IP」填成了网站域名'
                )];
            }
            return ['ok' => false, 'role' => 'user', 'detail' => self::connectHint($url, 'APP 返回内容不是合法 JSON')];
        }
        if (($data['ok'] ?? false) !== true) {
            $err = self::errorText($data);
            // v1.3：not_found 说明请求大概率打到了网站（域名填错），而非 Windows APP
            if (isset($data['error']['code']) && $data['error']['code'] === 'not_found') {
                $err = self::connectHint($url, '目标地址返回「接口不存在」——很可能把「服务器 IP」填成了网站域名');
            }
            return ['ok' => false, 'role' => 'user', 'detail' => $err];
        }
        $role = in_array($data['role'] ?? 'user', ['user', 'admin'], true) ? $data['role'] : 'user';
        return ['ok' => true, 'role' => $role, 'detail' => self::errorText($data, 'detail')];
    }

    /**
     * 向 Windows APP 下达「改号」命令（管理员强制修改账号名 / 密码）。
     *
     * 通信契约（HTTP JSON）：
     *   POST http://{ip}:{port}/api/v1/update-user
     *   Authorization: Bearer {pass}
     *   Body: {"order_no":"...","account":"旧账号","new_account":"新账号(可选)","new_password":"新密码(可选)"}
     *   响应: {"ok":true,"password_changed":bool,"renamed":bool,"account":"最终账号名","detail":"..."}
     *
     * 网站端不存储密码，改密一律由 APP 以管理员权限强制重置，无需也不校验旧密码。
     * @return array{ok:bool,renamed:bool,password_changed:bool,account:string,detail:string}
     */
    public static function updateUser(
        array $cfg,
        string $orderNo,
        string $productId,
        string $account,
        string $newAccount,
        string $newPassword
    ): array {
        $fail = ['ok' => false, 'renamed' => false, 'password_changed' => false,
                 'account' => $account, 'detail' => ''];

        if (($cfg['mock'] ?? '0') === '1') {
            return self::mockUpdate($orderNo, $productId, $account, $newAccount, $newPassword);
        }

        $ip    = trim((string)($cfg['ip'] ?? ''));
        $port  = (int)($cfg['port'] ?? 0);
        $token = (string)($cfg['pass'] ?? '');
        if ($ip === '' || $port <= 0) {
            $fail['detail'] = '欲蓝服务器地址未配置（请在后台「欲蓝服务器」填写 IP 与端口）';
            return $fail;
        }

        $url  = 'http://' . $ip . ':' . $port . '/api/v1/update-user';
        $body = json_encode([
            'order_no'     => $orderNo,
            'product_id'   => $productId,
            'account'      => $account,
            'new_account'  => $newAccount,
            'new_password' => $newPassword,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = self::httpPost($url, $body, $token);
        if ($response === null) {
            $fail['detail'] = self::connectHint($url, '无法建立连接');
            return $fail;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $head = strtolower(substr(ltrim($response), 0, 256));
            if (strpos($head, '<!doctype') === 0 || strpos($head, '<html') !== false) {
                $fail['detail'] = self::connectHint($url, '目标地址返回网页而非 APP 响应——服务器 IP 可能填成了网站域名');
                return $fail;
            }
            $fail['detail'] = self::connectHint($url, 'APP 返回内容不是合法 JSON');
            return $fail;
        }
        if (($data['ok'] ?? false) !== true) {
            $fail['detail'] = self::errorText($data, 'detail');
            return $fail;
        }
        return [
            'ok'               => true,
            'renamed'          => ($data['renamed'] ?? false) === true,
            'password_changed' => ($data['password_changed'] ?? false) === true,
            'account'          => (string)($data['account'] ?? $account),
            'detail'           => (string)($data['detail'] ?? ''),
        ];
    }

    /** 从 APP 响应里安全地取出文本错误信息（error 可能是对象/数组） */
    private static function errorText(array $data, string $key = 'error'): string
    {
        $v = $data[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        if (is_array($v)) {
            // 兼容 {"error":{"message":"..."}} / {"error":{"code":"x","message":"y"}}
            if (isset($v['message']) && is_string($v['message'])) {
                $prefix = isset($v['code']) && is_string($v['code']) ? '[' . $v['code'] . '] ' : '';
                return $prefix . $v['message'];
            }
            return json_encode($v, JSON_UNESCAPED_UNICODE) ?: 'APP 返回异常';
        }
        // 兜底：换另一个键试试
        $alt = $key === 'error' ? ($data['detail'] ?? null) : ($data['error'] ?? null);
        if (is_string($alt)) {
            return $alt;
        }
        return 'APP 返回异常';
    }

    /**
     * v1.3：把连接失败的原因拼成可操作的提示，帮用户自查配置。
     * 关键点：APP 跑在客户自己的 Windows 服务器 IP:8321 上，不在网站域名上。
     */
    private static function connectHint(string $url, string $reason): string
    {
        return $reason . '。请检查：① 后台「服务器 IP」应填运行 Windows APP 那台机器的 IP（不是网站域名）；'
            . '② 端口与 APP 配置一致（默认 8321）；③ APP 已用管理员权限启动；④ 服务器防火墙放行该端口；'
            . '⑤ 口令与 APP 的 token 一致。当前请求地址：' . $url;
    }

    /** 从站点设置拼出连接器配置（全局默认值） */
    public static function configFromSettings(): array
    {
        $s = SettingsService::all();
        return [
            'ip'   => trim((string)($s['yulan_server_ip'] ?? '')),
            'port' => (int)($s['yulan_server_port'] ?? 8321),
            'user' => (string)($s['yulan_server_user'] ?? ''),
            'pass' => (string)($s['yulan_server_pass'] ?? ''),
            'mock' => ($s['yulan_mock'] ?? '0') === '1' ? '1' : '0',
        ];
    }

    /**
     * 合并商品级配置：每个欲蓝商品可以指向自己的 Windows 服务器，
     * 商品字段为空时回退全局设置。
     * @param array $productRow 含 yulan_server_ip/port/user/pass/rdp_host 的商品行
     */
    public static function resolveProductConfig(array $cfg, array $productRow): array
    {
        $pIp   = trim((string)($productRow['yulan_server_ip'] ?? ''));
        $pPort = trim((string)($productRow['yulan_server_port'] ?? ''));
        $pUser = trim((string)($productRow['yulan_server_user'] ?? ''));
        $pPass = (string)($productRow['yulan_server_pass'] ?? '');

        if ($pIp !== '')   { $cfg['ip'] = $pIp; }
        if ($pPort !== '' && (int)$pPort > 0) { $cfg['port'] = (int)$pPort; }
        if ($pUser !== '') { $cfg['user'] = $pUser; }
        if ($pPass !== '') { $cfg['pass'] = $pPass; }
        return $cfg;
    }

    /**
     * v1.3：按商品绑定的「财务魔方 API」解析连接配置。
     *
     * 优先级（高 → 低）：
     *   1. 商品绑定了启用的 API（yulan_api_id > 0）→ 用该 API 的 IP/端口/口令
     *   2. 否则回退商品级 yulan_server_* 字段（旧行为）
     *   3. 再否则用全局设置（configFromSettings）
     *
     * @param array $cfg        全局配置（configFromSettings 的结果）
     * @param array $productRow 商品行（需含 yulan_api_id 及商品级 yulan_server_* 字段）
     * @return array{ip:string,port:int,user:string,pass:string,rdp_host?:string}
     */
    public static function resolveConfig(array $cfg, array $productRow): array
    {
        $apiId = (int)($productRow['yulan_api_id'] ?? 0);
        if ($apiId > 0 && class_exists('YulanApiService')) {
            $api = YulanApiService::connectionConfig($apiId);
            if ($api !== null && $api['ip'] !== '') {
                return $api;   // API 绑定优先（完整替换，不再叠加商品级字段）
            }
        }
        return self::resolveProductConfig($cfg, $productRow);
    }

    /** 交付给用户的「连接地址」：v1.3 绑定 API > 商品级 yulan_rdp_host/IP > 全局 */
    public static function connectionAddress(array $productRow = []): string
    {
        // v1.3：商品绑定了 API 时，用该 API 的交付地址（rdp_host 优先，否则 server_ip）
        $apiId = (int)($productRow['yulan_api_id'] ?? 0);
        if ($apiId > 0 && class_exists('YulanApiService')) {
            $api = YulanApiService::connectionConfig($apiId);
            if ($api !== null) {
                if (!empty($api['rdp_host'])) {
                    return (string)$api['rdp_host'];
                }
                if (!empty($api['ip'])) {
                    return (string)$api['ip'];
                }
            }
        }
        $pRdp = trim((string)($productRow['yulan_rdp_host'] ?? ''));
        if ($pRdp !== '') {
            return $pRdp;
        }
        $pIp = trim((string)($productRow['yulan_server_ip'] ?? ''));
        if ($pIp !== '') {
            return $pIp;
        }
        $s = SettingsService::all();
        $host = trim((string)($s['yulan_rdp_host'] ?? ''));
        if ($host === '') {
            $host = trim((string)($s['yulan_server_ip'] ?? ''));
        }
        return $host;
    }

    /** HTTP POST（优先 curl，回退 file_get_contents）；失败返回 null */
    private static function httpPost(string $url, string $body, string $token): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_POSTFIELDS    => $body,
            ]);
            $res = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            return $err === 0 && is_string($res) ? $res : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer " . $token . "\r\n",
                'content'       => $body,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        return is_string($res) ? $res : null;
    }

    /** 模拟模式：把命令写进队列文件，不真正连接 APP */
    private static function mockCreate(string $orderNo, string $productId, string $account, string $password): array
    {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'role' => 'user', 'detail' => '无法写入模拟队列目录'];
        }
        $entry = [
            'ts'         => date('c'),
            'order_no'   => $orderNo,
            'product_id' => $productId,
            'account'    => $account,
            // 安全基线：连模拟队列也绝不落明文口令（与 mockUpdate 一致）。
            // 真实模式口令只在 HTTP 请求里临时持有，本就不落盘；模拟文件同样脱敏。
            'password'   => $password === '' ? '' : '***',
            'mode'       => 'mock',
            'action'     => 'create-user',
        ];
        @file_put_contents($dir . '/yulan_mock_queue.jsonl', json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return ['ok' => true, 'role' => 'user', 'detail' => 'MOCK 模式：命令已写入队列'];
    }

    /** 模拟模式：把「改号」命令写进队列，密码脱敏（连 mock 也不落明文） */
    private static function mockUpdate(
        string $orderNo,
        string $productId,
        string $account,
        string $newAccount,
        string $newPassword
    ): array {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'renamed' => false, 'password_changed' => false,
                    'account' => $account, 'detail' => '无法写入模拟队列目录'];
        }
        $entry = [
            'ts'              => date('c'),
            'order_no'        => $orderNo,
            'product_id'      => $productId,
            'account'         => $account,
            'new_account'     => $newAccount,
            'new_password'    => $newPassword === '' ? '' : '***', // 模拟文件也不落明文
            'mode'            => 'mock',
            'action'          => 'update-user',
        ];
        @file_put_contents($dir . '/yulan_mock_queue.jsonl', json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return [
            'ok'               => true,
            'renamed'          => $newAccount !== '',
            'password_changed' => $newPassword !== '',
            'account'          => $newAccount !== '' ? $newAccount : $account,
            'detail'           => 'MOCK 模式：改号命令已写入队列',
        ];
    }
}
