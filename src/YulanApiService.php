<?php
/**
 * 财务魔方 API（v1.3）—— 多条 Windows 服务器连接配置，可自定义名称
 *
 * 背景：早期只有一个「全局默认」服务器连接，所有欲蓝商品共用。
 * v1.3 参考智简魔方的「服务器分组 / API 授权」模式，允许后台创建多条
 * 命名 API（如「电信机房-A」「联通机房-B」），每个虚拟/欲蓝商品在发货配置里
 * 指定使用哪一条。下单时由 YulanProvision 按商品绑定的 API 取连接参数。
 *
 * 安全基线（与 YulanConnector / SettingsService 一致）：
 *  - 口令 server_pass「只写不读」：list()/get() 绝不回传明文，只给 server_pass_set。
 *  - 留空 = 不修改；'__CLEAR__' = 清空。
 *  - 名称/地址/端口都做白名单校验，杜绝存储型 XSS 与脏数据。
 */
declare(strict_types=1);

final class YulanApiService
{
    /** 对外输出（口令脱敏） */
    private static function publicRow(array $r): array
    {
        return [
            'id'          => (int)$r['id'],
            'name'        => (string)$r['name'],
            'server_ip'   => (string)$r['server_ip'],
            'server_port' => (string)$r['server_port'],
            'server_user' => (string)$r['server_user'],
            'rdp_host'    => (string)$r['rdp_host'],
            'enabled'     => (int)$r['enabled'],
            'server_pass_set' => ((string)$r['server_pass']) !== '',
            'created_at'  => (string)($r['created_at'] ?? ''),
            'updated_at'  => (string)($r['updated_at'] ?? ''),
        ];
    }

    /** 列表（按 id 升序） */
    public static function list(): array
    {
        try {
            $rows = Db::conn()->query('SELECT * FROM yulan_apis ORDER BY id ASC')->fetchAll();
        } catch (Throwable $e) {
            return [];   // 未迁移/表缺失时静默返回空，不让后台白屏
        }
        return array_map([self::class, 'publicRow'], $rows);
    }

    /** 单条（脱敏） */
    public static function get(int $id): array
    {
        $stmt = Db::conn()->prepare('SELECT * FROM yulan_apis WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            Response::fail('not_found', 'API 配置不存在', 404);
        }
        return self::publicRow($r);
    }

    /**
     * 取连接参数（内部用，含明文口令）—— 供 YulanConnector 发货时调用。
     * @return array{ip:string,port:int,user:string,pass:string,rdp_host:string}|null
     */
    public static function connectionConfig(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = Db::conn()->prepare('SELECT * FROM yulan_apis WHERE id = ? AND enabled = 1');
            $stmt->execute([$id]);
            $r = $stmt->fetch();
        } catch (Throwable $e) {
            return null;
        }
        if (!$r) {
            return null;
        }
        return [
            'ip'        => trim((string)$r['server_ip']),
            'port'      => (int)($r['server_port'] ?: 8321),
            'user'      => (string)$r['server_user'],
            'pass'      => (string)$r['server_pass'],
            'rdp_host'  => (string)$r['rdp_host'],
        ];
    }

    /** 新增 */
    public static function create(array $in): array
    {
        $v = self::validate($in, false);
        $pdo = Db::conn();
        $pdo->prepare(
            'INSERT INTO yulan_apis (name, server_ip, server_port, server_user, server_pass, rdp_host, enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
        )->execute([
            $v['name'], $v['server_ip'], $v['server_port'], $v['server_user'],
            $v['server_pass'], $v['rdp_host'], $v['enabled'],
        ]);
        return self::get((int)$pdo->lastInsertId());
    }

    /** 更新 */
    public static function update(int $id, array $in): array
    {
        self::get($id);   // 存在性校验
        $v = self::validate($in, true);
        $pdo = Db::conn();
        $sets = ['name = ?', 'server_ip = ?', 'server_port = ?', 'server_user = ?',
                 'rdp_host = ?', 'enabled = ?', "updated_at = datetime('now')"];
        $args = [$v['name'], $v['server_ip'], $v['server_port'], $v['server_user'],
                 $v['rdp_host'], $v['enabled']];
        // 口令只写不读：本轮请求带了才更新
        if ($v['pass_set']) {
            $sets[] = 'server_pass = ?';
            $args[] = $v['server_pass'];
        }
        $args[] = $id;
        $pdo->prepare('UPDATE yulan_apis SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        return self::get($id);
    }

    /** 删除；若仍被商品引用则拒绝（除非 force） */
    public static function delete(int $id, bool $force = false): array
    {
        self::get($id);
        $pdo = Db::conn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE yulan_api_id = ?');
        $stmt->execute([$id]);
        $used = (int)$stmt->fetchColumn();
        if ($used > 0 && !$force) {
            Response::fail('api_in_use', "该 API 仍被 {$used} 个商品使用，请先在商品发货配置里改绑或清空后再删除", 409);
        }
        // 解绑引用，避免留下指向不存在 ID 的悬空配置
        $pdo->prepare('UPDATE products SET yulan_api_id = 0 WHERE yulan_api_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM yulan_apis WHERE id = ?')->execute([$id]);
        return ['ok' => true, 'unbound' => $used];
    }

    /** 校验 + 归一化输入 */
    private static function validate(array $in, bool $isUpdate): array
    {
        $name = isset($in['name']) ? trim((string)$in['name']) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 40) {
            Response::fail('invalid_name', 'API 名称需为 1-40 个字符', 400);
        }
        $ip = isset($in['server_ip']) ? trim((string)$in['server_ip']) : '';
        if ($ip === '' || mb_strlen($ip, 'UTF-8') > 64) {
            Response::fail('invalid_ip', '服务器 IP / 域名需为 1-64 个字符', 400);
        }
        $port = isset($in['server_port']) ? trim((string)($in['server_port'])) : '8321';
        if ($port === '') {
            $port = '8321';
        }
        if (!preg_match('/^\d{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
            Response::fail('invalid_port', '端口需为 1-65535 的数字', 400);
        }
        $user = isset($in['server_user']) ? trim((string)$in['server_user']) : '';
        if (mb_strlen($user, 'UTF-8') > 64) {
            Response::fail('invalid_user', '连接账号不能超过 64 个字符', 400);
        }
        $rdp = isset($in['rdp_host']) ? trim((string)$in['rdp_host']) : '';
        if (mb_strlen($rdp, 'UTF-8') > 128) {
            Response::fail('invalid_rdp', '连接地址不能超过 128 个字符', 400);
        }
        $enabled = array_key_exists('enabled', $in) ? ((int)($in['enabled']) === 1 ? 1 : 0) : 1;

        // 口令：新增必须有；更新时留空=不改，__CLEAR__=清空
        $passSet = false;
        $pass = '';
        if (array_key_exists('server_pass', $in)) {
            $raw = (string)$in['server_pass'];
            if ($raw === '__CLEAR__') {
                $passSet = true;
                $pass = '';
            } elseif ($raw !== '') {
                if (mb_strlen($raw, 'UTF-8') < 6) {
                    Response::fail('invalid_pass', '连接口令至少 6 位', 400);
                }
                $passSet = true;
                $pass = $raw;
            }
        }
        if (!$isUpdate && !$passSet) {
            Response::fail('invalid_pass', '新增 API 必须填写连接口令（至少 6 位）', 400);
        }

        return [
            'name' => $name, 'server_ip' => $ip, 'server_port' => $port,
            'server_user' => $user, 'rdp_host' => $rdp, 'enabled' => $enabled,
            'pass_set' => $passSet, 'server_pass' => $pass,
        ];
    }
}
