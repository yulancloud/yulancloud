<?php
/**
 * v1.2 扩展管理 —— 后台上传 / 安装 / 启用停用 / 卸载「主题」与「插件」
 *
 * 设计目标（对应 v2 教程「更简单的实现方式」）：
 *   1. 零侵入：不改任何核心文件、不建表、不跑迁移、不用 bump ?v= 版本号。
 *      - 主题：只放一份变量覆盖 CSS，由 /api/theme.css 动态输出，全站页面多引一行 link 即可。
 *      - 插件：只把静态资源放进 public/plugins/<id>/，访问 /plugins/<id>/ 就能看到。
 *   2. 零依赖：不依赖 ZipArchive / unzip 命令，纯 PHP 读 ZIP（只需 zlib 的 gzinflate）。
 *      线上虚拟主机装不了扩展也能用。
 *   3. 可回滚：原件存在 data/extensions/（Web 不可访问），public 下只是副本；
 *      停用 = 删副本，卸载 = 删原件 + 副本。升级覆盖 Release 不会破坏扩展。
 *
 * 安全基线（上传压缩包是高风险操作，逐条落实）：
 *   - 只允许管理员，POST/PUT/DELETE 走 AdminAuth 的 CSRF 同源校验，另有独立限流
 *   - 上传大小 / 条目数 / 单文件 / 解压总量四重上限，防 zip 炸弹
 *   - 拒绝 ZIP64、分卷、加密、数据描述符、符号链接
 *   - 拒绝 .. / 绝对路径 / 盘符 / 空字节 / 控制字符 / 反斜杠 / 隐藏文件
 *   - 出现任何服务端脚本、配置、数据库后缀 → 整包拒绝（绝不让 PHP 落进 web 目录）
 *   - 只放行白名单静态后缀；主题 CSS 另做内容过滤（禁 @import / javascript: / < ）
 *   - 全部内容先解析校验完才落盘，中途失败不留半成品
 *
 * 包格式（v1.2，v2 教程会展开讲）：
 *   <任意顶层目录>/plugin.json    {"id","name","version","type":"theme|plugin", ...}
 *   主题包另有 theme.css（或 files/theme.css）：只写 :root{} 与 :root[data-theme=dark]{} 的变量覆盖
 *   插件包另有 files/ 目录：里面的静态资源原样装到 public/plugins/<id>/
 *   兼容模板仓库的老写法 files/public/plugins/xxx/...（会自动剥掉这段前缀）
 */
declare(strict_types=1);

final class ExtensionService
{
    /** 上传 zip 上限 2 MB；解压后总量 4 MB；单文件 512 KB；条目 200 个；主题 CSS 64 KB */
    private const MAX_ZIP_BYTES   = 2097152;
    private const MAX_TOTAL_BYTES = 4194304;
    private const MAX_FILE_BYTES  = 524288;
    private const MAX_ENTRIES     = 200;
    private const MAX_CSS_BYTES   = 65536;

    /** 允许装进 public/plugins/ 的静态资源后缀（白名单，其他一律拒绝） */
    private const ALLOWED_ASSET_EXT = [
        'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg',
        'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'txt', 'md', 'xml',
    ];

    /** 包里只要出现这些后缀就整包拒绝，避免把可执行内容带进站点 */
    private const DANGEROUS_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'exe', 'dll', 'so', 'dylib',
        'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'jsp', 'asp', 'aspx', 'sql',
        'sqlite', 'sqlite3', 'db', 'db3', 'ini', 'env', 'yaml', 'yml', 'lock',
        'tar', 'gz', 'tgz', 'zip', 'rar', '7z', 'bz2', 'xz', 'jar', 'war',
        'apk', 'msi', 'scr', 'com', 'htpasswd',
    ];

    /** 功能插件可声明的 feature 标识白名单（对应核心内置的功能底座） */
    private const FEATURE_WHITELIST = [
        'smtp-login', 'login-dark-mode', 'multi-admin',
    ];

    // ---------------------------------------------------------------------
    // 路径
    // ---------------------------------------------------------------------

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function dataDir(): string
    {
        return self::root() . '/data/extensions';
    }

    private static function registryPath(): string
    {
        return self::dataDir() . '/registry.json';
    }

    private static function themeDir(string $id): string
    {
        return self::dataDir() . '/themes/' . $id;
    }

    private static function pluginDir(string $id): string
    {
        return self::dataDir() . '/plugins/' . $id;
    }

    /** 启用后对外的静态目录 */
    private static function publicPluginDir(string $id): string
    {
        return self::root() . '/public/plugins/' . $id;
    }

    // ---------------------------------------------------------------------
    // registry（原子写，避免半截 JSON）
    // ---------------------------------------------------------------------

    private static function emptyRegistry(): array
    {
        return ['version' => 1, 'active_theme' => '', 'themes' => [], 'plugins' => []];
    }

    private static function loadRegistry(): array
    {
        $p = self::registryPath();
        if (!is_file($p)) {
            return self::emptyRegistry();
        }
        $raw = @file_get_contents($p);
        if ($raw === false) {
            return self::emptyRegistry();
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !is_array($d['themes'] ?? null) || !is_array($d['plugins'] ?? null)) {
            return self::emptyRegistry();
        }
        $d['version']      = (int)($d['version'] ?? 1);
        $d['active_theme'] = (string)($d['active_theme'] ?? '');
        return $d;
    }

    private static function saveRegistry(array $r): void
    {
        self::ensureDirs();
        $p   = self::registryPath();
        $tmp = $p . '.tmp' . bin2hex(random_bytes(4));
        $json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('无法写入扩展清单，请确认 data/ 目录可写');
        }
        if (!@rename($tmp, $p)) {
            @unlink($tmp);
            throw new RuntimeException('无法保存扩展清单，请确认 data/ 目录可写');
        }
    }

    private static function ensureDirs(): void
    {
        $dirs = [
            self::dataDir(),
            self::dataDir() . '/themes',
            self::dataDir() . '/plugins',
            self::root() . '/public/plugins',
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
                throw new RuntimeException('无法创建扩展目录，请确认 data/ 与 public/ 可写');
            }
        }
    }

    // ---------------------------------------------------------------------
    // 对外：列表 / 当前主题 CSS
    // ---------------------------------------------------------------------

    /** 后台「主题与插件」页的数据 */
    public static function list(): array
    {
        $r = self::loadRegistry();
        $themes = [];
        foreach ($r['themes'] as $id => $m) {
            $themes[] = self::publicMeta('theme', (string)$id, $m) + [
                'active'      => $r['active_theme'] === (string)$id,
                'css_ready'   => is_file(self::themeDir((string)$id) . '/theme.css'),
            ];
        }
        $plugins = [];
        foreach ($r['plugins'] as $id => $m) {
            $plugins[] = self::publicMeta('plugin', (string)$id, $m) + [
                'url'         => 'plugins/' . $id . '/',
                'deployed'    => is_dir(self::publicPluginDir((string)$id)),
            ];
        }
        return [
            'active_theme' => $r['active_theme'],
            'themes'       => $themes,
            'plugins'      => $plugins,
            'limits'       => [
                'max_zip_mb'      => (int)(self::MAX_ZIP_BYTES / 1048576),
                'max_entries'     => self::MAX_ENTRIES,
                'max_file_kb'     => (int)(self::MAX_FILE_BYTES / 1024),
                'allowed_ext'     => self::ALLOWED_ASSET_EXT,
            ],
            'theme_css_url' => 'api/theme.css',
        ];
    }

    private static function publicMeta(string $type, string $id, array $m): array
    {
        return [
            'id'           => $id,
            'type'         => $type,
            'name'         => (string)($m['name'] ?? $id),
            'version'      => (string)($m['version'] ?? ''),
            'author'       => (string)($m['author'] ?? ''),
            'description'  => (string)($m['description'] ?? ''),
            'enabled'      => (bool)($m['enabled'] ?? false),
            'entry'        => (string)($m['entry'] ?? 'index.html'),
            'feature'      => (string)($m['feature'] ?? ''),
            'installed_at' => (string)($m['installed_at'] ?? ''),
        ];
    }

    /**
     * 功能插件是否处于「已安装且启用」。
     * 供核心各功能底座判断对应入口是否对站长开放（插件即功能开关）。
     * 只认 plugin（不是主题），且 enabled 为真。
     */
    public static function isEnabled(string $id): bool
    {
        $r = self::loadRegistry();
        return isset($r['plugins'][$id]) && !empty($r['plugins'][$id]['enabled']);
    }

    /**
     * 已启用的功能标识集合（取所有已启用插件的 feature 字段）。
     * 用于下发 /api/features 给前端决定显示哪些菜单 / 登录增强。
     *
     * @return string[]
     */
    public static function enabledFeatures(): array
    {
        $r = self::loadRegistry();
        $out = [];
        foreach ($r['plugins'] as $m) {
            if (!empty($m['enabled']) && !empty($m['feature'])) {
                $out[] = (string)$m['feature'];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * 当前生效的主题 CSS。由 GET /api/theme.css 直接输出（text/css，no-store）。
     * 没有启用主题时返回一段空注释，前端 link 始终有效，不会 404。
     */
    public static function activeCss(): string
    {
        $r  = self::loadRegistry();
        $id = $r['active_theme'];
        if ($id === '' || !isset($r['themes'][$id]) || empty($r['themes'][$id]['enabled'])) {
            return "/* 欲蓝财务 v1.2 · 当前未启用自定义主题 */\n";
        }
        $file = self::themeDir($id) . '/theme.css';
        $css  = is_file($file) ? @file_get_contents($file) : false;
        if ($css === false) {
            return "/* 欲蓝财务 v1.2 · 主题「" . $id . "」样式文件缺失，已回退默认配色 */\n";
        }
        try {
            $css = self::boostSelectors(self::sanitizeCss($css));
        } catch (RuntimeException $e) {
            return "/* 主题样式未通过安全校验，已回退默认配色 */\n";
        }
        $name = (string)($r['themes'][$id]['name'] ?? $id);
        return "/* 欲蓝财务 v1.2 · 当前主题：" . str_replace(['*/', '/*'], '', $name) . " */\n"
            . $css . "\n";
    }

    // ---------------------------------------------------------------------
    // 对外：安装 / 启用停用 / 设为当前 / 卸载
    // ---------------------------------------------------------------------

    /**
     * 从上传的临时 zip 安装。同 id 已存在时视为「更新」：新包全部校验通过后才替换。
     * @return array 安装后的元数据（含 updated 标记）
     */
    public static function install(string $tmpFile, string $clientName = ''): array
    {
        $size = @filesize($tmpFile);
        if ($size === false || $size === 0) {
            throw new RuntimeException('上传内容为空，请选择 .zip 压缩包');
        }
        if ($size > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('压缩包超过 ' . (int)(self::MAX_ZIP_BYTES / 1048576) . ' MB 上限');
        }

        // 1) 先完整解析 + 校验，任何一步失败都不碰磁盘
        $entries = self::readZip($tmpFile);
        [$meta, $css, $assets] = self::parsePackage($entries);
        $id   = $meta['id'];
        $type = $meta['type'];

        // 2) 落盘
        self::ensureDirs();
        $r       = self::loadRegistry();
        $bucket  = $type === 'theme' ? 'themes' : 'plugins';
        $updated = isset($r[$bucket][$id]);
        self::purge($type, $id, $r);

        if ($type === 'theme') {
            $dir = self::themeDir($id);
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('无法创建主题目录');
            }
            if (@file_put_contents($dir . '/theme.css', $css, LOCK_EX) === false) {
                throw new RuntimeException('无法写入主题样式');
            }
        } else {
            $dir = self::pluginDir($id) . '/files';
            foreach ($assets as $rel => $content) {
                self::writeAsset($dir, $rel, $content);
            }
            self::syncPublic($id, true);
        }

        $meta['enabled']      = true;
        $meta['installed_at'] = date('c');
        $r[$bucket][$id]      = $meta;
        if ($type === 'theme') {
            $r['active_theme'] = $id;   // 主题上传即设为当前，装完刷新前台就能看到
        }
        self::saveRegistry($r);

        return self::publicMeta($type, $id, $meta) + [
            'updated'      => $updated,
            'active'       => $type === 'theme',
            'url'          => $type === 'plugin' ? 'plugins/' . $id . '/' : '',
            'file_count'   => count($assets),
            'package_name' => basename($clientName),
        ];
    }

    /** 启用 / 停用。主题启用即设为当前；停用当前主题会清空 active_theme。 */
    public static function toggle(string $type, string $id, bool $enabled): array
    {
        self::assertType($type);
        self::assertId($id);
        $r      = self::loadRegistry();
        $bucket = $type === 'theme' ? 'themes' : 'plugins';
        if (!isset($r[$bucket][$id])) {
            throw new RuntimeException('扩展不存在或已卸载');
        }
        $r[$bucket][$id]['enabled'] = $enabled;
        if ($type === 'plugin') {
            self::syncPublic($id, $enabled);
        } elseif ($enabled) {
            $r['active_theme'] = $id;
        } elseif ($r['active_theme'] === $id) {
            $r['active_theme'] = '';
        }
        self::saveRegistry($r);
        return ['id' => $id, 'type' => $type, 'enabled' => $enabled, 'active_theme' => $r['active_theme']];
    }

    /** 把某个主题设为当前（不改动其他主题的 enabled 状态） */
    public static function activateTheme(string $id): array
    {
        self::assertId($id);
        $r = self::loadRegistry();
        if (!isset($r['themes'][$id])) {
            throw new RuntimeException('主题不存在或已卸载');
        }
        $r['themes'][$id]['enabled'] = true;
        $r['active_theme'] = $id;
        self::saveRegistry($r);
        return ['id' => $id, 'active_theme' => $id];
    }

    public static function uninstall(string $type, string $id): array
    {
        self::assertType($type);
        self::assertId($id);
        $r      = self::loadRegistry();
        $bucket = $type === 'theme' ? 'themes' : 'plugins';
        if (!isset($r[$bucket][$id])) {
            throw new RuntimeException('扩展不存在或已卸载');
        }
        self::purge($type, $id, $r);
        unset($r[$bucket][$id]);
        self::saveRegistry($r);
        return ['id' => $id, 'type' => $type, 'active_theme' => $r['active_theme']];
    }

    // ---------------------------------------------------------------------
    // 包解析
    // ---------------------------------------------------------------------

    /** @return array{meta:array, css:string, assets:array<string,string>} */
    private static function parsePackage(array $entries): array
    {
        // 找 plugin.json（允许包一层顶层目录）
        $manifestKey = null;
        foreach (array_keys($entries) as $n) {
            if (self::baseName($n) === 'plugin.json') {
                $manifestKey = $n;
                break;
            }
        }
        if ($manifestKey === null) {
            throw new RuntimeException('包里找不到 plugin.json，请按 v2 教程的格式打包');
        }
        $base = substr($manifestKey, 0, -strlen('plugin.json'));

        $raw = $entries[$manifestKey];
        if (strlen($raw) > 16384) {
            throw new RuntimeException('plugin.json 过大');
        }
        $m = json_decode($raw, true);
        if (!is_array($m)) {
            throw new RuntimeException('plugin.json 不是合法 JSON');
        }

        $id = strtolower(trim((string)($m['id'] ?? '')));
        self::assertId($id);
        $type = strtolower(trim((string)($m['type'] ?? 'plugin')));
        self::assertType($type);

        $meta = [
            'id'          => $id,
            'type'        => $type,
            'name'        => self::clip((string)($m['name'] ?? $id), 60),
            'version'     => self::clip((string)($m['version'] ?? '1.0.0'), 20),
            'author'      => self::clip((string)($m['author'] ?? ''), 60),
            'description' => self::clip((string)($m['description'] ?? ''), 200),
            'entry'       => self::clip((string)($m['entry'] ?? 'index.html'), 80),
        ];
        // 功能插件标识：只有白名单内的值才保留，避免插件声明任意功能名
        $feature = strtolower(trim((string)($m['feature'] ?? '')));
        $meta['feature'] = in_array($feature, self::FEATURE_WHITELIST, true) ? $feature : '';
        if ($meta['name'] === '') {
            $meta['name'] = $id;
        }
        // 入口页只允许一个相对文件名/路径，不能有 .. 或绝对路径
        $entry = self::safeRelPath($meta['entry']);
        if ($entry === null) {
            throw new RuntimeException('plugin.json 的 entry 不是合法的相对路径');
        }
        $meta['entry'] = $entry;

        if ($type === 'theme') {
            $cssKey = $base . 'theme.css';
            if (!isset($entries[$cssKey])) {
                $cssKey = $base . 'files/theme.css';
            }
            if (!isset($entries[$cssKey])) {
                throw new RuntimeException('主题包里找不到 theme.css');
            }
            return [$meta, self::sanitizeCss($entries[$cssKey]), []];
        }

        // 插件：收集 files/ 下的静态资源
        $prefix = $base . 'files/';
        $assets = [];
        $total  = 0;
        foreach ($entries as $n => $content) {
            if (!str_starts_with($n, $prefix)) {
                continue;
            }
            $rel = substr($n, strlen($prefix));
            if ($rel === '' || str_ends_with($rel, '/')) {
                continue;
            }
            // 兼容模板仓库老写法 files/public/plugins/<任意目录>/xxx
            $rel = preg_replace('#^public/plugins/[^/]+/#', '', $rel) ?? $rel;
            // 兼容 files/<id>/xxx 这种多包一层的写法
            $rel = preg_replace('#^' . preg_quote($id, '#') . '/#', '', $rel) ?? $rel;
            $rel = self::safeRelPath($rel);
            if ($rel === null) {
                throw new RuntimeException('插件包含非法路径，已拒绝安装');
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_ASSET_EXT, true)) {
                throw new RuntimeException('插件文件类型不允许：' . $rel . '（只放行静态资源）');
            }
            $total += strlen($content);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('插件解压后体积超过上限');
            }
            $assets[$rel] = $content;
        }
        if ($assets === []) {
            throw new RuntimeException('插件包里 files/ 目录为空，没有可安装的文件');
        }
        return [$meta, '', $assets];
    }

    /** 只允许「字母数字._-/」构成的相对路径，且每段不能是 . / .. / 空 / 隐藏文件 */
    private static function safeRelPath(string $p): ?string
    {
        $p = str_replace('\\', '/', $p);
        if ($p === '' || str_contains($p, "\0") || str_starts_with($p, '/')) {
            return null;
        }
        if (preg_match('#^[A-Za-z]:#', $p) === 1) {
            return null;
        }
        if (preg_match('#[\x00-\x1F\x7F<>"|:*?]#', $p) === 1) {
            return null;
        }
        $parts = explode('/', $p);
        foreach ($parts as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || str_starts_with($seg, '.')) {
                return null;
            }
        }
        return implode('/', $parts);
    }

    private static function sanitizeCss(string $css): string
    {
        if (strlen($css) > self::MAX_CSS_BYTES) {
            throw new RuntimeException('theme.css 超过 64 KB 上限');
        }
        $css = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $css) ?? '';
        $low = strtolower($css);
        $bad = ['@import', 'expression(', 'javascript:', 'vbscript:', 'data:text/html',
                '-moz-binding', 'behavior:', 'url(javascript', 'url(data:text'];
        foreach ($bad as $b) {
            if (str_contains($low, $b)) {
                throw new RuntimeException('theme.css 含有不允许的内容（' . $b . '）');
            }
        }
        if (str_contains($css, '<')) {
            throw new RuntimeException('theme.css 不能包含 HTML 标签字符');
        }
        return trim($css) . "\n";
    }

    /**
     * 提升主题 CSS 里暗色选择器的特异性，让「后加载的主题」能盖住默认配色。
     *
     * 为什么必须做：
     *   默认 style.css 的暗色变量写在 `:root[data-theme=dark]`（特异性 0,2,0），
     *   而小白按直觉写的是 `[data-theme=dark]`（0,1,0）。两者都命中 <html>，
     *   但特异性不够，结果就是「亮色换了、切到暗色又变回默认蓝」——这个坑很隐蔽。
     *   亮色的 `:root` 与默认同为 0,1,0，靠加载顺序就能赢，所以只有暗色需要处理。
     *
     * 这里按「主题包只写两块变量覆盖」的约定做轻量改写，不解析完整 CSS：
     *   已写对的 `:root[data-theme=dark]` 原样保留（先保护再还原，避免叠成两个 :root）；
     *   `[data-theme=dark]` / `html[data-theme=dark]` 统一提升为 `:root[data-theme=dark]`。
     */
    private static function boostSelectors(string $css): string
    {
        $guard = "\x01DARKSEL\x02";
        $css = preg_replace('/:root\s*\[data-theme\s*=\s*(["\']?)dark\1\s*\]/i', $guard, $css) ?? $css;
        $css = preg_replace('/(?:html)?\s*\[data-theme\s*=\s*(["\']?)dark\1\s*\]/i',
                            ':root[data-theme=dark]', $css) ?? $css;
        return str_replace($guard, ':root[data-theme=dark]', $css);
    }

    private static function clip(string $s, int $max): string
    {
        $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
        return mb_substr($s, 0, $max, 'UTF-8');
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,47}$/', $id) !== 1) {
            throw new RuntimeException('plugin.json 的 id 不合法（只能用小写字母、数字、连字符，2–48 位）');
        }
    }

    private static function assertType(string $type): void
    {
        if (!in_array($type, ['theme', 'plugin'], true)) {
            throw new RuntimeException('plugin.json 的 type 只能是 theme 或 plugin');
        }
    }

    private static function baseName(string $p): string
    {
        $parts = explode('/', $p);
        return (string)end($parts);
    }

    // ---------------------------------------------------------------------
    // 纯 PHP ZIP 读取（无 ZipArchive 依赖）
    // ---------------------------------------------------------------------

    /**
     * @return array<string,string> 归一化后的 路径 => 内容
     * 支持 store(0) 与 deflate(8)；明确拒绝 ZIP64 / 分卷 / 加密 / 数据描述符 / 符号链接。
     */
    private static function readZip(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('无法读取上传的压缩包');
        }
        $len = strlen($raw);
        if ($len > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('压缩包超过大小上限');
        }
        if ($len < 22) {
            throw new RuntimeException('压缩包为空或已损坏');
        }

        // 1) 从尾部找 EOCD（PK\x05\x06），注释最长 65535
        $eocd = -1;
        $from = max(0, $len - 22 - 65535);
        for ($i = $len - 22; $i >= $from; $i--) {
            if (substr($raw, $i, 4) === "PK\x05\x06") {
                $eocd = $i;
                break;
            }
        }
        if ($eocd < 0) {
            throw new RuntimeException('不是有效的 ZIP 压缩包（请确认打包成 .zip 而不是 .rar/.7z）');
        }
        $e = unpack(
            'vDisk/vCdDisk/vEntriesThis/vTotal/VSize/VOffset/vCommentLen',
            substr($raw, $eocd + 4, 18)
        );
        if ($e === false) {
            throw new RuntimeException('压缩包结束标记损坏');
        }
        if ($e['Disk'] !== 0 || $e['CdDisk'] !== 0 || $e['EntriesThis'] !== $e['Total']) {
            throw new RuntimeException('不支持分卷压缩包');
        }
        $count = (int)$e['Total'];
        if ($count < 1) {
            throw new RuntimeException('压缩包内没有文件');
        }
        if ($count > self::MAX_ENTRIES) {
            throw new RuntimeException('压缩包内文件过多（上限 ' . self::MAX_ENTRIES . ' 个）');
        }
        if ($e['Size'] === 0xFFFFFFFF || $e['Offset'] === 0xFFFFFFFF) {
            throw new RuntimeException('不支持 ZIP64 格式的压缩包');
        }
        $pos = (int)$e['Offset'];
        if ($pos < 0 || $pos + 46 > $len) {
            throw new RuntimeException('压缩包目录损坏');
        }

        $entries = [];
        $total   = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($pos + 46 > $len || substr($raw, $pos, 4) !== "PK\x01\x02") {
                throw new RuntimeException('压缩包目录损坏');
            }
            $h = unpack(
                'vMadeBy/vNeeded/vFlags/vMethod/vTime/vDate/VCrc/VCompressed/VUncompressed/' .
                'vNameLen/vExtraLen/vCommentLen/vDiskStart/vInternal/VExternal/VLocalOffset',
                substr($raw, $pos + 4, 42)
            );
            if ($h === false) {
                throw new RuntimeException('压缩包目录项损坏');
            }
            $nameLen = (int)$h['NameLen'];
            if ($nameLen < 1 || $pos + 46 + $nameLen > $len) {
                throw new RuntimeException('压缩包文件名损坏');
            }
            $name = substr($raw, $pos + 46, $nameLen);
            // 危险后缀检查对「所有原始条目名」做，包括后面会被忽略的隐藏文件与非 files/ 文件，
            // 避免有人把 .php 藏在 .README/ 之类的角落绕过扫描。
            $rawName = str_replace('\\', '/', $name);
            $rawExt  = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
            if (in_array($rawExt, self::DANGEROUS_EXT, true)) {
                throw new RuntimeException('包内含有不允许的文件类型（.' . $rawExt . '），已拒绝安装');
            }
            $name = self::normalizeZipName($name);
            $next = $pos + 46 + $nameLen + (int)$h['ExtraLen'] + (int)$h['CommentLen'];
            if ($next > $len || $next <= $pos) {
                throw new RuntimeException('压缩包目录损坏');
            }

            $flags = (int)$h['Flags'];
            if (($flags & 0x0001) !== 0 || ($flags & 0x0008) !== 0 || ($flags & 0x0040) !== 0) {
                throw new RuntimeException('不支持加密或带数据描述符的压缩包');
            }
            $method = (int)$h['Method'];
            if ($method !== 0 && $method !== 8) {
                throw new RuntimeException('不支持的压缩方式（只接受标准 deflate / 不压缩）');
            }
            // 符号链接：external attributes 高 16 位是 Unix mode
            $mode = ((int)$h['External'] >> 16) & 0xFFFF;
            if (($mode & 0170000) === 0120000) {
                throw new RuntimeException('压缩包不允许包含符号链接');
            }
            $uncompressed = (int)$h['Uncompressed'];
            $compressed   = (int)$h['Compressed'];
            if ($uncompressed > self::MAX_FILE_BYTES) {
                throw new RuntimeException('压缩包内单个文件超过 512 KB 上限');
            }
            $total += $uncompressed;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('压缩包解压后体积超过上限（疑似 zip 炸弹）');
            }

            if ($name !== null && !str_ends_with($name, '/')) {
                $lo = (int)$h['LocalOffset'];
                if ($lo < 0 || $lo + 30 > $len || substr($raw, $lo, 4) !== "PK\x03\x04") {
                    throw new RuntimeException('压缩包数据块损坏');
                }
                $lh = unpack('vNameLen/vExtraLen', substr($raw, $lo + 26, 4));
                if ($lh === false) {
                    throw new RuntimeException('压缩包数据块损坏');
                }
                $dataStart = $lo + 30 + (int)$lh['NameLen'] + (int)$lh['ExtraLen'];
                if ($dataStart + $compressed > $len) {
                    throw new RuntimeException('压缩包数据不完整');
                }
                $data = substr($raw, $dataStart, $compressed);
                $content = $method === 8 ? @gzinflate($data) : $data;
                if ($content === false || strlen($content) !== $uncompressed) {
                    throw new RuntimeException('压缩包数据解压失败：' . $name);
                }
                $entries[$name] = $content;
            }

            $pos = $next;
        }

        if ($entries === []) {
            throw new RuntimeException('压缩包内没有可用文件');
        }
        return $entries;
    }

    /** 条目名归一化：反斜杠转正斜杠；拒绝穿越 / 绝对路径 / 控制字符 / 隐藏段 */
    private static function normalizeZipName(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_contains($name, "\0")) {
            return null;
        }
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            return null;
        }
        if (preg_match('#[\x00-\x1F\x7F]#', $name) === 1) {
            return null;
        }
        $parts = explode('/', $name);
        foreach ($parts as $seg) {
            if ($seg === '..') {
                // 路径穿越是明确的攻击迹象：整包拒绝，而不是悄悄跳过
                throw new RuntimeException('压缩包含有路径穿越（..），已拒绝安装');
            }
            if ($seg === '.' || $seg === '') {
                continue;      // 空段 / 当前目录段允许（目录项与结尾斜杠）
            }
            if (str_starts_with($seg, '.')) {
                return null;   // 隐藏文件（.env / .htaccess / .gitignore）一律忽略
            }
        }
        return $name;
    }

    // ---------------------------------------------------------------------
    // 文件操作
    // ---------------------------------------------------------------------

    private static function writeAsset(string $baseDir, string $rel, string $content): void
    {
        $target = $baseDir . '/' . $rel;
        $dir    = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建插件目录：' . $rel);
        }
        if (@file_put_contents($target, $content, LOCK_EX) === false) {
            throw new RuntimeException('无法写入插件文件：' . $rel);
        }
    }

    /** 把 data 里的插件原件同步到 public/plugins/<id>/；$on=false 时删除副本 */
    private static function syncPublic(string $id, bool $on): void
    {
        $dst = self::publicPluginDir($id);
        self::rmDir($dst);
        if (!$on) {
            return;
        }
        $src = self::pluginDir($id) . '/files';
        if (!is_dir($src)) {
            return;
        }
        self::copyDir($src, $dst);
    }

    private static function copyDir(string $src, string $dst): void
    {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new RuntimeException('无法创建插件对外目录');
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $to = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            if ($item->isDir()) {
                if (!is_dir($to) && !@mkdir($to, 0755, true) && !is_dir($to)) {
                    throw new RuntimeException('无法创建插件子目录');
                }
            } elseif (!@copy($item->getPathname(), $to)) {
                throw new RuntimeException('无法复制插件文件');
            }
        }
    }

    /** 清掉某个扩展的所有磁盘痕迹（registry 由调用方维护） */
    private static function purge(string $type, string $id, array &$r): void
    {
        if ($type === 'theme') {
            self::rmDir(self::themeDir($id));
            if (($r['active_theme'] ?? '') === $id) {
                $r['active_theme'] = '';
            }
        } else {
            self::rmDir(self::publicPluginDir($id));
            self::rmDir(self::pluginDir($id));
        }
    }

    private static function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
