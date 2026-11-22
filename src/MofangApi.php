<?php
/**
 * 智简魔方 v10 上游 API 适配器（v1.5）
 *
 * 依据智简魔方业务管理系统 v10（ZJMF-CBAP v10 / idcsmart）真实源码实现：
 *   - app/api/controller/AuthController.php   鉴权 /api/v1/auth
 *   - app/api/controller/ProductController.php 产品 /api/v1/product
 *   - app/common/model/SupplierModel.php::apiAuth()  登录换 JWT（v10: data.jwt；老财务: jwt）
 *   - app/common.php::idcsmart_api_curl()      业务请求统一 Bearer JWT + 401/405 自动重登
 *   - app/common/logic/UpstreamLogic.php       上游类型分支（finance / whmcs / 默认v10）
 *   - app/common/logic/ResModuleLogic.php      下单开通（v10: console/v1/cart→settle→pay；老财务: cart/add_to_shop→settle→apply_credit）
 *
 * 三种上游协议（api_type）：
 *   - v10     ：新魔方云 / 魔方财务 v10。登录 POST /api/v1/auth {username,password} → data.jwt；
 *                产品 GET /api/v1/product → data.list；下单 console/v1/cart→settle→pay。
 *   - finance ：老财务。登录 POST /zjmf_api_login {username,password} → jwt；
 *                产品 GET /api/product/list → data.list；下单 /cart/clear→add_to_shop→settle→apply_credit。
 *   - whmcs   ：WHMCS reseller 插件。Header Email/Password；路径 modules/addons/idcsmart_reseller/logic/index.php?action=xxx。
 *
 * 关键修复（相对 v1.4.x 全部 404 的根因）：
 *   1) 真实系统是「先登录换 JWT，再用 Bearer JWT 调业务接口」两阶段，v1.4.x 直接拿 api_key 当 Bearer → 鉴权失败/404。
 *   2) 产品路径是单数 /api/v1/product（老财务 /api/product/list），v1.4.x 用 /api/v1/products → 404。
 *   3) 响应是 {status:200,msg,data:{list|product}}，v1.4.x 按 {data:[...]} 解析 → 空列表。
 *
 * 安全：api_key（密码）只用于登录，不写入日志；JWT 缓存到 data/ 目录，2 小时有效，401 自动刷新。
 */
declare(strict_types=1);

final class MofangApi
{
    private string $base;
    private string $apiType;   // v10 | finance | whmcs
    private string $username;
    private string $password;
    private int $upstreamId;
    private string $lastError = '';

    public function __construct(array $cfg)
    {
        $base = (string)($cfg['base_url'] ?? '');
        $base = preg_replace('#^https?://#i', '', $base) ?? $base;
        $this->base = rtrim($base, '/');
        $scheme = !empty($cfg['use_https']) ? 'https://' : 'http://';
        $this->base = $scheme . $this->base;

        // protocol 来自 api_type；缺省时按 kind 推断（finance→老财务，cloud→v10）
        $type = strtolower((string)($cfg['api_type'] ?? ''));
        if (!in_array($type, ['v10', 'finance', 'whmcs'], true)) {
            $type = (string)($cfg['kind'] ?? 'cloud') === 'finance' ? 'finance' : 'v10';
        }
        $this->apiType = $type;
        $this->username = (string)($cfg['api_user'] ?? '');
        $this->password = (string)($cfg['api_key'] ?? '');
        $this->upstreamId = (int)($cfg['id'] ?? 0);
    }

    public function lastError(): string
    {
        return $this->lastError !== '' ? $this->lastError : '未知错误';
    }

    public function apiType(): string
    {
        return $this->apiType;
    }

    /* ==================== 登录换 JWT ==================== */

    private function cacheFile(): string
    {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/mofang_jwt_' . $this->upstreamId . '.json';
    }

    /** 取 JWT：先读缓存（2 小时），失效则登录。$force 强制重新登录。 */
    private function jwt(bool $force = false): ?string
    {
        $file = $this->cacheFile();
        if (!$force && is_file($file)) {
            $c = json_decode((string)@file_get_contents($file), true);
            if (is_array($c) && !empty($c['jwt']) && (int)($c['expire'] ?? 0) > time()) {
                return (string)$c['jwt'];
            }
        }
        $jwt = $this->login();
        if ($jwt === null) {
            return null;
        }
        @file_put_contents($file, json_encode([
            'jwt' => $jwt, 'expire' => time() + 2 * 3600,
        ]));
        return $jwt;
    }

    /** 登录，返回 JWT 或 null。JWT 在 v10 位于 data.jwt，老财务位于根级 jwt。 */
    private function login(): ?string
    {
        if ($this->apiType === 'whmcs') {
            // WHMCS 不走 JWT，业务请求用 Header Email/Password
            return 'whmcs-no-jwt';
        }
        $path = $this->apiType === 'finance' ? '/zjmf_api_login' : '/api/v1/auth';
        $resp = $this->rawRequest($this->base . $path, [
            'username' => $this->username,
            'password' => $this->password,
        ], 'POST', [], false);
        if ($resp === null) {
            $this->lastError = '登录上游失败：' . $this->lastError;
            return null;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            $this->lastError = '上游登录响应不是合法 JSON';
            return null;
        }
        $status = $data['status'] ?? null;
        if ((string)$status !== '200') {
            $this->lastError = '上游登录被拒绝：' . (string)($data['msg'] ?? $data['message'] ?? '账号或密钥错误');
            return null;
        }
        $jwt = $this->apiType === 'finance'
            ? (string)($data['jwt'] ?? '')
            : (string)($data['data']['jwt'] ?? $data['jwt'] ?? '');
        if ($jwt === '') {
            $this->lastError = '上游登录未返回 JWT';
            return null;
        }
        return $jwt;
    }

    /* ==================== 统一业务请求（Bearer JWT + 401 自动重登）==================== */

    /**
     * 请求上游业务接口。
     * @param string $path   以 / 开头的路径，如 /api/v1/product
     * @param array  $data   请求数据（GET 作为 query，POST 作为 JSON body）
     * @param string $method GET|POST|DELETE|PUT
     */
    public function request(string $path, array $data = [], string $method = 'POST'): ?array
    {
        $token = $this->jwt(false);
        if ($token === null) {
            return null;
        }
        $resp = $this->doBusiness($path, $data, $method, $token);
        if ($resp === null) {
            return null;
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            $this->lastError = '上游响应不是合法 JSON';
            return null;
        }
        // 401/405：强制刷新 JWT 重试一次（对齐 idcsmart_api_curl）
        $status = (string)($decoded['status'] ?? '');
        if ($status === '401' || $status === '405') {
            $token = $this->jwt(true);
            if ($token === null) {
                return null;
            }
            $resp = $this->doBusiness($path, $data, $method, $token);
            if ($resp === null) {
                return null;
            }
            $decoded = json_decode($resp, true);
            if (!is_array($decoded)) {
                $this->lastError = '上游响应不是合法 JSON';
                return null;
            }
        }
        return $decoded;
    }

    private function doBusiness(string $path, array $data, string $method, string $token): ?string
    {
        $url = $this->base . (str_starts_with($path, '/') ? $path : '/' . $path);
        if ($this->apiType === 'whmcs') {
            $headers = [
                'Email: ' . $this->username,
                'Password: ' . $this->password,
            ];
            // whmcs 的 path 形如 host_addOrder，拼到 reseller index.php?action=
            $sep = strpos($path, '?') === false ? '?' : '&';
            if (strpos($path, 'index.php') === false) {
                $url = $this->base . '/modules/addons/idcsmart_reseller/logic/index.php?action=' . ltrim($path, '/');
            } else {
                $url = $this->base . '/' . ltrim($path, '/');
            }
            return $this->rawRequest($url, $data, $method, $headers, true);
        }
        $headers = ['Authorization: Bearer ' . $token];
        return $this->rawRequest($url, $data, $method, $headers, true, $method === 'GET');
    }

    /* ==================== 底层 HTTP ==================== */

    private function rawRequest(string $url, array $data, string $method, array $extraHeaders, bool $isBusiness, bool $asQuery = false): ?string
    {
        $headers = array_merge(['Accept: application/json'], $extraHeaders);
        $body = '';
        if ($asQuery && $method === 'GET') {
            $q = http_build_query($data);
            if ($q !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $q;
            }
        } elseif (!empty($data)) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }
        $method = strtoupper($method);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ];
            if ($body !== '') {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $opts);
            $res = curl_exec($ch);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                $this->lastError = '网络错误：' . $err;
                return null;
            }
            if ($code <= 0) {
                $this->lastError = '上游无响应（HTTP ' . $code . '）';
                return null;
            }
            if ($code >= 400) {
                $this->lastError = '上游返回 HTTP ' . $code;
                // 404 时把响应体也保留，便于诊断路径错误
                if (is_string($res) && $res !== '') {
                    $this->lastError .= '：' . mb_substr($res, 0, 120, 'UTF-8');
                }
                if ($isBusiness) {
                    return is_string($res) ? $res : null;
                }
                return null;
            }
            return is_string($res) ? $res : null;
        }

        // fallback: stream
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return is_string($res) ? $res : null;
    }

    /* ==================== 业务封装：产品 / 下单 / 操作 ==================== */

    /** 拉取产品列表，统一返回 [{external_id,name,spec,price}] */
    public function fetchProducts(): ?array
    {
        if ($this->apiType === 'finance') {
            $resp = $this->request('/api/product/list', [], 'GET');
        } elseif ($this->apiType === 'whmcs') {
            $resp = $this->request('product_listing', [], 'POST');
        } else {
            $resp = $this->request('/api/v1/product', [], 'GET');
        }
        if ($resp === null) {
            return null;
        }
        // 兼容 data.list / data.products / list / products / 直接数组
        $list = $resp['data']['list'] ?? $resp['data']['products'] ?? $resp['list'] ?? $resp['products'] ?? null;
        if ($list === null && array_is_list($resp)) {
            $list = $resp;
        }
        if (!is_array($list)) {
            $this->lastError = '上游产品列表为空或格式不符（响应：' . mb_substr(json_encode($resp, JSON_UNESCAPED_UNICODE) ?: '', 0, 160, 'UTF-8') . '）';
            return [];
        }
        $out = [];
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }
            $out[] = [
                'external_id' => (string)($p['id'] ?? $p['product_id'] ?? $p['pid'] ?? ''),
                'name'        => (string)($p['name'] ?? ''),
                'spec'        => (string)($p['description'] ?? $p['spec'] ?? ''),
                'price'       => (float)($p['price'] ?? 0),
                'cycle'       => (string)($p['cycle'] ?? $p['billingcycle'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * 开通：在真实上游创建订单并用余额支付，返回交付信息。
     * $opts: product_id(上游产品ID), config_options(配置), domain, password 等
     */
    public function provision(string $productId, array $opts = []): array
    {
        $fail = static fn(string $msg): array => ['ok' => false, 'detail' => $msg, 'instance' => []];

        if ($this->apiType === 'v10') {
            // 新魔方云 v10：DELETE cart（查重）→ POST cart（加入）→ POST cart/settle（结算）→ POST pay（余额支付）
            $common = [
                'downstream_host_id'   => (string)($opts['order_item_id'] ?? ''),
                'downstream_client_id' => (string)($opts['user_id'] ?? ''),
            ];
            $cartData = [
                'product_id' => $productId,
                'qty' => 1,
                'config_options' => $opts['config_options'] ?? [],
            ];
            $add = $this->request('/console/v1/cart', $cartData, 'POST');
            if ($add === null || (string)($add['status'] ?? '') !== '200') {
                return $fail('上游加入购物车失败：' . $this->lastError . (is_array($add) ? (' / ' . (string)($add['msg'] ?? '')) : ''));
            }
            $settleData = array_merge($common, ['positions' => [0]]);
            $settle = $this->request('/console/v1/cart/settle', $settleData, 'POST');
            if ($settle === null || (string)($settle['status'] ?? '') !== '200') {
                return $fail('上游结算失败：' . $this->lastError . (is_array($settle) ? (' / ' . (string)($settle['msg'] ?? '')) : ''));
            }
            $sdata = $settle['data'] ?? [];
            $orderId = $sdata['order_id'] ?? '';
            $hostIds = $sdata['host_ids'] ?? [];
            $d = $this->base . '';
            // 余额支付（若需支付）
            if (!empty($orderId)) {
                $this->request('/console/v1/pay', ['id' => $orderId, 'gateway' => 'credit'], 'POST');
            }
            return [
                'ok' => true,
                'detail' => '上游开通成功',
                'instance' => $this->buildInstance($sdata, (string)($hostIds[0] ?? ''), $productId, $opts),
            ];
        }

        if ($this->apiType === 'finance') {
            // 老财务：cart/clear → cart/add_to_shop → cart/settle → apply_credit
            $common = [
                'downstream_url'   => (string)($opts['callback_url'] ?? ''),
                'downstream_token' => (string)($opts['callback_token'] ?? ''),
                'downstream_id'    => (string)($opts['order_item_id'] ?? ''),
            ];
            $this->request('/cart/clear', $common, 'POST');
            $cartData = [
                'pid' => $productId,
                'billingcycle' => (string)($opts['cycle'] ?? ''),
                'host' => (string)($opts['domain'] ?? ''),
                'password' => (string)($opts['password'] ?? ''),
                'currencyid' => 1,
                'qty' => 1,
                'configoption' => $opts['config_options'] ?? [],
                'customfield' => $opts['custom_fields'] ?? [],
            ];
            $add = $this->request('/cart/add_to_shop', $cartData, 'POST');
            if ($add === null || (string)($add['status'] ?? '') !== '200') {
                return $fail('上游加入购物车失败：' . $this->lastError . (is_array($add) ? (' / ' . (string)($add['msg'] ?? '')) : ''));
            }
            $settle = $this->request('/cart/settle', $common, 'POST');
            if ($settle === null) {
                return $fail('上游结算失败：' . $this->lastError);
            }
            $sstatus = (string)($settle['status'] ?? '');
            $hostId = '';
            $hostIds = $settle['data']['hostid'] ?? [];
            if (!empty($hostIds[0])) {
                $hostId = (string)$hostIds[0];
            } elseif (!empty($settle['hostid'])) {
                $hostId = (string)$settle['hostid'];
            }
            $invoiceId = (string)($settle['data']['invoiceid'] ?? $settle['invoiceid'] ?? '');
            if ($sstatus === '1001') {
                // 1001 = 已开通
                return ['ok' => true, 'detail' => '上游开通成功', 'instance' => $this->buildInstance($settle, $hostId, $productId, $opts)];
            }
            if ($invoiceId !== '') {
                $pay = $this->request('/apply_credit', [
                    'invoiceid' => $invoiceId, 'use_credit' => 1, 'enough' => 0,
                ] + $common, 'POST');
                if (is_array($pay) && (string)($pay['status'] ?? '') === '1001') {
                    return ['ok' => true, 'detail' => '上游开通成功', 'instance' => $this->buildInstance($pay, $hostId, $productId, $opts)];
                }
            }
            return ['ok' => true, 'detail' => '上游下单已提交', 'instance' => $this->buildInstance($settle, $hostId, $productId, $opts)];
        }

        // whmcs：host_addOrder
        $wdata = [
            'hostname' => (string)($opts['domain'] ?? ''),
            'productid' => $productId,
            'billingcycle' => (string)($opts['cycle'] ?? ''),
            'configoptions' => $opts['config_options'] ?? [],
            'customfields' => $opts['custom_fields'] ?? [],
            'downstream_url' => (string)($opts['callback_url'] ?? ''),
            'downstream_token' => (string)($opts['callback_token'] ?? ''),
            'downstream_id' => (string)($opts['order_item_id'] ?? ''),
        ];
        $res = $this->request('host_addOrder', $wdata, 'POST');
        if ($res === null || (string)($res['status'] ?? '') !== '200') {
            return $fail('上游下单失败：' . $this->lastError);
        }
        return [
            'ok' => true,
            'detail' => '上游开通成功',
            'instance' => $this->buildInstance($res, (string)($res['data']['hosting_id'] ?? ''), $productId, $opts),
        ];
    }

    /** 实例操作：开机/关机/重启/重置密码等（v10 走 /provision/default） */
    public function instanceAction(string $externalId, string $action, array $opts = []): array
    {
        $map = [
            'start' => 'on', 'stop' => 'off', 'restart' => 'reboot',
            'reset-password' => 'reset_password', 'reinstall' => 'reinstall',
            'delete' => 'terminate',
        ];
        $op = $map[$action] ?? $action;
        if ($this->apiType === 'v10') {
            $data = ['host_id' => $externalId, 'action' => $op] + $opts;
            $res = $this->request('/provision/default', $data, 'POST');
        } elseif ($this->apiType === 'finance') {
            $data = ['host_id' => $externalId, 'action' => $op] + $opts;
            $res = $this->request('/provision/default', $data, 'POST');
        } else {
            $res = $this->request('service_' . str_replace('-', '_', $op), ['hosting_id' => $externalId] + $opts, 'POST');
        }
        if ($res === null) {
            return ['ok' => false, 'detail' => '上游操作失败：' . $this->lastError];
        }
        $ok = in_array((string)($res['status'] ?? ''), ['200', '1001'], true) || ($res['ok'] ?? false) === true;
        return ['ok' => $ok, 'detail' => (string)($res['msg'] ?? $res['message'] ?? ($ok ? '操作成功' : '操作失败'))];
    }

    /** 测试连接：登录 + 拉一次产品，成功即连通 */
    public function test(): array
    {
        $token = $this->jwt(false);
        if ($token === null) {
            return ['ok' => false, 'detail' => $this->lastError];
        }
        if ($this->apiType === 'whmcs') {
            return ['ok' => true, 'detail' => 'WHMCS 凭据已配置（业务请求时验证）'];
        }
        $products = $this->fetchProducts();
        if ($products === null) {
            return ['ok' => false, 'detail' => $this->lastError];
        }
        return ['ok' => true, 'detail' => '连接成功，协议=' . $this->apiType . '，读到 ' . count($products) . ' 个产品'];
    }

    /** 组装交付实例信息（兼容多种字段名） */
    private function buildInstance(array $src, string $hostId, string $productId, array $opts): array
    {
        $d = $src['data'] ?? $src;
        $pick = static function (array $a, array $keys): string {
            foreach ($keys as $k) {
                if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null && !is_array($a[$k])) {
                    return (string)$a[$k];
                }
            }
            return '';
        };
        return [
            'external_id' => $pick($d, ['host_id', 'id', 'hostid', 'hosting_id', 'instance_id']) ?: $hostId,
            'instance_name' => $pick($d, ['name', 'hostname', 'domain']) ?: (string)($opts['domain'] ?? ''),
            'ip' => $pick($d, ['ip', 'ip_address', 'ipAddr', 'dedicatedip']),
            'os' => $pick($d, ['os', 'os_name', 'osName', 'system']),
            'cpu' => $pick($d, ['cpu', 'cpu_cores']),
            'mem' => $pick($d, ['mem', 'memory', 'ram']),
            'disk' => $pick($d, ['disk', 'disk_size']),
            'bandwidth' => $pick($d, ['bandwidth']),
            'region' => $pick($d, ['region', 'node', 'area']),
            'username' => $pick($d, ['username', 'user', 'account']) ?: (string)($opts['username'] ?? ''),
            'password' => $pick($d, ['password', 'passwd', 'pwd']) ?: (string)($opts['password'] ?? ''),
        ];
    }
}
