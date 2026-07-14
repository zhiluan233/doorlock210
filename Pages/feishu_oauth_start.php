<?php
/*

飞书一键登录跳转模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

if (!Settings::getBool('feishu_oauth_enabled')) {
    exit('飞书一键登录未启用');
}

$appId = Settings::get('feishu_app_id', '');
if ($appId === '') {
    exit('飞书 App ID 未在 config.php 中配置');
}

$redirectUri = Settings::get('feishu_oauth_redirect_uri', '');
if ($redirectUri === '') {
    $scheme = anim210System\Utils::isHttps() ? 'https://' : 'http://';
    $redirectUri = $scheme . $_SERVER['HTTP_HOST'] . '/?page=feishu_oauth_callback';
}

$returnUrl = feishuOauthStartReturnUrl($_GET['redirect'] ?? ($_GET['return'] ?? ''));
if ($returnUrl !== '') {
    $_SESSION['feishu_oauth_return_url'] = $returnUrl;
}

$state = md5(mt_rand(0, 999999) . microtime(true));
$_SESSION['feishu_oauth_state'] = $state;

$authorizeUrl = Settings::get('feishu_oauth_authorize_url', '');
if ($authorizeUrl === '') {
    exit('飞书 oauthAuthorize endpoint 未在 config.php 中配置');
}

$params = [
    'client_id' => $appId,
    'response_type' => 'code',
    'redirect_uri' => $redirectUri,
    'state' => $state
];
$scope = trim(Settings::get('feishu_oauth_scope', ''));
if ($scope !== '') {
    $params['scope'] = $scope;
}
$prompt = trim(Settings::get('feishu_oauth_prompt', ''));
if ($prompt !== '') {
    $params['prompt'] = $prompt;
}

$separator = strpos($authorizeUrl, '?') === false ? '?' : '&';
$url = $authorizeUrl . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
Header('Location: ' . $url);
exit;

function feishuOauthStartReturnUrl($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '';
    }
    $path = $parts['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    if ($path !== '/') {
        return '';
    }
    $query = $parts['query'] ?? '';
    parse_str($query, $params);
    $page = $params['page'] ?? '';
    if (!in_array($page, ['badgecard', 'userpanel', 'panel'], true)) {
        return '';
    }
    return $path . ($query !== '' ? '?' . $query : '');
}
