<?php
namespace anim210System;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
if (in_array('--help', $argv, true)) {
    echo "php tools/optimize_indexes.php [--check|--apply]\n默认只检查；--apply 在线补齐缺少的非唯一索引，不修改业务数据。\n";
    exit;
}
foreach (array_slice($argv, 1) as $arg) {
    if (!in_array($arg, ['--check', '--apply'], true)) {
        fwrite(STDERR, "未知参数：{$arg}\n");
        exit(2);
    }
}

define('ROOT', dirname(__DIR__));
require ROOT . '/config.php';
require ROOT . '/Core/DataBase.php';
require ROOT . '/Core/PerformanceIndexes.php';

try {
    $db = new Database();
    $result = PerformanceIndexes::run(in_array('--apply', $argv, true));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, '索引部署失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
