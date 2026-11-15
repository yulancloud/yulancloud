<?php
/**
 * 生成一次性「安装密钥」（命令行）
 *
 *   php bin/install_token.php
 *
 * 用途：未安装的站点原本任何访客都能 POST /api/install 把自己设成 owner 并永久接管。
 * 现在安装接口必须提供本脚本输出的密钥，而密钥只能由能在服务器上执行 CLI 的人取得。
 *
 * 落盘的是密钥的 SHA-256 摘要（config/.install_token，在 Web 根 public/ 之外，HTTP 取不到），
 * 不存明文；即便被同机其他用户读到摘要也无法反推出密钥。
 * 安装成功后由 Installer::consumeInstallToken() 自动删除。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Installer.php';

$path = Installer::tokenPath();
$dir  = dirname($path);
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "无法创建配置目录：{$dir}\n");
    exit(1);
}

$token = bin2hex(random_bytes(24));
if (@file_put_contents($path, hash('sha256', $token) . "\n") === false) {
    fwrite(STDERR, "无法写入密钥文件：{$path}\n");
    exit(1);
}
// 需让 PHP-FPM 用户也能读到（文件不在 public/ 下，HTTP 无法访问）。
// 若 FPM 与 CLI 属主不同且 0644 仍不可读，请把属主改成 FPM 用户或设置 SHOP_WEB_USER。
@chmod($path, 0644);

echo "============================================================\n";
echo "  一次性安装密钥（仅显示这一次，请复制到安装页）\n\n";
echo "    {$token}\n\n";
echo "  密钥文件：{$path}\n";
echo "  安装完成后该文件会自动删除；如需重新生成，再次执行本脚本即可。\n";
echo "  部署提示：CLI 与 PHP-FPM 若不是同一用户，请确认 FPM 用户对该文件有读权限。\n";
echo "============================================================\n";
