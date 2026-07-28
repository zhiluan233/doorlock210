# P0 考勤模块设计

## 目标

本模块用于在门禁系统内计算“有效考勤”，不替代飞书假勤原始数据。核心规则是：

- 本地工牌刷卡 + 飞书假勤人脸打卡在配置间隔内成对出现，即生成一次有效考勤。
- 间隔默认 300 秒，可在后台系统设置页修改。
- 先刷工牌或先人脸都可配对，配对后的有效时间取两条记录中较晚的一条。
- 飞书点位命中“免工牌点位前缀”时，该人脸打卡直接生成有效考勤，不再要求 5 分钟内有本地工牌刷卡。
- 本系统推送到飞书的工牌流水点位统一以 `工牌-` 开头，读回后只作为溯源记录，不参与二次配对。

## 数据来源

| 来源 | 表 | 说明 |
| --- | --- | --- |
| 本地刷卡 | `logs` / `attendance_source_records` | 员工开门成功时实时入库；全量同步时会回填历史 `logs`。 |
| 飞书假勤流水 | `attendance_source_records` | 通过事件订阅实时写入，也可通过全量任务批量拉取。 |
| 有效考勤 | `attendance_effective_records` | 每个员工每天按时间顺序配对生成。 |
| 日报 | `attendance_daily_reports` | 用于后台查询、导出和 AMT 推送。 |
| 飞书考勤组 | `attendance_groups` / `attendance_group_members` | 从飞书假勤考勤组拉取，用于上下班时间和成员归属。 |
| 异步任务 | `attendance_sync_jobs` | 存放全量流水、单条流水、重算、考勤组同步任务。 |

## 流水分类

`attendance_source_records.source_kind` 使用以下分类：

- `badge`：本地门禁刷卡成功。
- `face`：飞书假勤内非 `工牌-` 点位的普通打卡。
- `exempt_face`：飞书点位命中免工牌前缀，直接算有效考勤。
- `badge_shadow`：本系统导入飞书后又被读回的 `工牌-` 流水，只保留溯源，不参与配对。

## 配对算法

1. 按人员和自然日读取源流水，按 `punch_time ASC, id ASC` 排序。
2. `exempt_face` 直接生成有效记录。
3. `badge_shadow` 跳过配对，仅保留溯源。
4. `badge` 与 `face` 双队列互相匹配，选择配置间隔内时间差最小的未使用记录。
5. 一条源流水只允许被消费一次。
6. 生成的有效考勤按有效时间排序并写入日报。

示例：`工牌 人脸 工牌 人脸` 生成 2 次有效考勤；`工牌 人脸 工牌 工牌 工牌 人脸 人脸 工牌` 会按 5 分钟窗口和最近未使用记录配对，避免同一条人脸或工牌被重复消费。

## 日报规则

- 当天首次有效考勤晚于应上班时间 + 迟到宽限秒数，即标记迟到。
- 默认宽限为 60 秒，即晚 1 分钟开始迟到。
- 当天最后一次有效考勤作为下班时间。
- 当天无有效考勤标记缺勤。
- 只有一次有效考勤时标记缺少下班有效考勤。
- `raw_trace` 保留源流水和配对 ID，后台“溯源”按钮可查看。

## 并发设计

- 刷卡链路只同步写 `logs` 和本地考勤源表，不等待飞书或 AMT。
- 飞书事件只入库源流水并提交重算任务。
- 全量同步通过 `attendance_worker.php` 异步处理，不堵塞 Web 请求。
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
