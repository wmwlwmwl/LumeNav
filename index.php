<?php
/**
 * 导航站前台 —— 主题加载器
 *
 * 主题机制：template/<主题名>/index.php 为主题页面，theme.ini 为元数据。
 * 加载器向主题注入变量：$set（全部设置）、$links（导航链接数组），主题内可用 e() 转义。
 */
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}
try {
    require __DIR__ . '/config.php';
    $set = settings();
    $links = db()->query('SELECT name, url, target FROM nav_links ORDER BY sort ASC, id ASC')->fetchAll();
} catch (Throwable $ex) {
    http_response_code(500);
    exit('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>出错了</title></head><body style="font-family:sans-serif;text-align:center;padding-top:80px;color:#333;"><h2>数据库连接失败</h2><p style="margin-top:12px;">请检查 config.php 中的数据库配置，或确认 MySQL 服务正在运行。</p></body></html>');
}

// 确定主题：设置值优先，非法或缺失时回退到第一个可用主题
$theme = preg_replace('/[^\w-]/u', '', (string)($set['theme'] ?? ''));
$themeFile = ($theme !== '') ? __DIR__ . '/template/' . $theme . '/index.php' : '';
if ($themeFile === '' || !is_file($themeFile)) {
    foreach (glob(__DIR__ . '/template/*/index.php') ?: [] as $file) {
        $themeFile = $file;
        $theme = basename(dirname($file)); // 同步主题目录名，供主题引用自身资源
        break;
    }
}
if ($themeFile === '' || !is_file($themeFile)) {
    http_response_code(500);
    exit('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>出错了</title></head><body style="font-family:sans-serif;text-align:center;padding-top:80px;color:#333;"><h2>未找到可用模板</h2><p style="margin-top:12px;">请将主题放到 template/ 目录下（需包含 index.php），或在后台重新选择主题。</p></body></html>');
}

include $themeFile;
