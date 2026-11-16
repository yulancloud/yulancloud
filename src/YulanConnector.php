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
            return ['ok' => false, 'role' => 'user', 'detail' => '无法连接欲蓝 APP（' . $url . '），请确认 APP 已运行且地址正确'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            return ['ok' => false, 'role' => 'user', 'detail' => (string)($data['error'] ?? 'APP 返回异常')];
        }
        $role = in_array($data['role'] ?? 'user', ['user', 'admin'], true) ? $data['role'] : 'user';
        return ['ok' => true, 'role' => $role, 'detail' => (string)($data['detail'] ?? '')];
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
            $fail['detail'] = '无法连接欲蓝 APP（' . $url . '），请确认 APP 已运行且地址正确';
            return $fail;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            $fail['detail'] = (string)($data['detail'] ?? $data['error'] ?? 'APP 返回异常');
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

    /** 交付给用户的「连接地址」：优先商品级 yulan_rdp_host / 商品 IP，再回退全局 */
    public static function connectionAddress(array $productRow = []): string
    {
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
