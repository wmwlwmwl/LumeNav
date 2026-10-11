# LumeNav 模板开发文档

导航站主题机制说明。主题统一放在 `template/<主题名>/` 目录下，后台「网站设置 → 模板主题」可随时切换。

## 一、目录结构

```
template/
├── default/            一个主题一个目录，目录名即主题标识
│   ├── index.php       主题页面文件（必需，缺此文件的主题不会出现在后台）
│   ├── theme.ini       主题元数据（可选，缺省时后台显示目录名）
│   └── css/img/...     主题自带静态资源（可选）
└── 其他主题/
```

## 二、theme.ini 元数据（可选）

JSON 格式，全部字段均可省略：

```json
{
    "theme_name": "default",
    "author_name": "无名",
    "theme_version": "1.0",
    "theme_explain": "一句话主题说明，显示在后台主题名下方"
}
```

| 字段 | 说明 |
|---|---|
| theme_name | 后台显示的主题名，省略时显示目录名 |
| author_name | 作者名 |
| theme_version | 版本号 |
| theme_explain | 说明文字，为空不显示 |

## 三、主题命名规则

- 目录名仅允许**字母、数字、下划线、横线、中文**（白名单 `/^[\w-]+$/u`）。
- 不含 `/ \ .` 等字符，杜绝路径穿越；非法值保存时会被忽略并回退到第一个可用主题。

## 四、可用变量

根目录 `index.php` 是加载器，include 主题前会注入以下变量：

### $set —— 全部网站设置（键值数组）

| 键 | 说明 | 后台位置 |
|---|---|---|
| title | 网站名（浏览器标题） | 网站设置 |
| home_title | 首页大标题 | 网站设置 |
| keywords | SEO 关键词 | 网站设置 |
| description | SEO 描述 | 网站设置 |
| icp | ICP 备案号，可能为空 | 网站设置 |
| copyright | 版权信息 | 网站设置 |
| logo | Logo 图片地址，可能为空；本地上传相对路径（如 `uploads/up_xxx.png`）或 http(s) 外链 | 网站设置 |
| background | 背景图地址，可能为空；本地上传相对路径或 http(s) 外链 | 网站设置 |
| theme | 当前主题目录名 | 模板主题 |

### $links —— 导航链接数组

已按 `sort ASC, id ASC` 排序，每条包含：

| 键 | 说明 |
|---|---|
| name | 链接名称 |
| url | 链接地址 |
| target | 1 = 新窗口打开，0 = 当前窗口 |

### $theme —— 实际生效的主题目录名

主题被回退加载时可能与 `$set['theme']` 不同，**主题引用自身资源请一律使用 `$theme`**：

```html
<link rel="stylesheet" href="template/<?php echo e($theme); ?>/style.css">
```

### e() —— 输出转义函数

`e(?string $s): string`，等价 `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`。

## 五、安全要求（必须遵守）

**所有输出（尤其是 `$links` 的 name/url 和 `$set` 的内容）必须经 `e()` 转义后再输出**，防止 XSS。参考写法：

```php
<a href="<?php echo e($link['url']); ?>"<?php echo ((int)$link['target'] === 1) ? ' target="_blank"' : ''; ?>><?php echo e($link['name']); ?></a>
```

## 六、静态资源引用

主题自带的 CSS / 图片直接放主题目录内（如 `style.css`、`css/`、`img/`），通过 `$theme` 拼相对路径引用（无需路由）。建议像 default 主题一样：**样式全部放独立 CSS 文件，HTML 只留结构**，浏览器可缓存样式、页面体积更小。更新 CSS 后记得给引用加个版本号（如 `style.css?v=2`）防止浏览器用旧缓存：

```html
<link rel="stylesheet" href="template/<?php echo e($theme); ?>/style.css">
```

## 七、最小主题示例

新建 `template/demo/index.php` 即可成为一个可用主题：

```php
<?php
/**
 * 主题：示例
 *
 * 可用变量（由根目录 index.php 加载器注入）：
 *   $set   —— 全部网站设置
 *   $links —— 导航链接数组（name / url / target）
 *   e()    —— HTML 输出转义函数
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($set['title'] ?? ''); ?></title>
    <meta name="keywords" content="<?php echo e($set['keywords'] ?? ''); ?>">
    <meta name="description" content="<?php echo e($set['description'] ?? ''); ?>">
</head>
<body>
    <h1><?php echo e($set['home_title'] ?? ''); ?></h1>
    <ul>
        <?php foreach ($links as $link): ?>
        <li><a href="<?php echo e($link['url']); ?>"<?php echo ((int)$link['target'] === 1) ? ' target="_blank"' : ''; ?>><?php echo e($link['name']); ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php if (!empty($set['icp'])): ?>
    <p><a href="https://beian.miit.gov.cn/" target="_blank"><?php echo e($set['icp']); ?></a></p>
    <?php endif; ?>
    <p><?php echo e($set['copyright'] ?? ''); ?></p>
</body>
</html>
```

保存后到后台「网站设置 → 模板主题」刷新即可看到并切换。

## 八、加载与回退机制

1. 加载器读取设置 `theme` → 消毒后拼路径 `template/<主题名>/index.php`；
2. 文件不存在 → 回退到 `template/` 下第一个包含 `index.php` 的主题；
3. 一个可用主题都没有 → 显示「未找到可用模板」错误页；
4. 切换主题立即生效，无需重启或清缓存。
