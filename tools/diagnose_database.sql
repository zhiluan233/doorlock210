-- 先选择 doorlock 使用的数据库，再执行本文件。所有语句只读。
-- 不对历史大表执行 COUNT(*) 或全表 JSON 聚合。

SELECT VERSION() AS mysql_version, DATABASE() AS database_name;

SELECT table_name, engine, table_rows AS estimated_rows,
       ROUND(data_length / 1024 / 1024, 2) AS data_mb,
       ROUND(index_length / 1024 / 1024, 2) AS index_mb,
       ROUND(data_free / 1024 / 1024, 2) AS reusable_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY data_length + index_length DESC
LIMIT 15;

SELECT id, time AS elapsed_seconds, state, LEFT(info, 300) AS current_sql
FROM information_schema.processlist
WHERE db = DATABASE() AND command <> 'Sleep'
ORDER BY time DESC
LIMIT 30;

SHOW INDEX FROM logs;
SHOW INDEX FROM attendance_source_records;

-- 最近 1000 行采样；row_count 仅代表样本，不能当作全表数量。
SELECT source, source_kind, COUNT(*) AS sample_rows,
       MIN(punch_date) AS sample_first_day, MAX(punch_date) AS sample_last_day,
       ROUND(AVG(OCTET_LENGTH(raw_payload))) AS avg_payload_bytes,
       MAX(OCTET_LENGTH(raw_payload)) AS max_payload_bytes,
       SUM(raw_payload LIKE '%"allowedEmployee"%') AS full_device_snapshot_rows,
       SUM(raw_payload LIKE '%"records"%' OR raw_payload LIKE '%"user_flows"%'
           OR raw_payload LIKE '%"flow_records"%') AS bulk_payload_rows
FROM (
    SELECT source, source_kind, punch_date, raw_payload
    FROM attendance_source_records
    ORDER BY id DESC
    LIMIT 1000
) AS recent_sources
GROUP BY source, source_kind;

-- 最大的 10 条样本，仅输出大小和 ID，不输出员工资料或完整原文。
SELECT id, source, source_kind, punch_date, OCTET_LENGTH(raw_payload) AS payload_bytes
FROM (
    SELECT id, source, source_kind, punch_date, raw_payload
    FROM attendance_source_records
    ORDER BY id DESC
    LIMIT 1000
) AS recent_sources
ORDER BY payload_bytes DESC
LIMIT 10;
