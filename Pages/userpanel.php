<?php
/*

普通员工刷卡记录页面
Ver 1.1.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

if (empty($_SESSION['member_open_id'])) {
    exit("<script>location='/?page=login';</script>");
}

$employee = Database::querySingleLine('employee', ['open_id' => $_SESSION['member_open_id']]);
if (!$employee) {
    exit('未找到员工资料');
}

$adminUser = null;
$canEnterAdmin = false;
if (!empty($_SESSION['user'])) {
    $adminUser = Database::querySingleLine('user', ['username' => $_SESSION['user']]);
    $canEnterAdmin = $adminUser && in_array($adminUser['type'], ['admin', 'readonly'], true);
}

$cardId = $employee['card_id'] ?? '';
$keyword = memberLimitText(trim((string)($_GET['q'] ?? '')), 40);
$keyword = preg_replace('/\s+/u', ' ', $keyword);
$keyword = $keyword === null ? '' : $keyword;
$filterDate = memberNormalizeDate($_GET['date'] ?? '');
$pageNo = max(1, intval($_GET['p'] ?? 1));
$perPage = 8;
$offset = ($pageNo - 1) * $perPage;
$hasMore = false;
$rows = [];

if ($cardId !== '') {
    $card = Database::escape($cardId);
    $where = ["`cardid`='{$card}'"];
    if ($filterDate !== '') {
        $startAt = strtotime($filterDate . ' 00:00:00');
        $endAt = $startAt + 86400;
        $where[] = "`time`>=" . intval($startAt) . " AND `time`<" . intval($endAt);
    }
    if ($keyword !== '') {
        $safeKeyword = Database::escape($keyword);
        $likeKeyword = "'%" . $safeKeyword . "%'";
        $where[] = "(`passdoor` LIKE {$likeKeyword} OR `action` LIKE {$likeKeyword} OR `cardid` LIKE {$likeKeyword})";
    }
    $queryLimit = $perPage + 1;
    $sql = "SELECT `passdoor`, `cardid`, `action`, `time` FROM `logs` WHERE " . implode(' AND ', $where) . " ORDER BY `time` DESC LIMIT {$offset}, {$queryLimit}";
    $rs = Database::query('logs', $sql, '', true);
    if ($rs instanceof \mysqli_result) {
        while ($row = mysqli_fetch_assoc($rs)) {
            $rows[] = $row;
        }
        mysqli_free_result($rs);
    }
    if (count($rows) > $perPage) {
        $hasMore = true;
        array_pop($rows);
    }
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', time() - 86400);
$prevUrl = memberUserPanelUrl(['q' => $keyword, 'date' => $filterDate, 'p' => max(1, $pageNo - 1)]);
$nextUrl = memberUserPanelUrl(['q' => $keyword, 'date' => $filterDate, 'p' => $pageNo + 1]);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>两点十分门禁 ｜ 我的刷卡记录</title>
    <link href="asset/plugins/Font-awesome/css/all.min.css" rel="stylesheet" />
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f6f7f9;
            color: #172033;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif;
        }
        a { color: inherit; text-decoration: none; }
        .member-shell {
            width: min(760px, 100%);
            min-height: 100vh;
            margin: 0 auto;
            padding: 18px 14px 28px;
        }
        .member-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }
        .member-title h1 {
            margin: 0 0 6px;
            font-size: 24px;
            line-height: 1.25;
            font-weight: 650;
            letter-spacing: 0;
        }
        .member-title p {
            margin: 0;
            color: #667085;
            font-size: 14px;
            line-height: 1.6;
            word-break: break-all;
        }
        .member-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex: 0 0 auto;
        }
        .btn {
            min-height: 40px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fff;
            color: #344054;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 12px;
            font-size: 14px;
            line-height: 1;
            white-space: nowrap;
        }
        .btn-primary {
            background: #13b887;
            border-color: #13b887;
            color: #fff;
        }
        .filter-panel {
            background: #fff;
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 14px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .field {
            min-height: 44px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
        }
        .field i { color: #667085; flex: 0 0 auto; }
        .field input {
            width: 100%;
            min-width: 0;
            border: 0;
            outline: 0;
            background: transparent;
            color: #101828;
            font-size: 16px;
            line-height: 1;
        }
        .filter-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        .date-shortcuts {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-top: 10px;
            -webkit-overflow-scrolling: touch;
        }
        .date-shortcuts::-webkit-scrollbar { display: none; }
        .shortcut {
            flex: 0 0 auto;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            min-height: 34px;
            padding: 0 12px;
            display: inline-flex;
            align-items: center;
            color: #475467;
            background: #fff;
            font-size: 13px;
        }
        .shortcut.active {
            border-color: #13b887;
            color: #0f7f60;
            background: #ecfdf6;
        }
        .result-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            color: #667085;
            font-size: 13px;
            margin: 6px 2px 10px;
        }
        .record-list {
            display: grid;
            gap: 10px;
        }
        .record-card {
            background: #fff;
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            padding: 13px 14px;
        }
        .record-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .record-door {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 16px;
            font-weight: 600;
            color: #101828;
        }
        .record-door span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .record-door i { color: #13b887; flex: 0 0 auto; }
        .record-time {
            flex: 0 0 auto;
            font-size: 20px;
            line-height: 1;
            font-weight: 650;
            color: #101828;
        }
        .record-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 11px;
        }
        .meta-pill {
            min-height: 28px;
            border-radius: 8px;
            background: #f2f4f7;
            color: #475467;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 9px;
            font-size: 13px;
        }
        .action-success {
            background: #ecfdf6;
            color: #0f7f60;
        }
        .empty-state {
            background: #fff;
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            padding: 28px 18px;
            text-align: center;
            color: #667085;
        }
        .empty-state i {
            display: block;
            color: #98a2b3;
            font-size: 28px;
            margin-bottom: 10px;
        }
        .pager {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
        }
        .pager-label {
            color: #667085;
            font-size: 13px;
            text-align: center;
        }
        .pager .btn[aria-disabled="true"] {
            opacity: .45;
            pointer-events: none;
        }
        @media screen and (min-width: 720px) {
            .member-shell { padding-top: 28px; }
            .filter-grid { grid-template-columns: 1fr 190px; }
            .filter-actions { grid-template-columns: 120px 100px; justify-content: end; }
            .record-card { padding: 15px 16px; }
        }
        @media screen and (max-width: 480px) {
            .member-shell { padding-left: 12px; padding-right: 12px; }
            .member-header { flex-direction: column; }
            .member-actions { width: 100%; justify-content: flex-start; }
            .member-actions .btn { flex: 1 1 auto; }
            .record-time { font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="member-shell">
        <div class="member-header">
            <div class="member-title">
                <h1>我的刷卡记录</h1>
                <p><?php echo memberH($employee['name']); ?> · <?php echo $cardId === '' ? '未绑定门禁卡' : memberH(memberMaskCard($cardId)); ?></p>
            </div>
            <div class="member-actions">
                <?php if ($canEnterAdmin) { ?>
                    <a class="btn btn-primary" href="/?page=panel&module=home"><i class="fa-solid fa-gauge-high"></i>进入管理后台</a>
                <?php } ?>
                <a class="btn" href="?page=logout&csrf=<?php echo memberH($_SESSION['token']); ?>"><i class="fa-solid fa-right-from-bracket"></i>退出</a>
            </div>
        </div>

        <form class="filter-panel" method="GET" action="/">
            <input type="hidden" name="page" value="userpanel">
            <div class="filter-grid">
                <label class="field">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" name="q" value="<?php echo memberH($keyword); ?>" placeholder="搜索门禁、动作、卡号" autocomplete="off">
                </label>
                <label class="field">
                    <i class="fa-solid fa-calendar-day"></i>
                    <input type="date" name="date" value="<?php echo memberH($filterDate); ?>">
                </label>
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i>筛选</button>
                <a class="btn" href="/?page=userpanel"><i class="fa-solid fa-rotate-left"></i>重置</a>
            </div>
            <div class="date-shortcuts">
                <a class="shortcut <?php echo $filterDate === '' ? 'active' : ''; ?>" href="<?php echo memberH(memberUserPanelUrl(['q' => $keyword])); ?>">全部</a>
                <a class="shortcut <?php echo $filterDate === $today ? 'active' : ''; ?>" href="<?php echo memberH(memberUserPanelUrl(['q' => $keyword, 'date' => $today])); ?>">今天</a>
                <a class="shortcut <?php echo $filterDate === $yesterday ? 'active' : ''; ?>" href="<?php echo memberH(memberUserPanelUrl(['q' => $keyword, 'date' => $yesterday])); ?>">昨天</a>
            </div>
        </form>

        <div class="result-head">
            <span><?php echo $filterDate !== '' ? memberH($filterDate) : '最近记录'; ?></span>
            <span>每页最多 <?php echo intval($perPage); ?> 条</span>
        </div>

        <?php if ($cardId === '') { ?>
            <div class="empty-state">
                <i class="fa-regular fa-credit-card"></i>
                暂未绑定门禁卡
            </div>
        <?php } elseif (count($rows) === 0) { ?>
            <div class="empty-state">
                <i class="fa-regular fa-folder-open"></i>
                暂无匹配的刷卡记录
            </div>
        <?php } else { ?>
            <div class="record-list">
                <?php foreach ($rows as $row) { ?>
                    <article class="record-card">
                        <div class="record-top">
                            <div class="record-door">
                                <i class="fa-solid fa-door-open"></i>
                                <span><?php echo memberH($row['passdoor'] ?: '未知门禁'); ?></span>
                            </div>
                            <div class="record-time"><?php echo date('H:i', intval($row['time'])); ?></div>
                        </div>
                        <div class="record-meta">
                            <span class="meta-pill"><i class="fa-regular fa-calendar"></i><?php echo date('Y-m-d', intval($row['time'])); ?></span>
                            <span class="meta-pill action-success"><i class="fa-solid fa-check"></i><?php echo memberH($row['action'] ?: '刷卡'); ?></span>
                            <span class="meta-pill"><i class="fa-regular fa-credit-card"></i><?php echo memberH(memberMaskCard($row['cardid'])); ?></span>
                        </div>
                    </article>
                <?php } ?>
            </div>

            <nav class="pager" aria-label="刷卡记录分页">
                <a class="btn" href="<?php echo memberH($prevUrl); ?>" aria-disabled="<?php echo $pageNo <= 1 ? 'true' : 'false'; ?>"><i class="fa-solid fa-arrow-left"></i>上一页</a>
                <span class="pager-label">第 <?php echo intval($pageNo); ?> 页</span>
                <a class="btn" href="<?php echo memberH($nextUrl); ?>" aria-disabled="<?php echo $hasMore ? 'false' : 'true'; ?>">下一页<i class="fa-solid fa-arrow-right"></i></a>
            </nav>
        <?php } ?>
    </div>
</body>
</html>
<?php

function memberH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function memberNormalizeDate($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    $time = strtotime($value . ' 00:00:00');
    if ($time === false || date('Y-m-d', $time) !== $value) {
        return '';
    }
    return $value;
}

function memberLimitText($value, $limit)
{
    $value = trim((string)$value);
    $limit = max(1, intval($limit));
    if (preg_match_all('/./u', $value, $matches) === false) {
        return substr($value, 0, $limit);
    }
    return implode('', array_slice($matches[0], 0, $limit));
}

function memberMaskCard($cardId)
{
    $cardId = trim((string)$cardId);
    $length = strlen($cardId);
    if ($length <= 4) {
        return $cardId;
    }
    return str_repeat('*', max(0, $length - 4)) . substr($cardId, -4);
}

function memberUserPanelUrl($params = [])
{
    $query = ['page' => 'userpanel'];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        if ($key === 'p' && intval($value) <= 1) {
            continue;
        }
        $query[$key] = $value;
    }
    return '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
