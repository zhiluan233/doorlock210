# 考勤模块部署与配置

## PHP 与计划任务

运行环境要求 PHP 8.0。

推荐每分钟执行一次根目录计划任务：

```bash
php /home/210official/doorlock/cron.php
```

`cron.php` 会做三件事：

- 处理原有 OA / 飞书 / 机器人刷卡提醒队列。
- 检查是否到达考勤全量同步时间。
- 唤醒 `attendance_worker.php` 处理考勤任务。

如果 PHP Web 环境禁用了 `exec`，仍可单独由守护任务调用：

```bash
php /home/210official/doorlock/attendance_worker.php
```

`attendance_worker.php` 自带锁，重复执行会直接退出，不会并发处理同一批考勤任务。

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
        'attendanceGroupListUser' => 'https://open.feishu.cn/open-apis/attendance/v1/groups/{group_id}/list_user'
    ]
]
```

AMT 凭证也继续放在 `config.php` 的 `oa.appId` 和 `oa.appSecret`。

## 系统设置

后台 `系统设置` 需要确认：

- `启用模块`：启用 P0 考勤模块。
- `配对间隔秒`：默认 300 秒。
- `迟到宽限秒`：默认 60 秒。
- `免工牌点位`：异地分子公司、外部人脸点位前缀。
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
- `attendance:rule:readonly` 或“导出打卡管理规则”：读取考勤组和考勤组成员。
- `contact:user:readonly`、`contact:department:readonly`：已有通讯录同步需要。
- `im:message`：已有刷卡机器人卡片提醒需要。

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

## 故障排查

- 看不到飞书流水：检查 `attendance:task:readonly`、应用数据权限范围和 `attendanceUserFlowsQuery` endpoint。
- 事件不进系统：检查事件订阅 URL、Token、Encrypt Key 和事件类型；用户打卡成功事件如果缺少点位，系统会按 `record_id` 补查单条流水。
- 免工牌点位不生效：确认配置的是飞书 `location_name` 的前缀，不是设备名。
- 日报缺少工牌证据：确认本地 `logs` 有员工开门成功记录；全量同步会回填历史 `logs`，如果本地历史缺失，会使用飞书 `工牌-` 流水作为历史工牌证据参与计算。
- 历史日报没有自动变化：这是按天封存策略；请在考勤管理页手动指定日期范围重算。
- 点位显示为 `-`：系统会按批次从飞书流水 `raw_payload` 回填 `location_name`；GPS 点位读取 `location_name`，考勤机流水只有 `device_id` 时显示为 `考勤机-{device_id}`。
- 有效考勤提醒未发送：检查 `attendance_effective_message_queue`、飞书 `im:message` 权限和员工 `open_id`。
- 任务长时间运行：检查 `attendance_sync_jobs` 和 `tmp/doorlock_attendance_worker.lock`。
