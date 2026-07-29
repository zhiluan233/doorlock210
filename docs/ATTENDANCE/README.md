# P0 考勤模块设计

## 目标

本模块用于在门禁系统内计算“有效考勤”，不替代飞书假勤原始数据。核心规则是：

- 本地工牌刷卡 + 飞书假勤人脸打卡在配置间隔内成对出现，即生成一次有效考勤。
- 间隔默认 300 秒，可在后台系统设置页修改。
- 先刷工牌或先人脸都可配对，配对后的有效时间取两条记录中较晚的一条。
- 飞书点位命中“免工牌点位前缀”时，该人脸打卡直接生成有效考勤，不再要求 5 分钟内有本地工牌刷卡。
- 本系统推送到飞书的工牌流水点位统一以 `工牌-` 开头。读回后标记为飞书工牌证据：本地同时间有刷卡记录时只做对照，本地缺失历史记录时参与有效考勤配对。
- 可启用“有效考勤提醒”，只在有效考勤生成时向员工推送飞书卡片消息。

## 数据来源

| 来源 | 表 | 说明 |
| --- | --- | --- |
| 本地刷卡 | `logs` / `attendance_source_records` | 员工开门成功时实时入库；全量同步时会回填历史 `logs`。 |
| 飞书假勤流水 | `attendance_source_records` | 通过事件订阅实时写入，也可通过全量任务批量拉取；飞书历史 `工牌-` 流水可补齐本地缺失的工牌证据。 |
| 有效考勤 | `attendance_effective_records` | 每个员工每天按时间顺序配对生成。 |
| 日报 | `attendance_daily_reports` | 用于后台查询、导出和 AMT 推送。 |
| 飞书考勤组 | `attendance_groups` / `attendance_group_members` | 从飞书假勤考勤组拉取，用于上下班时间和成员归属。 |
| 异步任务 | `attendance_sync_jobs` | 存放全量流水、单条流水、重算、考勤组同步任务。 |
| 有效提醒 | `attendance_effective_message_queue` | 存放有效考勤飞书卡片提醒，按 `pair_hash` 去重并失败重试。 |

## 流水分类

`attendance_source_records.source_kind` 使用以下分类：

- `badge`：本地门禁刷卡成功。
- `face`：飞书假勤内非 `工牌-` 点位的普通打卡。
- `exempt_face`：飞书点位命中免工牌前缀，直接算有效考勤。
- `feishu_badge`：本系统导入飞书后又被读回的 `工牌-` 流水；本地同时间有 `badge` 时只保留对照，本地缺失时作为历史工牌证据参与配对。
- `badge_shadow`：旧版本名称，重算时按 `feishu_badge` 兼容处理。

## 配对算法

1. 按人员和自然日读取源流水，按 `punch_time ASC, id ASC` 排序。
2. `exempt_face` 直接生成有效记录。
3. `feishu_badge` 如果找到同时间本地 `badge`，只作为对照；如果本地缺失，则按工牌记录参与配对。
4. `badge` / `feishu_badge` 与 `face` 双队列互相匹配，选择配置间隔内时间差最小的未使用记录。
5. 一条源流水只允许被消费一次。
6. 生成的有效考勤按有效时间排序并写入日报。

示例：`工牌 人脸 工牌 人脸` 生成 2 次有效考勤；`工牌 人脸 工牌 工牌 工牌 人脸 人脸 工牌` 会按 5 分钟窗口和最近未使用记录配对，避免同一条人脸或工牌被重复消费。

## 日报规则

- 当天首次有效考勤晚于应上班时间 + 迟到宽限秒数，即标记迟到。
- 默认宽限为 60 秒，即晚 1 分钟开始迟到。
- 当天最后一次有效考勤作为下班时间。
- 当天无有效考勤标记缺勤。
- 只有一次有效考勤时标记缺少下班有效考勤。
- 日报按天封存：昨天及以前的日报不会被事件、计划全量、点位修复等自动任务重算；只有管理员在考勤管理页手动指定日期范围重算时才会更新。
- `raw_trace` 保留源流水和配对 ID，后台“溯源”按钮可查看。
- `raw_trace.feishu_badge_compare` 会记录飞书工牌流水与本地刷卡记录的匹配数量，便于检查历史数据是否一致。

## 日报状态维度

日报保留兼容旧筛选的 `status`，同时新增多维状态字段：

- `work_start_valid`：当天有上班有效考勤。
- `work_end_valid`：最后一次有效考勤不早于考勤组下班时间。
- `is_late`：首次有效考勤晚于应上班时间 + 宽限秒。
- `is_early_leave`：已出现有效考勤，但最后一次有效考勤早于考勤组下班时间。
- `is_full_absent`：没有有效考勤，也没有只刷脸/只刷卡的无效考勤。
- `invalid_face_count`：只刷脸次数，即未在配对窗口内找到工牌的飞书人脸流水。
- `invalid_badge_count`：只刷卡次数，即未在配对窗口内找到人脸的工牌流水。
- `invalid_late_face_count` / `invalid_late_badge_count`：上班时间及以前的只刷脸/只刷卡次数；如果首次有效考勤迟到，则标记为上班单边验证导致迟到。
- `invalid_early_leave_face_count` / `invalid_early_leave_badge_count`：下班时间及以后的只刷脸/只刷卡次数；如果最后有效考勤早于下班时间，则标记为下班单边验证导致早退。
- `invalid_late_related` / `invalid_early_leave_related`：迟到或早退当天存在上述边界时段无效考勤，便于导出统计。

Excel 导出默认不输出 `raw_trace` JSON 原文；如果导出字段配置里保留 `trace`，系统只输出人可读的溯源摘要。

## 有效考勤提醒

后台路径：`系统设置 -> P0考勤模块 -> 有效提醒`。

提醒只在单人单日增量重算生成有效考勤时入队；全量同步和批量历史重算不会主动推送历史提醒，避免集中补算时打扰员工。

卡片支持 Markdown 或飞书卡片 JSON，变量包括：

- `{time}` / `{date}` / `{datetime}`
- `{work_date}` / `{name}` / `{employee_no}`
- `{location}` / `{device}` / `{group}` / `{status}`
- `{badge_time}` / `{badge_datetime}`
- `{face_time}` / `{face_datetime}`
- `{interval_seconds}` / `{pair_hash}`

## 双验证补刷提醒

后台路径：`系统设置 -> P0考勤模块 -> 补刷提醒`。

当员工只完成刷脸或刷卡，且记录发生在考勤组上班时间及以前、下班时间及以后时，系统会在配对截止前发送红色飞书卡片提醒。例如配对间隔为 300 秒、提前秒数为 120 秒，则单边记录产生 180 秒后仍未配对时提醒员工及时补刷。中间时段的单边记录不会提醒。

补刷提醒失败会进入重试；如果已经生成有效考勤、出现可配对的另一条流水、超过配对截止时间，系统会跳过该提醒。

卡片支持 Markdown 或飞书卡片 JSON，头部颜色固定为红色，变量包括有效提醒变量以及：

- `{phase}`：上班或下班。
- `{done_method}`：已完成的方式，刷脸或刷卡。
- `{missing_method}`：待完成的方式，刷脸或刷卡。
- `{punch_time}` / `{punch_datetime}`
- `{deadline_time}` / `{deadline_datetime}`
- `{remaining_seconds}` / `{remaining_minutes}`

## 并发设计

- 刷卡链路只同步写 `logs` 和本地考勤源表，不等待飞书或 AMT。
- 飞书事件只入库源流水并提交重算任务。
- 飞书打卡成功事件如果自带 `location_name`，直接使用事件字段；如果事件缺少点位但有 `record_id`，会异步调用“查询打卡流水”补齐后再参与计算。
- 计划全量同步只处理当天，避免自动改写封存日报；历史日期需要管理员手动指定范围重算或同步。
- 全量同步和日报重算通过 `attendance_worker.php` 异步处理，不堵塞 Web 请求。
- `attendance_worker.php` 有文件锁，避免多进程重复跑任务。
- `cron.php` 本身也有全局文件锁，适合每分钟由 PHP CLI 调用。
- 飞书批量查询按 50 人一批，符合官方批量查询打卡流水的单批上限和 50 次/秒频率限制。

## 免工牌点位

后台路径：`系统设置 -> P0考勤模块 -> 免工牌点位`。

配置方式：按飞书 `location_name` 的前缀匹配，支持逗号、分号和换行分隔。

示例：

```text
上海分公司
深圳办公室
异地外勤-
```

如果飞书流水点位为 `上海分公司-前台人脸机`，会命中 `上海分公司`，直接生成 `exempt_face` 有效考勤，不要求本地工牌记录。

## 官方文档

- 飞书导入打卡流水：https://open.feishu.cn/document/server-docs/attendance-v1/user_task/batch_create
- 飞书批量查询打卡流水：https://open.feishu.cn/document/uAjLw4CM/ukTMukTMukTM/reference/attendance-v1/user_flow/query
- 飞书用户打卡成功事件：https://open.feishu.cn/document/server-docs/attendance-v1/event/user-attendance-records-event
- 飞书查询所有考勤组：https://open.feishu.cn/open-apis/attendance/v1/groups
- 飞书查询考勤组成员：https://open.feishu.cn/document/attendance-v1/group/list_user
