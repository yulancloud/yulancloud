<?php
/**
 * 前台用户鉴权（/console 用户中心）
 *
 * 与后台 AdminAuth 完全独立：独立表（users / user_sessions）、独立 Cookie、
 * 独立超时配置。两套登录互不影响，管理员账号不能登录前台，反之亦然。
 *
 * 安全设计：
 *  - 密码一律 password_hash(PASSWORD_ARGON2ID → 退化 bcrypt)，绝不存明文
 *  - 会话只存 token 的 SHA-256，Cookie 里是随机 token 明文；DB 泄露也无法冒充
 *  - Cookie 属性：HttpOnly + SameSite=Lax + Secure(https 时) + Path=/
 *    （前台需要从支付网关跳回，SameSite=Strict 会导致回跳丢 Cookie，故用 Lax）
 *  - 滑动空闲超时 + 绝对超时，双闸门
 *  - 登录失败按 IP 与账号双维度计数并锁定（防撞库、防定向爆破）
 *  - 所有写操作（POST/PUT/DELETE）强制校验 Origin/Referer 同源 → 防 CSRF
 *  - 失败信息不区分「用户不存在」与「密码错误」，且用户不存在时也跑一次哈希校验
 */
declare(strict_types=1);

final class UserAuth
{
    private static ?array $current = null;

    public static function cfg(): array
    {
        return (require dirname(__DIR__) . '/config/config.php')['user'];
    }

    /** 生成密码哈希（优先 Argon2id，不可用则 bcrypt） */
    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /** 密码强度：至少 8 位，且不能全数字/全字母 */
    public static function passwordProblem(string $pw): ?string
    {
        $len = mb_strlen($pw, 'UTF-8');
        if ($len < 8) {
            return '密码至少 8 个字符';
        }
        if ($len > 200) {
            return '密码过长';
        }
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return '密码需同时包含字母和数字';
        }
        return null;
    }

    /** 用户名规则：3-32 位，字母数字下划线点短横 */
    public static function usernameProblem(string $u): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $u)) {
            return '用户名只能使用字母、数字、下划线、点、短横，长度 3-32';
        }
        return null;
    }

    // ---------------------------------------------------------------
    // 注册 / 登录 / 登出
    // ---------------------------------------------------------------

    /**
     * 注册
     * @return array{ok:bool, message:string, user?:array}
     */
    public static function register(string $username, string $password, string $displayName = '', string $email = ''): array
    {
        $cfg = self::cfg();
        $ip  = RateLimiter::clientIp();

        // 注册限流：同一 IP 10 分钟最多 5 次
        if (!RateLimiter::hit('user_register', $ip, $cfg['register_limit']['window'], $cfg['register_limit']['max'])) {
            return ['ok' => false, 'message' => '注册过于频繁，请稍后再试'];
        }

        $problem = self::usernameProblem($username);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }
        $problem = self::passwordProblem($password);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = $username;
        }
        if (mb_strlen($displayName, 'UTF-8') > 40) {
            return ['ok' => false, 'message' => '昵称不能超过 40 个字符'];
        }
        if (preg_match('/[<>{}\\\\]/', $displayName)) {
            return ['ok' => false, 'message' => '昵称包含不允许的字符'];
        }

        $email = trim($email);
        if ($email !== '') {
            if (mb_strlen($email, 'UTF-8') > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'message' => '邮箱格式不正确'];
            }
        }

        $pdo = Db::conn();

        // 用户名唯一性（大小写不敏感：统一按小写比对，避免 Admin / admin 混淆）
        $dup = $pdo->prepare('SELECT id FROM users WHERE lower(username) = lower(?) LIMIT 1');
        $dup->execute([$username]);
        if ($dup->fetchColumn() !== false) {
            return ['ok' => false, 'message' => '该用户名已被注册'];
        }

        try {
            $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, display_name, balance_cents, status)
                 VALUES (?, ?, ?, ?, 0, 'active')"
            )->execute([$username, $email, self::hash($password), $displayName]);
        } catch (PDOException $e) {
            // 唯一索引兜底（并发注册同名）
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['ok' => false, 'message' => '该用户名已被注册'];
            }
            throw $e;
        }

        $userId = (int)$pdo->lastInsertId();
        self::issueSession($userId);

        return [
            'ok' => true,
            'message' => '注册成功',
            'user' => self::publicUser($userId),
        ];
    }

    /**
     * 登录
     * @return array{ok:bool, message:string, user?:array}
     */
    public static function login(string $username, string $password): array
    {
        $cfg = self::cfg();
        $ip  = RateLimiter::clientIp();

        // IP 维度锁定
        if (!RateLimiter::hit('user_login_ip', $ip, $cfg['login_limit']['window'], $cfg['login_limit']['max'])) {
            return ['ok' => false, 'message' => '登录尝试过于频繁，请 15 分钟后再试'];
        }
        // 账号维度锁定（防定向爆破单个账号）
        if (!RateLimiter::hit('user_login_acct', mb_strtolower($username, 'UTF-8'), $cfg['login_limit']['window'], $cfg['login_limit']['max'])) {
            return ['ok' => false, 'message' => '该账号登录尝试过于频繁，请稍后再试'];
        }

        $stmt = Db::conn()->prepare('SELECT * FROM users WHERE lower(username) = lower(?) LIMIT 1');
        $stmt->execute([$username]);
        $u = $stmt->fetch();

        // 用户不存在时也跑一次哈希校验，避免用响应时间探测账号是否存在
        $hash = $u['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok = password_verify($password, $hash);

        if (!$u || !$ok) {
            return ['ok' => false, 'message' => '用户名或密码错误'];
        }
        if ((string)$u['status'] !== 'active') {
            return ['ok' => false, 'message' => '该账号已被停用，请联系客服'];
        }

        // 旧哈希算法升级（例如从 bcrypt 升到 argon2id）
        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Db::conn()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([self::hash($password), (int)$u['id']]);
        }

        self::issueSession((int)$u['id']);

        Db::conn()->prepare(
            "UPDATE users SET last_login_at = datetime('now'), last_login_ip_hash = ? WHERE id = ?"
        )->execute([RateLimiter::ipHash(), (int)$u['id']]);

        return [
            'ok' => true,
            'message' => '登录成功',
            'user' => self::publicUser((int)$u['id']),
        ];
    }

    /** 下发会话 Cookie（token 明文只在 Cookie 里，DB 只存哈希） */
    private static function issueSession(int $userId): void
    {
        $cfg   = self::cfg();
        $token = bin2hex(random_bytes(32));
        $now   = time();
        $ttl   = (int)$cfg['absolute_timeout'];

        Db::conn()->prepare(
            'INSERT INTO user_sessions (token_hash, user_id, created_at, last_seen_at, expires_at, ip_hash, ua_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $token), $userId, $now, $now, $now + $ttl,
            RateLimiter::ipHash(), self::uaHash(),
        ]);

        // 顺手清理过期会话
        Db::conn()->prepare('DELETE FROM user_sessions WHERE expires_at < ?')->execute([$now]);

        self::setCookie($token, $now + $ttl);
        self::$current = null;
    }

    private static function setCookie(string $value, int $expires): void
    {
        $cfg   = self::cfg();
        $https = self::isHttps();

        setcookie($cfg['cookie_name'], $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function logout(): void
    {
        $cfg = self::cfg();
        $sid = $_COOKIE[$cfg['cookie_name']] ?? '';
        if (is_string($sid) && $sid !== '') {
            Db::conn()->prepare('DELETE FROM user_sessions WHERE token_hash = ?')
                ->execute([hash('sha256', $sid)]);
        }
        setcookie($cfg['cookie_name'], '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        self::$current = null;
    }

    // ---------------------------------------------------------------
    // 会话校验
    // ---------------------------------------------------------------

    /** 当前登录用户（未登录返回 null） */
    public static function user(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $cfg = self::cfg();
        $sid = $_COOKIE[$cfg['cookie_name']] ?? '';
        if (!is_string($sid) || !preg_match('/^[a-f0-9]{64}$/', $sid)) {
            return null;
        }

        $hash = hash('sha256', $sid);
        $stmt = Db::conn()->prepare(
            'SELECT s.token_hash, s.user_id, s.created_at, s.last_seen_at, s.expires_at,
                    u.username, u.display_name, u.status, u.balance_cents, u.must_change_password
             FROM user_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => $hash]);
        $s = $stmt->fetch();
        if (!$s) {
            return null;
        }

        $now = time();
        // 绝对超时
        if ($now > (int)$s['expires_at']) {
            Db::conn()->prepare('DELETE FROM user_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }
        // 空闲超时
        if ($now - (int)$s['last_seen_at'] > (int)$cfg['idle_timeout']) {
            Db::conn()->prepare('DELETE FROM user_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }
        if ((string)$s['status'] !== 'active') {
            Db::conn()->prepare('DELETE FROM user_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }

        // 滑动续期（限频：每 60 秒最多写一次，减少 SQLite 写压力）
        if ($now - (int)$s['last_seen_at'] > 60) {
            Db::conn()->prepare('UPDATE user_sessions SET last_seen_at = ? WHERE token_hash = ?')
                ->execute([$now, $hash]);
        }

        return self::$current = [
            'id'           => (int)$s['user_id'],
            'username'     => (string)$s['username'],
            'display_name' => (string)$s['display_name'],
            'balance_cents' => (int)$s['balance_cents'],
            'must_change_password' => (int)$s['must_change_password'] === 1,
            'session_hash' => $hash,
        ];
    }

    /** 要求已登录；未登录直接 401（API 用） */
    public static function require(): array
    {
        $u = self::user();
        if ($u === null) {
            Response::fail('unauthorized', '请先登录', 401);
        }
        self::guardCsrf();
        return $u;
    }

    /**
     * CSRF 防护：写操作必须来自同源页面。
     * 第一层依赖 SameSite=Lax Cookie，这一层用于兜底老浏览器与跨站表单提交。
     */
    public static function guardCsrf(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $origin  = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $selfHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($selfHost === '') {
            return;
        }

        $source = $origin !== '' ? $origin : $referer;
        if ($source === '') {
            Response::fail('csrf_failed', '请求来源不合法', 403);
        }
        $p = parse_url($source);
        if (!is_array($p)) {
            Response::fail('csrf_failed', '请求来源不合法', 403);
        }

        $srcHost = strtolower((string)($p['host'] ?? ''));
        $srcPort = isset($p['port']) ? (int)$p['port'] : null;

        // 允许的来源 host 候选：直接部署看 HTTP_HOST；
        // 走反向代理（HTTPS 网关 / 发布代理）时，代理会把真实外部域名放进
        // X-Forwarded-Host，而 HTTP_HOST 已被改写成内部地址（如 127.0.0.1:3000）。
        // 两者都纳入比对，只要来源 host 命中任意一个即视为同源，
        // 否则代理部署下所有写请求都会被误判为跨站（"请求来源不合法"）。
        $selfHosts = array($selfHost);
        $fwdHost = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        if ($fwdHost !== '') {
            foreach (explode(',', $fwdHost) as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $selfHosts[] = $part;
                }
            }
        }
        // 运维显式声明的可信来源（部署时通过 SHOP_TRUSTED_ORIGINS 注入，如外网发布域名）。
        // 当反向代理把 Host 改写成内部地址、且不透传外部域名时（本发布代理即如此：
        // HTTP_HOST 与 X-Forwarded-Host 都是内部沙箱主机名），仅凭 Host 无法判断同源，
        // 此时以声明过的可信来源为准，避免发布环境下所有写请求被误判为跨站（"请求来源不合法"）。
        // 正常自托管不配置该变量时，下面的逻辑与旧版完全一致。
        $trusted = (string)(getenv('SHOP_TRUSTED_ORIGINS') ?: '');
        if ($trusted !== '') {
            foreach (explode(',', $trusted) as $o) {
                $o = trim($o);
                if ($o === '') {
                    continue;
                }
                $pu = parse_url($o);
                if (is_array($pu) && isset($pu['host']) && $pu['host'] !== '') {
                    $selfHosts[] = isset($pu['port']) ? strtolower($pu['host']) . ':' . (int)$pu['port'] : strtolower($pu['host']);
                }
            }
        }

        $matched = false;
        foreach ($selfHosts as $sh) {
            $selfHostOnly = strtolower($sh);
            $selfPort = null;
            if (preg_match('/^(.+):(\d{1,5})$/', $selfHostOnly, $hm)) {
                $selfHostOnly = $hm[1];
                $selfPort = (int)$hm[2];
            }
            if ($srcHost !== $selfHostOnly) {
                continue;
            }
            // 端口：来源显式带端口时必须一致；不带端口则按协议默认端口推断
            if ($srcPort !== null) {
                if ($selfPort !== null && $srcPort !== $selfPort) {
                    continue;
                }
            } elseif ($selfPort !== null) {
                $scheme = strtolower((string)($p['scheme'] ?? 'http'));
                $defaultPort = $scheme === 'https' ? 443 : 80;
                if ($selfPort !== $defaultPort) {
                    continue;
                }
            }
            $matched = true;
            break;
        }
        if (!$matched) {
            Response::fail('csrf_failed', '请求来源不合法', 403);
        }
        if (self::isHttps() && strtolower((string)($p['scheme'] ?? '')) !== 'https') {
            Response::fail('csrf_failed', '请求来源不合法', 403);
        }
    }

    // ---------------------------------------------------------------
    // 账号维护
    // ---------------------------------------------------------------

    /** 修改密码（要求当前密码；改完注销该账号其他会话） */
    public static function changePassword(int $userId, string $oldPw, string $newPw): array
    {
        $stmt = Db::conn()->prepare('SELECT id, username, password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            return ['ok' => false, 'message' => '账号不存在'];
        }
        if (!password_verify($oldPw, $u['password_hash'])) {
            return ['ok' => false, 'message' => '当前密码不正确'];
        }
        $problem = self::passwordProblem($newPw);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }
        if (password_verify($newPw, $u['password_hash'])) {
            return ['ok' => false, 'message' => '新密码不能与当前密码相同'];
        }

        $cur = self::$current['session_hash'] ?? '';
        Db::tx(function (PDO $pdo) use ($userId, $newPw, $cur): void {
            $pdo->prepare(
                "UPDATE users SET password_hash = ?, must_change_password = 0,
                     password_changed_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
            )->execute([self::hash($newPw), $userId]);
            $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ? AND token_hash <> ?')
                ->execute([$userId, $cur]);
        });

        return ['ok' => true, 'message' => '密码已更新'];
    }

    /** 对外暴露的用户信息（绝不含密码哈希等敏感字段） */
    public static function publicUser(int $userId): ?array
    {
        $stmt = Db::conn()->prepare(
            'SELECT id, username, email, display_name, balance_cents, status, created_at, last_login_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            return null;
        }
        return [
            'id'            => (int)$u['id'],
            'username'      => (string)$u['username'],
            'email'         => (string)$u['email'],
            'display_name'  => (string)$u['display_name'],
            'balance_cents' => (int)$u['balance_cents'],
            'status'        => (string)$u['status'],
            'created_at'    => (string)$u['created_at'],
            'last_login_at' => $u['last_login_at'],
        ];
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function uaHash(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', mb_substr($ua, 0, 300, 'UTF-8'));
    }
}
