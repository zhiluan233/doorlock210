<?php

namespace anim210System;

use anim210System;

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

$state = md5(mt_rand(0, 999999) . microtime(true));
$_SESSION['feishu_oauth_state'] = $state;

$authorizeUrl = Settings::get('feishu_oauth_authorize_url', 'https://open.feishu.cn/open-apis/authen/v1/index');
$url = $authorizeUrl . '?app_id=' . rawurlencode($appId) . '&redirect_uri=' . rawurlencode($redirectUri) . '&state=' . rawurlencode($state);
Header('Location: ' . $url);
exit;
