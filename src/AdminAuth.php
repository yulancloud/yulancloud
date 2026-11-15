<?php
/**
 * 后台鉴权
 *
 * 安全设计：
 *  - 密码一律 password_hash(PASSWORD_ARGON2ID → 退化到 bcrypt)，绝不存明文
 *  - 会话只存 token 的 SHA-256，Cookie 里是随机 token 明文；DB 泄露也无法冒充
 *  - Cookie 属性：HttpOnly + SameSite=Strict + Secure(https 时) + 独立 Path
 *  - 滑动空闲超时 + 绝对超时，双闸门
 *  - 登录失败按 IP 计数并锁定（防撞库），失败信息不区分「用户不存在」与「密码错」
 *  - 所有写操作（POST/PUT/DELETE）强制校验 Origin/Referer 同源 → 防 CSRF
 *  - 修改密码、改角色等敏感动作要求再输当前密码
 */
declare(strict_types=1);

final class AdminAuth
{
    private static ?array $current = null;

    public static function cfg(): array
    {
        return (require dirname(__DIR__) . '/config/config.php')['admin'];
    }

    /** 生成密码哈希（优先 Argon2id，不可用则 bcrypt） */
    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /** 密码强度：至少 10 位，且不能全数字/全字母 */
    public static function passwordProblem(string $pw): ?string
    {
        if (mb_strlen($pw, 'UTF-8') < 10) {
            return '密码至少 10 个字符';
        }
        if (mb_strlen($pw, 'UTF-8') > 200) {
            return '密码过长';
        }
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return '密码需同时包含字母和数字';
        }
        return null;
    }

    // ---------------------------------------------------------------
    // 登录 / 登出
    // ---------------------------------------------------------------

    /** 登录；返回 ['ok'=>bool,'message'=>string] */
    public static function login(string $username, string $password): array
    {
        $cfg = self::cfg();
        $ip  = RateLimiter::clientIp();

        // IP 锁定检查
        if (!RateLimiter::hit('admin_login', $ip, $cfg['login_limit']['window'], $cfg['login_limit']['max'])) {
            return ['ok' => false, 'message' => '登录尝试过于频繁，请 15 分钟后再试'];
        }

        $stmt = Db::conn()->prepare('SELECT * FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $u = $stmt->fetch();

        // 用户不存在时也跑一次哈希校验，避免用响应时间探测账号是否存在
        $hash = $u['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok = password_verify($password, $hash);

        if (!$u || !$ok) {
            Audit::log(null, $username, 'login_failed', '', '密码或账号错误');
            return ['ok' => false, 'message' => '账号或密码错误'];
        }
        if ((string)$u['status'] !== 'active') {
            Audit::log((int)$u['id'], $username, 'login_blocked', '', '账号已停用');
            return ['ok' => false, 'message' => '账号已停用'];
        }

        // 旧哈希算法升级（例如从 bcrypt 升到 argon2id）
        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Db::conn()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                ->execute([self::hash($password), (int)$u['id']]);
        }

        self::issueSession((int)$u['id']);

        Db::conn()->prepare(
            "UPDATE admin_users SET last_login_at = datetime('now'), last_login_ip_hash = ? WHERE id = ?"
        )->execute([RateLimiter::ipHash(), (int)$u['id']]);

        Audit::log((int)$u['id'], $u['username'], 'login', '', '');
        return [
            'ok' => true,
            'message' => 'ok',
            'user' => [
                'id'           => (int)$u['id'],
                'username'     => (string)$u['username'],
                'display_name' => (string)$u['display_name'],
                'role'         => (string)$u['role'],
                'must_change_password' => (int)$u['must_change_password'] === 1,
            ],
        ];
    }

    /** 下发会话 Cookie（token 明文只在 Cookie 里，DB 只存哈希） */
    private static function issueSession(int $userId): void
    {
        $cfg = self::cfg();
        $token = bin2hex(random_bytes(32));
        $now   = time();
        $ttl   = (int)$cfg['absolute_timeout'];

        Db::conn()->prepare(
            'INSERT INTO admin_sessions (token_hash, user_id, created_at, last_seen_at, expires_at, ip_hash, ua_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $token), $userId, $now, $now, $now + $ttl,
            RateLimiter::ipHash(), self::uaHash(),
        ]);

        // 顺手清理过期会话
        Db::conn()->prepare('DELETE FROM admin_sessions WHERE expires_at < ?')->execute([$now]);

        self::setCookie($token, $now + $ttl);

        // 会话固定攻击防护：登录成功后换新 token（上面就是全新的，无需额外 rotate）
        self::$current = null;
    }

    private static function setCookie(string $value, int $expires): void
    {
        $cfg = self::cfg();
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        setcookie($cfg['cookie_name'], $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function logout(): void
    {
        $cfg = self::cfg();
        $sid = $_COOKIE[$cfg['cookie_name']] ?? '';
        if (is_string($sid) && $sid !== '') {
            Db::conn()->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')
                ->execute([hash('sha256', $sid)]);
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie($cfg['cookie_name'], '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
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
            'SELECT s.token_hash, s.user_id, s.created_at, s.last_seen_at, s.expires_at, s.ip_hash,
                    u.username, u.display_name, u.role, u.status, u.must_change_password, u.password_changed_at
             FROM admin_sessions s JOIN admin_users u ON u.id = s.user_id
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
            Db::conn()->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }
        // 空闲超时
        if ($now - (int)$s['last_seen_at'] > (int)$cfg['idle_timeout']) {
            Db::conn()->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }
        if ((string)$s['status'] !== 'active') {
            Db::conn()->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }

        // 滑动续期（限频：每 60 秒最多写一次，减少 SQLite 写压力）
        if ($now - (int)$s['last_seen_at'] > 60) {
            Db::conn()->prepare('UPDATE admin_sessions SET last_seen_at = ? WHERE token_hash = ?')
                ->execute([$now, $hash]);
        }

        return self::$current = [
            'id'           => (int)$s['user_id'],
            'username'     => (string)$s['username'],
            'display_name' => (string)$s['display_name'],
            'role'         => (string)$s['role'],
            'must_change_password' => (int)$s['must_change_password'] === 1,
            'session_hash' => $hash,
        ];
    }

    /** 要求已登录；未登录直接 401（API 用） */
    public static function require(): array
    {
        $u = self::user();
        if ($u === null) {
            Response::fail('unauthorized', '请先登录后台', 401);
        }
        self::guardCsrf();
        return $u;
    }

    /** 要求 owner 角色（改管理员、看审计等） */
    public static function requireOwner(): array
    {
        $u = self::require();
        if ($u['role'] !== 'owner') {
            Response::fail('forbidden', '该操作仅限店主账号', 403);
        }
        return $u;
    }

    /**
     * CSRF 防护：写操作必须来自同源页面。
     * 同时依赖 SameSite=Strict Cookie 作为第一层，这一层用于兜底老浏览器。
     */
    public static function guardCsrf(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $selfHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($selfHost === '') {
            return;
        }

        $source = '';
        if ($origin !== '') {
            $source = $origin;
        } elseif ($referer !== '') {
            $source = $referer;
        }
        if ($source === '') {
            // 没有 Origin/Referer 的写请求（curl/脚本）一律拒绝，防止被跨站表单提交
            Response::fail('csrf_failed', '请求来源不合法', 403);
        }
        $p = parse_url($source);
        if (!is_array($p)) {
            Response::fail('csrf_failed', '请求来源不合法', 403);
        }

        // 比较 host + port：HTTP_HOST 可能带端口（127.0.0.1:8091），
        // 而 parse_url 的 host 不含端口，必须分开比，否则同源也会被误拒。
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

        // https 站点不接受 http 来源
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttps && strtolower((string)($p['scheme'] ?? '')) !== 'https') {
            Response::fail('csrf_failed', '请求来源不合法', 403);
        }
    }

    // ---------------------------------------------------------------
    // 管理员账号维护
    // ---------------------------------------------------------------

    public static function changePassword(int $userId, string $oldPw, string $newPw): array
    {
        $stmt = Db::conn()->prepare('SELECT id, username, password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            return ['ok' => false, 'message' => '账号不存在'];
        }
        if (!password_verify($oldPw, $u['password_hash'])) {
            Audit::log($userId, $u['username'], 'password_change_failed', '', '当前密码错误');
            return ['ok' => false, 'message' => '当前密码不正确'];
        }
        $problem = self::passwordProblem($newPw);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }
        if (password_verify($newPw, $u['password_hash'])) {
            return ['ok' => false, 'message' => '新密码不能与当前密码相同'];
        }

        $now = time();
        Db::tx(function (PDO $pdo) use ($userId, $newPw, $now): void {
            $pdo->prepare(
                "UPDATE admin_users SET password_hash = ?, must_change_password = 0,
                     password_changed_at = datetime('now') WHERE id = ?"
            )->execute([self::hash($newPw), $userId]);
            // 改密后注销该账号所有其他会话（当前会话保留）
            $cur = self::$current['session_hash'] ?? '';
            $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = ? AND token_hash <> ?')
                ->execute([$userId, $cur]);
        });

        Audit::log($userId, $u['username'], 'password_changed', '', '');
        return ['ok' => true, 'message' => '密码已更新'];
    }

    /** 首次安装时创建初始管理员（幂等） */
    public static function ensureInitialAdmin(string $username, string $password, bool $forceRandom = false): ?string
    {
        $pdo = Db::conn();
        $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        if ($count > 0 && !$forceRandom) {
            return null;
        }

        $generated = null;
        if ($password === '') {
            // 没配密码就随机生成，打印一次让站长抄走，绝不使用弱默认值如 admin/123456
            $password = bin2hex(random_bytes(9)) . 'Aa1';
            $generated = $password;
        }
        $problem = self::passwordProblem($password);
        if ($problem !== null) {
            throw new RuntimeException('初始管理员密码不符合要求：' . $problem);
        }

        $pdo->prepare(
            "INSERT INTO admin_users (username, password_hash, display_name, role, status, must_change_password)
             VALUES (?, ?, '店主', 'owner', 'active', ?)
             ON CONFLICT(username) DO NOTHING"
        )->execute([$username, self::hash($password), $generated !== null ? 1 : 0]);

        return $generated;
    }

    private static function uaHash(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', mb_substr($ua, 0, 300, 'UTF-8'));
    }
}
