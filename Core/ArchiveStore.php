<?php
namespace anim210System;

require_once __DIR__ . '/OptionalRedisCache.php';

/** Immutable compressed row snapshots. Queryable fields stay online; large text is hydrated on read. */
class ArchiveStore {
    const PREFIX = '@doorlock-archive:v1:';
    private static $cache = [];

    public static function definitions()
    {
        return [
            ['attendance_source_records','punch_time',['raw_payload','warning_response']],
            ['attendance_queue','punch_time',['oa_response','feishu_response','message_response']],
            ['feishu_event_log','created_at',['payload']],
            ['attendance_effective_message_queue','effective_time',['raw_payload','message_response']],
            ['attendance_sync_jobs','created_at',['raw_payload','message']],
            ['attendance_effective_records','effective_time',['rule_snapshot']],
            ['attendance_daily_reports','work_date',['raw_trace','oa_response']],
            ['focus_card_message_queue','swipe_time',['response']],
            ['device_card_sync_jobs','created_at',['message']],
            ['logs','time_index',[]],
            ['operation_logs','created_at',[]]
        ];
    }

    private static function config()
    {
        return $GLOBALS['_config']['archive'] ?? [];
    }

    public static function hydrate($row)
    {
        if (!is_array($row)) { return $row; }
        foreach ($row as $key => $value) {
            if (!is_string($value) || strncmp($value, self::PREFIX, strlen(self::PREFIX)) !== 0) { continue; }
            if (!preg_match('/^@doorlock-archive:v1:([a-f0-9]{64}):([a-z_]+)$/D', $value, $match)) {
                throw new \RuntimeException('Invalid archive reference');
            }
            $snapshot = self::read($match[1]);
            if (!array_key_exists($match[2], $snapshot)) { throw new \RuntimeException('Archive field is missing'); }
            $row[$key] = $snapshot[$match[2]];
        }
        return $row;
    }

    public static function clearCache() { self::$cache = []; }

    public static function notifyWrite($table,$id,$data = [])
    {
        if (empty(self::config()['enabled']) || intval($id)<=0) { return; }
        foreach (self::definitions() as [$candidate,$clock,$fields]) {
            if ($candidate!==$table || count(array_intersect(array_keys($data),$fields))===0) { continue; }
            if (isset($data[$clock])) {
                $timestamp = $clock==='work_date' ? strtotime($data[$clock]) : intval($data[$clock]);
                if ($timestamp>=strtotime('-1 year')) { return; }
            }
            try {
                $nonce = bin2hex(random_bytes(8));
                $id = intval($id);
                self::query("INSERT INTO `runtime_archive_dirty` (`table_name`,`record_id`,`nonce`) VALUES ('{$table}',{$id},'{$nonce}') ON DUPLICATE KEY UPDATE `nonce`=VALUES(`nonce`)");
            } catch (\Throwable $e) {
                // The business value stays online if maintenance metadata is not installed yet.
                error_log('doorlock archive notification unavailable table=' . $table);
            }
            return;
        }
    }

    public static function read($token)
    {
        if (isset(self::$cache[$token])) { return self::$cache[$token]; }
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \RuntimeException('Invalid archive token'); }
        $entry = \mysqli_fetch_assoc(self::query("SELECT * FROM `runtime_archives` WHERE `token`='{$token}'"));
        if (!$entry || !preg_match('/^pack-[0-9]{6}-[a-f0-9]{16}\.bin$/D', $entry['file_name'])) {
            throw new \RuntimeException('Archive manifest is missing');
        }
        $cached = OptionalRedisCache::get($token);
        if (is_string($cached)) {
            $serialized = @gzdecode($cached,intval($entry['raw_length'])+1);
            if (is_string($serialized) && strlen($serialized)===intval($entry['raw_length'])
                && hash_equals($entry['sha256'],hash('sha256',$serialized))) {
                $row = unserialize($serialized,['allowed_classes'=>false]);
                if (is_array($row)) { return $row; }
            }
        }
        $directory = rtrim((string)(self::config()['directory'] ?? ''), '/\\');
        if ($directory === '') { throw new \RuntimeException('Archive directory is not configured'); }
        $file = @fopen($directory . '/' . $entry['file_name'], 'rb');
        if (!$file) { throw new \RuntimeException('Archive volume is unavailable'); }
        try {
            if (fseek($file, intval($entry['byte_offset'])) !== 0) { throw new \RuntimeException('Archive seek failed'); }
            $remaining = intval($entry['byte_length']);
            $compressed = '';
            while ($remaining > 0) {
                $chunk = fread($file, min(1048576, $remaining));
                if ($chunk === false || $chunk === '') { throw new \RuntimeException('Archive is truncated'); }
                $compressed .= $chunk;
                $remaining -= strlen($chunk);
            }
        } finally { fclose($file); }
        $serialized = gzdecode($compressed, intval($entry['raw_length']) + 1);
        if ($serialized === false || strlen($serialized) !== intval($entry['raw_length'])
            || !hash_equals($entry['sha256'], hash('sha256', $serialized))) {
            throw new \RuntimeException('Archive checksum mismatch');
        }
        $row = unserialize($serialized, ['allowed_classes' => false]);
        if (!is_array($row)) { throw new \RuntimeException('Invalid archive snapshot'); }
        if (count(self::$cache) >= 32) { array_shift(self::$cache); }
        self::$cache[$token] = $row;
        OptionalRedisCache::put($token,$compressed);
        return $row;
    }

    public static function runScheduled($forceWindow = false)
    {
        global $conn;
        $config = self::config();
        if (empty($config['enabled'])) { return ['ok'=>true,'status'=>'disabled']; }
        if (PHP_SAPI !== 'cli') { return ['ok'=>false,'status'=>'cli_required']; }
        if (!function_exists('fsync')) { return ['ok'=>false,'status'=>'requires_php_8_1_fsync']; }
        $zone = new \DateTimeZone($GLOBALS['_config']['timezone'] ?? 'Asia/Shanghai');
        $now = (new \DateTimeImmutable('@' . time()))->setTimezone($zone);
        $hour = intval($now->format('G'));
        if (!$forceWindow && ($hour < intval($config['start_hour'] ?? 2) || $hour >= intval($config['end_hour'] ?? 5))) {
            return ['ok'=>true,'status'=>'outside_window'];
        }
        $cutoff = $now->setTime(0,0)->modify('-1 year');
        $directory = rtrim((string)($config['directory'] ?? ''), '/\\');
        if ($directory === '') { return ['ok'=>false,'status'=>'directory_required']; }
        if (!is_dir($directory) && !@mkdir($directory,0700,true)) { return ['ok'=>false,'status'=>'directory_unavailable']; }
        $realDirectory = realpath($directory);
        $projectRoot = realpath(ROOT);
        if (!$realDirectory || ($projectRoot && ($realDirectory === $projectRoot || strpos($realDirectory,$projectRoot . DIRECTORY_SEPARATOR)===0))) {
            return ['ok'=>false,'status'=>'directory_must_be_outside_project'];
        }
        $free = disk_free_space($directory);
        if ($free === false || $free < max(0,intval($config['min_free_bytes'] ?? 2147483648))) {
            return ['ok'=>false,'status'=>'archive_disk_low'];
        }
        $lock = \mysqli_fetch_assoc(self::query("SELECT GET_LOCK(CONCAT('doorlock_archive:',SHA1(DATABASE())),0) AS acquired"));
        if (intval($lock['acquired'] ?? 0)!==1) { return ['ok'=>true,'status'=>'busy']; }
        try {
            self::ensureSchema();
            $definitions = self::definitions();
            $rotation = \mysqli_fetch_assoc(self::query("SELECT `last_id` FROM `runtime_archive_state` WHERE `table_name`='__rotation__'"));
            $position = intval($rotation['last_id'] ?? 0) % count($definitions);
            [$table,$clock,$fields] = $definitions[$position];
            if ($table==='logs') {
                $columns = array_column(\mysqli_fetch_all(self::query('SHOW COLUMNS FROM `logs`'),MYSQLI_ASSOC),'Type','Field');
                if (!isset($columns['time_index']) && preg_match('/int|decimal|double|float/', $columns['time'] ?? '')) { $clock='time'; }
            }
            // A keyset scan must have an index starting with its time field.
            $indexes = \mysqli_fetch_all(self::query("SHOW INDEX FROM `{$table}`"), MYSQLI_ASSOC);
            $indexed = false;
            foreach ($indexes as $index) {
                if (intval($index['Seq_in_index'])===1 && $index['Column_name']===$clock
                    && empty($index['Sub_part']) && ($index['Visible'] ?? 'YES')!=='NO') { $indexed=true; }
            }
            if (!$indexed) { return ['ok'=>false,'status'=>'index_required','table'=>$table,'column'=>$clock]; }
            $cursor = \mysqli_fetch_assoc(self::query("SELECT * FROM `runtime_archive_state` WHERE `table_name`='{$table}'"));
            $dateClock = $clock === 'work_date';
            $upper = self::escape($dateClock ? $cutoff->format('Y-m-d') : $cutoff->getTimestamp());
            $where = "`{$clock}`<'{$upper}'";
            if ($cursor && $cursor['last_time']!=='') {
                $last = self::escape($cursor['last_time']);
                $lastId = intval($cursor['last_id']);
                $where .= " AND (`{$clock}`>'{$last}' OR (`{$clock}`='{$last}' AND `id`>{$lastId}))";
            }
            $limit = max(1,min(1000,intval($config['batch_size'] ?? 1000)));
            // Deliberately use the native fetch: a row already archived must not be re-expanded here.
            $rows = \mysqli_fetch_all(self::query("SELECT * FROM `{$table}` WHERE {$where} ORDER BY `{$clock}`,`id` LIMIT {$limit}"),MYSQLI_ASSOC);
            $dirty = \mysqli_fetch_all(self::query("SELECT * FROM `runtime_archive_dirty` WHERE `table_name`='{$table}' ORDER BY `record_id` LIMIT 100"),MYSQLI_ASSOC);
            $toArchive = [];
            foreach ($rows as $row) { $toArchive[$row['id']]=$row; }
            foreach ($dirty as $notification) {
                $id = intval($notification['record_id']);
                $row = \mysqli_fetch_assoc(self::query("SELECT * FROM `{$table}` WHERE `id`={$id} AND `{$clock}`<'{$upper}'"));
                if ($row) { $toArchive[$row['id']]=$row; }
            }
            $result = self::archiveBatch($table,$clock,$fields,array_values($toArchive),$directory,$now->format('Ym'));
            foreach ($dirty as $notification) {
                $id=intval($notification['record_id']); $nonce=self::escape($notification['nonce']);
                self::query("DELETE FROM `runtime_archive_dirty` WHERE `table_name`='{$table}' AND `record_id`={$id} AND `nonce`='{$nonce}'");
            }
            $lastRow = count($rows)>0 ? $rows[count($rows)-1] : null;
            // Keep the frontier when caught up. Resetting it would rescan all archived history forever.
            $lastTime = self::escape($lastRow[$clock] ?? ($cursor['last_time'] ?? ''));
            $lastId = intval($lastRow['id'] ?? ($cursor['last_id'] ?? 0));
            self::query("INSERT INTO `runtime_archive_state` (`table_name`,`last_time`,`last_id`) VALUES ('{$table}','{$lastTime}',{$lastId}) ON DUPLICATE KEY UPDATE `last_time`=VALUES(`last_time`),`last_id`=VALUES(`last_id`)");
            $next = ($position+1) % count($definitions);
            self::query("INSERT INTO `runtime_archive_state` (`table_name`,`last_time`,`last_id`) VALUES ('__rotation__','',{$next}) ON DUPLICATE KEY UPDATE `last_id`=VALUES(`last_id`)");
            return ['ok'=>true,'status'=>'complete','table'=>$table,'cutoff'=>$cutoff->format('Y-m-d'),'scanned'=>count($rows)] + $result;
        } catch (\Throwable $e) {
            return ['ok'=>false,'status'=>'failed','message'=>$e->getMessage()];
        } finally {
            self::query("SELECT RELEASE_LOCK(CONCAT('doorlock_archive:',SHA1(DATABASE())))");
        }
    }

    private static function archiveBatch($table,$clock,$fields,$rows,$directory,$month)
    {
        global $conn;
        $fileName = 'pack-' . $month . '-0000000000000000.bin';
        $file = null;
        $entries = [];
        try {
            foreach ($rows as $row) {
                $eligible = [];
                foreach ($fields as $field) {
                    $value = $row[$field] ?? null;
                    if (is_string($value) && strlen($value)>256 && strpos($value,self::PREFIX)!==0) { $eligible[]=$field; }
                }
                if (count($fields)>0 && count($eligible)===0) {
                    $alreadyArchived = false;
                    foreach ($fields as $field) {
                        if (isset($row[$field]) && strpos((string)$row[$field],self::PREFIX)===0) { $alreadyArchived=true; }
                    }
                    if ($alreadyArchived) { continue; }
                }
                // Hydrate pre-existing references before taking a new complete row snapshot.
                $snapshot = self::hydrate($row);
                $serialized = serialize($snapshot);
                $sha = hash('sha256',$serialized);
                $token = hash('sha256',$table . '|' . $row['id'] . '|' . $sha);
                if (\mysqli_fetch_assoc(self::query("SELECT `token` FROM `runtime_archives` WHERE `token`='{$token}'"))) { continue; }
                if (strlen($serialized)>33554432) { continue; } // Never truncate oversized rows.
                $compressed = gzencode($serialized,6);
                if ($compressed===false) { throw new \RuntimeException('Archive compression failed'); }
                if (!$file) {
                    $tail = \mysqli_fetch_assoc(self::query("SELECT `last_id` FROM `runtime_archive_state` WHERE `table_name`='__pack_{$month}'"));
                    $expected = intval($tail['last_id'] ?? 0);
                    $path = $directory . '/' . $fileName;
                    if ($expected>0 && (!is_file($path) || filesize($path)<$expected)) {
                        throw new \RuntimeException('Archive pack is missing or older than database manifest');
                    }
                    $file = fopen($path,'c+b');
                    if (!flock($file,LOCK_EX) || fseek($file,0,SEEK_END)!==0) { throw new \RuntimeException('Archive file lock failed'); }
                    chmod($directory . '/' . $fileName,0600);
                }
                $header = json_encode(['token'=>$token,'table'=>$table,'id'=>$row['id'],'length'=>strlen($compressed),'raw_length'=>strlen($serialized),'sha256'=>$sha],JSON_THROW_ON_ERROR) . "\n";
                $free=disk_free_space($directory);
                $reserve=max(0,intval(self::config()['min_free_bytes'] ?? 2147483648));
                if ($free===false || $free<$reserve+strlen($header)+strlen($compressed)+1) {
                    throw new \RuntimeException('Archive disk reserve reached; online payloads retained');
                }
                self::writeAll($file,$header);
                $offset = ftell($file);
                self::writeAll($file,$compressed . "\n");
                $entries[] = [$row,$eligible,$token,$sha,$offset,strlen($compressed),strlen($serialized)];
            }
            if (!$file) { return ['archived'=>0,'offloaded_fields'=>0]; }
            if (!fflush($file) || !fsync($file)) { throw new \RuntimeException('Archive fsync failed'); }
            $packEnd = ftell($file);
            fclose($file); $file=null;
            $directoryHandle = fopen($directory,'r');
            try {
                if (!fsync($directoryHandle)) { throw new \RuntimeException('Archive directory fsync failed'); }
            } finally { fclose($directoryHandle); }
            // Verify each durable chunk before replacing any online payload with its reference.
            $verification = fopen($directory . '/' . $fileName,'rb');
            if (!$verification) { throw new \RuntimeException('Archive read-back failed'); }
            try {
                foreach ($entries as [$row,$eligible,$token,$sha,$offset,$length,$rawLength]) {
                    if (fseek($verification,$offset)!==0) { throw new \RuntimeException('Archive read-back seek failed'); }
                    $bytes = stream_get_contents($verification,$length);
                    $raw = is_string($bytes) && strlen($bytes)===$length ? @gzdecode($bytes,$rawLength+1) : false;
                    if (!is_string($raw) || strlen($raw)!==$rawLength || !hash_equals($sha,hash('sha256',$raw))) {
                        throw new \RuntimeException('Archive read-back checksum failed');
                    }
                }
            } finally { fclose($verification); }
            // Files and directory entries are durable BEFORE any database reference is committed.
            if (!$conn->begin_transaction()) { throw new \RuntimeException('Archive transaction could not start'); }
            $offloaded = 0;
            try {
                foreach ($entries as [$row,$eligible,$token,$sha,$offset,$length,$rawLength]) {
                    $id = intval($row['id']);
                    self::query("INSERT IGNORE INTO `runtime_archives` (`token`,`table_name`,`record_id`,`file_name`,`byte_offset`,`byte_length`,`raw_length`,`sha256`,`created_at`) VALUES ('{$token}','{$table}',{$id},'{$fileName}',{$offset},{$length},{$rawLength},'{$sha}'," . time() . ')');
                    foreach ($eligible as $field) {
                        $old = self::escape($row[$field]);
                        $marker = self::escape(self::PREFIX . $token . ':' . $field);
                        // Optimistic exact-byte compare: concurrent updates are never overwritten.
                        self::query("UPDATE `{$table}` SET `{$field}`='{$marker}' WHERE `id`={$id} AND BINARY `{$field}`=BINARY '{$old}'");
                        $offloaded += \mysqli_affected_rows($conn);
                    }
                }
                self::query("INSERT INTO `runtime_archive_state` (`table_name`,`last_time`,`last_id`) VALUES ('__pack_{$month}','',{$packEnd}) ON DUPLICATE KEY UPDATE `last_id`=VALUES(`last_id`)");
                if (!$conn->commit()) { throw new \RuntimeException('Archive transaction could not commit'); }
            } catch (\Throwable $e) { $conn->rollback(); throw $e; }
            self::clearCache();
            return ['archived'=>count($entries),'offloaded_fields'=>$offloaded];
        } finally { if ($file) { fclose($file); } }
    }

    private static function writeAll($file,$bytes)
    {
        $written = 0;
        while ($written<strlen($bytes)) {
            $count = fwrite($file,substr($bytes,$written));
            if (!$count) { throw new \RuntimeException('Archive write failed'); }
            $written += $count;
        }
    }

    public static function ensureSchema()
    {
        self::query("CREATE TABLE IF NOT EXISTS `runtime_archives` (
            `token` char(64) NOT NULL PRIMARY KEY, `table_name` varchar(64) NOT NULL,
            `record_id` bigint unsigned NOT NULL, `file_name` varchar(64) NOT NULL,
            `byte_offset` bigint unsigned NOT NULL, `byte_length` int unsigned NOT NULL,
            `raw_length` int unsigned NOT NULL, `sha256` char(64) NOT NULL,
            `created_at` int unsigned NOT NULL, KEY `idx_archive_record` (`table_name`,`record_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii");
        self::query("CREATE TABLE IF NOT EXISTS `runtime_archive_state` (
            `table_name` varchar(64) NOT NULL PRIMARY KEY, `last_time` varchar(32) NOT NULL DEFAULT '',
            `last_id` bigint unsigned NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii");
        self::query("CREATE TABLE IF NOT EXISTS `runtime_archive_dirty` (
            `table_name` varchar(64) NOT NULL, `record_id` bigint unsigned NOT NULL,
            `nonce` char(16) NOT NULL, PRIMARY KEY (`table_name`,`record_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii");
    }
    private static function escape($value) { return \mysqli_real_escape_string($GLOBALS['conn'],(string)$value); }
    private static function query($sql) {
        $result = Database::executeQuery($GLOBALS['conn'],$sql);
        if ($result===false) { throw new \RuntimeException('Archive database operation failed'); }
        return $result;
    }
}
