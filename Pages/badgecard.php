<?php
/*

手机端工牌查询页面
Ver 1.0.0.0 20260714
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

$rawUid = badgeNormalizeUid($_GET['cardid'] ?? ($_SESSION['badge_lookup_card_uid'] ?? ''));
$cardId = $rawUid !== '' ? AttendanceService::uidToWiegand34Card($rawUid) : '';
$returnPath = $rawUid !== '' ? '/?page=badgecard&cardid=' . rawurlencode($rawUid) : '/?page=badgecard';

if ($rawUid !== '') {
    $_SESSION['badge_lookup_card_uid'] = $rawUid;
    $_SESSION['badge_lookup_card_id'] = $cardId;
    $_SESSION['badge_lookup_return'] = $returnPath;
}

if ($rawUid === '' || $cardId === '') {
    badgeRenderPage([
        'mode' => 'invalid',
        'title' => '工牌无法识别',
        'subtitle' => 'NDEF 链接中的 UID 无效，无法转换为门禁工牌号。',
        'raw_uid' => $rawUid,
        'card_id' => $cardId
    ]);
}

$adminUser = null;
$isAdmin = false;
if (!empty($_SESSION['user'])) {
    $adminUser = Database::querySingleLine('user', ['username' => $_SESSION['user']]);
    $isAdmin = $adminUser && ($adminUser['type'] ?? '') === 'admin';
}

if (empty($_SESSION['member_open_id']) && !$isAdmin) {
    $_SESSION['feishu_oauth_return_url'] = $returnPath;
    Header('Location: /?page=feishu_oauth_start&redirect=' . rawurlencode($returnPath));
    exit;
}

$currentEmployee = null;
if (!empty($_SESSION['member_open_id'])) {
    $currentEmployee = Database::querySingleLine('employee', ['open_id' => $_SESSION['member_open_id']]);
}
if (!$currentEmployee && !$isAdmin) {
    badgeRenderPage([
        'mode' => 'invalid',
        'title' => '未找到员工资料',
        'subtitle' => '请先同步飞书通讯录，或联系门禁管理员处理。',
        'raw_uid' => $rawUid,
        'card_id' => $cardId
    ]);
}

$assignedEmployee = Database::querySingleLine('employee', ['card_id' => $cardId]);
$assignedLearner = Database::querySingleLine('learner', ['card_id' => $cardId]);
$assignedGuest = Database::querySingleLine('guest', ['card_id' => $cardId]);
$isOwnBadge = $assignedEmployee && ($assignedEmployee['open_id'] ?? '') === ($_SESSION['member_open_id'] ?? '');
$lostFoundUrl = trim((string)($_config['feishu']['badgeLookup']['lostFoundUrl'] ?? ''));

if ($isAdmin) {
    if ($assignedEmployee) {
        badgeRenderPage([
            'mode' => 'admin_employee',
            'title' => '员工工牌',
            'subtitle' => '当前工牌已绑定员工',
            'raw_uid' => $rawUid,
            'card_id' => $cardId,
            'person' => $assignedEmployee,
            'csrf' => $_SESSION['token'] ?? ''
        ]);
    }
    if ($assignedGuest) {
        badgeRenderPage([
            'mode' => 'admin_guest',
            'title' => '访客工牌',
            'subtitle' => '当前工牌已绑定访客',
            'raw_uid' => $rawUid,
            'card_id' => $cardId,
            'person' => $assignedGuest,
            'csrf' => $_SESSION['token'] ?? ''
        ]);
    }
    if ($assignedLearner) {
        badgeRenderPage([
            'mode' => 'admin_learner',
            'title' => '学员工牌',
            'subtitle' => '当前工牌已绑定学员',
            'raw_uid' => $rawUid,
            'card_id' => $cardId,
            'person' => $assignedLearner,
            'csrf' => $_SESSION['token'] ?? ''
        ]);
    }
    badgeRenderPage([
        'mode' => 'admin_empty',
        'title' => '空置工牌',
        'subtitle' => '当前工牌尚未绑定员工、学员或访客',
        'raw_uid' => $rawUid,
        'card_id' => $cardId,
        'person' => null,
        'csrf' => $_SESSION['token'] ?? ''
    ]);
}

if ($isOwnBadge) {
    badgeRenderPage([
        'mode' => 'member_own',
        'title' => '我的工牌',
        'subtitle' => '这张工牌已绑定到你的门禁账户',
        'raw_uid' => $rawUid,
        'card_id' => $cardId,
        'person' => $currentEmployee,
        'csrf' => $_SESSION['token'] ?? ''
    ]);
}

badgeRenderPage([
    'mode' => 'member_mismatch',
    'title' => '不是你的工牌',
    'subtitle' => '当前登录账户与这张工牌不匹配',
    'raw_uid' => $rawUid,
    'card_id' => $cardId,
    'person' => $currentEmployee,
    'csrf' => $_SESSION['token'] ?? '',
    'lost_found_url' => $lostFoundUrl
]);

function badgeRenderPage($data)
{
    $mode = $data['mode'] ?? 'invalid';
    $person = is_array($data['person'] ?? null) ? $data['person'] : null;
    $cardId = (string)($data['card_id'] ?? '');
    $rawUid = (string)($data['raw_uid'] ?? '');
    $csrf = (string)($data['csrf'] ?? '');
    $avatarUrl = $person ? badgeAvatarUrl($person) : '';
    $initial = $person ? badgeInitial($person['name'] ?? '') : '卡';
    $name = $person['name'] ?? ($mode === 'admin_empty' ? '空置工牌' : '工牌');
    $employeeNo = $person['employee_id'] ?? '';
    $studentNo = $person['student_no'] ?? '';
    $mobile = $person['mobile'] ?? '';
    $className = $person['class_name'] ?? '';
    $trainingCenter = $person['training_center'] ?? '';
    $department = $person['department_name'] ?? '';
    $realname = $person['realname'] ?? '';
    $isAdminMode = strpos($mode, 'admin_') === 0;
    $isMismatch = $mode === 'member_mismatch';
    $isInvalid = $mode === 'invalid';

    Header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>两点十分门禁 ｜ 工牌查询</title>
    <link href="asset/plugins/Font-awesome/css/all.min.css" rel="stylesheet" />
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #f6f7f9;
            color: #172033;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif;
        }
        a { color: inherit; text-decoration: none; }
        .shell {
            width: min(520px, 100%);
            min-height: 100vh;
            margin: 0 auto;
            padding: 18px 14px 26px;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 36px;
            color: #667085;
            font-size: 13px;
            margin-bottom: 12px;
        }
        .badge-card {
            flex: 1;
            background: #fff;
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            padding: 22px 18px 18px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .07);
        }
        .status {
            align-self: flex-start;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 8px;
            background: #ecfdf6;
            color: #0f7f60;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            margin-bottom: 18px;
        }
        .status.warn { background: #fff7ed; color: #b45309; }
        .status.danger { background: #fef2f2; color: #b42318; }
        .avatar {
            width: 104px;
            height: 104px;
            border-radius: 50%;
            margin: 4px auto 16px;
            background: #ecfdf6;
            color: #13b887;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-size: 34px;
            font-weight: 700;
            border: 4px solid #f2f4f7;
        }
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        h1 {
            margin: 0;
            text-align: center;
            font-size: 25px;
            line-height: 1.25;
            letter-spacing: 0;
        }
        .subtitle {
            margin: 8px 0 18px;
            text-align: center;
            color: #667085;
            font-size: 14px;
            line-height: 1.65;
        }
        .info-list {
            display: grid;
            gap: 10px;
            margin: 4px 0 18px;
        }
        .info-row {
            min-height: 44px;
            border-radius: 8px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            font-size: 14px;
        }
        .info-row span:first-child {
            color: #667085;
            flex: 0 0 auto;
        }
        .info-row span:last-child {
            color: #101828;
            text-align: right;
            min-width: 0;
            word-break: break-all;
        }
        .actions {
            display: grid;
            gap: 10px;
            margin-top: auto;
        }
        .btn {
            min-height: 46px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fff;
            color: #344054;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 14px;
            font-size: 15px;
            line-height: 1;
            width: 100%;
        }
        .btn-primary {
            background: #13b887;
            border-color: #13b887;
            color: #fff;
        }
        .btn-danger {
            background: #f04438;
            border-color: #f04438;
            color: #fff;
        }
        .btn[disabled],
        .btn.disabled {
            opacity: .45;
            pointer-events: none;
        }
        .sheet-mask {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .36);
            display: none;
            align-items: flex-end;
            justify-content: center;
            padding: 14px;
            z-index: 1000;
        }
        .sheet-mask.show { display: flex; }
        .sheet {
            width: min(520px, 100%);
            max-height: min(78vh, 640px);
            background: #fff;
            border-radius: 8px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, .18);
        }
        .sheet-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .sheet-head h2 {
            margin: 0;
            font-size: 18px;
            line-height: 1.35;
        }
        .icon-btn {
            width: 38px;
            height: 38px;
            border: 0;
            border-radius: 8px;
            background: #f2f4f7;
            color: #475467;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .search-field {
            min-height: 44px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
        }
        .search-field input {
            width: 100%;
            min-width: 0;
            border: 0;
            outline: 0;
            font-size: 16px;
            background: transparent;
        }
        .assign-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .assign-tab {
            min-height: 38px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fff;
            color: #344054;
            font-size: 14px;
        }
        .assign-tab.active {
            background: #13b887;
            border-color: #13b887;
            color: #fff;
        }
        .result-list {
            overflow: auto;
            display: grid;
            gap: 8px;
            -webkit-overflow-scrolling: touch;
        }
        .person-option {
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            background: #fff;
            padding: 11px 12px;
            text-align: left;
        }
        .person-option.selected {
            border-color: #13b887;
            background: #ecfdf6;
        }
        .person-option strong {
            display: block;
            font-size: 15px;
            color: #101828;
            margin-bottom: 5px;
        }
        .person-option span {
            display: block;
            color: #667085;
            font-size: 13px;
            line-height: 1.45;
        }
        .sheet-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .toast {
            display: none;
            position: fixed;
            left: 50%;
            bottom: 18px;
            transform: translateX(-50%);
            max-width: calc(100vw - 28px);
            border-radius: 8px;
            background: #101828;
            color: #fff;
            padding: 11px 13px;
            font-size: 14px;
            line-height: 1.45;
            z-index: 1200;
        }
        .toast.show { display: block; }
        @media screen and (min-width: 520px) {
            .shell { padding-top: 28px; padding-bottom: 34px; }
            .badge-card { padding: 26px 24px 22px; }
            .sheet-mask { align-items: center; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <div class="topbar">
            <span>两点十分门禁</span>
            <span><?php echo badgeH($rawUid !== '' ? 'UID ' . $rawUid : 'NDEF 工牌'); ?></span>
        </div>
        <section class="badge-card">
            <?php if ($isInvalid) { ?>
                <div class="status danger"><i class="fa-solid fa-triangle-exclamation"></i>无法识别</div>
            <?php } elseif ($mode === 'admin_empty') { ?>
                <div class="status warn"><i class="fa-regular fa-credit-card"></i>空置</div>
            <?php } elseif ($isMismatch) { ?>
                <div class="status danger"><i class="fa-solid fa-circle-exclamation"></i>不匹配</div>
            <?php } else { ?>
                <div class="status"><i class="fa-solid fa-check"></i>已识别</div>
            <?php } ?>

            <div class="avatar">
                <?php if ($avatarUrl !== '') { ?>
                    <img src="<?php echo badgeH($avatarUrl); ?>" alt="">
                <?php } else { ?>
                    <?php echo badgeH($initial); ?>
                <?php } ?>
            </div>

            <h1><?php echo badgeH($isMismatch ? '不是你的工牌' : $name); ?></h1>
            <p class="subtitle"><?php echo badgeH($data['subtitle'] ?? ''); ?></p>

            <div class="info-list">
                <div class="info-row"><span>工牌号</span><span><?php echo badgeH($cardId !== '' ? $cardId : '无法转换'); ?></span></div>
                <div class="info-row"><span>卡片 UID</span><span><?php echo badgeH($rawUid !== '' ? $rawUid : '--'); ?></span></div>
                <?php if ($person && !$isMismatch) { ?>
                    <?php if ($employeeNo !== '') { ?><div class="info-row"><span>工号</span><span><?php echo badgeH($employeeNo); ?></span></div><?php } ?>
                    <?php if ($studentNo !== '') { ?><div class="info-row"><span>学号</span><span><?php echo badgeH($studentNo); ?></span></div><?php } ?>
                    <?php if ($realname !== '' && $realname !== '--') { ?><div class="info-row"><span>真实姓名</span><span><?php echo badgeH($realname); ?></span></div><?php } ?>
                    <?php if ($mobile !== '') { ?><div class="info-row"><span>手机号</span><span><?php echo badgeH($mobile); ?></span></div><?php } ?>
                    <?php if ($className !== '') { ?><div class="info-row"><span>班级</span><span><?php echo badgeH($className); ?></span></div><?php } ?>
                    <?php if ($trainingCenter !== '') { ?><div class="info-row"><span>培养中心</span><span><?php echo badgeH($trainingCenter); ?></span></div><?php } ?>
                    <?php if ($department !== '') { ?><div class="info-row"><span>部门</span><span><?php echo badgeH($department); ?></span></div><?php } ?>
                <?php } elseif ($person && $isMismatch) { ?>
                    <div class="info-row"><span>当前账号</span><span><?php echo badgeH($person['name'] ?? ''); ?></span></div>
                    <?php if (($person['employee_id'] ?? '') !== '') { ?><div class="info-row"><span>当前工号</span><span><?php echo badgeH($person['employee_id']); ?></span></div><?php } ?>
                <?php } ?>
            </div>

            <div class="actions">
                <?php if ($mode === 'admin_employee' || $mode === 'admin_learner' || $mode === 'admin_guest') { ?>
                    <button class="btn btn-danger" type="button" onclick="releaseBadge()"><i class="fa-solid fa-rotate-left"></i>回收该工牌</button>
                    <a class="btn" href="/?page=panel&module=<?php echo $mode === 'admin_learner' ? 'learner' : 'submitcard'; ?>"><i class="fa-solid fa-list"></i>返回<?php echo $mode === 'admin_learner' ? '学员管理' : '发卡管理'; ?></a>
                <?php } elseif ($mode === 'admin_empty') { ?>
                    <button class="btn btn-primary" type="button" onclick="openAssignSheet()"><i class="fa-solid fa-id-card"></i>发工牌</button>
                    <a class="btn" href="/?page=panel&module=submitcard"><i class="fa-solid fa-list"></i>返回发卡管理</a>
                <?php } elseif ($mode === 'member_own') { ?>
                    <a class="btn btn-primary" href="/?page=userpanel"><i class="fa-solid fa-clock-rotate-left"></i>查看工牌打卡记录</a>
                    <a class="btn" href="/?page=logout&csrf=<?php echo badgeH($csrf); ?>"><i class="fa-solid fa-right-from-bracket"></i>退出</a>
                <?php } elseif ($mode === 'member_mismatch') { ?>
                    <a class="btn" href="/?page=logout&csrf=<?php echo badgeH($csrf); ?>"><i class="fa-solid fa-right-from-bracket"></i>退出</a>
                    <?php if (!empty($data['lost_found_url'])) { ?>
                        <a class="btn btn-primary" href="<?php echo badgeH($data['lost_found_url']); ?>"><i class="fa-solid fa-hand-holding-heart"></i>捡到了别人的工牌？</a>
                    <?php } else { ?>
                        <button class="btn btn-primary disabled" type="button" disabled><i class="fa-solid fa-hand-holding-heart"></i>捡到了别人的工牌？</button>
                    <?php } ?>
                <?php } else { ?>
                    <a class="btn" href="/?page=login"><i class="fa-solid fa-arrow-left"></i>返回登录</a>
                <?php } ?>
            </div>
        </section>
    </main>

    <?php if ($mode === 'admin_empty') { ?>
        <div class="sheet-mask" id="assignSheet" aria-hidden="true">
            <section class="sheet">
                <div class="sheet-head">
                    <h2>选择人员发工牌</h2>
                    <button class="icon-btn" type="button" onclick="closeAssignSheet()" aria-label="关闭"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="assign-tabs" role="tablist" aria-label="发卡对象">
                    <button class="assign-tab active" type="button" data-kind="employee" onclick="switchAssignKind('employee')">员工</button>
                    <button class="assign-tab" type="button" data-kind="learner" onclick="switchAssignKind('learner')">学员</button>
                </div>
                <label class="search-field">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input id="personSearch" type="search" placeholder="搜索花名、拼音、工号、部门" autocomplete="off">
                </label>
                <div class="result-list" id="personResults"></div>
                <div class="sheet-actions">
                    <button class="btn btn-primary" id="assignButton" type="button" onclick="assignBadge()" disabled>保存发卡</button>
                </div>
            </section>
        </div>
    <?php } ?>
    <div class="toast" id="toast"></div>

    <?php if ($mode === 'admin_empty') { ?>
        <script src="https://cdn.jsdelivr.net/npm/pinyin-pro@3.27.0/dist/index.min.js"></script>
    <?php } ?>
    <script>
        var BADGE_CARD_ID = <?php echo json_encode($cardId, JSON_UNESCAPED_UNICODE); ?>;
        var BADGE_CSRF = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
        var assignKind = 'employee';
        var selectedPerson = null;
        var searchTimer = null;
        var personSearchCache = {employee: null, learner: null};
        var personSearchPromise = {employee: null, learner: null};

        function toast(message) {
            var el = document.getElementById('toast');
            if (!el) {
                alert(message);
                return;
            }
            el.textContent = message;
            el.className = 'toast show';
            clearTimeout(el._timer);
            el._timer = setTimeout(function() {
                el.className = 'toast';
            }, 2200);
        }

        function postAction(action, data) {
            var body = new URLSearchParams();
            Object.keys(data || {}).forEach(function(key) {
                body.append(key, data[key]);
            });
            return fetch('/?action=' + encodeURIComponent(action) + '&page=badgecard&csrf=' + encodeURIComponent(BADGE_CSRF), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            }).then(function(resp) {
                return resp.text().then(function(text) {
                    if (!resp.ok) {
                        throw new Error(text || '请求失败');
                    }
                    return text;
                });
            });
        }

        function releaseBadge() {
            if (!confirm('确定回收该工牌？')) {
                return;
            }
            postAction('releasecard', {cardid: BADGE_CARD_ID}).then(function(message) {
                toast(message || '工牌已回收');
                setTimeout(function() { location.reload(); }, 700);
            }).catch(function(error) {
                toast(error.message || '回收失败');
            });
        }

        function openAssignSheet() {
            var sheet = document.getElementById('assignSheet');
            if (!sheet) {
                return;
            }
            sheet.className = 'sheet-mask show';
            sheet.setAttribute('aria-hidden', 'false');
            setTimeout(function() {
                var input = document.getElementById('personSearch');
                if (input) {
                    input.focus();
                    searchPeople('');
                }
            }, 80);
        }

        function closeAssignSheet() {
            var sheet = document.getElementById('assignSheet');
            if (!sheet) {
                return;
            }
            sheet.className = 'sheet-mask';
            sheet.setAttribute('aria-hidden', 'true');
        }

        function switchAssignKind(kind) {
            assignKind = kind === 'learner' ? 'learner' : 'employee';
            selectedPerson = null;
            var assignButton = document.getElementById('assignButton');
            if (assignButton) {
                assignButton.disabled = true;
            }
            Array.prototype.forEach.call(document.querySelectorAll('.assign-tab'), function(tab) {
                tab.classList.toggle('active', tab.getAttribute('data-kind') === assignKind);
            });
            var input = document.getElementById('personSearch');
            if (input) {
                input.value = '';
                input.placeholder = assignKind === 'learner' ? '搜索花名、拼音、学号、班级、培养中心' : '搜索花名、拼音、工号、部门';
                input.focus();
            }
            searchPeople('');
        }

        function renderPeople(items) {
            var list = document.getElementById('personResults');
            if (!list) {
                return;
            }
            if (!items || !items.length) {
                list.innerHTML = '<div class="person-option"><strong>未找到' + (assignKind === 'learner' ? '学员' : '员工') + '</strong><span>换个关键词再试</span></div>';
                return;
            }
            list.innerHTML = items.map(function(item) {
                var cardText = item.card_id ? '已绑定 ' + item.card_id : '未绑定工牌';
                if (assignKind === 'learner') {
                    var learnerMeta = [
                        item.student_no || '未设置学号',
                        item.realname || '未设置真实姓名',
                        item.class_name || '未设置班级',
                        item.training_center || '未设置培养中心',
                        cardText
                    ].join(' · ');
                    return '<button class="person-option" type="button" data-id="' + item.id + '">' +
                        '<strong>' + escapeHtml(item.name || '未设置花名') + '</strong>' +
                        '<span>' + escapeHtml(learnerMeta) + '</span>' +
                        '</button>';
                }
                var dept = item.department_name || '未设置部门';
                return '<button class="person-option" type="button" data-id="' + item.id + '">' +
                    '<strong>' + escapeHtml(item.name || '未设置花名') + '</strong>' +
                    '<span>' + escapeHtml((item.employee_id || '未分配工号') + ' · ' + dept + ' · ' + cardText) + '</span>' +
                    '</button>';
            }).join('');
            Array.prototype.forEach.call(list.querySelectorAll('.person-option[data-id]'), function(btn, index) {
                btn.addEventListener('click', function() {
                    selectedPerson = items[index];
                    Array.prototype.forEach.call(list.querySelectorAll('.person-option'), function(node) {
                        node.classList.remove('selected');
                    });
                    btn.classList.add('selected');
                    document.getElementById('assignButton').disabled = false;
                });
            });
        }

        function searchPeople(keyword) {
            keyword = keyword || '';
            if (getPinyinApi()) {
                loadPersonSearchCache(assignKind).then(function(items) {
                    var filtered = filterPeopleByKeyword(items, keyword);
                    renderPeople(filtered.slice(0, 30));
                }).catch(function() {
                    searchPeopleFromServer(keyword);
                });
                return;
            }
            searchPeopleFromServer(keyword);
        }

        function searchPeopleFromServer(keyword) {
            postAction(assignKind === 'learner' ? 'searchBadgeLearners' : 'searchBadgeEmployees', {q: keyword || ''}).then(function(text) {
                var data = JSON.parse(text);
                renderPeople(data.items || []);
            }).catch(function(error) {
                toast(error.message || '搜索失败');
            });
        }

        function loadPersonSearchCache(kind) {
            kind = kind === 'learner' ? 'learner' : 'employee';
            if (personSearchCache[kind]) {
                return Promise.resolve(personSearchCache[kind]);
            }
            if (personSearchPromise[kind]) {
                return personSearchPromise[kind];
            }
            personSearchPromise[kind] = postAction(kind === 'learner' ? 'searchBadgeLearners' : 'searchBadgeEmployees', {q: '', all: '1'}).then(function(text) {
                var data = JSON.parse(text);
                personSearchCache[kind] = data.items || [];
                personSearchCache[kind].forEach(function(item) {
                    item._searchText = buildPersonSearchText(item, kind);
                });
                return personSearchCache[kind];
            }).catch(function(error) {
                personSearchPromise[kind] = null;
                throw error;
            });
            return personSearchPromise[kind];
        }

        function getPinyinApi() {
            return window.pinyinPro && typeof window.pinyinPro.pinyin === 'function' ? window.pinyinPro : null;
        }

        function normalizeSearchText(value) {
            return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
        }

        function compactSearchText(value) {
            return normalizeSearchText(value).replace(/\s+/g, '');
        }

        function toPinyinText(text, pattern, separator, nonZh) {
            var api = getPinyinApi();
            if (!api || !text) {
                return '';
            }
            try {
                return api.pinyin(text, {
                    pattern: pattern || 'pinyin',
                    toneType: 'none',
                    type: 'string',
                    separator: separator === undefined ? ' ' : separator,
                    nonZh: nonZh || 'spaced'
                });
            } catch (e) {
                return '';
            }
        }

        function buildPersonSearchText(item, kind) {
            var parts = kind === 'learner' ? [
                item.name || '',
                item.realname || '',
                item.student_no || '',
                item.mobile || '',
                item.class_name || '',
                item.training_center || '',
                item.card_id || ''
            ] : [
                item.name || '',
                item.realname || '',
                item.employee_id || '',
                item.department_name || '',
                item.card_id || ''
            ];
            var raw = normalizeSearchText(parts.join(' '));
            var fullPinyin = normalizeSearchText(toPinyinText(raw, 'pinyin', ' ', 'spaced'));
            var firstPinyin = normalizeSearchText(toPinyinText(raw, 'first', '', 'removed'));
            return [
                raw,
                compactSearchText(raw),
                fullPinyin,
                compactSearchText(fullPinyin),
                firstPinyin
            ].join(' ');
        }

        function filterPeopleByKeyword(items, keyword) {
            var query = normalizeSearchText(keyword);
            if (query === '') {
                return items || [];
            }
            var terms = query.split(/\s+/);
            return (items || []).filter(function(item) {
                var target = item._searchText || buildPersonSearchText(item, assignKind);
                var compactTarget = compactSearchText(target);
                return terms.every(function(term) {
                    var compactTerm = compactSearchText(term);
                    return target.indexOf(term) !== -1 || (compactTerm !== '' && compactTarget.indexOf(compactTerm) !== -1);
                });
            });
        }

        function assignBadge() {
            if (!selectedPerson) {
                toast('请选择' + (assignKind === 'learner' ? '学员' : '员工'));
                return;
            }
            var subjectLabel = assignKind === 'learner' ? '学员' : '员工';
            var message = selectedPerson.card_id ? '该' + subjectLabel + '已有工牌，继续会替换为当前工牌。是否继续？' : '确定为 ' + selectedPerson.name + ' 发放该工牌？';
            if (!confirm(message)) {
                return;
            }
            postAction('submitcard', {
                id: selectedPerson.id,
                type: assignKind,
                cardid: BADGE_CARD_ID
            }).then(function(text) {
                toast(text || '发卡成功');
                setTimeout(function() { location.reload(); }, 700);
            }).catch(function(error) {
                toast(error.message || '发卡失败');
            });
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function(ch) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
            });
        }

        (function() {
            var input = document.getElementById('personSearch');
            if (!input) {
                return;
            }
            input.addEventListener('input', function() {
                clearTimeout(searchTimer);
                selectedPerson = null;
                document.getElementById('assignButton').disabled = true;
                searchTimer = setTimeout(function() {
                    searchPeople(input.value);
                }, 220);
            });
        })();
    </script>
</body>
</html>
<?php
    exit;
}

function badgeNormalizeUid($value)
{
    return AttendanceService::normalizeUidValue($value);
}

function badgeAvatarUrl($person)
{
    $url = trim((string)($person['avatar_url'] ?? ''));
    if ($url !== '' && preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }
    return '';
}

function badgeInitial($name)
{
    $name = trim((string)$name);
    if ($name === '') {
        return '卡';
    }
    if (preg_match_all('/./u', $name, $matches) !== false && count($matches[0]) > 0) {
        return $matches[0][0];
    }
    return substr($name, 0, 1);
}

function badgeH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
