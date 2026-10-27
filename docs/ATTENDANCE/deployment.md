# 考勤模块部署与配置

## PHP 与计划任务

基础运行环境要求 PHP 8.0；启用一年以上数据自动归档时，CLI 必须使用 64 位 PHP 8.1+（需要 `fsync`），Web 与 CLI 推荐统一版本。数据库使用 MySQL 8.0。

推荐每分钟执行一次根目录计划任务：

```bash
php /home/210official/doorlock/cron.php
```

`cron.php` 会处理以下任务：

- 处理原有 OA / 飞书 / 机器人刷卡提醒队列，以及重点卡片加急队列。
- 检查是否到达考勤全量同步时间。
- 唤醒 `attendance_worker.php` 处理考勤任务。
- 分批清理悬空角色成员；夜间归档一年以前的数据；写入磁盘容量、数据库持久化配置和表大小的健康快照。

如果 PHP Web 环境禁用了 `exec`，仍可单独由守护任务调用：

```bash
php /home/210official/doorlock/attendance_worker.php
```

`attendance_worker.php` 自带锁，重复执行会直接退出，不会并发处理同一批考勤任务。

生产请将 `phpCliBinary` 设置为实际 CLI PHP 的绝对路径。刷卡普通消息与重点卡片加急消息由新增 `message_worker.php` 独立消费，HTTP 结束时只唤醒 CLI，不再在 FPM 进程里发送消息。唤醒有并发锁和节流；失败/未唤醒的已入库消息仍由每分钟 cron 补偿。未开放 `exec` 时必须配置 CLI cron，或用守护任务执行 `message_worker.php`、`attendance_worker.php`、`device_card_worker.php`；Web 请求不会再以长期后台工作兜底。消息内容、加急渠道和可靠重试记录保留，繁忙时发送时间由队列调度决定。

## config.php

飞书凭证继续放在 `config.php`，不要写入系统设置：

```php
'feishu' => [
    'appId' => '',
    'appSecret' => '',
    'eventToken' => '',
    'eventEncryptKey' => '',
    'attendanceFlowComment' => '门禁刷卡自动同步',
    'appEndpoint' => [
        'batchCreateAttendanceFlow' => 'https://open.feishu.cn/open-apis/attendance/v1/user_flows/batch_create',
        'attendanceUserFlowsQuery' => 'https://open.feishu.cn/open-apis/attendance/v1/user_flows/query',
        'attendanceUserFlowGet' => 'https://open.feishu.cn/open-apis/attendance/v1/user_flows/{user_flow_id}',
        'attendanceGroupsList' => 'https://open.feishu.cn/open-apis/attendance/v1/groups',
        'attendanceGroupGet' => 'https://open.feishu.cn/open-apis/attendance/v1/groups/{group_id}',
        'attendanceShiftGet' => 'https://open.feishu.cn/open-apis/attendance/v1/shifts/{shift_id}',
        'attendanceUserDailyShiftsQuery' => 'https://open.feishu.cn/open-apis/attendance/v1/user_daily_shifts/query',
        'attendanceGroupListUser' => 'https://open.feishu.cn/open-apis/attendance/v1/groups/{group_id}/list_user',
        'urgentAppMessage' => 'https://open.feishu.cn/open-apis/im/v1/messages/{message_id}/urgent_app?user_id_type=open_id',
        'urgentSmsMessage' => 'https://open.feishu.cn/open-apis/im/v1/messages/{message_id}/urgent_sms?user_id_type=open_id',
        'urgentPhoneMessage' => 'https://open.feishu.cn/open-apis/im/v1/messages/{message_id}/urgent_phone?user_id_type=open_id'
    ]
]
```

AMT 凭证也继续放在 `config.php` 的 `oa.appId` 和 `oa.appSecret`。

## 系统设置

后台 `系统设置` 需要确认：

- `重点关注卡片`：添加一个或多个精确匹配卡号、一个或多个飞书接收人，并选择应用内、短信、电话加急；三种方式可同时勾选。
- `启用模块`：启用 P0 考勤模块。
- `配对间隔秒`：默认 300 秒。
- `有效时间`：双认证周期内有效考勤时间取值规则，默认“最后一次认证”，可切换为“最早一次认证”；切换后只影响后续计算，不自动重算历史日报。
- `迟到宽限秒`：默认 60 秒。
- `免工牌点位`：异地分子公司、外部人脸点位前缀。
- `直记Type`：飞书流水 `type` 白名单，命中后直接记有效考勤，不进行双验证，也不推送有效考勤成功提醒。
- `直记考勤组`：完全依赖飞书假勤的考勤组白名单，命中后该组任意飞书流水直接记有效考勤；优先级高于 Type 设置。
- `有效提醒`：有效考勤生成后向员工推送飞书卡片消息，失败进入重试队列。
- `提醒标题` / `提醒卡片` / `提醒批量`：配置有效考勤提醒的卡片标题、Markdown 或飞书卡片 JSON、单轮发送数量。
- `补刷提醒`：单边刷脸/刷卡在配对截止前仍未完成双验证时，向员工推送红色飞书卡片；仅上班时间及以前、下班时间及以后触发。
- `补刷标题` / `补刷卡片` / `提前秒数` / `补刷批量`：配置补刷提醒文案、提前提醒秒数和单轮发送数量。
- `全量同步`：启用后按配置时间自动同步。
- `同步时间`：建议配置 `13:00,13:30,14:00`，用于中午闲时多次校准当天数据。
- `同步天数`：保留给管理员手动同步范围使用；计划任务不会自动重算昨天及以前的封存日报。
- `单批员工`：最多 50，和飞书接口一致。
- `重算批量`：默认 200，适合 2000 人规模分批重算。
- `AMT推送`：预留有效考勤日报推送接口，默认关闭。
- `导出字段`：控制日报 Excel 字段。

## 飞书应用权限

需要在飞书开放平台为自建应用开通以下权限：

- `attendance:task`：写入打卡数据，用于原有工牌流水导入。
- `attendance:task:readonly`：导出打卡数据，用于批量查询打卡流水和获取单条流水。
- `attendance:rule:readonly` 或“导出打卡管理规则”：读取考勤组、考勤组成员和班次详情。
- 每日班表查询所需权限：按飞书开放平台“查询班表信息”接口要求开通，并确保数据权限范围覆盖所有需要计算考勤的员工。
- `contact:user:readonly`、`contact:department:readonly`：已有通讯录同步需要。
- `im:message`：已有刷卡机器人卡片提醒需要。
- 消息加急相关权限：按飞书开放平台的应用内、短信、电话加急接口要求开通；短信和电话加急会消耗企业额度。

应用数据权限范围必须覆盖需要计算考勤的员工。

## 飞书事件订阅

事件订阅入口：

```text
https://你的域名/?action=feishuWebhook
```

需要订阅：

- 用户打卡成功事件：`attendance.user_flow.created_v1`
- 已有通讯录/人事入离职事件继续保持订阅。

事件 Token 和 Encrypt Key 使用 `config.php` 中的：

- `$_config['feishu']['eventToken']`
- `$_config['feishu']['eventEncryptKey']`

## 验收建议

1. 在系统设置启用考勤模块，配置免工牌点位前缀。
2. 在考勤管理点击“同步考勤组”，确认考勤组和成员数出现。
3. 选择今天点击“同步流水”，确认任务状态推进。
4. 员工刷工牌后 5 分钟内做人脸打卡，后台日报应出现有效考勤。
5. 在免工牌点位做人脸打卡，不刷工牌，也应出现有效考勤。
6. 点击日报“溯源”，确认能看到源流水、配对间隔和外部记录 ID。
7. 用“重算日报”按钮手动指定日期范围，确认封存日报只在手动范围任务中更新。
8. 导出 Excel，确认字段符合系统设置，且没有 JSON 原文。
9. 在重点关注卡片中同时勾选应用内、短信、电话加急，用重点卡片执行一次允许开门和一次拒绝开门刷卡，确认两次均发送红色卡片，且 `focus_card_message_queue` 三个渠道状态均为 `sent`。

## 故障排查

- 2026-10-27 高峰期网页打不开的排查与恢复顺序见下方“高峰期数据库卡顿”。
- 看不到飞书流水：检查 `attendance:task:readonly`、应用数据权限范围和 `attendanceUserFlowsQuery` endpoint。
- 考勤组时间全部相同：检查 `attendanceShiftGet` endpoint、班次详情读取权限，以及 `attendance_shifts` 是否写入了 `start_time/end_time`。
- 节假日或特殊日被算缺勤：检查 `attendanceUserDailyShiftsQuery` endpoint、每日班表读取权限，以及 `attendance_daily_schedules` 当天是否存在 `need_punch=0` 记录。
- 事件不进系统：检查事件订阅 URL、Token、Encrypt Key 和事件类型；用户打卡成功事件如果缺少点位，系统会按 `record_id` 补查单条流水。
- 免工牌点位不生效：确认配置的是飞书 `location_name` 的前缀，不是设备名。
- 直记考勤组不生效：先同步考勤组，再在系统设置中勾选；如果日报使用每日班表，确认 `attendance_daily_schedules.feishu_group_id` 已写入。
- 直记Type不生效：检查 `attendance_source_records.flow_type` 是否已写入；历史 raw 中有 `type` 的记录会在重算时逐步回填。
- 日报缺少工牌证据：确认本地 `logs` 有员工开门成功记录；全量同步会回填历史 `logs`，如果本地历史缺失，会使用飞书 `工牌-` 流水作为历史工牌证据参与计算。
- 历史日报没有自动变化：这是按天封存策略；请在考勤管理页手动指定日期范围重算。
- 点位显示为 `-`：系统会按批次从飞书流水 `raw_payload` 回填 `location_name`；GPS 点位读取 `location_name`，考勤机流水只有 `device_id` 时显示为 `考勤机-{device_id}`。
- 有效考勤提醒未发送：检查 `attendance_effective_message_queue`、飞书 `im:message` 权限和员工 `open_id`。
- 任务长时间运行：检查 `attendance_sync_jobs` 和 `tmp/doorlock_attendance_worker.lock`。

## 高峰期数据库卡顿（2026-10-27）

已收到的 PHP 慢日志有 143 条全部停在 `Migrator::normalizeRuntimeSettings()` 的历史 `logs.cardid` 全表 UPDATE。
旧版页面入口对除 `action=api` 以外的请求都调用完整迁移，没有检查已经保存的 `schema_version`。
MySQL 慢日志还显示：8 条配对查询各扫描 819655–823188 行、耗时 3.12–7.95 秒；10 条历史刷卡去重查询扫描 81863–202602 行、耗时 3.22–6.47 秒。
这两类 SELECT 的锁等待均小于 0.006 秒，主要问题是查询扫描量。

本次代码修复：

- 已完成的迁移只读版本号；真正升级用数据库级非阻塞锁，重读版本，仅成功后标记完成。删除启动时历史 `logs.cardid` 全表更新，新日志入库时补齐卡号。
- 配对、提醒、日报溯源直接比较身份字段，使用身份/时间联合索引。刷卡去重先查来源/外部 ID 唯一索引，再分别查询身份、时间、卡号、点位，保留原来的匹配范围和优先级。
- 生产 `logs.time` 实际为 TEXT，普通字符串索引不能解决原来的数字比较。索引工具新增只读虚拟数字列及索引；原始时间和原来的数字谓词保留，异常旧值通过 NULL 分支交给原谓词判断。
- 大屏未刷卡人员查询按日构造精确卡号与补齐卡号集合，避免每个人重复扫描日志；汇总公式、成功/失败判断和人员范围不变。日报页复用已经计算的总数。
- 并列门禁排名、相同时间日志、同名日报增加第二排序条件。旧 SQL 的并列顺序未定义；此处稳定展示顺序，不改变统计值。
- 工牌源流水只存必要员工/设备身份快照，避免每条复制权限名单。最近 1000 行样本中，328 条工牌原文平均约 3818 字节，全部带完整设备权限字段；飞书原文约 542–668 字节，继续保留其完整上下文，供历史 type/点位回填使用。
- 角色页面不再执行三条清理 DELETE；页面过滤悬空成员，定时任务每轮按主键最多检查 200 条，保留原成员列表和人数结果。
- 员工刷卡日志与考勤入队在同一事务提交，失败回滚，不提前向设备确认成功。Web SQL 设置超时、锁等待和总数据库预算；失败返回可重试 503。

生产表状态输出：

| 项目 | 数值 |
| --- | --- |
| InnoDB 估算行数 | 745092（不是精确 COUNT） |
| 数据及聚簇索引分配空间 | 1598029824 字节，约 1.488 GiB |
| 二级索引分配空间 | 338018304 字节，约 322.36 MiB |
| 数据及索引合计 | 约 1.803 GiB |
| 报告的空闲表空间 | 7340032 字节，7 MiB |
| 平均行长 | 约 2144 字节（包含存储开销，不等于 raw_payload 长度） |

慢日志涉及的 `idx_attendance_source_open_time`、`idx_attendance_source_no_time` 和 `uniq_attendance_source_external` 均存在且可见。
已提供单独的 CLI 索引工具，复用可见的等价索引，补齐 user/time、有效记录的证据 ID、提醒、点位、归档时钟等索引；不会重复创建已有 open/time、no/time 和来源/外部 ID 唯一索引。
`AUTO_INCREMENT=1439954` 不能当作实际行数，索引 Cardinality 也只是优化器估计。

用户所见 1.49GB 对应数据及聚簇索引部分，二级索引另计，不能把全部空间当作可清理数据。
InnoDB 字段含义参见 [MySQL SHOW TABLE STATUS 文档](https://dev.mysql.com/doc/refman/8.0/en/show-table-status.html)。

## 本次版本的生产发布顺序

本次在生产备份副本上验证，未连接或修改正在运行的生产库。上线需要部署整个提交，不能只复制最初诊断时列出的几个文件。

1. 保留当前代码、配置和一致性数据库备份；确认 MySQL 数据盘有建索引的临时空间。选择低峰发布整个提交，保留生产 `config.php`，按示例增补配置，不覆盖凭证。归档和 Redis 首次保持关闭。
2. 重载对应 PHP-FPM / OPcache，让旧 worker 正常退出后启动新版。`schema_version` 保持原值，性能索引不在 Web 请求或迁移入口执行。
3. 用 `SHOW FULL PROCESSLIST` 检查遗留请求。如仍有旧版 `UPDATE logs SET cardid=LPAD(...)`，核对连接 ID、SQL 和业务影响后终止该查询。部署代码不会取消已经执行的旧 SQL。
4. 在项目根目录用生产 CLI PHP 检查并安装索引：

   ```bash
   php tools/optimize_indexes.php --check
   php tools/optimize_indexes.php --apply
   php tools/optimize_indexes.php --check
   ```

   工具只新增列/索引，使用 `ALGORITHM=INPLACE, LOCK=NONE`，元数据锁最多等待 5 秒；不支持时明确失败，不自动降级锁表复制。在线 DDL 仍占用 I/O 和空间，逐项检查输出。不要在早高峰运行，也不要反复并发启动。最后一次检查应全部为 `present`。
5. 确认每分钟 CLI cron 正常运行。数据库健康快照首次由 cron 生成，`maintenance.database_volume_path` 必须填 **MySQL 数据实际所在挂载点**，不能用另一个分区的空闲量代替。
6. 执行 `tools/diagnose_database.sql` 的只读检查；核验真实刷卡入队、大屏、当天/历史日报、筛选、导出、溯源和个人查询。观察下一次真实早高峰的 FPM 活跃数、数据库 CPU/I/O、慢日志与请求耗时；本机备份测试不等同于生产并发压测。

## 一年前数据的自动归档及历史查询

归档保留所有主键、唯一键、身份、日期、状态、统计字段和检索字段在 MySQL；完整原行压缩写入归档包，较大的非检索原文/响应/trace 字段改为引用。原页面查询、导出、溯源和历史重算通过数据库读取层透明恢复原文字节，不要求切换历史页面。归档覆盖源流水、队列、飞书事件、同步任务、有效记录、日报、重点提醒、设备任务及操作/门禁日志。小字段或无可转移大字段的表保留在线数据，并保存完整快照。

这能减少大字段对热库的占用，**不代表无限历史可以占用固定硬盘空间**：在线检索字段、索引、清单和外部归档仍会增长。门禁/操作日志也保留在线，保证原查询完整。归档盘应是项目目录之外的可靠独立存储；建议本地持久磁盘并做异机备份。如用 NAS，需确认 `fsync`、目录同步和访问延迟，避免网络盘阻塞历史原文读取。不能把归档放在公开 Web 目录或会被自动清理的 tmp。

完成索引部署后，在生产 `config.php` 增补：

```php
'archive' => [
    'enabled' => true,
    'directory' => '/mnt/doorlock-archive',
    'start_hour' => 2, 'end_hour' => 5,
    'batch_size' => 1000,
    'min_free_bytes' => 2147483648
],
'maintenance' => [
    'database_volume_path' => '/var/lib/mysql', // 替换为实际 MySQL 数据盘路径
    'min_free_bytes' => 5368709120, 'min_free_ratio' => 0.1
],
```

目录应由运行 cron 和 PHP 的账号读写，权限 0700，归档包 0600。每分钟 cron 在 02:00–05:00 轮转处理一张表、最多 1000 行；按系统时区日界线判断一年以前，按时间/主键游标推进，不在追平后反复扫全历史。第一次大积压可在低峰增加执行频率或调整窗口，按实际每日新增量核对归档吞吐。当前备份仅约两个月，因此开启后暂时没有一年以前的数据可转移。

安全提交顺序是：压缩完整行 → 写包 → 文件和目录 `fsync` → 读回校验长度/SHA-256 → 数据库事务写清单和引用。事务失败留下的未引用尾部允许重试，不会删除在线原文；按原字段精确字节比较更新，避免覆盖并发修改。历史源流水/有效记录/日报经应用重写会进入归档补处理队列。手工 SQL 导入旧数据后执行 `php tools/archive.php --rescan`，重置扫描游标而不删除已有文件或清单。

检查命令：

```bash
php tools/archive.php --run     # 遵守夜间窗口
php tools/archive.php --verify  # 分批遍历清单，校验归档原文；在低峰运行
```

首次转移建议先用生产备份演练。缺盘、截断、校验失败会明确失败，不返回空原文冒充正常数据；新写入和未归档数据仍留在数据库。设置 `enabled=false` 只停止新增归档，历史读取仍需要保留 `directory`、归档文件、清单和新读取代码。

**数据库和归档必须配套备份。** 推荐备份时暂停归档调度、等待本轮结束，然后对 MySQL 做一致性快照并复制归档包/清单；恢复时先恢复归档文件再开放对应数据库，执行 `--verify` 和历史溯源验收。不得用早于数据库清单的文件集恢复，也不得只备份 MySQL。首次产生引用后，回滚业务代码仍须保留本版归档读取层，不能直接退回不认识引用的旧版本。不要自动清理被引用的归档包。

转移大字段释放的 InnoDB 页首先供后续写入复用，`.ibd` 不会因代码发布或归档立即缩小。不自动运行 `OPTIMIZE TABLE`；确需回收文件空间时，另选维护窗口、足够临时空间和已验证的备份后安排重建。磁盘已接近满时先扩容或迁移可靠归档存储，不能靠删除证据救急。

## Redis、意外断电和程序崩溃

本次卡顿的主要修复是查询、索引及迁移执行路径。Redis 可选，当前只缓存经过 SHA-256 校验的不可变归档压缩原文，不缓存实时考勤统计，不接管刷卡入库或可靠队列。要试用需安装 phpredis，按 `config.php.example` 设置 Redis 地址、密码、database 并启用；连接及读取超时为 150ms，故障或坏缓存自动读可靠归档文件。设置合理 `maxmemory` 和缓存淘汰策略，24 小时 TTL，避免 Redis 挤占 MySQL 内存。

Redis 断电、重启、清库、淘汰最多造成缓存重新预热，不丢业务事实；此用途不依赖 Redis 持久化。不能把 Redis AOF 每秒刷新等同于零丢失保证，参见 [Redis 持久化说明](https://redis.io/docs/latest/management/persistence/)。

可靠写入仍以 MySQL 提交为准。员工日志和考勤队列一同提交后才确认成功；未提交事务在故障后回滚，设备未收到成功需按设备能力重试/补传。生产应检查 `innodb_flush_log_at_trx_commit=1`，启用 binlog 时 `sync_binlog=1`，并使用可靠存储、异机备份与恢复演练。代码健康检查会报告不满足该持久化配置的情况，不会擅自修改服务器参数。硬盘损坏或设备本身未保存的未确认刷卡，不能靠应用或 Redis 凭空恢复。

## 数据库堵塞时的前端和监控

默认连接超时 3 秒、Web 单次读取超时 8 秒、单条 SELECT 执行上限 5 秒、Web 总数据库预算 12 秒、锁等待 3 秒；CLI worker 单次读取允许 120 秒。总数据库预算不等同于整个 HTTP 请求的硬超时。FPM 可配 `request_terminate_timeout=30s`、`request_slowlog_timeout=3s`，Nginx 配匹配的 `fastcgi_read_timeout`；按服务器内存配置 `pm.max_children`，避免靠无限增加进程加重数据库拥堵。外部飞书/OA 调用仍使用各自网络超时。

考勤 AJAX 15 秒超时，数据库异常返回 HTTP 503 和明确重试提示，不伪造成功/零统计。只读页面完成鉴权后提前释放 PHP session 锁，避免同一浏览器其他请求被连带卡住。不要让定时健康检查访问需要数据库的普通首页。

`Public/health.php` 不连接数据库，读取最近一次 cron 快照，用 `Authorization: Bearer <apiCommonKey>` 鉴权；反向代理根目录为 Public 时 URL 是 `/health.php`。健康异常或超过 5 分钟未更新返回 503。配置外部监控每分钟检查并报警，能在数据库/cron 被堵时发现快照过期。后台页面也显示已有的容量/持久化异常提示。快照文件是固定大小覆盖，不记录员工明细。

默认容量阈值是剩余至少 5GiB 且至少 10%，归档不足 2GiB 停止转移以保留在线原文。根据健康快照记录每日表/磁盘增长，按剩余天数提前扩容；报警需要接入实际监控，代码不会自动向外部人员发送消息。为 MySQL slow log、binlog、PHP-FPM/Nginx 日志配置大小/天数轮转和保留策略，备份放独立存储；binlog 清理必须覆盖现有恢复与复制需求，不能照抄短保留期。

## 验证记录（隔离 MySQL 8.0.46）

使用用户提供的 2026-10-27 备份，只恢复到独立本机 Unix socket、禁用网络的数据库。没有对线上数据做归档、删行或重算。对照基线提交 `33fc6b78`，参考代码仅统一未定义的并列排序，原计算、筛选和统计谓词保留。

- 47 项不依赖数据库的回归检查：迁移幂等/互斥/失败、卡号、快照、身份查询及去重。
- 5 项 CLI 消息消费者检查：HTTP 唤醒不发送网络消息、运行中不重复启动、突发节流、命令参数转义、两类消息队列处理到空闲。
- 7 项真实 HTTP 故障检查：数据库不存在时页面/JSON 返回 503、重试及禁缓存标头、错误不泄漏数据库标识；健康端点鉴权、离线读取快照及过期返回 503。
- 58 项真实 MySQL 检查：最早/最后有效时间、13 类日报筛选、重算、导出、溯源、配对/提醒、全部 9 档大屏、未刷卡列表、个人/日志/角色页面；归档原文逐字节一致，容量不足保留在线原文、晚到历史更新、事务中断、坏包/缺盘、Redis 异常回退、查询超时和刷卡原子提交；cron/即时消息消费互斥、失败重试和相同幂等标识。
- 8 组生产备份对比覆盖全部历史日报日期范围、13 类筛选及导出；另核对抽样溯源/配对、大屏不同日期/条件、考勤页、日志搜索、角色列表和人数。全历史日报导出用时约 3 秒，结果一致。
- 大屏昨天/跨日筛选从约 2.5 秒降为约 0.22–0.30 秒（本机串行对比，非生产并发承诺）。日志日期谓词 EXPLAIN 从全表约 262567 行，改为数字时间索引范围约 4164 行。配对新 SQL 使用 open/time 索引，样本估算 4 行；旧 SQL 即使搭配新增时间索引仍估算 69 行。原生产慢日志为约 82 万行扫描。
- 六类后台待办查询抽样均命中重试/状态索引，估算 2–3 行，本机均小于 1ms。保留现有调度规则，没有为了提速跳过待办或历史日期。

可在独立测试实例复跑 `tests/performance_regression.php`、`tests/message_worker_regression.php`、`tests/mysql_regression.php`、`tests/web_runtime_regression.php` 与 `tests/production_regression.php`。后三者必须设置测试 socket；HTTP 检查仅启动临时本机服务，使用故意不存在的测试库。生产对比脚本仅接受 `doorlock_prod_test_*` 库名，索引部署只作用于该副本。MySQL 测试会新建并最终删除随机 `doorlock_perf_test_*` 库。测试工具不读取生产 `config.php` 凭证。
