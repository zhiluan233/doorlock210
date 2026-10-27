<?php

namespace anim210System;

use anim210System;

OB_START();
SESSION_START();

define("APP_FILE", __DIR__);
define("ROOT", APP_FILE . '/../');

//核心库
require(ROOT . "/Core/vendor/autoload.php");
include(ROOT . "/config.php");
include(ROOT . "/Core/Utils.php");
include(ROOT . "/Core/DataBase.php");
include(ROOT . "/Core/Settings.php");
include(ROOT . "/Core/Migrator.php");
include(ROOT . "/Core/OperationLog.php");
include(ROOT . "/Core/UserCheck.php");

set_exception_handler(function (\Throwable $error) {
    error_log('doorlock request failed type=' . get_class($error) . ' code=' . $error->getCode());
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(503);
    header('Retry-After: 3');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset($_GET['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'retryable'=>true,'message'=>'数据服务暂时繁忙，请稍后重试'],JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>数据服务繁忙</title><body><h2>数据服务暂时繁忙</h2><p>本次数据未能加载，请稍后重试。</p><button onclick="location.reload()">重新加载</button></body></html>';
    }
});

/*

// 获取客户端IP地址
$client_ip = $_SERVER['REMOTE_ADDR'];
$is_public_callback = isset($_GET['action']) && $_GET['action'] === 'feishuWebhook';
$public_pages = ['openfeishu', 'badgecard', 'feishu_oauth_start', 'feishu_oauth_callback'];
$is_public_page = isset($_GET['page']) && in_array($_GET['page'], $public_pages, true);

// 定义白名单IP地址段
$whitelist = [
    '192.168.3.0/24',
    '10.0.0.0/8',
    '172.16.0.0/16'
];

// 函数：检查IP是否在某个IP段内
function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) {
        $range .= '/32';
    }
    list($range, $netmask) = explode('/', $range, 2);
    $range_decimal = ip2long($range);
    $ip_decimal = ip2long($ip);
    $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
    $netmask_decimal = ~ $wildcard_decimal;
    return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
}

// 检查客户端IP是否在白名单中
$is_allowed = false;
foreach ($whitelist as $range) {
    if (ip_in_range($client_ip, $range)) {
        $is_allowed = true;
        break;
    }
}

// 如果不在白名单中，返回403
if (!$is_allowed && !$is_public_callback && !$is_public_page) {
    header('HTTP/1.1 403 Forbidden');
    exit('内网系统，不支持公网访问！');
}

*/

//中间件
//include(ROOT . '/Middleware/Class.Alipay.php');
//include(ROOT . "/Middleware/Class.TencentSMS.php");
include(ROOT . "/Middleware/Class.Feishu.php");
include(ROOT . "/Middleware/Class.FeishuSync.php");
include(ROOT . "/Middleware/Class.FeishuEvent.php");
include(ROOT . "/Middleware/Class.DeviceApi.php");
include(ROOT . "/Middleware/Class.ApiCenter.php");
include(ROOT . "/Middleware/Class.PostHandler.php");

$conn = null;
$db = new anim210System\Database();

if (!(isset($_GET['action']) && $_GET['action'] === 'api')) {
    $migration = anim210System\Migrator::ensure();
    if (empty($migration['ok'])) {
        error_log('doorlock migration: ' . ($migration['message'] ?? 'unknown error'));
        http_response_code(503);
        header('Retry-After: 3');
        exit('数据库升级暂未完成，请稍后重试');
    }
}

//页面渲染类与路由中间件
include(ROOT . "/Core/Pages.php");
include(ROOT . "/Middleware/Class.Attendance.php");
include(ROOT . "/Middleware/Class.FocusCard.php");
include(ROOT . "/Middleware/Class.AttendanceModule.php");
include(ROOT . "/Core/DeviceCardSync.php");
include(ROOT . "/Middleware/Class.Router.php");
