<?php
/**
 * 导航站安装向导（安装完成后请删除本文件）
 */
// 会话 Cookie 加固（与后台 admin/index.php 一致）
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=UTF-8');

// 已安装则拒绝再次运行
if (file_exists(__DIR__ . '/config.php')) {
    exit('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>已安装</title></head><body style="font-family:\'Segoe UI\',\'PingFang SC\',\'Microsoft YaHei\',sans-serif;text-align:center;padding-top:80px;color:#333;"><h2>LumeNav 已安装</h2><p style="margin:12px 0;">如需重新安装，请先删除 config.php。</p><p style="margin:12px 0;"><a href="index.php">前往首页</a> ｜ <a href="admin/">前往后台</a></p><p style="color:#e74c3c;font-size:.9rem;">安全提示：安装完成后建议删除本文件（install.php）。</p></body></html>');
}

$errors = [];
$done = false;
$old = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'admin_user' => '',
    'site_title' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = '页面校验失败，请刷新页面后重试';
    } else {
        foreach ($old as $k => $v) {
            $old[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $pass = (string)($_POST['admin_pass'] ?? '');
        $pass2 = (string)($_POST['admin_pass2'] ?? '');

        if ($old['db_host'] === '') $old['db_host'] = 'localhost';
        if ($old['db_port'] === '') $old['db_port'] = '3306';
        if (!ctype_digit($old['db_port'])) $errors[] = '数据库端口格式不正确';
        if ($old['db_name'] === '') $errors[] = '请填写数据库名';
        if ($old['db_user'] === '') $errors[] = '请填写数据库用户名';
        if ($old['site_title'] === '') $errors[] = '请填写网站名';
        if (mb_strlen($old['admin_user']) < 2) $errors[] = '管理员用户名至少 2 个字符';
        if (strlen($pass) < 6) $errors[] = '管理员密码至少 6 位';
        if ($pass !== $pass2) $errors[] = '两次输入的管理员密码不一致';

        if (!$errors) {
            try {
                $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
                $dsn = "mysql:host={$old['db_host']};port={$old['db_port']};dbname={$old['db_name']};charset=utf8mb4";
                try {
                    $pdo = new PDO($dsn, $old['db_user'], $old['db_pass'], $opts);
                } catch (PDOException $ex) {
                    // 数据库不存在时尝试自动创建
                    $msg = $ex->getMessage();
                    if (strpos($msg, '1049') !== false || stripos($msg, 'Unknown database') !== false) {
                        $tmp = new PDO("mysql:host={$old['db_host']};port={$old['db_port']};charset=utf8mb4", $old['db_user'], $old['db_pass'], $opts);
                        $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $old['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        $pdo = new PDO($dsn, $old['db_user'], $old['db_pass'], $opts);
                    } else {
                        throw $ex;
                    }
                }

                // 建表（幂等）
                $pdo->exec("CREATE TABLE IF NOT EXISTS lumenav_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  url VARCHAR(500) NOT NULL,
  target TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("CREATE TABLE IF NOT EXISTS lumenav_settings (
  skey VARCHAR(50) PRIMARY KEY,
  svalue TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("CREATE TABLE IF NOT EXISTS lumenav_admin (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                // 初始设置
                $defaults = [
                    'title' => $old['site_title'],
                    'keywords' => '',
                    'description' => '',
                    'home_title' => $old['site_title'],
                    'icp' => '',
                    'copyright' => '© ' . date('Y') . ' ' . $old['site_title'],
                    'logo' => '',
                    'background' => '',
                    'theme' => 'default',
                    'admin_dir' => 'admin',
                ];
                $st = $pdo->prepare('REPLACE INTO lumenav_settings (skey, svalue) VALUES (?, ?)');
                foreach ($defaults as $k => $v) {
                    $st->execute([$k, $v]);
                }

                // 管理员账号
                $pdo->prepare('REPLACE INTO lumenav_admin (username, password) VALUES (?, ?)')
                    ->execute([$old['admin_user'], password_hash($pass, PASSWORD_DEFAULT)]);

                // 上传目录
                if (!is_dir(__DIR__ . '/uploads')) {
                    @mkdir(__DIR__ . '/uploads', 0755, true);
                }
                if (!file_exists(__DIR__ . '/uploads/index.html')) {
                    @file_put_contents(__DIR__ . '/uploads/index.html', '');
                }

                // 生成 config.php
                $common = <<<'PHPCODE'

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function settings(): array {
    static $set = null;
    if ($set === null) {
        $set = [];
        foreach (db()->query('SELECT skey, svalue FROM lumenav_settings') as $row) {
            $set[$row['skey']] = $row['svalue'];
        }
    }
    return $set;
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
PHPCODE;
                $cfg = "<?php\n// 本文件由 install.php 自动生成，如需修改数据库信息请直接编辑本文件\n"
                    . "define('DB_HOST', " . var_export($old['db_host'], true) . ");\n"
                    . "define('DB_PORT', " . var_export($old['db_port'], true) . ");\n"
                    . "define('DB_NAME', " . var_export($old['db_name'], true) . ");\n"
                    . "define('DB_USER', " . var_export($old['db_user'], true) . ");\n"
                    . "define('DB_PASS', " . var_export($old['db_pass'], true) . ");\n"
                    . $common;
                if (@file_put_contents(__DIR__ . '/config.php', $cfg) === false) {
                    throw new RuntimeException('无法写入 config.php，请检查网站目录写入权限');
                }
                $done = true;
            } catch (Throwable $ex) {
                $errors[] = '安装失败：' . $ex->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LumeNav · 安装向导</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif; }
        body { background: #f0f2f5; color: #333; min-height: 100vh; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 30px 15px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0, 0, 0, .08); padding: 32px 30px; width: 460px; max-width: 94vw; }
        h1 { text-align: center; font-size: 1.35rem; color: #2c3e50; margin-bottom: 8px; }
        h2 { font-size: 1rem; color: #3498db; margin: 22px 0 4px; padding-bottom: 8px; border-bottom: 1px solid #f0f0f0; }
        label { display: block; font-weight: 600; font-size: .88rem; margin: 12px 0 6px; color: #34495e; }
        input[type=text], input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid #dcdfe6; border-radius: 8px; font-size: .95rem; outline: none; background: #fafbfc; }
        input:focus { border-color: #3498db; background: #fff; }
        .btn { display: inline-block; padding: 10px 22px; border: none; border-radius: 8px; background: #3498db; color: #fff; font-size: .98rem; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #2f89c5; }
        .btn.gray { background: #95a5a6; }
        .btn.gray:hover { background: #82968f; }
        .flash { padding: 10px 14px; border-radius: 8px; margin: 12px 0 2px; font-size: .9rem; }
        .flash.ok { background: #e8f8f0; color: #1e8449; border: 1px solid #bfe9d2; }
        .flash.err { background: #fdeeec; color: #c0392b; border: 1px solid #f5c6c0; }
        .center { text-align: center; }
        .actions { margin-top: 24px; text-align: center; }
        .tip { margin-top: 16px; color: #e74c3c; font-size: .85rem; text-align: center; }
        .done-links { margin: 18px 0; display: flex; gap: 12px; justify-content: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>LumeNav · 安装向导</h1>

        <?php foreach ($errors as $er): ?>
        <div class="flash err"><?php echo h($er); ?></div>
        <?php endforeach; ?>

        <?php if ($done): ?>
        <div class="flash ok">安装完成！</div>
        <p class="center" style="margin-top:14px;">管理员用户名：<b><?php echo h($old['admin_user']); ?></b></p>
        <div class="done-links">
            <a class="btn" href="admin/">进入后台</a>
            <a class="btn gray" href="index.php">查看首页</a>
        </div>
        <p class="tip">安全提示：请尽快删除本文件（install.php）。</p>
        <?php else: ?>
        <form method="post" action="install.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">

            <h2>数据库信息</h2>
            <label>数据库主机</label>
            <input type="text" name="db_host" value="<?php echo h($old['db_host']); ?>" placeholder="一般填 localhost">
            <label>数据库端口</label>
            <input type="text" name="db_port" value="<?php echo h($old['db_port']); ?>" placeholder="一般填 3306">
            <label>数据库名 <span style="color:#e74c3c;">*</span></label>
            <input type="text" name="db_name" value="<?php echo h($old['db_name']); ?>" required>
            <label>数据库用户名 <span style="color:#e74c3c;">*</span></label>
            <input type="text" name="db_user" value="<?php echo h($old['db_user']); ?>" required>
            <label>数据库密码</label>
            <input type="password" name="db_pass" value="<?php echo h($old['db_pass']); ?>">

            <h2>管理员账号</h2>
            <label>管理员用户名 <span style="color:#e74c3c;">*</span></label>
            <input type="text" name="admin_user" value="<?php echo h($old['admin_user']); ?>" required>
            <label>管理员密码 <span style="color:#e74c3c;">*</span>（至少 6 位）</label>
            <input type="password" name="admin_pass" required>
            <label>确认密码 <span style="color:#e74c3c;">*</span></label>
            <input type="password" name="admin_pass2" required>

            <h2>网站信息</h2>
            <label>网站名 <span style="color:#e74c3c;">*</span>（显示在浏览器标题，可后台修改）</label>
            <input type="text" name="site_title" value="<?php echo h($old['site_title']); ?>" required>

            <div class="actions">
                <button type="submit" class="btn">开始安装</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
