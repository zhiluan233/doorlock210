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

$userProfile = [
    'open_id' => $openId,
    'employee_id' => $employee['employee_id'] ?? '',
    'display_name' => $employee['name'] ?: ($userData['name'] ?? ''),
    'mail' => $employee['email'] ?? ($userData['email'] ?? '')
];
$displayName = feishuOauthDisplayName($employee, $userData);
if ($adminUser) {
    $userProfile['username'] = feishuOauthUniqueUsername($displayName, $employee['employee_id'] ?? '', $openId, intval($adminUser['id']));
    Database::update('user', $userProfile, ['id' => $adminUser['id']]);
    $adminUser = Database::querySingleLine('user', ['id' => $adminUser['id']]);
} else {
    $userProfile['id'] = null;
    $userProfile['username'] = feishuOauthUniqueUsername($displayName, $employee['employee_id'] ?? '', $openId);
    $userProfile['password'] = md5($openId . time() . mt_rand(1000, 9999));
    $userProfile['type'] = 'user';
    Database::insert('user', $userProfile);
    $adminUser = Database::querySingleLine('user', ['open_id' => $openId]);
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

function feishuOauthDisplayName($employee, $userData)
{
    $name = $employee['name'] ?? '';
    if ($name === '' && isset($employee['realname']) && $employee['realname'] !== '--') {
        $name = $employee['realname'];
    }
    if ($name === '') {
        $name = $userData['name'] ?? $userData['en_name'] ?? $userData['nickname'] ?? '';
    }
    if ($name === '' && !empty($employee['email'])) {
        $name = explode('@', $employee['email'])[0];
    }
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($name));
    return $name !== '' ? $name : '飞书用户';
}

function feishuOauthUniqueUsername($displayName, $employeeId, $openId, $currentUserId = 0)
{
    $base = feishuOauthUtf8Limit($displayName, 24);
    $suffixes = [''];
    if ($employeeId !== '') {
        $suffixes[] = '_' . preg_replace('/[^A-Za-z0-9\_\-]/', '', $employeeId);
    }
    $suffixes[] = '_' . substr(hash('sha256', $openId), 0, 6);

    foreach ($suffixes as $suffix) {
        $candidate = feishuOauthUtf8Limit($base, 32 - feishuOauthUtf8Length($suffix)) . $suffix;
        $exists = Database::querySingleLine('user', ['username' => $candidate]);
        if (!$exists || intval($exists['id']) === intval($currentUserId)) {
            return $candidate;
        }
    }

    return '飞书用户_' . substr(hash('sha256', $openId), 0, 8);
}

function feishuOauthUtf8Limit($value, $limit)
{
    $limit = max(1, intval($limit));
    if (preg_match_all('/./u', $value, $matches) === false) {
        return substr($value, 0, $limit);
    }
    return implode('', array_slice($matches[0], 0, $limit));
}

function feishuOauthUtf8Length($value)
{
    if (preg_match_all('/./u', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
}
