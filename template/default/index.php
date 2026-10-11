<?php
/**
 * 主题：default（默认导航主题）
 *
 * 可用变量（由根目录 index.php 加载器注入）：
 *   $set   —— 全部网站设置（title / keywords / description / home_title / icp / copyright / logo / background / theme）
 *   $links —— 导航链接数组（name / url / target），已按 sort, id 排序
 *   $theme —— 实际生效的主题目录名（回退时可能与 $set['theme'] 不同，引用主题资源请用它）
 *   e()    —— HTML 输出转义函数
 */
$bg = trim((string)($set['background'] ?? ''));
$logo = trim((string)($set['logo'] ?? ''));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($set['title'] ?? ''); ?></title>
    <meta name="keywords" content="<?php echo e($set['keywords'] ?? ''); ?>">
    <meta name="description" content="<?php echo e($set['description'] ?? ''); ?>">
<?php if ($logo !== ''): ?>
    <link rel="icon" href="<?php echo e($logo); ?>" type="image/x-icon">
<?php endif; ?>
    <link rel="stylesheet" href="template/<?php echo e($theme); ?>/style.css?v=4">
</head>
<body<?php if ($bg !== ''): ?> style="--bg: url('<?php echo e($bg); ?>')"<?php endif; ?>>
    <!-- 背景图区域 -->
    <div id="bg"></div>

    <!-- 背景覆盖层 -->
    <div id="bg-overlay"></div>

    <!-- 玻璃态容器 -->
    <div class="glass-container">
        <header class="header">
<?php if ($logo !== ''): ?>
            <div class="logo-container">
                <img src="<?php echo e($logo); ?>" class="logo" alt="Logo">
            </div>
<?php endif; ?>

            <h1 class="home-title"><?php echo e($set['home_title'] ?? ''); ?></h1>
        </header>

        <main class="main">
            <div class="section-container">
                <h2 class="section-title">快捷导航</h2>
            </div>

            <ul class="nav-buttons">
                <?php foreach ($links as $i => $link): ?>
                <?php // 逐项错开入场动画；ponytail: 线性步进 0.1s 封顶 1s，链接极多时如需更平滑可改用 IntersectionObserver 懒入场 ?>
                <li style="animation-delay: <?php echo min(($i + 1) * 0.1, 1); ?>s;"><a href="<?php echo e($link['url']); ?>"<?php echo ((int)$link['target'] === 1) ? ' target="_blank"' : ''; ?>><?php echo e($link['name']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </main>

        <footer class="footer">
            <div class="footer-content">
                <?php if (!empty($set['icp'])): ?>
                <p><a href="https://beian.miit.gov.cn/" class="icp nav-link" target="_blank"><?php echo e($set['icp']); ?></a></p>
                <?php endif; ?>
                <p class="copyright"><?php echo e($set['copyright'] ?? ''); ?></p>
            </div>
        </footer>
    </div>
</body>
</html>
