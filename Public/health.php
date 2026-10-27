<?php
namespace anim210System;
define('ROOT',dirname(__DIR__));
require ROOT . '/config.php';
require ROOT . '/Core/RuntimeHealth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$expected = (string)($_config['apiCommonKey'] ?? '');
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($expected==='' || !hash_equals('Bearer ' . $expected,$authorization)) {
    http_response_code(403); echo json_encode(['ok'=>false]); exit;
}
$snapshot = RuntimeHealth::snapshot();
$fresh = is_array($snapshot) && time()-intval($snapshot['checked_at'] ?? 0)<=300;
if (!$fresh || empty($snapshot['ok'])) { http_response_code(503); }
echo json_encode(['fresh'=>$fresh,'health'=>$snapshot],JSON_UNESCAPED_UNICODE);
