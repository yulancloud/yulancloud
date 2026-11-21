<?php
/**
 * 上游服务（v1.4）—— 统一承载「魔方财务对接上游」与「魔方云 KVM 云服务器」
 *
 * 背景：v1.3 的「欲蓝财务魔方」走本地 Windows APP 建号（YulanConnector）。
 * v1.4 重新定义为「对接上游」：
 *   - kind=finance：像智简魔方财务那样对接上游财务系统，拉取上游产品、加价销售，
 *     下单时在上游创建订单、用上游余额付款，获取产品交付信息。
 *   - kind=cloud：对接魔方云（KVM）RESTful API，下单创建云主机，
 *     支持开机 / 关机 / 重启 / 重置密码 / 重装系统 / 删除。
 * 两者共用一套 upstreams 配置与 HTTP 客户端，安全基线一致。
 *
 * 安全基线：
 *   - api_key 只写不读：list()/get() 只回传 api_key_set，绝不回传明文。
 *   - 留空 = 不修改；'__CLEAR__' = 清空。
 *   - mock 模式（upstream_mock=1）：不真正联网，把动作写入 data/upstream_mock_queue.jsonl，
 *     并返回模拟的交付信息，方便演示与测试。
 */
declare(strict_types=1);

final class UpstreamService
{
    /* ==================== 上游 CRUD ==================== */

    private static function publicRow(array $r): array
    {
        return [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'kind'         => (string)$r['kind'],
            'kind_label'   => (string)$r['kind'] === 'cloud' ? '魔方云 KVM' : '魔方财务（上游）',
            'base_url'     => (string)$r['base_url'],
            'api_user'     => (string)$r['api_user'],
            'financial_id' => (string)$r['financial_id'],
            'account_type' => (string)$r['account_type'],
            'use_https'    => (int)$r['use_https'],
            'enabled'      => (int)$r['enabled'],
            // v1.4.1：协议可配置字段（回退默认值，兼容旧库未迁移）
            'auth_mode'             => (string)($r['auth_mode'] ?? 'bearer'),
            'path_products'         => (string)($r['path_products'] ?? '/api/v1/products'),
            'path_provision_cloud'  => (string)($r['path_provision_cloud'] ?? '/api/v1/vm/create'),
            'path_provision_finance'=> (string)($r['path_provision_finance'] ?? '/api/v1/orders'),
            'path_action'           => (string)($r['path_action'] ?? '/api/v1/vm/{id}/{action}'),
            'price_is_cents'        => (int)($r['price_is_cents'] ?? 0),
            'remark'       => (string)$r['remark'],
            'api_key_set'  => ((string)$r['api_key']) !== '',
            'created_at'   => (string)($r['created_at'] ?? ''),
            'updated_at'   => (string)($r['updated_at'] ?? ''),
        ];
    }

    public static function list(?string $kind = null): array
    {
        try {
            if ($kind !== null && in_array($kind, ['finance', 'cloud'], true)) {
                $st = Db::conn()->prepare('SELECT * FROM upstreams WHERE kind = ? ORDER BY id ASC');
                $st->execute([$kind]);
            } else {
                $st = Db::conn()->query('SELECT * FROM upstreams ORDER BY id ASC');
            }
            $rows = $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        return array_map([self::class, 'publicRow'], $rows);
    }

    public static function get(int $id): array
    {
        $st = Db::conn()->prepare('SELECT * FROM upstreams WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            Response::fail('not_found', '上游配置不存在', 404);
        }
        return self::publicRow($r);
    }

    /** 内部用：含明文密钥的连接参数 */
    public static function connectionConfig(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = Db::conn()->prepare('SELECT * FROM upstreams WHERE id = ? AND enabled = 1');
            $st->execute([$id]);
            $r = $st->fetch();
        } catch (Throwable $e) {
            return null;
        }
        if (!$r) {
            return null;
        }
        return [
            'id'           => (int)$r['id'],
            'kind'         => (string)$r['kind'],
            'base_url'     => trim((string)$r['base_url']),
            'api_user'     => (string)$r['api_user'],
            'api_key'      => (string)$r['api_key'],
            'financial_id' => (string)$r['financial_id'],
            'account_type' => (string)$r['account_type'],
            'use_https'    => (int)$r['use_https'] === 1,
            'auth_mode'    => (string)($r['auth_mode'] ?? 'bearer'),
            'path_products'         => (string)($r['path_products'] ?? '/api/v1/products'),
            'path_provision_cloud'  => (string)($r['path_provision_cloud'] ?? '/api/v1/vm/create'),
            'path_provision_finance'=> (string)($r['path_provision_finance'] ?? '/api/v1/orders'),
            'path_action'           => (string)($r['path_action'] ?? '/api/v1/vm/{id}/{action}'),
            'price_is_cents'        => (int)($r['price_is_cents'] ?? 0),
            'mock'         => self::mockEnabled(),
        ];
    }

    public static function create(array $in): array
    {
        $v = self::validate($in, false);
        Db::conn()->prepare(
            'INSERT INTO upstreams (name, kind, base_url, api_user, api_key, financial_id, account_type, use_https,
                auth_mode, path_products, path_provision_cloud, path_provision_finance, path_action, price_is_cents,
                enabled, remark, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
        )->execute([
            $v['name'], $v['kind'], $v['base_url'], $v['api_user'], $v['api_key'],
            $v['financial_id'], $v['account_type'], $v['use_https'],
            $v['auth_mode'], $v['path_products'], $v['path_provision_cloud'],
            $v['path_provision_finance'], $v['path_action'], $v['price_is_cents'],
            $v['enabled'], $v['remark'],
        ]);
        return self::get((int)Db::conn()->lastInsertId());
    }

    public static function update(int $id, array $in): array
    {
        self::get($id);
        $v = self::validate($in, true);
        $sets = ['name = ?', 'kind = ?', 'base_url = ?', 'api_user = ?', 'financial_id = ?',
                 'account_type = ?', 'use_https = ?',
                 'auth_mode = ?', 'path_products = ?', 'path_provision_cloud = ?',
                 'path_provision_finance = ?', 'path_action = ?', 'price_is_cents = ?',
                 'enabled = ?', 'remark = ?', "updated_at = datetime('now')"];
        $args = [$v['name'], $v['kind'], $v['base_url'], $v['api_user'], $v['financial_id'],
                 $v['account_type'], $v['use_https'],
                 $v['auth_mode'], $v['path_products'], $v['path_provision_cloud'],
                 $v['path_provision_finance'], $v['path_action'], $v['price_is_cents'],
                 $v['enabled'], $v['remark']];
        if ($v['key_set']) {
            $sets[] = 'api_key = ?';
            $args[] = $v['api_key'];
        }
        $args[] = $id;
        Db::conn()->prepare('UPDATE upstreams SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        self::get($id);
        $pdo = Db::conn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE upstream_id = ?');
        $st->execute([$id]);
        $used = (int)$st->fetchColumn();
        if ($used > 0) {
            Response::fail('upstream_in_use', "该上游仍被 {$used} 个商品使用，请先在商品里改绑后再删除", 409);
        }
        $pdo->prepare('DELETE FROM upstreams WHERE id = ?')->execute([$id]);
        return ['ok' => true, 'unbound' => $used];
    }

    private static function validate(array $in, bool $isUpdate): array
    {
        $name = isset($in['name']) ? trim((string)$in['name']) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 40) {
            Response::fail('invalid_name', '上游名称需为 1-40 个字符', 400);
        }
        $kind = (string)($in['kind'] ?? 'finance');
        if (!in_array($kind, ['finance', 'cloud'], true)) {
            Response::fail('invalid_kind', '上游类型不合法', 400);
        }
        $base = isset($in['base_url']) ? trim((string)$in['base_url']) : '';
        if ($base === '' || mb_strlen($base, 'UTF-8') > 200) {
            Response::fail('invalid_url', '接口地址需为 1-200 个字符', 400);
        }
        // v1.4.1：拒绝把上游指向本机/内网地址（SSRF 防护）。mock 模式由全局开关控制，不在此处放行。
        self::assertPublicBaseUrl($base);
        $user = isset($in['api_user']) ? trim((string)$in['api_user']) : '';
        $fin  = isset($in['financial_id']) ? trim((string)$in['financial_id']) : '';
        $acct = isset($in['account_type']) ? trim((string)$in['account_type']) : 'agent';
        if (!in_array($acct, ['agent', 'normal'], true)) {
            $acct = 'agent';
        }
        $https = array_key_exists('use_https', $in) ? ((int)($in['use_https']) === 1 ? 1 : 0) : 0;
        $enabled = array_key_exists('enabled', $in) ? ((int)($in['enabled']) === 1 ? 1 : 0) : 1;
        $remark = isset($in['remark']) ? trim((string)$in['remark']) : '';
        if (mb_strlen($remark, 'UTF-8') > 200) {
            $remark = mb_substr($remark, 0, 200, 'UTF-8');
        }

        $keySet = false;
        $key = '';
        if (array_key_exists('api_key', $in)) {
            $raw = (string)$in['api_key'];
            if ($raw === '__CLEAR__') {
                $keySet = true;
                $key = '';
            } elseif ($raw !== '') {
                if (mb_strlen($raw, 'UTF-8') < 4) {
                    Response::fail('invalid_key', 'API 密钥至少 4 位', 400);
                }
                $keySet = true;
                $key = $raw;
            }
        }
        if (!$isUpdate && !$keySet) {
            Response::fail('invalid_key', '新增上游必须填写 API 密钥', 400);
        }

        // v1.4.1：协议可配置字段
        $authMode = (string)($in['auth_mode'] ?? 'bearer');
        if (!in_array($authMode, ['bearer', 'basic', 'query', 'none'], true)) {
            $authMode = 'bearer';
        }
        $paths = [
            'path_products'          => '/api/v1/products',
            'path_provision_cloud'   => '/api/v1/vm/create',
            'path_provision_finance' => '/api/v1/orders',
            'path_action'            => '/api/v1/vm/{id}/{action}',
        ];
        foreach ($paths as $k => $default) {
            $paths[$k] = self::normalizeApiPath(isset($in[$k]) ? (string)$in[$k] : '', $default);
        }
        $priceIsCents = array_key_exists('price_is_cents', $in) ? ((int)($in['price_is_cents']) === 1 ? 1 : 0) : 0;

        return [
            'name' => $name, 'kind' => $kind, 'base_url' => $base, 'api_user' => $user,
            'api_key' => $key, 'key_set' => $keySet, 'financial_id' => $fin,
            'account_type' => $acct, 'use_https' => $https, 'enabled' => $enabled, 'remark' => $remark,
            'auth_mode' => $authMode, 'price_is_cents' => $priceIsCents,
            'path_products' => $paths['path_products'],
            'path_provision_cloud' => $paths['path_provision_cloud'],
            'path_provision_finance' => $paths['path_provision_finance'],
            'path_action' => $paths['path_action'],
        ];
    }

    /** 规整 API 路径：空用默认；必须以 / 开头；拒绝空白与 .. */
    private static function normalizeApiPath(string $path, string $default): string
    {
        $path = trim($path);
        if ($path === '') {
            return $default;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strpos($path, '..') !== false || mb_strlen($path, 'UTF-8') > 200) {
            return $default;
        }
        return $path;
    }

    /**
     * SSRF 防护：base_url 不允许指向回环/链路本地/私有网段。
     * 仅校验 host 字面量；域名解析到内网的 DNS rebinding 属进阶威胁，需网络层防火墙兜底。
     */
    private static function assertPublicBaseUrl(string $base): void
    {
        $host = parse_url(preg_match('#^https?://#i', $base) ? $base : 'http://' . $base, PHP_URL_HOST);
        $host = is_string($host) ? strtolower(trim($host)) : '';
        if ($host === '' || strpos($host, '..') !== false) {
            Response::fail('invalid_url', '接口地址格式不正确', 400);
        }
        // 去掉 IPv6 方括号
        $bare = trim($host, '[]');
        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
        if (in_array($host, $blocked, true) || in_array($bare, $blocked, true)) {
            Response::fail('unsafe_url', '接口地址不能指向本机或内网地址', 400);
        }
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            if (!filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                Response::fail('unsafe_url', '接口地址不能指向私有网段', 400);
            }
        }
        // 常见内网域名后缀
        foreach (['.local', '.internal', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                Response::fail('unsafe_url', '接口地址不能指向内网域名', 400);
            }
        }
    }

    /* ==================== 上游产品目录 ==================== */

    public static function products(int $upstreamId): array
    {
        $st = Db::conn()->prepare('SELECT * FROM upstream_products WHERE upstream_id = ? ORDER BY id ASC');
        $st->execute([$upstreamId]);
        return array_map(static fn($r) => [
            'id' => (int)$r['id'],
            'upstream_id' => (int)$r['upstream_id'],
            'external_id' => (string)$r['external_id'],
            'name' => (string)$r['name'],
            'spec' => (string)$r['spec'],
            'price_cents' => (int)$r['price_cents'],
            'status' => (string)$r['status'],
            'synced_at' => $r['synced_at'],
        ], $st->fetchAll());
    }

    /** 同步上游产品：真实模式调上游 API 拉取；mock 模式生成演示产品 */
    public static function syncProducts(int $upstreamId): array
    {
        $cfg = self::connectionConfig($upstreamId);
        if ($cfg === null) {
            Response::fail('upstream_disabled', '上游不存在或已停用', 409);
        }
        if ($cfg['mock']) {
            $list = self::mockProducts($cfg);
        } else {
            $list = self::httpFetchProducts($cfg);
            if ($list === null) {
                Response::fail('sync_failed', '拉取上游产品失败：' . self::lastHttpError(), 502);
            }
        }

        $pdo = Db::conn();
        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare(
            'INSERT INTO upstream_products (upstream_id, external_id, name, spec, price_cents, status, synced_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'active\', ?, datetime(\'now\'), datetime(\'now\'))
             ON CONFLICT(upstream_id, external_id) DO UPDATE SET
               name = excluded.name, spec = excluded.spec, price_cents = excluded.price_cents,
               status = \'active\', synced_at = excluded.synced_at, updated_at = datetime(\'now\')'
        );
        Db::tx(function () use ($st, $list, $upstreamId, $now): void {
            foreach ($list as $p) {
                $st->execute([
                    $upstreamId,
                    (string)($p['external_id'] ?? ''),
                    (string)($p['name'] ?? '未命名产品'),
                    (string)($p['spec'] ?? ''),
                    max(0, (int)($p['price_cents'] ?? 0)),
                    $now,
                ]);
            }
        });
        return ['count' => count($list), 'items' => self::products($upstreamId)];
    }

    public static function saveProduct(int $upstreamId, int $productId, array $in): array
    {
        $st = Db::conn()->prepare('SELECT id FROM upstream_products WHERE id = ? AND upstream_id = ?');
        $st->execute([$productId, $upstreamId]);
        if (!$st->fetchColumn()) {
            Response::fail('not_found', '上游产品不存在', 404);
        }
        $name = trim((string)($in['name'] ?? ''));
        $spec = trim((string)($in['spec'] ?? ''));
        $price = max(0, (int)($in['price_cents'] ?? 0));
        $status = ((string)($in['status'] ?? 'active')) === 'hidden' ? 'hidden' : 'active';
        Db::conn()->prepare(
            "UPDATE upstream_products SET name = ?, spec = ?, price_cents = ?, status = ?, updated_at = datetime('now') WHERE id = ?"
        )->execute([$name, $spec, $price, $status, $productId]);
        return ['ok' => true];
    }

    /* ==================== 开通 / 实例管理 ==================== */

    /**
     * 开通实例（下单后调用）。返回交付信息（含一次性密码，写入发货内容，平台不长期持有）。
     * @return array{ok:bool,detail:string,instance:array}
     */
    public static function provision(int $upstreamId, string $productId, array $opts = []): array
    {
        $cfg = self::connectionConfig($upstreamId);
        if ($cfg === null) {
            return ['ok' => false, 'detail' => '上游不存在或已停用', 'instance' => []];
        }
        if ($cfg['mock']) {
            return self::mockProvision($cfg, $productId, $opts);
        }
        return self::httpProvision($cfg, $productId, $opts);
    }

    /** 实例操作：start/stop/restart/reset-password/reinstall/delete */
    public static function instanceAction(int $upstreamId, string $externalId, string $action, array $opts = []): array
    {
        $allowed = ['start', 'stop', 'restart', 'reset-password', 'reinstall', 'delete'];
        if (!in_array($action, $allowed, true)) {
            Response::fail('invalid_action', '操作不被支持', 400);
        }
        $cfg = self::connectionConfig($upstreamId);
        if ($cfg === null) {
            Response::fail('upstream_disabled', '上游不可用', 409);
        }
        if ($cfg['mock']) {
            self::mockLog(['action' => 'instance-action', 'upstream' => $cfg['id'], 'external_id' => $externalId, 'op' => $action]);
            return ['ok' => true, 'detail' => 'MOCK 模式：操作已记录（' . $action . '）'];
        }
        $result = self::httpInstanceAction($cfg, $externalId, $action, $opts);
        if ($result === null) {
            Response::fail('action_failed', '上游操作失败：' . self::lastHttpError(), 502);
        }
        return $result;
    }

    /** 测试连接：真实模式请求上游 health/产品列表；mock 直接成功 */
    public static function testConnection(array $in): array
    {
        // 用传入的临时配置测试（不落库）
        $base = trim((string)($in['base_url'] ?? ''));
        $https = ((int)($in['use_https'] ?? 0) === 1);
        if ($base === '') {
            Response::fail('invalid_url', '请先填写接口地址', 400);
        }
        if (self::mockEnabled()) {
            return ['ok' => true, 'detail' => 'MOCK 模式：连接测试通过（未真实联网）'];
        }
        self::assertPublicBaseUrl($base);
        $authMode = (string)($in['auth_mode'] ?? 'bearer');
        $testCfg = [
            'auth_mode' => in_array($authMode, ['bearer', 'basic', 'query', 'none'], true) ? $authMode : 'bearer',
            'api_user'  => (string)($in['api_user'] ?? ''),
            'api_key'   => (string)($in['api_key'] ?? ''),
        ];
        $path = self::normalizeApiPath((string)($in['path_products'] ?? ''), '/api/v1/products');
        $url = self::withQueryAuth(self::normalizeBase($base, $https) . $path, $testCfg);
        $header = self::authHeader($testCfg);
        $resp = self::httpGet($url, $header);
        if ($resp === null) {
            return ['ok' => false, 'detail' => self::lastHttpError()];
        }
        return ['ok' => true, 'detail' => '连接成功，上游有响应'];
    }

    /* ==================== HTTP 客户端 ==================== */

    private static string $lastError = '';

    private static function lastHttpError(): string
    {
        return self::$lastError !== '' ? self::$lastError : '未知错误';
    }

    private static function normalizeBase(string $base, bool $https): string
    {
        $base = preg_replace('#^https?://#i', '', $base) ?? $base;
        $base = rtrim($base, '/');
        return ($https ? 'https://' : 'http://') . $base;
    }

    private static function httpGet(string $url, string $authHeader): ?string
    {
        self::$lastError = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', $authHeader],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $res = curl_exec($ch);
            $err = curl_errno($ch);
            $msg = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($err !== 0) {
                self::$lastError = '网络错误：' . $msg;
                return null;
            }
            if ($code >= 400) {
                self::$lastError = '上游返回 HTTP ' . $code;
                return null;
            }
            return is_string($res) ? $res : null;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'header' => "Accept: application/json\r\n" . $authHeader . "\r\n",
            'timeout' => 10, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if (!is_string($res)) {
            self::$lastError = '无法连接上游';
            return null;
        }
        return $res;
    }

    private static function httpPost(string $url, string $body, string $authHeader): ?string
    {
        self::$lastError = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', $authHeader],
                CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $res = curl_exec($ch);
            $err = curl_errno($ch);
            $msg = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($err !== 0) {
                self::$lastError = '网络错误：' . $msg;
                return null;
            }
            if ($code >= 400) {
                self::$lastError = '上游返回 HTTP ' . $code;
                return null;
            }
            return is_string($res) ? $res : null;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n" . $authHeader . "\r\n",
            'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return is_string($res) ? $res : null;
    }

    /**
     * v1.4.1：按上游配置的鉴权方式生成请求头。
     *  - bearer：Authorization: Bearer <api_key>（默认，兼容旧行为）
     *  - basic ：Authorization: Basic base64(api_user:api_key)（魔方系常用）
     *  - query ：不使用请求头，凭据作为查询参数附加（在 url 上拼接）
     *  - none  ：不使用鉴权
     */
    private static function authHeader(array $cfg): string
    {
        $mode = (string)($cfg['auth_mode'] ?? 'bearer');
        if ($mode === 'basic') {
            return 'Authorization: Basic ' . base64_encode($cfg['api_user'] . ':' . $cfg['api_key']);
        }
        if ($mode === 'none' || $mode === 'query') {
            return 'Accept: application/json';
        }
        return 'Authorization: Bearer ' . $cfg['api_key'];
    }

    /** query 鉴权：把 api_user/api_key 拼到 URL 查询参数 */
    private static function withQueryAuth(string $url, array $cfg): string
    {
        if ((string)($cfg['auth_mode'] ?? '') !== 'query') {
            return $url;
        }
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . 'username=' . rawurlencode((string)$cfg['api_user'])
             . '&token=' . rawurlencode((string)$cfg['api_key']);
    }

    private static function httpFetchProducts(array $cfg): ?array
    {
        $path = (string)($cfg['path_products'] ?? '/api/v1/products');
        $url = self::withQueryAuth(
            self::normalizeBase($cfg['base_url'], $cfg['use_https']) . $path,
            $cfg
        );
        $resp = self::httpGet($url, self::authHeader($cfg));
        if ($resp === null) {
            return null;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            self::$lastError = '上游返回内容不是合法 JSON';
            return null;
        }
        // 兼容 {data:[...]} / {products:[...]} / 直接数组
        $list = $data['data'] ?? $data['products'] ?? (array_is_list($data) ? $data : []);
        if (!is_array($list)) {
            $list = [];
        }
        $out = [];
        $priceIsCents = (int)($cfg['price_is_cents'] ?? 0) === 1;
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }
            // v1.4.1：价格单位可配置。默认上游价格为元 → ×100 转分；price_is_cents=1 时直接用分。
            $rawPrice = (float)($p['price'] ?? $p['price_cents'] ?? 0);
            $priceCents = $priceIsCents ? (int)round($rawPrice) : (int)round($rawPrice * 100);
            $out[] = [
                'external_id' => (string)($p['id'] ?? $p['product_id'] ?? ''),
                'name'        => (string)($p['name'] ?? ''),
                'spec'        => (string)($p['spec'] ?? $p['description'] ?? ''),
                'price_cents' => $priceCents,
            ];
        }
        return $out;
    }

    private static function httpProvision(array $cfg, string $productId, array $opts): array
    {
        // v1.4.1：路径可配置，未在配置里填则用 kind 对应的默认路径
        $defaultPath = $cfg['kind'] === 'cloud'
            ? '/api/v1/vm/create' : '/api/v1/orders';
        $pathKey = $cfg['kind'] === 'cloud' ? 'path_provision_cloud' : 'path_provision_finance';
        $path = (string)($cfg[$pathKey] ?? '') ?: $defaultPath;
        $url = self::withQueryAuth(
            self::normalizeBase($cfg['base_url'], $cfg['use_https']) . $path,
            $cfg
        );
        $payload = [
            'product_id' => $productId,
            'account'    => $cfg['api_user'],
            'opts'       => $opts,
        ];
        // v1.4.1：financial_id 仅在非空时附加一次（修复旧版重复赋值）
        if ($cfg['financial_id'] !== '') {
            $payload['financial_id'] = $cfg['financial_id'];
        }
        $resp = self::httpPost($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', self::authHeader($cfg));
        if ($resp === null) {
            return ['ok' => false, 'detail' => '开通失败：' . self::lastHttpError(), 'instance' => []];
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return ['ok' => false, 'detail' => '上游返回内容不是合法 JSON', 'instance' => []];
        }
        if (($data['ok'] ?? true) === false || (isset($data['error']) && $data['error'])) {
            return ['ok' => false, 'detail' => '上游拒绝开通：' . (string)($data['message'] ?? $data['error'] ?? '未知原因'), 'instance' => []];
        }
        $d = $data['data'] ?? $data;
        // v1.4.1：兼容真实魔方云/财务 API 常见字段别名
        $pick = static function (array $d, array $keys): string {
            foreach ($keys as $k) {
                if (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null) {
                    return (string)$d[$k];
                }
            }
            return '';
        };
        return [
            'ok' => true,
            'detail' => $pick($d, ['detail', 'message', 'msg']) ?: '开通成功',
            'instance' => [
                'external_id' => $pick($d, ['id', 'instance_id', 'vm_id', 'uuid']),
                'instance_name' => $pick($d, ['name', 'instance_name', 'hostname']),
                'ip' => $pick($d, ['ip', 'ip_address', 'ipAddr', 'ipaddr', 'host']),
                'os' => $pick($d, ['os', 'os_name', 'osName', 'system']),
                'cpu' => $pick($d, ['cpu', 'cpu_cores', 'cpuNum']),
                'mem' => $pick($d, ['mem', 'memory', 'mem_size']),
                'disk' => $pick($d, ['disk', 'disk_size', 'diskSize']),
                'bandwidth' => $pick($d, ['bandwidth', 'band']),
                'region' => $pick($d, ['region', 'area', 'node']),
                'username' => $pick($d, ['username', 'user', 'account', 'root_user']),
                'password' => $pick($d, ['password', 'passwd', 'pwd', 'root_pwd', 'root_password']),
            ],
        ];
    }

    private static function httpInstanceAction(array $cfg, string $externalId, string $action, array $opts): ?array
    {
        // v1.4.1：操作路径可配置，支持 {id} {action} 占位符
        $tpl = (string)($cfg['path_action'] ?? '') ?: '/api/v1/vm/{id}/{action}';
        $path = str_replace(['{id}', '{action}'], [rawurlencode($externalId), rawurlencode($action)], $tpl);
        $url = self::withQueryAuth(
            self::normalizeBase($cfg['base_url'], $cfg['use_https']) . $path,
            $cfg
        );
        $resp = self::httpPost($url, json_encode($opts, JSON_UNESCAPED_UNICODE) ?: '{}', self::authHeader($cfg));
        if ($resp === null) {
            return null;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return ['ok' => false, 'detail' => '上游返回内容不是合法 JSON'];
        }
        return [
            'ok' => ($data['ok'] ?? true) !== false,
            'detail' => (string)($data['message'] ?? $data['detail'] ?? '操作成功'),
        ];
    }

    /* ==================== mock 模式 ==================== */

    public static function mockEnabled(): bool
    {
        try {
            $s = SettingsService::all();
            return ($s['upstream_mock'] ?? '0') === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function mockLog(array $entry): void
    {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $entry['ts'] = date('c');
        @file_put_contents($dir . '/upstream_mock_queue.jsonl', json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    private static function mockProducts(array $cfg): array
    {
        self::mockLog(['action' => 'sync-products', 'upstream' => $cfg['id']]);
        if ($cfg['kind'] === 'cloud') {
            return [
                ['external_id' => 'vm-plan-1', 'name' => '云主机 · 1核1G', 'spec' => '1核 / 1G 内存 / 20G 硬盘 / 5M 带宽', 'price_cents' => 3000],
                ['external_id' => 'vm-plan-2', 'name' => '云主机 · 2核4G', 'spec' => '2核 / 4G 内存 / 60G 硬盘 / 10M 带宽', 'price_cents' => 9900],
                ['external_id' => 'vm-plan-4', 'name' => '云主机 · 4核8G', 'spec' => '4核 / 8G 内存 / 100G 硬盘 / 20M 带宽', 'price_cents' => 19900],
            ];
        }
        return [
            ['external_id' => 'up-p-1001', 'name' => '上游产品 · 虚拟料 A', 'spec' => '演示用上游产品', 'price_cents' => 1000],
            ['external_id' => 'up-p-1002', 'name' => '上游产品 · 虚拟料 B', 'spec' => '演示用上游产品', 'price_cents' => 2500],
        ];
    }

    private static function mockProvision(array $cfg, string $productId, array $opts): array
    {
        self::mockLog(['action' => 'provision', 'upstream' => $cfg['id'], 'product_id' => $productId, 'opts' => $opts]);
        $suffix = strtoupper(bin2hex(random_bytes(3)));
        $pass = 'Mofang@' . $suffix;
        return [
            'ok' => true,
            'detail' => 'MOCK 模式：模拟开通成功',
            'instance' => [
                'external_id' => 'mock-' . strtolower($suffix),
                'instance_name' => 'yl-' . strtolower($suffix),
                'ip' => '203.0.113.' . random_int(2, 254),
                'os' => (string)($opts['os'] ?? 'CentOS 7.9'),
                'cpu' => (string)($opts['cpu'] ?? '2核'),
                'mem' => (string)($opts['mem'] ?? '4G'),
                'disk' => (string)($opts['disk'] ?? '60G'),
                'bandwidth' => (string)($opts['bandwidth'] ?? '10M'),
                'region' => (string)($opts['region'] ?? ''),
                'username' => $cfg['kind'] === 'cloud' ? 'root' : 'user_' . strtolower($suffix),
                'password' => $pass,
            ],
        ];
    }
}
