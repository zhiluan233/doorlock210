<?php
/*

普通员工刷卡记录页面
Ver 1.0.0.0 20260708
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
$rows = [];
if ($cardId !== '') {
    $card = Database::escape($cardId);
    $rs = Database::query('logs', "SELECT * FROM `logs` WHERE `cardid`='{$card}' ORDER BY `time` DESC LIMIT 300", '', true);
    if ($rs) {
        while ($row = mysqli_fetch_assoc($rs)) {
            $rows[] = $row;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>两点十分门禁 ｜ 我的刷卡记录</title>
    <link href="asset/plugins/Bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="asset/plugins/Font-awesome/css/all.min.css" rel="stylesheet" />
    <link href="asset/css/ecaps.css" rel="stylesheet" />
    <link href="asset/css/custom.css" rel="stylesheet" />
    <style>
        body { background: #f5f6f8; }
        .member-shell { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
        .member-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .member-title h3 { margin: 0 0 6px; font-weight: 500; }
        .member-title p { margin: 0; color: #6b7280; }
        .member-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .panel { border-radius: 6px; }
        .table-wrap { overflow-x: auto; }
        @media screen and (max-width: 640px) {
            .member-header { align-items: flex-start; gap: 14px; flex-direction: column; }
            .member-actions { justify-content: flex-start; }
        }
    </style>
</head>
<body>
    <div class="member-shell">
        <div class="member-header">
            <div class="member-title">
                <h3>我的刷卡记录</h3>
                <p><?php echo htmlspecialchars($employee['name']); ?> · <?php echo $cardId === '' ? '未绑定门禁卡' : htmlspecialchars($cardId); ?></p>
            </div>
            <div class="member-actions">
                <?php if ($canEnterAdmin) { ?>
                    <a class="btn btn-primary" href="/?page=panel&module=home">进入管理后台</a>
                <?php } ?>
                <a class="btn btn-default" href="?page=logout&csrf=<?php echo $_SESSION['token']; ?>">退出</a>
            </div>
        </div>

        <div class="panel panel-white">
            <div class="panel-body table-wrap">
                <table class="table table-bordered table-auto">
                    <thead>
                        <tr>
                            <th>出入口位置</th>
                            <th>使用的卡号</th>
                            <th>动作</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rows) === 0) { ?>
                            <tr><td colspan="4">暂无刷卡记录</td></tr>
                        <?php } ?>
                        <?php foreach ($rows as $row) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['passdoor']); ?></td>
                                <td><?php echo htmlspecialchars($row['cardid']); ?></td>
                                <td><?php echo htmlspecialchars($row['action']); ?></td>
                                <td><?php echo date('Y-m-d H:i:s', intval($row['time'])); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
