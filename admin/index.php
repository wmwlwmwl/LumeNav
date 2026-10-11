<?php
/**
 * 导航站后台
 */
// 会话 Cookie 加固：禁止 JS 读取 + SameSite 防跨站携带
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// 后台实际文件名（后台目录重命名后所有链接自动跟随）；ROOT_DIR 为站点根目录
define('ADMIN_SELF', basename(__FILE__));
define('ROOT_DIR', dirname(__DIR__));

if (!file_exists(ROOT_DIR . '/config.php')) {
    header('Location: ../install.php');
    exit;
}

try {
    require_once ROOT_DIR . '/config.php';
    // 后台激活目录校验：访问已弃用的旧目录时直接响应失效（含手动改名的恢复指引）
    if (basename(__DIR__) !== trim((string)(settings()['admin_dir'] ?? 'admin'))) {
        header('Content-Type: text/plain; charset=UTF-8');
        http_response_code(404);
        exit("该后台地址已失效，请使用新地址访问。\r\n\r\n"
            . "若你是手动移动/重命名了后台目录，二选一恢复：\r\n"
            . "1) 把目录名改回「" . trim((string)(settings()['admin_dir'] ?? 'admin')) . "」；\r\n"
            . "2) 或执行 SQL，把激活目录名同步为当前目录名：\r\n"
            . "   UPDATE nav_settings SET svalue = '" . basename(__DIR__) . "' WHERE skey = 'admin_dir';");
    }
} catch (Throwable $ex) {
    http_response_code(500);
    exit('数据库连接失败，请检查 config.php 配置或 MySQL 服务。');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('请求校验失败，请返回刷新页面后重试。');
    }
}

function flash(string $msg, bool $err = false): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'err' => $err];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** 处理图片上传，成功返回相对路径，未选择文件返回 null，失败时写入 $err */
function handle_upload(string $field, string &$err): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if (!is_array($f) || $f['error'] !== UPLOAD_ERR_OK) {
        $err = '文件上传失败，请重试';
        return null;
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        $err = '图片大小不能超过 5MB';
        return null;
    }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'], true)) {
        $err = '仅支持 jpg / png / gif / webp / ico 格式';
        return null;
    }
    if ($ext === 'ico') {
        // ICO 无法用 getimagesize 完全可靠识别，校验文件头
        $head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 4);
        if (strlen($head) < 4 || $head[0] !== "\x00" || $head[1] !== "\x00" || $head[2] !== "\x01" || $head[3] !== "\x00") {
            $err = '不是有效的 ICO 图标文件';
            return null;
        }
    } elseif (@getimagesize($f['tmp_name']) === false) {
        $err = '不是有效的图片文件';
        return null;
    }
    if (!is_dir(ROOT_DIR . '/uploads')) {
        @mkdir(ROOT_DIR . '/uploads', 0755, true);
    }
    $name = 'up_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], ROOT_DIR . '/uploads/' . $name)) {
        $err = '保存图片失败，请检查 uploads 目录写入权限';
        return null;
    }
    return 'uploads/' . $name;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('REPLACE INTO nav_settings (skey, svalue) VALUES (?, ?)')->execute([$k, $v]);
}

/** 清除/替换设置时，删除其引用的本地上传图片（外链不动；两处共用同一文件时不删） */
function del_setting_file(string $key): void
{
    $val = trim((string)(settings()[$key] ?? ''));
    if ($val === '' || str_starts_with($val, 'http') || !str_starts_with($val, 'uploads/')) {
        return; // 空值 / 外链 / 非本站上传路径，无文件可删
    }
    $otherKey = $key === 'logo' ? 'background' : 'logo';
    if (trim((string)(settings()[$otherKey] ?? '')) === $val) {
        return; // 另一个设置仍引用此文件
    }
    $path = realpath(ROOT_DIR . '/' . $val);
    $base = realpath(ROOT_DIR . '/uploads');
    if ($path && $base && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path)) {
        @unlink($path);
    }
}

/** 后台页面引用站点根目录资源的地址（后台在子目录中，本地路径需加 ../） */
function asset(string $v): string
{
    $v = trim($v);
    return ($v === '' || str_starts_with($v, 'http') || str_starts_with($v, '/')) ? $v : '../' . $v;
}

/** 校验导航链接：必须带 http:// 或 https:// 协议头（拦住 javascript: 等伪协议与裸域名） */
function check_nav_url(string $url): bool
{
    return (bool)preg_match('#^https?://#i', $url);
}

// 登出
if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . ADMIN_SELF);
    exit;
}

$logged = !empty($_SESSION['admin_id']);

// 登录
if (!$logged && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'login') {
    check_csrf();
    try {
        $st = db()->prepare('SELECT id, username, password FROM nav_admin WHERE username = ?');
        $st->execute([trim((string)($_POST['username'] ?? ''))]);
        $row = $st->fetch();
        if ($row && password_verify((string)($_POST['password'] ?? ''), $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$row['id'];
            $_SESSION['admin_name'] = $row['username'];
            header('Location: ' . ADMIN_SELF . '?p=links');
            exit;
        }
        flash('用户名或密码错误', true);
        // ponytail: 仅靠随机延迟拖慢爆破，共享 FPM 下非精确限速；如遭持续爆破可升级为失败计数锁定
        usleep(random_int(300000, 900000));
    } catch (Throwable $ex) {
        flash('登录失败：' . $ex->getMessage(), true);
    }
    header('Location: ' . ADMIN_SELF);
    exit;
}

$logged = !empty($_SESSION['admin_id']);

if (!$logged) {
    $p = 'login';
} else {
    $p = (string)($_GET['p'] ?? 'links');
    if (!in_array($p, ['setting', 'links', 'account'], true)) {
        $p = 'links';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $act = (string)($_POST['act'] ?? '');
        try {
            if ($p === 'setting' && $act === 'save_setting') {
                foreach (['title', 'keywords', 'description', 'home_title', 'icp', 'copyright'] as $k) {
                    set_setting($k, trim((string)($_POST[$k] ?? '')));
                }
                // 主题切换：目录名白名单（支持中文）+ 目录存在性双重校验
                $newTheme = preg_replace('/[^\w-]/u', '', (string)($_POST['theme'] ?? ''));
                if ($newTheme !== '' && is_dir(ROOT_DIR . '/template/' . $newTheme)) {
                    set_setting('theme', $newTheme);
                }
                foreach (['logo' => 'Logo', 'background' => '背景图'] as $field => $cn) {
                    $err = '';
                    $path = handle_upload($field, $err);
                    if ($err !== '') {
                        flash($cn . '上传失败：' . $err, true);
                    } elseif ($path !== null) {
                        // 优先级：本地上传 > 勾选清除 > 外链 > 保持不变
                        del_setting_file($field);
                        set_setting($field, $path);
                        flash($cn . '已更新（本地上传）');
                    } elseif (isset($_POST[$field . '_clear'])) {
                        del_setting_file($field);
                        set_setting($field, '');
                        flash($cn . '已清除');
                    } elseif (($url = trim((string)($_POST[$field . '_url'] ?? ''))) !== '') {
                        // 未上传文件时接受外链地址（仅允许 http/https，防 javascript: 伪协议）
                        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
                            del_setting_file($field);
                            set_setting($field, $url);
                            flash($cn . '已更新（外链）');
                        } else {
                            flash($cn . '外链地址无效（需以 http:// 或 https:// 开头），已忽略', true);
                        }
                    }
                }
                flash('网站设置已保存');
                header('Location: ' . ADMIN_SELF . '?p=setting');
                exit;
            }

            if ($p === 'links' && $act === 'add_link') {
                $name = trim((string)($_POST['name'] ?? ''));
                $url = trim((string)($_POST['url'] ?? ''));
                if ($name === '' || $url === '') {
                    flash('名称和网址不能为空', true);
                } elseif (!check_nav_url($url)) {
                    flash('网址需以 http:// 或 https:// 开头', true);
                } else {
                    db()->prepare('INSERT INTO nav_links (name, url, target, sort) VALUES (?, ?, ?, ?)')
                        ->execute([$name, $url, isset($_POST['target']) ? 1 : 0, (int)($_POST['sort'] ?? 0)]);
                    flash('链接已添加');
                }
                header('Location: ' . ADMIN_SELF . '?p=links');
                exit;
            }

            if ($p === 'links' && $act === 'save_link') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $url = trim((string)($_POST['url'] ?? ''));
                if ($id <= 0 || $name === '' || $url === '') {
                    flash('参数不完整，修改失败', true);
                } elseif (!check_nav_url($url)) {
                    flash('网址需以 http:// 或 https:// 开头', true);
                } else {
                    db()->prepare('UPDATE nav_links SET name = ?, url = ?, target = ?, sort = ? WHERE id = ?')
                        ->execute([$name, $url, isset($_POST['target']) ? 1 : 0, (int)($_POST['sort'] ?? 0), $id]);
                    flash('链接已修改');
                }
                header('Location: ' . ADMIN_SELF . '?p=links');
                exit;
            }

            if ($p === 'links' && $act === 'del_link') {
                db()->prepare('DELETE FROM nav_links WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
                flash('链接已删除');
                header('Location: ' . ADMIN_SELF . '?p=links');
                exit;
            }

            if ($p === 'account' && $act === 'change_user') {
                $name = trim((string)($_POST['new_user'] ?? ''));
                $st = db()->prepare('SELECT password FROM nav_admin WHERE id = ?');
                $st->execute([$_SESSION['admin_id']]);
                $row = $st->fetch();
                if (!$row || !password_verify((string)($_POST['pwd'] ?? ''), (string)$row['password'])) {
                    flash('当前密码不正确', true);
                } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
                    flash('用户名长度需在 2~50 个字符之间', true);
                } else {
                    $st = db()->prepare('SELECT id FROM nav_admin WHERE username = ? AND id != ?');
                    $st->execute([$name, $_SESSION['admin_id']]);
                    if ($st->fetch()) {
                        flash('该用户名已被使用', true);
                    } else {
                        db()->prepare('UPDATE nav_admin SET username = ? WHERE id = ?')
                            ->execute([$name, $_SESSION['admin_id']]);
                        $_SESSION['admin_name'] = $name;
                        flash('用户名已修改');
                    }
                }
                header('Location: ' . ADMIN_SELF . '?p=account');
                exit;
            }

            if ($p === 'account' && $act === 'change_pass') {
                $oldP = (string)($_POST['old_pass'] ?? '');
                $newP = (string)($_POST['new_pass'] ?? '');
                $newP2 = (string)($_POST['new_pass2'] ?? '');
                $st = db()->prepare('SELECT password FROM nav_admin WHERE id = ?');
                $st->execute([$_SESSION['admin_id']]);
                $row = $st->fetch();
                if (!$row || !password_verify($oldP, (string)$row['password'])) {
                    flash('旧密码不正确', true);
                } elseif (strlen($newP) < 6) {
                    flash('新密码至少 6 位', true);
                } elseif ($newP !== $newP2) {
                    flash('两次输入的新密码不一致', true);
                } else {
                    db()->prepare('UPDATE nav_admin SET password = ? WHERE id = ?')
                        ->execute([password_hash($newP, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
                    flash('密码已修改');
                }
                header('Location: ' . ADMIN_SELF . '?p=account');
                exit;
            }

            if ($p === 'account' && $act === 'rename_admin') {
                $newDir = strtolower(trim((string)($_POST['new_dir'] ?? '')));
                $st = db()->prepare('SELECT password FROM nav_admin WHERE id = ?');
                $st->execute([$_SESSION['admin_id']]);
                $row = $st->fetch();
                if (!$row || !password_verify((string)($_POST['pwd'] ?? ''), (string)$row['password'])) {
                    flash('当前密码不正确', true);
                } elseif (!preg_match('/^[a-z][a-z0-9_-]{1,29}$/', $newDir)) {
                    flash('格式：小写字母开头，仅小写字母/数字/横线/下划线（如 my-panel）', true);
                } elseif ($newDir === basename(__DIR__)) {
                    flash('新目录名与当前相同', true);
                } elseif (in_array($newDir, ['template', 'uploads'], true) || file_exists(ROOT_DIR . '/' . $newDir)) {
                    flash('该目录名已存在，请换一个', true);
                } else {
                    // 先切换激活目录名（旧目录随后自动失效），再迁移文件
                    set_setting('admin_dir', $newDir);
                    $migrated = @rename(__DIR__, ROOT_DIR . '/' . $newDir);
                    if (!$migrated) {
                        // Windows 下自身目录被进程占用无法 rename：复制自身到新目录
                        $migrated = @mkdir(ROOT_DIR . '/' . $newDir) && @copy(__FILE__, ROOT_DIR . '/' . $newDir . '/index.php');
                    }
                    if ($migrated) {
                        // ponytail: Windows 下可能因文件占用删不掉旧目录，旧目录已因激活名校验失效，手动删即可
                        if (@unlink(__FILE__)) {
                            @rmdir(__DIR__);
                        }
                        flash('后台地址已改为 /' . $newDir . '/，旧地址已失效' . (is_dir(__DIR__) ? '；旧目录未能自动删除，可到文件管理器手动删除' : ''));
                        header('Location: ../' . $newDir . '/index.php?p=account');
                        exit;
                    }
                    set_setting('admin_dir', basename(__DIR__)); // 迁移失败，回滚激活名
                    flash('迁移失败，请检查目录写入权限', true);
                }
                header('Location: ' . ADMIN_SELF . '?p=account');
                exit;
            }

            flash('未知操作', true);
        } catch (Throwable $ex) {
            flash('操作失败：' . $ex->getMessage(), true);
        }
        header('Location: ' . ADMIN_SELF . '?p=' . urlencode($p));
        exit;
    }
}

$flashes = take_flashes();
$set = $logged ? settings() : [];
$links = $logged ? db()->query('SELECT * FROM nav_links ORDER BY sort ASC, id ASC')->fetchAll() : [];

// 扫描可用主题（template/<名>/index.php），theme.ini 提供元数据
$themes = [];
if ($logged) {
    foreach (glob(ROOT_DIR . '/template/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (!preg_match('/^[\w-]+$/u', $name) || !is_file($dir . '/index.php')) {
            continue;
        }
        $meta = ['theme_name' => $name, 'author_name' => '', 'theme_version' => '', 'theme_explain' => ''];
        if (is_file($dir . '/theme.ini')) {
            $j = json_decode((string)@file_get_contents($dir . '/theme.ini'), true);
            if (is_array($j)) {
                foreach ($meta as $k => $v) {
                    if (isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '') {
                        $meta[$k] = $j[$k];
                    }
                }
            }
        }
        $meta['dir'] = $name;
        $themes[$name] = $meta;
    }
}
$currentTheme = trim((string)($set['theme'] ?? ''));
if (!isset($themes[$currentTheme])) {
    $currentTheme = $themes ? array_key_first($themes) : '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理<?php echo ($logged && !empty($set['title'])) ? ' - ' . e($set['title']) : ''; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif; }
        body { background: #f0f2f5; color: #333; min-height: 100vh; }
        .container { max-width: 960px; margin: 0 auto; padding: 22px 15px 40px; }
        .topbar { background: #fff; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; z-index: 10; }
        .topbar-inner { max-width: 960px; margin: 0 auto; padding: 13px 15px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .brand { font-weight: 700; font-size: 1.08rem; color: #2c3e50; }
        .nav a { display: inline-block; margin-left: 4px; padding: 6px 12px; border-radius: 8px; color: #34495e; text-decoration: none; font-size: .93rem; }
        .nav a:hover { background: #eef2f7; }
        .nav a.on { background: #3498db; color: #fff; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 5px 15px rgba(0, 0, 0, .06); padding: 22px; margin-bottom: 20px; }
        .card h2 { font-size: 1.12rem; color: #2c3e50; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f0; }
        label { display: block; font-weight: 600; font-size: .88rem; margin: 13px 0 6px; color: #34495e; }
        input[type=text], input[type=password], textarea { width: 100%; padding: 10px 12px; border: 1px solid #dcdfe6; border-radius: 8px; font-size: .95rem; outline: none; background: #fafbfc; }
        input:focus, textarea:focus { border-color: #3498db; background: #fff; }
        textarea { min-height: 70px; resize: vertical; }
        input[type=number] { width: 100%; padding: 10px 8px; border: 1px solid #dcdfe6; border-radius: 8px; font-size: .95rem; outline: none; background: #fafbfc; }
        .btn { display: inline-block; padding: 9px 20px; border: none; border-radius: 8px; background: #3498db; color: #fff; font-size: .95rem; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #2f89c5; }
        .btn.green { background: #2ecc71; } .btn.green:hover { background: #29b765; }
        .btn.red { background: #e74c3c; } .btn.red:hover { background: #d43a2f; }
        .btn.gray { background: #95a5a6; } .btn.gray:hover { background: #82968f; }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: .92rem; }
        .flash.ok { background: #e8f8f0; color: #1e8449; border: 1px solid #bfe9d2; }
        .flash.err { background: #fdeeec; color: #c0392b; border: 1px solid #f5c6c0; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .col { flex: 2; min-width: 150px; }
        .col.wide { flex: 3; min-width: 200px; }
        .small { width: 92px; flex: none; }
        .check { display: flex; align-items: center; gap: 6px; font-weight: 400; font-size: .9rem; color: #34495e; white-space: nowrap; }
        .link-item { border: 1px solid #ececec; border-radius: 10px; padding: 14px 14px 10px; margin-bottom: 12px; background: #fafbfc; }
        .row-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
        .row-foot .lid { color: #aaa; font-size: .8rem; }
        .row-foot form { display: inline; }
        .preview { display: flex; align-items: center; gap: 10px; margin: 8px 0; font-size: .88rem; color: #666; }
        .preview img { max-width: 64px; max-height: 64px; border-radius: 8px; border: 1px solid #eee; background: #fff; }
        .empty { text-align: center; color: #999; padding: 26px 0; font-size: .95rem; }
        .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-card { background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0, 0, 0, .08); padding: 34px 30px; width: 370px; max-width: 94vw; }
        .login-card h1 { text-align: center; font-size: 1.3rem; color: #2c3e50; margin-bottom: 18px; }
        .login-card .btn { width: 100%; margin-top: 20px; }
        .tip { color: #999; font-size: .82rem; margin-top: 4px; }
        .theme-item { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border: 1px solid #ececec; border-radius: 8px; margin-bottom: 8px; font-weight: 400; cursor: pointer; background: #fafbfc; }
        .theme-item:hover { border-color: #3498db; }
        .theme-item input[type=radio] { margin-top: 4px; width: auto; }
        @media (max-width: 640px) {
            .small { width: 76px; }
            .topbar-inner { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
<?php if (!$logged): ?>
<div class="login-wrap">
    <div class="login-card">
        <h1>LumeNav · 后台登录</h1>
        <?php foreach ($flashes as $fl): ?>
        <div class="flash <?php echo $fl['err'] ? 'err' : 'ok'; ?>"><?php echo e($fl['msg']); ?></div>
        <?php endforeach; ?>
        <form method="post" action="<?php echo ADMIN_SELF; ?>" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="login">
            <label>用户名</label>
            <input type="text" name="username" required autofocus>
            <label>密码</label>
            <input type="password" name="password" required>
            <button type="submit" class="btn">登 录</button>
        </form>
    </div>
</div>
<?php else: ?>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">LumeNav 后台<?php echo !empty($set['title']) ? ' · ' . e($set['title']) : ''; ?></div>
        <nav class="nav">
            <a href="<?php echo ADMIN_SELF; ?>?p=setting" <?php echo $p === 'setting' ? 'class="on"' : ''; ?>>网站设置</a>
            <a href="<?php echo ADMIN_SELF; ?>?p=links" <?php echo $p === 'links' ? 'class="on"' : ''; ?>>导航管理</a>
            <a href="<?php echo ADMIN_SELF; ?>?p=account" <?php echo $p === 'account' ? 'class="on"' : ''; ?>>账号设置</a>
            <a href="<?php echo ADMIN_SELF; ?>?action=logout" onclick="return confirm('确定退出登录吗？');">退出</a>
        </nav>
    </div>
</header>

<div class="container">
    <?php foreach ($flashes as $fl): ?>
    <div class="flash <?php echo $fl['err'] ? 'err' : 'ok'; ?>"><?php echo e($fl['msg']); ?></div>
    <?php endforeach; ?>

<?php if ($p === 'setting'): ?>
    <div class="card">
        <h2>网站设置</h2>
        <form method="post" action="<?php echo ADMIN_SELF; ?>?p=setting" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="save_setting">

            <label>模板主题</label>
<?php if (!$themes): ?>
            <p class="tip">未在 template/ 目录下找到可用主题（需包含 index.php）。</p>
<?php else: ?>
<?php foreach ($themes as $t): ?>
            <label class="theme-item" for="theme_<?php echo e($t['dir']); ?>">
                <input type="radio" name="theme" value="<?php echo e($t['dir']); ?>" id="theme_<?php echo e($t['dir']); ?>" <?php echo $t['dir'] === $currentTheme ? 'checked' : ''; ?>>
                <span><b><?php echo e($t['theme_name'] ?: $t['dir']); ?></b><?php echo $t['author_name'] !== '' ? ' · ' . e($t['author_name']) : ''; ?><?php echo $t['theme_version'] !== '' ? ' · v' . e($t['theme_version']) : ''; ?><?php echo $t['theme_explain'] !== '' ? '<br><span class="tip">' . e($t['theme_explain']) . '</span>' : ''; ?></span>
            </label>
<?php endforeach; ?>
<?php endif; ?>

            <label>网站名（浏览器标题）</label>
            <input type="text" name="title" value="<?php echo e($set['title'] ?? ''); ?>" required>

            <label>首页大标题（Logo 下方显示）</label>
            <input type="text" name="home_title" value="<?php echo e($set['home_title'] ?? ''); ?>">

            <label>SEO 关键词（keywords，多个用英文逗号分隔）</label>
            <input type="text" name="keywords" value="<?php echo e($set['keywords'] ?? ''); ?>">

            <label>SEO 描述（description）</label>
            <textarea name="description"><?php echo e($set['description'] ?? ''); ?></textarea>

            <label>ICP 备案号（留空不显示）</label>
            <input type="text" name="icp" value="<?php echo e($set['icp'] ?? ''); ?>">

            <label>版权信息</label>
            <input type="text" name="copyright" value="<?php echo e($set['copyright'] ?? ''); ?>">

            <label>Logo 图片</label>
            <?php if (!empty($set['logo'])): ?>
            <div class="preview">
                <img src="<?php echo e(asset((string)($set['logo'] ?? ''))); ?>" alt="当前 Logo">
                <span>当前已设置，可上传替换 / 换外链 / 勾选清除</span>
            </div>
            <div class="check">
                <input type="checkbox" name="logo_clear" value="1" id="logo_clear">
                <label for="logo_clear" style="margin:0;">清除当前 Logo（清后前台不显示）</label>
            </div>
            <?php endif; ?>
            <input type="file" name="logo" accept="image/*">
            <p class="tip">本地上传支持 jpg / png / gif / webp / ico，不超过 5MB</p>
            <label>或填写 Logo 外链地址（与上传同时填写时，上传文件优先）</label>
            <input type="text" name="logo_url" value="<?php echo str_starts_with((string)($set['logo'] ?? ''), 'http') ? e($set['logo']) : ''; ?>" placeholder="https://example.com/logo.png">

            <label>背景图片</label>
            <?php if (!empty($set['background'])): ?>
            <div class="preview">
                <img src="<?php echo e(asset((string)($set['background'] ?? ''))); ?>" alt="当前背景图">
                <span>当前已设置，可上传替换 / 换外链 / 勾选清除</span>
            </div>
            <div class="check">
                <input type="checkbox" name="background_clear" value="1" id="background_clear">
                <label for="background_clear" style="margin:0;">清除当前背景图（清后显示主题默认背景，如极光光斑）</label>
            </div>
            <?php endif; ?>
            <input type="file" name="background" accept="image/*">
            <p class="tip">本地上传支持 jpg / png / gif / webp / ico，不超过 5MB；留空且无外链时首页使用纯色背景</p>
            <label>或填写背景图外链地址（与上传同时填写时，上传文件优先）</label>
            <input type="text" name="background_url" value="<?php echo str_starts_with((string)($set['background'] ?? ''), 'http') ? e($set['background']) : ''; ?>" placeholder="https://example.com/bg.jpg">

            <p style="margin-top:22px;"><button type="submit" class="btn">保存设置</button></p>
        </form>
    </div>

<?php elseif ($p === 'account'): ?>
    <div class="card">
        <h2>修改用户名</h2>
        <form method="post" action="<?php echo ADMIN_SELF; ?>?p=account">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="change_user">
            <label>当前密码（验证身份）</label>
            <input type="password" name="pwd" required>
            <label>当前用户名</label>
            <input type="text" value="<?php echo e($_SESSION['admin_name'] ?? ''); ?>" disabled>
            <label>新用户名（2~50 个字符）</label>
            <input type="text" name="new_user" value="<?php echo e($_SESSION['admin_name'] ?? ''); ?>" required>
            <p style="margin-top:22px;"><button type="submit" class="btn">修改用户名</button></p>
        </form>
    </div>

    <div class="card">
        <h2>修改密码</h2>
        <form method="post" action="<?php echo ADMIN_SELF; ?>?p=account">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="change_pass">
            <label>旧密码</label>
            <input type="password" name="old_pass" required>
            <label>新密码（至少 6 位）</label>
            <input type="password" name="new_pass" required>
            <label>确认新密码</label>
            <input type="password" name="new_pass2" required>
            <p style="margin-top:22px;"><button type="submit" class="btn">修改密码</button></p>
        </form>
    </div>

    <div class="card">
        <h2>后台地址</h2>
        <p class="tip" style="margin-bottom:12px;">
            当前地址：<?php echo '/' . e(basename(__DIR__)); ?>/ 。修改后旧地址立即失效，请牢记新地址；忘记时到文件管理器查看根目录下的子目录即可找回。
        </p>
        <form method="post" action="<?php echo ADMIN_SELF; ?>?p=account" onsubmit="return confirm('确定修改后台地址吗？修改后需用新地址访问后台。');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="rename_admin">
            <label>当前密码（验证身份）</label>
            <input type="password" name="pwd" required>
            <label>新目录名（小写字母开头，如 my-panel）</label>
            <input type="text" name="new_dir" value="<?php echo e(basename(__DIR__)); ?>" required>
            <p style="margin-top:22px;"><button type="submit" class="btn">修改后台地址</button></p>
        </form>
    </div>

<?php else: /* links */ ?>
    <div class="card">
        <h2>添加导航</h2>
        <form method="post" action="<?php echo ADMIN_SELF; ?>?p=links">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="add_link">
            <div class="row">
                <div class="col">
                    <label>名称</label>
                    <input type="text" name="name" required>
                </div>
                <div class="col wide">
                    <label>网址</label>
                    <input type="text" name="url" placeholder="https://example.com" pattern="https?://.+" title="需以 http:// 或 https:// 开头" required>
                </div>
                <div class="small">
                    <label>排序（小在前）</label>
                    <input type="number" name="sort" value="0">
                </div>
            </div>
            <div class="check" style="margin-top:12px;">
                <input type="checkbox" name="target" value="1" id="add_target" checked>
                <label for="add_target" style="margin:0;">在新窗口打开</label>
            </div>
            <p style="margin-top:14px;"><button type="submit" class="btn">添加</button></p>
        </form>
    </div>

    <div class="card">
        <h2>导航列表（<?php echo count($links); ?> 条）</h2>
<?php if (!$links): ?>
        <p class="empty">还没有导航链接，先在上方添加一条吧。</p>
<?php else: ?>
        <input type="text" id="linkSearch" placeholder="输入名称或网址筛选…" autocomplete="off">
        <?php foreach ($links as $l): ?>
        <div class="link-item">
            <form method="post" action="<?php echo ADMIN_SELF; ?>?p=links">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="save_link">
                <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
                <div class="row">
                    <div class="col">
                        <label>名称</label>
                        <input type="text" name="name" value="<?php echo e($l['name']); ?>" required>
                    </div>
                    <div class="col wide">
                        <label>网址</label>
                        <input type="text" name="url" value="<?php echo e($l['url']); ?>" pattern="https?://.+" title="需以 http:// 或 https:// 开头" required>
                    </div>
                    <div class="small">
                        <label>排序</label>
                        <input type="number" name="sort" value="<?php echo (int)$l['sort']; ?>">
                    </div>
                </div>
                <div class="check" style="margin-top:12px;">
                    <input type="checkbox" name="target" value="1" id="t<?php echo (int)$l['id']; ?>" <?php echo (int)$l['target'] === 1 ? 'checked' : ''; ?>>
                    <label for="t<?php echo (int)$l['id']; ?>" style="margin:0;">在新窗口打开</label>
                </div>
                <div class="row-foot">
                    <span class="lid">ID: <?php echo (int)$l['id']; ?></span>
                    <button type="submit" class="btn green">保存修改</button>
                </div>
            </form>
            <form method="post" action="<?php echo ADMIN_SELF; ?>?p=links" onsubmit="return confirm('确定删除该链接吗？');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="del_link">
                <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
                <button type="submit" class="btn red">删除</button>
            </form>
        </div>
        <?php endforeach; ?>
        <p class="empty" id="linkEmpty" style="display:none;">没有匹配的链接</p>
        <script>
            // 输入时按名称/网址筛选链接列表
            (function () {
                var box = document.getElementById('linkSearch');
                var items = document.querySelectorAll('.link-item');
                var empty = document.getElementById('linkEmpty');
                if (!box || !items.length) return;
                box.addEventListener('input', function () {
                    var q = this.value.trim().toLowerCase();
                    var shown = 0;
                    items.forEach(function (el) {
                        var txt = '';
                        el.querySelectorAll('input[type=text]').forEach(function (i) { txt += i.value + ' '; });
                        var hit = q === '' || txt.toLowerCase().indexOf(q) !== -1;
                        el.style.display = hit ? '' : 'none';
                        if (hit) shown++;
                    });
                    if (empty) empty.style.display = (q !== '' && !shown) ? '' : 'none';
                });
            })();
        </script>
<?php endif; ?>
    </div>
<?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
