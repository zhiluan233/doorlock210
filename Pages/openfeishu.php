<?php
/*

飞书工牌 NDEF 拉起入口
Ver 1.0.0.0 20260714
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

$rawUid = openfeishuNormalizeUid($_GET['cardid'] ?? '');
$cardId = $rawUid !== '' ? AttendanceService::uidToWiegand34Card($rawUid) : '';
$targetPath = $rawUid !== '' ? '/?page=badgecard&cardid=' . rawurlencode($rawUid) : '/?page=badgecard';
$appPath = $rawUid !== '' ? '/badgecard/' . rawurlencode($rawUid) : '/badgecard';
$targetUrl = openfeishuAbsoluteUrl($targetPath);

if ($rawUid !== '') {
    $_SESSION['badge_lookup_card_uid'] = $rawUid;
    $_SESSION['badge_lookup_card_id'] = $cardId;
    $_SESSION['badge_lookup_return'] = $targetPath;
}

if (openfeishuIsFeishuClient() && $rawUid !== '') {
    Header('Location: ' . $targetPath);
    exit;
}

$appId = Settings::get('feishu_app_id', '');
$appLink = $rawUid !== '' ? openfeishuBuildAppLink($targetPath, $targetUrl, $appPath, $rawUid, $cardId, $appId) : '';
$canOpenApp = $appLink !== '' && $appId !== '';

Header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>正在打开飞书门禁</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #f6f7f9;
            color: #172033;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif;
        }
        .shell {
            width: min(430px, 100%);
            min-height: 100vh;
            margin: 0 auto;
            padding: 22px 18px 28px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .panel {
            width: 100%;
            background: #fff;
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            padding: 26px 22px;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .08);
        }
        .mark {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: #ecfdf6;
            color: #13b887;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 18px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 22px;
            line-height: 1.3;
            letter-spacing: 0;
        }
        p {
            margin: 0;
            color: #667085;
            font-size: 14px;
            line-height: 1.75;
        }
        .meta {
            margin-top: 16px;
            padding: 12px;
            border-radius: 8px;
            background: #f8fafc;
            color: #475467;
            font-size: 13px;
            line-height: 1.8;
            word-break: break-all;
        }
        .actions {
            display: none;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 20px;
        }
        body.show-actions .actions { display: grid; }
        .btn {
            min-height: 44px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fff;
            color: #344054;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 14px;
            font-size: 15px;
            text-decoration: none;
        }
        .btn-primary {
            background: #13b887;
            border-color: #13b887;
            color: #fff;
        }
        .hint { margin-top: 14px; font-size: 12px; color: #98a2b3; }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel">
            <div class="mark">卡</div>
            <?php if ($rawUid === '') { ?>
                <h1>工牌链接无效</h1>
                <p>NDEF 链接缺少有效的卡片 UID。</p>
            <?php } else { ?>
                <h1><?php echo $canOpenApp ? '正在打开飞书门禁' : '需要配置飞书应用链接'; ?></h1>
                <p><?php echo $canOpenApp ? '请稍候，系统正在尝试拉起飞书并进入工牌查询页。' : '请在 config.php 中配置 feishu.appId 和 feishu.badgeLookup.appLinkTemplate。'; ?></p>
                <div class="meta">
                    UID：<?php echo openfeishuH($rawUid); ?><br>
                    WG34：<?php echo $cardId !== '' ? openfeishuH($cardId) : '无法转换'; ?>
                </div>
                <div class="actions">
                    <?php if ($canOpenApp) { ?>
                        <a class="btn btn-primary" href="<?php echo openfeishuH($appLink); ?>">在飞书中打开</a>
                    <?php } ?>
                    <a class="btn" href="<?php echo openfeishuH($targetPath); ?>">继续在浏览器中查看</a>
                </div>
                <p class="hint">如果未自动跳转，请点击上方按钮。iOS 只有飞书官方 AppLink/Universal Link 可做到无感拉起。</p>
            <?php } ?>
        </section>
    </main>
    <?php if ($canOpenApp) { ?>
        <script>
            (function() {
                var appLink = <?php echo json_encode($appLink, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                setTimeout(function() {
                    window.location.href = appLink;
                }, 80);
                setTimeout(function() {
                    document.body.className = 'show-actions';
                }, 1200);
            })();
        </script>
    <?php } else { ?>
        <script>document.body.className = 'show-actions';</script>
    <?php } ?>
</body>
</html>
<?php

function openfeishuNormalizeUid($value)
{
    return AttendanceService::normalizeUidValue($value);
}

function openfeishuBuildAppLink($targetPath, $targetUrl, $appPath, $rawUid, $cardId, $appId)
{
    global $_config;

    $template = $_config['feishu']['badgeLookup']['appLinkTemplate'] ?? '';
    if ($template === '') {
        $template = 'https://applink.feishu.cn/client/web_app/open?appId={appId}&path={pathRaw}';
    }

    $replacements = [
        '{appId}' => rawurlencode($appId),
        '{path}' => $appPath,
        '{pathRaw}' => $appPath,
        '{pathEncoded}' => rawurlencode($appPath),
        '{browserPath}' => rawurlencode($targetPath),
        '{browserPathRaw}' => $targetPath,
        '{url}' => rawurlencode($targetUrl),
        '{urlRaw}' => $targetUrl,
        '{cardid}' => rawurlencode($rawUid),
        '{cardId}' => rawurlencode($cardId)
    ];

    return strtr($template, $replacements);
}

function openfeishuAbsoluteUrl($path)
{
    $scheme = Utils::isHttps() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    return $scheme . $host . $path;
}

function openfeishuIsFeishuClient()
{
    return preg_match('/Feishu|Lark/i', $_SERVER['HTTP_USER_AGENT'] ?? '') === 1;
}

function openfeishuH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
