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

feishuOauthRenderLoading();

$redirectUri = feishuOauthRedirectUri();
$feishu = new anim210System\appLinkFeishu(true);
$tokenResult = $feishu->getUserAccessToken($_GET['code'], $redirectUri);
if (!$tokenResult['ok']) {
    feishuOauthFail('飞书登录失败：' . $tokenResult['message']);
}

$userData = is_array($tokenResult['data'] ?? null) ? $tokenResult['data'] : [];
if (empty($userData['open_id']) && !empty($userData['access_token'])) {
    $userInfoResult = $feishu->getUserInfo($userData['access_token']);
    if (!$userInfoResult['ok']) {
        feishuOauthFail('飞书登录失败：获取用户信息失败：' . $userInfoResult['message']);
    }
    $userInfoData = is_array($userInfoResult['data'] ?? null) ? $userInfoResult['data'] : [];
    $userData = array_merge($userData, $userInfoData);
} elseif (!empty($userData['access_token']) && empty($userData['name']) && empty($userData['email'])) {
    $userInfoResult = $feishu->getUserInfo($userData['access_token']);
    if ($userInfoResult['ok']) {
        $userInfoData = is_array($userInfoResult['data'] ?? null) ? $userInfoResult['data'] : [];
        $userData = array_merge($userData, $userInfoData);
    }
}

$openId = $userData['open_id'] ?? '';
if ($openId === '') {
    feishuOauthFail('飞书登录失败：未返回 open_id');
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
    'avatar_url' => feishuOauthAvatarUrl($userData, $employee['avatar_url'] ?? ''),
    'job_title' => feishuOauthProfileText($userData, ['job_title', 'jobTitle', 'position', 'title', 'employee_title', 'staff_title', 'work_title'], $employee['job_title'] ?? ''),
    'joined_at' => feishuOauthJoinedAt($userData, intval($employee['joined_at'] ?? 0)),
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
    feishuOauthFail('你的员工通行账号已禁用，无法登录');
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
$isPrivilegedUser = $adminUser && in_array($adminUser['type'], ['admin', 'readonly'], true);
if ($isPrivilegedUser) {
    $_SESSION['user'] = $adminUser['username'];
    $_SESSION['token'] = $token;
}

$_SESSION['member_open_id'] = $openId;
$_SESSION['member_name'] = $employee['name'] ?: ($userData['name'] ?? '');
$_SESSION['member_token'] = $token;
$_SESSION['token'] = $token;

$returnUrl = feishuOauthConsumeReturnUrl();
if ($returnUrl !== '') {
    feishuOauthFinish($returnUrl);
}
if ($isPrivilegedUser) {
    feishuOauthFinishByViewport('/?page=userpanel', '/?page=panel&module=home');
}
feishuOauthFinish('/?page=userpanel');

function feishuOauthRedirectUri()
{
    $redirectUri = Settings::get('feishu_oauth_redirect_uri', '');
    if ($redirectUri !== '') {
        return $redirectUri;
    }
    $scheme = anim210System\Utils::isHttps() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    return $scheme . $host . '/?page=feishu_oauth_callback';
}

function feishuOauthRenderLoading()
{
    Header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>飞书登录中</title><style>';
    echo 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f7fb;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;}';
    echo '.box{width:min(420px,calc(100vw - 40px));padding:32px 28px;background:#fff;border:1px solid #e5e7eb;box-shadow:0 12px 32px rgba(15,23,42,.08);}';
    echo '.spin{width:28px;height:28px;border:3px solid #d8f3ea;border-top-color:#14b887;border-radius:50%;animation:spin .9s linear infinite;margin-bottom:18px;}';
    echo 'h1{font-size:20px;line-height:1.4;margin:0 0 8px;font-weight:600;}p{margin:0;color:#6b7280;line-height:1.8;font-size:14px;word-break:break-word;}';
    echo 'a{display:inline-block;margin-top:18px;color:#14b887;text-decoration:none;}@keyframes spin{to{transform:rotate(360deg)}}';
    echo '</style></head><body><div class="box"><div class="spin"></div><h1 id="oauthStatus">正在验证飞书身份</h1><p id="oauthDetail">请稍候，系统正在完成登录。</p><a href="/?page=login" id="oauthBack" style="display:none;">返回登录</a></div>';
    for ($i = 0, $levels = ob_get_level(); $i < $levels; $i++) {
        @ob_flush();
    }
    @flush();
}

function feishuOauthFail($message)
{
    $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($messageJson === false) {
        $messageJson = '"登录失败，请联系管理员查看服务端日志。"';
    }
    echo '<script>';
    echo 'document.getElementById("oauthStatus").innerText="登录失败";';
    echo 'document.getElementById("oauthDetail").innerText=' . $messageJson . ';';
    echo 'document.getElementById("oauthBack").style.display="inline-block";';
    echo '</script></body></html>';
    exit;
}

function feishuOauthFinish($url)
{
    $urlJson = json_encode($url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($urlJson === false) {
        $urlJson = '"/?page=login"';
    }
    echo '<script>';
    echo 'document.getElementById("oauthStatus").innerText="登录成功";';
    echo 'document.getElementById("oauthDetail").innerText="正在进入系统。";';
    echo 'setTimeout(function(){location.href=' . $urlJson . ';},80);';
    echo '</script></body></html>';
    exit;
}

function feishuOauthFinishByViewport($userUrl, $adminUrl)
{
    $userUrlJson = json_encode($userUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $adminUrlJson = json_encode($adminUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($userUrlJson === false) {
        $userUrlJson = '"/?page=userpanel"';
    }
    if ($adminUrlJson === false) {
        $adminUrlJson = '"/?page=panel&module=home"';
    }
    echo '<script>';
    echo 'var userUrl=' . $userUrlJson . ',adminUrl=' . $adminUrlJson . ';';
    echo 'var isLandscape=window.innerWidth>window.innerHeight;';
    echo 'var isWideScreen=window.innerWidth>=1024;';
    echo 'var target=(isLandscape&&isWideScreen)?adminUrl:userUrl;';
    echo 'document.getElementById("oauthStatus").innerText="登录成功";';
    echo 'document.getElementById("oauthDetail").innerText=target===adminUrl?"正在进入管理后台。":"正在进入用户视图。";';
    echo 'setTimeout(function(){location.href=target;},80);';
    echo '</script></body></html>';
    exit;
}

function feishuOauthConsumeReturnUrl()
{
    $url = $_SESSION['feishu_oauth_return_url'] ?? '';
    unset($_SESSION['feishu_oauth_return_url']);
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '';
    }
    $path = $parts['path'] ?? '/';
    $query = $parts['query'] ?? '';
    if ($path !== '/') {
        return '';
    }
    parse_str($query, $params);
    if (!in_array($params['page'] ?? '', ['badgecard', 'userpanel', 'panel'], true)) {
        return '';
    }
    return '/' . ($query !== '' ? '?' . $query : '');
}

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

function feishuOauthAvatarUrl($userData, $fallback = '')
{
    if (isset($userData['avatar']) && is_array($userData['avatar'])) {
        foreach (['avatar_240', 'avatar_72', 'avatar_origin', 'avatar_url'] as $key) {
            if (!empty($userData['avatar'][$key])) {
                return (string)$userData['avatar'][$key];
            }
        }
    }
    foreach (['avatar_url', 'avatar_thumb', 'avatar_middle', 'avatar_big'] as $key) {
        if (!empty($userData[$key])) {
            return (string)$userData[$key];
        }
    }
    return (string)$fallback;
}

function feishuOauthProfileText($userData, $fields, $fallback = '')
{
    foreach ($fields as $field) {
        $text = feishuOauthValueText(feishuOauthFieldValue($userData, $field));
        if ($text !== '') {
            return $text;
        }
    }
    return (string)$fallback;
}

function feishuOauthJoinedAt($userData, $fallback = 0)
{
    foreach (['join_time', 'joinTime', 'joined_at', 'joinedAt', 'hire_date', 'hireDate', 'entry_time', 'entryTime', 'employment_time', 'employmentTime', 'onboard_time', 'onboardTime', 'start_time', 'startTime'] as $key) {
        $value = feishuOauthFieldValue($userData, $key);
        if (is_numeric($value)) {
            $timestamp = intval($value);
            if ($timestamp > 100000000000) {
                $timestamp = intval($timestamp / 1000);
            }
            return $timestamp > 0 ? $timestamp : intval($fallback);
        }
        $text = feishuOauthValueText($value);
        if ($text === '') {
            continue;
        }
        $timestamp = strtotime($text);
        if ($timestamp !== false) {
            return intval($timestamp);
        }
    }
    return intval($fallback);
}

function feishuOauthFieldValue($data, $field)
{
    if (!is_array($data) || $field === '') {
        return null;
    }
    if (array_key_exists($field, $data)) {
        return $data[$field];
    }
    foreach (['employee', 'employment', 'staff', 'work_info', 'employee_info', 'job_info', 'profile'] as $group) {
        if (isset($data[$group]) && is_array($data[$group]) && array_key_exists($field, $data[$group])) {
            return $data[$group][$field];
        }
    }
    return null;
}

function feishuOauthValueText($value)
{
    if (is_scalar($value)) {
        return trim((string)$value);
    }
    if (!is_array($value)) {
        return '';
    }
    foreach (['text', 'value', 'name', 'date', 'datetime', 'option_value'] as $key) {
        if (isset($value[$key]) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') {
            return trim((string)$value[$key]);
        }
    }
    foreach ($value as $item) {
        $text = feishuOauthValueText($item);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
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
