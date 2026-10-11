<?php
/**
 * 主题：aurora（极光 · 深色玻璃导航）
 *
 * 可用变量（由根目录 index.php 加载器注入）：
 *   $set   —— 全部网站设置
 *   $links —— 导航链接数组（name / url / target），已按 sort, id 排序
 *   $theme —— 实际生效的主题目录名
 *   e()    —— HTML 输出转义函数
 */
$bg = trim((string)($set['background'] ?? ''));
$logo = trim((string)($set['logo'] ?? ''));

/** 取链接域名（去 www.），用于卡片副标题与图标取色 */
function aurora_host(string $url): string
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        $host = (string)preg_replace('#^https?://#i', '', trim($url));
        $host = (string)preg_split('#[/?#]#', $host)[0];
    }
    return preg_replace('/^www\./', '', $host);
}
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
    <link rel="stylesheet" href="template/<?php echo e($theme); ?>/style.css?v=5">
</head>
<body>
    <!-- 背景：上传/外链背景图优先，否则显示纯 CSS 极光光斑 -->
<?php if ($bg !== ''): ?>
    <div class="aurora-photo" style="background-image: url('<?php echo e($bg); ?>')"></div>
<?php else: ?>
    <div class="aurora-sky">
        <i class="blob b1"></i><i class="blob b2"></i><i class="blob b3"></i>
    </div>
<?php endif; ?>

    <div class="page">
        <header class="hero">
<?php if ($logo !== ''): ?>
            <img class="hero-logo" src="<?php echo e($logo); ?>" alt="Logo">
<?php endif; ?>
            <h1 class="hero-title"><?php echo e($set['home_title'] ?? ''); ?></h1>

            <form class="search" action="https://www.baidu.com/s" method="get" target="_blank">
                <div class="search-row">
                    <input type="text" name="wd" placeholder="搜一下，回车直达…" autocomplete="off">
                    <button type="submit">搜索</button>
                </div>
                <div class="engines">
                    <button type="button" class="eng on" data-url="https://www.baidu.com/s" data-q="wd">百度</button>
                    <button type="button" class="eng" data-url="https://www.bing.com/search" data-q="q">必应</button>
                    <button type="button" class="eng" data-url="https://www.google.com/search" data-q="q">谷歌</button>
                    <button type="button" class="eng" data-url="https://www.sogou.com/web" data-q="query">搜狗</button>
                    <button type="button" class="eng" data-url="https://www.so.com/s" data-q="q">360</button>
                </div>
            </form>
        </header>

        <main>
<?php if (!$links): ?>
            <p class="empty">还没有导航链接，去后台「导航管理」添加吧。</p>
<?php else: ?>
            <ul class="cards">
                <?php foreach ($links as $i => $link): ?>
                <?php
                    $host = aurora_host((string)$link['url']);
                    $hue = sprintf('%u', crc32($host)) % 360;
                ?>
                <li style="animation-delay: <?php echo min($i * 0.06, 0.9); ?>s;">
                    <a class="card" href="<?php echo e($link['url']); ?>"<?php echo ((int)$link['target'] === 1) ? ' target="_blank"' : ''; ?>>
                        <span class="ico" style="--h: <?php echo $hue; ?>;"><?php echo e(mb_substr((string)$link['name'], 0, 1)); ?></span>
                        <span class="txt">
                            <span class="name"><?php echo e($link['name']); ?></span>
                            <span class="host"><?php echo e($host); ?></span>
                        </span>
                        <span class="go">&rsaquo;</span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
<?php endif; ?>
        </main>

        <footer class="foot">
            <?php if (!empty($set['icp'])): ?>
            <p><a href="https://beian.miit.gov.cn/" target="_blank"><?php echo e($set['icp']); ?></a></p>
            <?php endif; ?>
            <p class="cp"><?php echo e($set['copyright'] ?? ''); ?></p>
        </footer>
    </div>

    <script>
        // 搜索引擎切换：更新表单提交地址与关键词参数名
        document.querySelectorAll('.search .eng').forEach(function (b) {
            b.addEventListener('click', function () {
                var f = this.closest('form');
                f.querySelectorAll('.eng').forEach(function (x) { x.classList.remove('on'); });
                this.classList.add('on');
                f.action = this.dataset.url;
                f.querySelector('input[type=text]').name = this.dataset.q;
            });
        });
    </script>
</body>
</html>
