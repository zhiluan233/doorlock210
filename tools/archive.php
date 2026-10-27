<?php
namespace anim210System;
if (PHP_SAPI!=='cli') { http_response_code(403); exit('CLI only'); }
if (in_array('--help',$argv,true)) {
    echo "php tools/archive.php [--run|--verify|--rescan]\n--run respects the configured night window; --verify checks every manifest and SHA-256; --rescan resets scan cursors after manual historical SQL imports.\n";
    exit;
}
if (count($argv)>2 || (isset($argv[1]) && !in_array($argv[1],['--run','--verify','--rescan'],true))) { fwrite(STDERR,"Invalid arguments\n"); exit(2); }
define('ROOT',dirname(__DIR__));
require ROOT . '/config.php';
require ROOT . '/Core/DataBase.php';
try {
    $db = new Database();
    if (($argv[1] ?? '--run')==='--rescan') {
        ArchiveStore::ensureSchema();
        foreach (ArchiveStore::definitions() as [$table]) {
            Database::executeQuery($conn,"DELETE FROM `runtime_archive_state` WHERE `table_name`='{$table}'");
        }
        $result=['ok'=>true,'status'=>'scan_cursors_reset','message'=>'下一轮夜间归档将重新检查历史行，归档包和清单未修改'];
    } elseif (($argv[1] ?? '--run')==='--verify') {
        $last=''; $verified=0;
        do {
            $safe=\mysqli_real_escape_string($conn,$last);
            $rows=\mysqli_fetch_all(Database::executeQuery($conn,"SELECT `token` FROM `runtime_archives` WHERE `token`>'{$safe}' ORDER BY `token` LIMIT 200"),MYSQLI_ASSOC);
            foreach ($rows as $row) { ArchiveStore::read($row['token']); $last=$row['token']; $verified++; }
        } while (count($rows)===200);
        $result=['ok'=>true,'verified'=>$verified];
    } else { $result=ArchiveStore::runScheduled(); }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
    exit(empty($result['ok']) ? 1 : 0);
} catch (\Throwable $e) { fwrite(STDERR,'Archive failed: ' . $e->getMessage() . PHP_EOL); exit(1); }
