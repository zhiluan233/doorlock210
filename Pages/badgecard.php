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
    $currentEmployee = badgeRefreshEmployeeProfile($currentEmployee);
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
$assignedEmployee = badgeRefreshEmployeeProfile($assignedEmployee);
$assignedLearner = Database::querySingleLine('learner', ['card_id' => $cardId]);
$assignedGuest = Database::querySingleLine('guest', ['card_id' => $cardId]);
$isOwnBadge = $assignedEmployee && ($assignedEmployee['open_id'] ?? '') === ($_SESSION['member_open_id'] ?? '');
$isAdminOwnBadge = $isAdmin && $assignedEmployee && badgeIsSameEmployee($assignedEmployee, $currentEmployee, $adminUser);
$lostFoundUrl = trim((string)($_config['feishu']['badgeLookup']['lostFoundUrl'] ?? ''));

if ($isAdmin) {
    if ($isAdminOwnBadge) {
        badgeRenderPage([
            'mode' => 'member_own_admin',
            'title' => '我的工牌',
            'subtitle' => '这张工牌已绑定到你的门禁账户',
            'raw_uid' => $rawUid,
            'card_id' => $cardId,
            'person' => $assignedEmployee,
            'csrf' => $_SESSION['token'] ?? ''
        ]);
    }
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
    $enrolledAt = intval($person['enrolled_at'] ?? 0);
    $realname = $person['realname'] ?? '';
    $role = badgeRoleFromMode($mode);
    $departmentDisplay = badgeDepartmentDisplay($person, $role);
    $isAdminMode = strpos($mode, 'admin_') === 0;
    $isAdminSelfMode = $mode === 'member_own_admin';
    $isPersonalProfile = in_array($mode, ['member_own', 'member_own_admin'], true);
    $isMismatch = $mode === 'member_mismatch';
    $isInvalid = $mode === 'invalid';
    $subtitle = badgeProfileSubtitle($role, $person, (string)($data['subtitle'] ?? ''));
    $topbarRight = badgeTopbarRightText($role, $person, $cardId, $rawUid, $isPersonalProfile, $isAdminMode);
    $quickActions = badgeQuickActions($role, $person, $cardId, $rawUid, $isPersonalProfile);

    Header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>个人中心</title>
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
        .topbar span:first-child {
            flex: 0 0 auto;
        }
        .topbar span:last-child {
            max-width: 72%;
            text-align: right;
            line-height: 1.45;
            word-break: break-word;
            white-space: pre-line;
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
        .info-toggle {
            width: 100%;
            border: 0;
            font: inherit;
            color: inherit;
            -webkit-appearance: none;
            appearance: none;
            cursor: pointer;
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
        .quick-fab {
            position: fixed;
            right: max(16px, env(safe-area-inset-right));
            bottom: calc(152px + env(safe-area-inset-bottom));
            width: 54px;
            height: 54px;
            border: 0;
            border-radius: 50%;
            background: #f67302;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 14px 28px rgba(246, 115, 2, .28);
            z-index: 900;
            font-size: 20px;
            touch-action: none;
            user-select: none;
            cursor: grab;
        }
        .quick-fab.dragging {
            transition: none;
            box-shadow: 0 18px 34px rgba(246, 115, 2, .34);
            cursor: grabbing;
        }
        .quick-menu-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        .quick-action {
            min-height: 86px;
            border: 1px solid #e4e7ec;
            border-radius: 8px;
            background: #fff;
            color: #344054;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 6px;
            text-align: center;
        }
        .quick-action-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #ecfdf6;
            color: #13b887;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex: 0 0 auto;
        }
        .quick-action-icon i {
            font-size: 18px;
            line-height: 1;
        }
        .quick-action-icon img {
            width: 24px;
            height: 24px;
            object-fit: contain;
            display: block;
        }
        .quick-action-name {
            width: 100%;
            color: #344054;
            font-size: 13px;
            line-height: 1.25;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
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
        .sheet-mask.centered {
            align-items: center;
        }
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
        #quickMenuSheet .sheet-head {
            justify-content: flex-end;
        }
        #quickMenuSheet .sheet-head h2 {
            margin-right: auto;
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
            <span>个人中心</span>
            <span><?php echo badgeH($topbarRight); ?></span>
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
            <p class="subtitle"><?php echo badgeH($subtitle); ?></p>

            <div class="info-list">
                <button class="info-row info-toggle" id="badgeIdentityToggle" type="button" data-card-id="<?php echo badgeH($cardId !== '' ? $cardId : '无法转换'); ?>" data-raw-uid="<?php echo badgeH($rawUid !== '' ? $rawUid : '--'); ?>">
                    <span id="badgeIdentityLabel">工牌号</span>
                    <span id="badgeIdentityValue"><?php echo badgeH($cardId !== '' ? $cardId : '无法转换'); ?></span>
                </button>
                <?php if ($person && !$isMismatch) { ?>
                    <?php if ($employeeNo !== '') { ?><div class="info-row"><span>工号</span><span><?php echo badgeH($employeeNo); ?></span></div><?php } ?>
                    <?php if ($studentNo !== '') { ?><div class="info-row"><span>学号</span><span><?php echo badgeH($studentNo); ?></span></div><?php } ?>
                    <?php if ($realname !== '' && $realname !== '--') { ?><div class="info-row"><span>真实姓名</span><span><?php echo badgeH($realname); ?></span></div><?php } ?>
                    <?php if ($mobile !== '') { ?><div class="info-row"><span>手机号</span><span><?php echo badgeH($mobile); ?></span></div><?php } ?>
                    <?php if ($className !== '') { ?><div class="info-row"><span>班级</span><span><?php echo badgeH($className); ?></span></div><?php } ?>
                    <?php if ($trainingCenter !== '') { ?><div class="info-row"><span>培养中心</span><span><?php echo badgeH($trainingCenter); ?></span></div><?php } ?>
                    <?php if ($studentNo !== '' && $enrolledAt > 0) { ?><div class="info-row"><span>入学时间</span><span><?php echo badgeH(date('Y-m-d', $enrolledAt)); ?></span></div><?php } ?>
                    <?php if ($departmentDisplay['value'] !== '') { ?><div class="info-row"><span><?php echo badgeH($departmentDisplay['label']); ?></span><span><?php echo badgeH($departmentDisplay['value']); ?></span></div><?php } ?>
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
                <?php } elseif ($mode === 'member_own' || $mode === 'member_own_admin') { ?>
                    <?php if ($isAdminSelfMode) { ?>
                        <button class="btn" type="button" onclick="openSelfManageSheet()"><i class="fa-solid fa-sliders"></i>管理自己的工牌</button>
                    <?php } ?>
                    <a class="btn btn-primary" href="/?page=userpanel"><i class="fa-solid fa-clock-rotate-left"></i>查看刷卡记录</a>
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

    <?php if ($isPersonalProfile && count($quickActions) > 0) { ?>
        <button class="quick-fab" id="quickFab" type="button" aria-label="打开功能菜单"><i class="fa-solid fa-compass"></i></button>
        <div class="sheet-mask centered" id="quickMenuSheet" aria-hidden="true">
            <section class="sheet">
                <div class="sheet-head">
                    <button class="icon-btn" type="button" onclick="closeQuickMenu()" aria-label="关闭"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="quick-menu-grid">
                    <?php foreach ($quickActions as $action) { ?>
                        <button class="quick-action" type="button" data-url="<?php echo badgeH($action['url']); ?>">
                            <span class="quick-action-icon"><?php echo badgeActionIconHtml($action['icon']); ?></span>
                            <span class="quick-action-name"><?php echo badgeH($action['name']); ?></span>
                        </button>
                    <?php } ?>
                </div>
            </section>
        </div>
    <?php } ?>

    <?php if ($isAdminSelfMode) { ?>
        <div class="sheet-mask" id="selfManageSheet" aria-hidden="true">
            <section class="sheet">
                <div class="sheet-head">
                    <h2>管理自己的工牌</h2>
                    <button class="icon-btn" type="button" onclick="closeSelfManageSheet()" aria-label="关闭"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="sheet-actions">
                    <button class="btn btn-danger" type="button" onclick="releaseBadge()"><i class="fa-solid fa-rotate-left"></i>回收该工牌</button>
                    <a class="btn" href="/?page=panel&module=submitcard"><i class="fa-solid fa-list"></i>进入发卡管理</a>
                    <button class="btn" type="button" onclick="closeSelfManageSheet()"><i class="fa-solid fa-arrow-left"></i>返回工牌菜单</button>
                </div>
            </section>
        </div>
    <?php } ?>

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
        var overlayState = null;

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

        function showSheet(id, stateName) {
            var sheet = document.getElementById(id);
            if (!sheet) {
                return;
            }
            sheet.classList.add('show');
            sheet.setAttribute('aria-hidden', 'false');
            overlayState = stateName || id;
            if (!history.state || history.state.badgeOverlay !== overlayState) {
                history.pushState({badgeOverlay: overlayState}, '', location.href);
            }
        }

        function hideSheet(id) {
            var sheet = document.getElementById(id);
            if (!sheet) {
                return;
            }
            sheet.classList.remove('show');
            sheet.setAttribute('aria-hidden', 'true');
            overlayState = null;
        }

        function openQuickMenu() {
            showSheet('quickMenuSheet', 'quickMenu');
        }

        function closeQuickMenu() {
            hideSheet('quickMenuSheet');
        }

        function openQuickAction(url) {
            if (!url) {
                toast('该功能暂未配置');
                return;
            }
            if (runConfiguredJsAction(url)) {
                return;
            }
            location.href = url;
        }

        function runConfiguredJsAction(url) {
            var match = String(url || '').match(/^\s*(?:window\.)?alert\(([\s\S]*)\)\s*;?\s*$/);
            if (!match) {
                return false;
            }
            var arg = match[1].trim();
            var message = arg;
            if ((arg.charAt(0) === '"' && arg.charAt(arg.length - 1) === '"') || (arg.charAt(0) === "'" && arg.charAt(arg.length - 1) === "'")) {
                try {
                    message = JSON.parse(arg.charAt(0) === "'" ? '"' + arg.slice(1, -1).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"' : arg);
                } catch (e) {
                    message = arg.slice(1, -1);
                }
            }
            alert(String(message || ''));
            return true;
        }

        function openSelfManageSheet() {
            showSheet('selfManageSheet', 'selfManage');
        }

        function closeSelfManageSheet() {
            hideSheet('selfManageSheet');
        }

        function openAssignSheet() {
            var sheet = document.getElementById('assignSheet');
            if (!sheet) {
                return;
            }
            showSheet('assignSheet', 'assign');
            setTimeout(function() {
                var input = document.getElementById('personSearch');
                if (input) {
                    input.focus();
                    searchPeople('');
                }
            }, 80);
        }

        function closeAssignSheet() {
            hideSheet('assignSheet');
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

        function initQuickMenuActions() {
            var grid = document.querySelector('.quick-menu-grid');
            if (!grid) {
                return;
            }
            grid.addEventListener('click', function(event) {
                var target = event.target;
                var button = target && target.closest ? target.closest('.quick-action') : null;
                if (!button || !grid.contains(button)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                openQuickAction(button.getAttribute('data-url') || '');
            });
        }

        function initBadgeIdentityToggle() {
            var button = document.getElementById('badgeIdentityToggle');
            if (!button) {
                return;
            }
            var label = document.getElementById('badgeIdentityLabel');
            var value = document.getElementById('badgeIdentityValue');
            var showingUid = false;
            button.addEventListener('click', function() {
                showingUid = !showingUid;
                if (label) {
                    label.textContent = showingUid ? '卡片 UID' : '工牌号';
                }
                if (value) {
                    value.textContent = showingUid ? (button.getAttribute('data-raw-uid') || '--') : (button.getAttribute('data-card-id') || '无法转换');
                }
            });
        }

        function initQuickFab() {
            var fab = document.getElementById('quickFab');
            if (!fab) {
                return;
            }
            var storageKey = 'badgeQuickFabPosition';
            var activePointer = null;
            var suppressClick = false;

            function clamp(value, min, max) {
                return Math.max(min, Math.min(max, value));
            }

            function placeFab(left, top) {
                var rect = fab.getBoundingClientRect();
                var size = Math.max(rect.width || 54, rect.height || 54);
                var margin = 10;
                var maxLeft = Math.max(margin, window.innerWidth - size - margin);
                var maxTop = Math.max(margin, window.innerHeight - size - margin);
                var nextLeft = clamp(left, margin, maxLeft);
                var nextTop = clamp(top, margin, maxTop);
                fab.style.left = nextLeft + 'px';
                fab.style.top = nextTop + 'px';
                fab.style.right = 'auto';
                fab.style.bottom = 'auto';
            }

            function savePosition() {
                try {
                    var rect = fab.getBoundingClientRect();
                    localStorage.setItem(storageKey, JSON.stringify({left: rect.left, top: rect.top}));
                } catch (e) {}
            }

            function restorePosition() {
                try {
                    var saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
                    if (saved && typeof saved.left === 'number' && typeof saved.top === 'number') {
                        placeFab(saved.left, saved.top);
                    }
                } catch (e) {}
            }

            restorePosition();

            if (!window.PointerEvent) {
                fab.addEventListener('click', function(event) {
                    event.preventDefault();
                    openQuickMenu();
                });
                return;
            }

            fab.addEventListener('pointerdown', function(event) {
                if (event.button && event.button !== 0) {
                    return;
                }
                var rect = fab.getBoundingClientRect();
                activePointer = {
                    id: event.pointerId,
                    startX: event.clientX,
                    startY: event.clientY,
                    offsetX: event.clientX - rect.left,
                    offsetY: event.clientY - rect.top,
                    moved: false
                };
                try {
                    fab.setPointerCapture(event.pointerId);
                } catch (e) {}
            });

            fab.addEventListener('pointermove', function(event) {
                if (!activePointer || event.pointerId !== activePointer.id) {
                    return;
                }
                var dx = Math.abs(event.clientX - activePointer.startX);
                var dy = Math.abs(event.clientY - activePointer.startY);
                if (dx > 5 || dy > 5) {
                    activePointer.moved = true;
                }
                if (!activePointer.moved) {
                    return;
                }
                fab.classList.add('dragging');
                placeFab(event.clientX - activePointer.offsetX, event.clientY - activePointer.offsetY);
                event.preventDefault();
            });

            function finishPointer(event) {
                if (!activePointer || event.pointerId !== activePointer.id) {
                    return;
                }
                var moved = activePointer.moved;
                activePointer = null;
                fab.classList.remove('dragging');
                suppressClick = true;
                window.setTimeout(function() {
                    suppressClick = false;
                }, 350);
                try {
                    fab.releasePointerCapture(event.pointerId);
                } catch (e) {}
                if (moved) {
                    savePosition();
                    event.preventDefault();
                    return;
                }
                openQuickMenu();
            }

            fab.addEventListener('pointerup', finishPointer);
            fab.addEventListener('pointercancel', function(event) {
                if (!activePointer || event.pointerId !== activePointer.id) {
                    return;
                }
                activePointer = null;
                fab.classList.remove('dragging');
            });
            fab.addEventListener('click', function(event) {
                if (suppressClick) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
            window.addEventListener('resize', function() {
                if (fab.style.left && fab.style.top) {
                    placeFab(parseFloat(fab.style.left), parseFloat(fab.style.top));
                    savePosition();
                }
            });
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function(ch) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
            });
        }

        (function() {
            initBadgeIdentityToggle();
            initQuickMenuActions();
            initQuickFab();

            window.addEventListener('popstate', function() {
                if (overlayState === 'quickMenu') {
                    closeQuickMenu();
                } else if (overlayState === 'selfManage') {
                    closeSelfManageSheet();
                } else if (overlayState === 'assign') {
                    closeAssignSheet();
                }
            });

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

function badgeIsSameEmployee($assignedEmployee, $currentEmployee, $adminUser)
{
    if (!$assignedEmployee) {
        return false;
    }
    $assignedOpenId = (string)($assignedEmployee['open_id'] ?? '');
    $assignedEmployeeId = (string)($assignedEmployee['employee_id'] ?? '');
    $candidates = [];
    if ($currentEmployee) {
        $candidates[] = [
            'open_id' => (string)($currentEmployee['open_id'] ?? ''),
            'employee_id' => (string)($currentEmployee['employee_id'] ?? '')
        ];
    }
    if ($adminUser) {
        $candidates[] = [
            'open_id' => (string)($adminUser['open_id'] ?? ''),
            'employee_id' => (string)($adminUser['employee_id'] ?? '')
        ];
    }
    foreach ($candidates as $candidate) {
        if ($assignedOpenId !== '' && $candidate['open_id'] !== '' && $assignedOpenId === $candidate['open_id']) {
            return true;
        }
        if ($assignedEmployeeId !== '' && $candidate['employee_id'] !== '' && $assignedEmployeeId === $candidate['employee_id']) {
            return true;
        }
    }
    return false;
}

function badgeRefreshEmployeeProfile($employee)
{
    if (!is_array($employee) || trim((string)($employee['open_id'] ?? '')) === '') {
        return $employee;
    }
    $avatarUrl = (string)($employee['avatar_url'] ?? '');
    $needsRefresh = trim((string)($employee['job_title'] ?? '')) === ''
        || intval($employee['joined_at'] ?? 0) <= 0
        || $avatarUrl === ''
        || preg_match('/image_size=(72|240)x/i', $avatarUrl);
    if (!$needsRefresh) {
        return $employee;
    }

    static $cache = [];
    $openId = trim((string)$employee['open_id']);
    if (!array_key_exists($openId, $cache)) {
        try {
            $feishu = new \anim210System\appLinkFeishu(true);
            $cache[$openId] = $feishu->getNormalizedMemberProfile($openId);
        } catch (\Throwable $e) {
            $cache[$openId] = [];
        }
    }
    $profile = is_array($cache[$openId]) ? $cache[$openId] : [];
    if (count($profile) === 0) {
        return $employee;
    }

    $data = [];
    foreach ([
        'user_id' => 'user_id',
        'union_id' => 'union_id',
        'name' => 'name',
        'employee_id' => 'employee_no',
        'email' => 'email',
        'mobile' => 'mobile',
        'tenant_key' => 'tenant_key',
        'avatar_url' => 'avatar_url',
        'job_title' => 'job_title'
    ] as $dbField => $profileField) {
        $value = trim((string)($profile[$profileField] ?? ''));
        if ($value !== '') {
            $data[$dbField] = $value;
        }
    }
    if (($profile['real_name'] ?? '') !== '' && ($profile['real_name'] ?? '') !== '--') {
        $data['realname'] = $profile['real_name'];
    }
    if (intval($profile['joined_at'] ?? 0) > 0) {
        $data['joined_at'] = intval($profile['joined_at']);
    }
    if (isset($profile['status'])) {
        $data['status'] = $profile['status'] ? 'true' : 'false';
    }
    if (count($data) === 0) {
        return $employee;
    }

    $data['updated_at'] = time();
    Database::update('employee', $data, ['id' => $employee['id']]);
    return array_merge($employee, $data);
}

function badgeDepartmentDisplay($person, $role)
{
    $config = badgeDepartmentDisplayConfig();
    $fallbackValue = trim((string)($person['department_name'] ?? ''));
    if (!is_array($person) || $role !== 'employee') {
        return [
            'label' => $config['fallbackLabel'],
            'value' => $fallbackValue
        ];
    }
    if (!$config['enabled']) {
        return [
            'label' => $config['fallbackLabel'],
            'value' => $fallbackValue
        ];
    }

    $path = badgeDepartmentPath($person);
    if (count($path) === 0 && $fallbackValue !== '') {
        $path = [$fallbackValue];
    }
    $levels = array_slice($path, $config['rootDepth']);

    $titleIndex = $config['titleDepth'] - 1;
    $valueIndex = $config['valueDepth'] - 1;
    if (count($levels) > $valueIndex) {
        $label = $levels[$titleIndex] ?? $config['defaultLabel'];
        $value = $levels[$valueIndex];
    } else if (count($levels) > $titleIndex) {
        $label = $titleIndex > 0 ? ($levels[$titleIndex - 1] ?? $config['defaultLabel']) : $config['defaultLabel'];
        $value = $levels[$titleIndex];
    } else if (count($levels) > 0) {
        $label = $config['defaultLabel'];
        $value = $levels[0];
    } else {
        $label = $config['defaultLabel'];
        $value = '-';
    }

    $label = trim((string)$label);
    $value = trim((string)$value);
    return [
        'label' => $label !== '' ? $label : $config['defaultLabel'],
        'value' => $value !== '' ? $value : '-'
    ];
}

function badgeDepartmentDisplayConfig()
{
    $lookup = badgeLookupConfig();
    $config = is_array($lookup['departmentDisplay'] ?? null) ? $lookup['departmentDisplay'] : [];
    $enabled = array_key_exists('enabled', $config) ? (bool)$config['enabled'] : true;
    $rootDepth = max(0, intval($config['rootDepth'] ?? 0));
    $titleDepth = max(1, intval($config['titleDepth'] ?? 2));
    $valueDepth = max(1, intval($config['valueDepth'] ?? 3));
    if ($valueDepth <= $titleDepth) {
        $valueDepth = $titleDepth + 1;
    }
    $defaultLabel = trim((string)($config['defaultLabel'] ?? '两点十分'));
    $fallbackLabel = trim((string)($config['fallbackLabel'] ?? '部门'));
    return [
        'enabled' => $enabled,
        'defaultLabel' => $defaultLabel !== '' ? $defaultLabel : '两点十分',
        'fallbackLabel' => $fallbackLabel !== '' ? $fallbackLabel : '部门',
        'rootDepth' => $rootDepth,
        'titleDepth' => $titleDepth,
        'valueDepth' => $valueDepth
    ];
}

function badgeDepartmentPath($person)
{
    $ids = badgePersonDepartmentIds($person);
    $bestPath = [];
    foreach ($ids as $id) {
        $path = badgeDepartmentPathById($id);
        if (count($path) > count($bestPath)) {
            $bestPath = $path;
        }
    }
    return $bestPath;
}

function badgePersonDepartmentIds($person)
{
    $ids = [];
    $raw = $person['department_ids'] ?? '';
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        } else if (strpos($raw, ',') !== false) {
            $raw = array_map('trim', explode(',', $raw));
        }
    }
    if (is_array($raw)) {
        foreach ($raw as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') {
                $ids[] = trim((string)$item);
            }
        }
    }
    if (!empty($person['department_id'])) {
        $ids[] = trim((string)$person['department_id']);
    }
    return array_values(array_unique(array_filter($ids, function($id) {
        return $id !== '';
    })));
}

function badgeDepartmentPathById($departmentId)
{
    $path = [];
    $seen = [];
    $department = badgeFindDepartment($departmentId);
    while (is_array($department)) {
        $id = trim((string)($department['department_id'] ?? ''));
        $openId = trim((string)($department['open_department_id'] ?? ''));
        $seenKey = $id !== '' ? $id : $openId;
        if ($seenKey !== '' && isset($seen[$seenKey])) {
            break;
        }
        if ($seenKey !== '') {
            $seen[$seenKey] = true;
        }
        $name = trim((string)($department['name'] ?? ''));
        if ($name !== '') {
            array_unshift($path, $name);
        }
        $parentId = trim((string)($department['parent_department_id'] ?? ''));
        if ($parentId === '' || $parentId === '0') {
            break;
        }
        $department = badgeFindDepartment($parentId);
    }
    return $path;
}

function badgeFindDepartment($departmentId)
{
    static $cache = [];
    $departmentId = trim((string)$departmentId);
    if ($departmentId === '') {
        return null;
    }
    if (array_key_exists($departmentId, $cache)) {
        return $cache[$departmentId];
    }
    $escaped = Database::escape($departmentId);
    $sql = "SELECT * FROM `feishu_departments` WHERE `department_id`='{$escaped}' OR `open_department_id`='{$escaped}' LIMIT 1";
    $row = Database::querySingleLine('feishu_departments', $sql, true);
    $cache[$departmentId] = is_array($row) ? $row : null;
    return $cache[$departmentId];
}

function badgeRoleFromMode($mode)
{
    if (strpos($mode, 'learner') !== false) {
        return 'learner';
    }
    if (strpos($mode, 'guest') !== false) {
        return 'guest';
    }
    return 'employee';
}

function badgeProfileSubtitle($role, $person, $fallback)
{
    if (!$person) {
        return $fallback;
    }
    if ($role === 'learner') {
        $parts = array_values(array_filter([
            trim((string)($person['training_center'] ?? '')),
            trim((string)($person['class_name'] ?? ''))
        ], function($item) { return $item !== ''; }));
        return count($parts) > 0 ? implode(' | ', $parts) : '学员';
    }
    if ($role === 'guest') {
        return '访客';
    }
    $jobTitle = trim((string)($person['job_title'] ?? ''));
    if ($jobTitle !== '') {
        return $jobTitle;
    }
    return '员工';
}

function badgeTopbarRightText($role, $person, $cardId, $rawUid, $isPersonalProfile, $isAdminMode)
{
    if (!$isPersonalProfile || $isAdminMode || !$person) {
        return $rawUid !== '' ? 'UID ' . $rawUid : 'NDEF 工牌';
    }
    $profile = badgeRoleProfileConfig($role);
    $days = badgeProfileDays($role, $person, intval($profile['fallbackDays'] ?? 0));
    $template = (string)($profile['textTemplate'] ?? badgeDefaultProfile($role)['textTemplate']);
    $base = badgeApplyTemplate($template, [
        'days' => (string)$days,
        'name' => (string)($person['name'] ?? ''),
        'card_id' => $cardId,
        'uid' => $rawUid
    ]);
    $blessing = badgeStableBlessing($profile['blessings'] ?? [], $cardId . '|' . $role);
    return $blessing !== '' ? $base . "\n" . $blessing : $base;
}

function badgeProfileDays($role, $person, $fallback)
{
    $timestamp = 0;
    if ($role === 'learner') {
        $timestamp = intval($person['enrolled_at'] ?? 0);
        if ($timestamp <= 0) {
            $timestamp = intval($person['created_at'] ?? 0);
        }
    } else if ($role === 'employee') {
        $timestamp = intval($person['joined_at'] ?? 0);
    }
    if ($timestamp <= 0) {
        return max(0, intval($fallback));
    }
    return max(1, intval(floor((time() - $timestamp) / 86400)) + 1);
}

function badgeRoleProfileConfig($role)
{
    $config = badgeLookupConfig();
    $profiles = is_array($config['profile'] ?? null) ? $config['profile'] : [];
    $default = badgeDefaultProfile($role);
    $profile = is_array($profiles[$role] ?? null) ? $profiles[$role] : [];
    return array_merge($default, $profile);
}

function badgeDefaultProfile($role)
{
    if ($role === 'learner') {
        return [
            'textTemplate' => '您已入学 {days} 天',
            'fallbackDays' => 0,
            'blessings' => ['愿你每天都有新的成长', '保持好奇，继续进阶', '把练习变成作品', '今天也向目标更近一步', '愿灵感和努力都在线']
        ];
    }
    if ($role === 'guest') {
        return [
            'textTemplate' => '感谢到访两点十分',
            'fallbackDays' => 0,
            'blessings' => ['愿这次相遇留下好印象', '欢迎常来交流', '期待与你一起创造可能', '感谢你走进两点十分', '愿今天的行程顺利愉快']
        ];
    }
    return [
        'textTemplate' => '您与两点十分并肩 {days} 天',
        'fallbackDays' => 0,
        'blessings' => ['感恩一路有你', '今天也一起创造精彩', '愿每一次靠近都带来新的灵感', '继续一起把想象做成作品', '并肩同行，热爱常在']
    ];
}

function badgeStableBlessing($items, $seed)
{
    if (!is_array($items) || count($items) === 0) {
        return '';
    }
    $clean = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item !== '') {
            $clean[] = $item;
        }
    }
    if (count($clean) === 0) {
        return '';
    }
    $index = abs(crc32($seed . '|' . date('Ymd'))) % count($clean);
    return $clean[$index];
}

function badgeQuickActions($role, $person, $cardId, $rawUid, $isPersonalProfile)
{
    if (!$isPersonalProfile) {
        return [];
    }
    $config = badgeLookupConfig();
    $items = $config['quickActions'] ?? [
        ['name' => '打卡记录', 'icon' => 'history', 'url' => '/?page=userpanel']
    ];
    if (!is_array($items)) {
        return [];
    }
    $actions = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        $icon = trim((string)($item['icon'] ?? 'link'));
        $url = badgeResolveActionUrl((string)($item['url'] ?? ''), $role, $person, $cardId, $rawUid);
        if ($name === '' || $url === '') {
            continue;
        }
        $actions[] = ['name' => $name, 'icon' => $icon, 'url' => $url];
        if (count($actions) >= 12) {
            break;
        }
    }
    return $actions;
}

function badgeResolveActionUrl($url, $role, $person, $cardId, $rawUid)
{
    $url = trim($url);
    if ($url === '' || !badgeIsSafeActionUrl($url)) {
        return '';
    }
    return badgeApplyTemplate($url, [
        'role' => $role,
        'card_id' => $cardId,
        'uid' => $rawUid,
        'open_id' => (string)($person['open_id'] ?? ''),
        'employee_id' => (string)($person['employee_id'] ?? ''),
        'student_no' => (string)($person['student_no'] ?? ''),
        'name' => (string)($person['name'] ?? '')
    ]);
}

function badgeIsSafeActionUrl($url)
{
    return !preg_match('/^\s*(javascript|data|vbscript):/i', $url);
}

function badgeApplyTemplate($template, $vars)
{
    foreach ($vars as $key => $value) {
        $template = str_replace('{' . $key . '}', (string)$value, $template);
        $template = str_replace('{' . $key . ':url}', rawurlencode((string)$value), $template);
    }
    return $template;
}

function badgeActionIconHtml($icon)
{
    $icon = trim((string)$icon);
    if ($icon === '') {
        $icon = 'link';
    }
    if (preg_match('/^(https?:)?\/\//i', $icon) || preg_match('/^\//', $icon)) {
        return '<img src="' . badgeH($icon) . '" alt="">';
    }
    $class = badgeIconClass($icon);
    return '<i class="' . badgeH($class) . '"></i>';
}

function badgeIconClass($icon)
{
    if (strpos($icon, 'fa-') !== false) {
        return $icon;
    }
    $map = [
        'add' => 'fa-solid fa-plus',
        'admin' => 'fa-solid fa-sliders',
        'alert' => 'fa-solid fa-triangle-exclamation',
        'announcement' => 'fa-solid fa-bullhorn',
        'app' => 'fa-solid fa-grip',
        'archive' => 'fa-solid fa-box-archive',
        'arrow' => 'fa-solid fa-arrow-up-right-from-square',
        'asset' => 'fa-solid fa-box-archive',
        'history' => 'fa-solid fa-clock-rotate-left',
        'attendance' => 'fa-solid fa-clipboard-check',
        'audio' => 'fa-solid fa-headphones',
        'award' => 'fa-solid fa-award',
        'back' => 'fa-solid fa-arrow-left',
        'badge' => 'fa-solid fa-id-card',
        'bell' => 'fa-solid fa-bell',
        'book' => 'fa-solid fa-book',
        'bookmark' => 'fa-solid fa-bookmark',
        'briefcase' => 'fa-solid fa-briefcase',
        'bug' => 'fa-solid fa-bug',
        'building' => 'fa-solid fa-building',
        'calendar' => 'fa-solid fa-calendar-days',
        'camera' => 'fa-solid fa-camera',
        'card' => 'fa-solid fa-id-card',
        'chart' => 'fa-solid fa-chart-line',
        'check' => 'fa-solid fa-check',
        'checklist' => 'fa-solid fa-list-check',
        'class' => 'fa-solid fa-chalkboard-user',
        'clipboard' => 'fa-solid fa-clipboard',
        'clock' => 'fa-solid fa-clock',
        'close' => 'fa-solid fa-xmark',
        'cloud' => 'fa-solid fa-cloud',
        'code' => 'fa-solid fa-code',
        'coffee' => 'fa-solid fa-mug-hot',
        'comment' => 'fa-solid fa-comment',
        'community' => 'fa-solid fa-people-group',
        'company' => 'fa-solid fa-building',
        'compass' => 'fa-solid fa-compass',
        'config' => 'fa-solid fa-sliders',
        'contact' => 'fa-solid fa-address-book',
        'copy' => 'fa-solid fa-copy',
        'course' => 'fa-solid fa-graduation-cap',
        'dashboard' => 'fa-solid fa-gauge-high',
        'data' => 'fa-solid fa-database',
        'device' => 'fa-solid fa-tablet-screen-button',
        'docs' => 'fa-solid fa-file-lines',
        'door' => 'fa-solid fa-door-open',
        'download' => 'fa-solid fa-download',
        'edit' => 'fa-solid fa-pen-to-square',
        'email' => 'fa-solid fa-envelope',
        'employee' => 'fa-solid fa-user-tie',
        'entry' => 'fa-solid fa-right-to-bracket',
        'event' => 'fa-solid fa-calendar-check',
        'exit' => 'fa-solid fa-right-from-bracket',
        'external' => 'fa-solid fa-arrow-up-right-from-square',
        'favorite' => 'fa-solid fa-star',
        'feedback' => 'fa-solid fa-comment-dots',
        'feishu' => 'fa-solid fa-paper-plane',
        'file' => 'fa-solid fa-file',
        'filter' => 'fa-solid fa-filter',
        'finance' => 'fa-solid fa-coins',
        'folder' => 'fa-solid fa-folder',
        'gift' => 'fa-solid fa-gift',
        'group' => 'fa-solid fa-users',
        'growth' => 'fa-solid fa-seedling',
        'guide' => 'fa-solid fa-signs-post',
        'heart' => 'fa-solid fa-heart',
        'help' => 'fa-solid fa-circle-question',
        'home' => 'fa-solid fa-house',
        'idea' => 'fa-solid fa-lightbulb',
        'image' => 'fa-solid fa-image',
        'inbox' => 'fa-solid fa-inbox',
        'info' => 'fa-solid fa-circle-info',
        'key' => 'fa-solid fa-key',
        'lab' => 'fa-solid fa-flask',
        'learn' => 'fa-solid fa-book-open',
        'learner' => 'fa-solid fa-user-graduate',
        'leave' => 'fa-solid fa-person-walking-arrow-right',
        'light' => 'fa-solid fa-lightbulb',
        'link' => 'fa-solid fa-arrow-up-right-from-square',
        'list' => 'fa-solid fa-list',
        'location' => 'fa-solid fa-location-dot',
        'lock' => 'fa-solid fa-lock',
        'log' => 'fa-solid fa-clipboard-list',
        'map' => 'fa-solid fa-map-location-dot',
        'meeting' => 'fa-solid fa-handshake',
        'member' => 'fa-solid fa-user',
        'message' => 'fa-solid fa-message',
        'mobile' => 'fa-solid fa-mobile-screen-button',
        'monitor' => 'fa-solid fa-desktop',
        'music' => 'fa-solid fa-music',
        'nfc' => 'fa-solid fa-wifi',
        'notice' => 'fa-solid fa-bell',
        'oa' => 'fa-solid fa-briefcase',
        'open-door' => 'fa-solid fa-door-open',
        'order' => 'fa-solid fa-receipt',
        'org' => 'fa-solid fa-sitemap',
        'password' => 'fa-solid fa-shield-keyhole',
        'pay' => 'fa-solid fa-credit-card',
        'phone' => 'fa-solid fa-phone',
        'photo' => 'fa-solid fa-image',
        'pin' => 'fa-solid fa-thumbtack',
        'policy' => 'fa-solid fa-shield-halved',
        'print' => 'fa-solid fa-print',
        'profile' => 'fa-solid fa-user',
        'project' => 'fa-solid fa-diagram-project',
        'qr' => 'fa-solid fa-qrcode',
        'record' => 'fa-solid fa-clipboard-list',
        'refresh' => 'fa-solid fa-rotate',
        'repair' => 'fa-solid fa-screwdriver-wrench',
        'report' => 'fa-solid fa-chart-column',
        'request' => 'fa-solid fa-paper-plane',
        'role' => 'fa-solid fa-user-shield',
        'room' => 'fa-solid fa-door-closed',
        'safe' => 'fa-solid fa-shield-halved',
        'save' => 'fa-solid fa-floppy-disk',
        'scan' => 'fa-solid fa-qrcode',
        'schedule' => 'fa-solid fa-calendar-days',
        'school' => 'fa-solid fa-school',
        'search' => 'fa-solid fa-magnifying-glass',
        'security' => 'fa-solid fa-shield-halved',
        'send' => 'fa-solid fa-paper-plane',
        'service' => 'fa-solid fa-headset',
        'setting' => 'fa-solid fa-gear',
        'settings' => 'fa-solid fa-gears',
        'share' => 'fa-solid fa-share-nodes',
        'shop' => 'fa-solid fa-store',
        'sign' => 'fa-solid fa-signature',
        'star' => 'fa-solid fa-star',
        'status' => 'fa-solid fa-signal',
        'student' => 'fa-solid fa-user-graduate',
        'support' => 'fa-solid fa-headset',
        'sync' => 'fa-solid fa-rotate',
        'tag' => 'fa-solid fa-tag',
        'task' => 'fa-solid fa-list-check',
        'team' => 'fa-solid fa-people-group',
        'ticket' => 'fa-solid fa-ticket',
        'time' => 'fa-solid fa-clock',
        'tool' => 'fa-solid fa-screwdriver-wrench',
        'training' => 'fa-solid fa-graduation-cap',
        'upload' => 'fa-solid fa-upload',
        'user' => 'fa-solid fa-user',
        'users' => 'fa-solid fa-users',
        'video' => 'fa-solid fa-video',
        'visitor' => 'fa-solid fa-user-clock',
        'wallet' => 'fa-solid fa-wallet',
        'warning' => 'fa-solid fa-triangle-exclamation',
        'wifi' => 'fa-solid fa-wifi',
        'work' => 'fa-solid fa-briefcase'
    ];
    return $map[$icon] ?? 'fa-solid fa-arrow-up-right-from-square';
}

function badgeJs($value)
{
    return json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function badgeLookupConfig()
{
    global $_config;
    $config = $_config['feishu']['badgeLookup'] ?? [];
    return is_array($config) ? $config : [];
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
