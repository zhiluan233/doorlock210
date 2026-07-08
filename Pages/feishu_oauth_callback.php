<?php
/*

飞书一键登录回调模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

if (!Settings::getBool('feishu_oauth_enabled')) {
    exit('飞书一键登录未启用');
}

if (!isset($_GET['code']) || $_GET['code'] === '') {
    exit('缺少飞书授权码');
}

if (isset($_SESSION['feishu_oauth_state']) && isset($_GET['state']) && $_SESSION['feishu_oauth_state'] !== $_GET['state']) {
    exit('飞书登录 state 校验失败');
}
unset($_SESSION['feishu_oauth_state']);

$feishu = new anim210System\appLinkFeishu(true);
$tokenResult = $feishu->getUserAccessToken($_GET['code']);
if (!$tokenResult['ok']) {
    exit('飞书登录失败：' . $tokenResult['message']);
}

$userData = $tokenResult['data'];
if (!empty($userData['access_token'])) {
    $userInfoResult = $feishu->getUserInfo($userData['access_token']);
    if ($userInfoResult['ok']) {
        $userData = array_merge($userData, $userInfoResult['data']);
    }
}

$openId = $userData['open_id'] ?? '';
if ($openId === '') {
    exit('飞书登录失败：未返回 open_id');
}

$employee = Database::querySingleLine('employee', ['open_id' => $openId]);
$employeeData = [
    'open_id' => $openId,
    'user_id' => $userData['user_id'] ?? ($employee['user_id'] ?? ''),
    'union_id' => $userData['union_id'] ?? ($employee['union_id'] ?? ''),
    'name' => $userData['name'] ?? ($employee['name'] ?? ''),
    'employee_id' => $userData['employee_no'] ?? ($employee['employee_id'] ?? ''),
    'realname' => $employee['realname'] ?? '--',
    'email' => $userData['email'] ?? ($employee['email'] ?? ''),
    'mobile' => $userData['mobile'] ?? ($employee['mobile'] ?? ''),
    'tenant_key' => $userData['tenant_key'] ?? ($employee['tenant_key'] ?? ''),
    'status' => $employee['status'] ?? 'true',
    'updated_at' => time()
];
if ($employee) {
    unset($employeeData['open_id']);
    Database::update('employee', $employeeData, ['open_id' => $openId]);
    $employee = Database::querySingleLine('employee', ['open_id' => $openId]);
} else {
    Database::insert('employee', $employeeData);
    $employee = Database::querySingleLine('employee', ['open_id' => $openId]);
}

if (($employee['status'] ?? 'true') !== 'true') {
    exit('你的员工通行账号已禁用，无法登录');
}

$adminUser = Database::querySingleLine('user', ['open_id' => $openId]);
if (!$adminUser && !empty($employee['employee_id'])) {
    $adminUser = Database::querySingleLine('user', ['employee_id' => $employee['employee_id']]);
}
if (!$adminUser) {
    $adminUser = Database::querySingleLine('user', ['username' => $openId]);
}

$token = md5(mt_rand(0, 999999) . time() . $openId);
if ($adminUser && in_array($adminUser['type'], ['admin', 'readonly'], true)) {
    $_SESSION['user'] = $adminUser['username'];
    $_SESSION['token'] = $token;
    exit("<script>location='/?page=panel&module=home';</script>");
}

$_SESSION['member_open_id'] = $openId;
$_SESSION['member_name'] = $employee['name'] ?: ($userData['name'] ?? '');
$_SESSION['member_token'] = $token;
$_SESSION['token'] = $token;
exit("<script>location='/?page=userpanel';</script>");
