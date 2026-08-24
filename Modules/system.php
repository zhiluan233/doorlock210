<?php
/*

系统设置页面模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));
if(!$rs || $rs['type'] !== 'admin') {
	exit("<script>location='/?page=panel&module=accesslog';</script>");
}

$feishuCredentialReady = Settings::get('feishu_app_id', '') !== '' && Settings::get('feishu_app_secret', '') !== '';
$oaCredentialReady = Settings::get('oa_app_id', '') !== '' && Settings::get('oa_app_secret', '') !== '';
$remoteCredentialReady = Settings::get('remote_open_username', '') !== '' || Settings::get('remote_open_password', '') !== '';
$feishuAttendanceEndpointReady = Settings::get('feishu_attendance_endpoint', '') !== '';
$feishuOauthEndpointReady = Settings::get('feishu_oauth_authorize_url', '') !== '';

function checked($key) {
	return Settings::getBool($key) ? 'checked' : '';
}

function settingValue($key) {
	return htmlspecialchars(Settings::get($key, ''), ENT_QUOTES, 'UTF-8');
}

function settingListValues($key) {
	$value = Settings::get($key, '');
	$decoded = json_decode((string)$value, true);
	$items = is_array($decoded) ? $decoded : preg_split('/[,\n;\s]+/', (string)$value);
	$out = [];
	foreach ($items as $item) {
		if (is_array($item)) {
			continue;
		}
		$item = trim((string)$item);
		if ($item !== '') {
			$out[] = $item;
		}
	}
	return array_values(array_unique($out));
}

$attendanceDirectTypes = settingListValues('attendance_direct_success_flow_types');
$attendanceDirectGroups = settingListValues('attendance_direct_success_group_keys');
$attendanceGroups = [];
$attendanceGroupRs = Database::query('attendance_groups', "SELECT * FROM `attendance_groups` WHERE `enabled`=1 ORDER BY `source` DESC, `name` ASC LIMIT 200", '', true);
if ($attendanceGroupRs instanceof \mysqli_result) {
	while ($groupRow = mysqli_fetch_assoc($attendanceGroupRs)) {
		$attendanceGroups[] = $groupRow;
	}
	mysqli_free_result($attendanceGroupRs);
}
$attendanceTypeOptions = [
	'0' => '0 用户自己打卡',
	'1' => '1 管理员修改',
	'2' => '2 用户补卡',
	'3' => '3 系统自动生成',
	'4' => '4 下班免打卡',
	'5' => '5 考勤机打卡',
	'6' => '6 极速打卡',
	'7' => '7 开放平台导入'
];

?>
<div class="page-title">
	<h3 class="breadcrumb-header">系统设置</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;">
					<h4 style="font-weight: 400">运行设置</h4><br>
					<form class="layui-form layui-form-pane" id="systemForm">
						<div class="layui-row layui-col-space20">
							<div class="layui-col-md6">
								<h5>AMT考勤推送</h5>
								<div class="layui-form-item">
									<label class="layui-form-label">启用</label>
									<div class="layui-input-block"><input type="checkbox" name="oa_attendance_enabled" value="true" lay-skin="switch" <?php echo checked('oa_attendance_enabled'); ?>></div>
								</div>
								<div class="layui-form-item"><label class="layui-form-label">AMT地址</label><div class="layui-input-block"><input class="layui-input" name="oa_base_url" value="<?php echo settingValue('oa_base_url'); ?>" placeholder="https://oa.example.com"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">取Token路径</label><div class="layui-input-block"><input class="layui-input" name="oa_auth_path" value="<?php echo settingValue('oa_auth_path'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">上传路径</label><div class="layui-input-block"><input class="layui-input" name="oa_upload_path" value="<?php echo settingValue('oa_upload_path'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">默认地点</label><div class="layui-input-block"><input class="layui-input" name="oa_location_default" value="<?php echo settingValue('oa_location_default'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">批量条数</label><div class="layui-input-block"><input class="layui-input" name="oa_batch_size" value="<?php echo settingValue('oa_batch_size'); ?>"></div></div>
								<p>AMT凭证：<?php echo $oaCredentialReady ? '已在 config.php 配置' : '未配置'; ?></p>
							</div>
							<div class="layui-col-md6">
								<h5>飞书考勤与提醒</h5>
								<div class="layui-form-item"><label class="layui-form-label">考勤推送</label><div class="layui-input-block"><input type="checkbox" name="feishu_attendance_enabled" value="true" lay-skin="switch" <?php echo checked('feishu_attendance_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">刷卡入考勤</label><div class="layui-input-block"><input type="checkbox" name="card_as_attendance_enabled" value="true" lay-skin="switch" <?php echo checked('card_as_attendance_enabled'); ?>></div></div>
								<div class="layui-form-item">
									<label class="layui-form-label">推送模式</label>
									<div class="layui-input-block">
										<select name="feishu_attendance_mode">
											<option value="flow" <?php echo Settings::get('feishu_attendance_mode') === 'flow' ? 'selected' : ''; ?>>官方打卡流水导入</option>
											<option value="custom" <?php echo Settings::get('feishu_attendance_mode') === 'custom' ? 'selected' : ''; ?>>自定义端点</option>
										</select>
									</div>
								</div>
								<div class="layui-form-item"><label class="layui-form-label">员工ID类型</label><div class="layui-input-block"><select name="feishu_employee_id_type"><option value="employee_no" <?php echo Settings::get('feishu_employee_id_type') === 'employee_no' ? 'selected' : ''; ?>>employee_no</option><option value="employee_id" <?php echo Settings::get('feishu_employee_id_type') === 'employee_id' ? 'selected' : ''; ?>>employee_id</option></select></div></div>
								<div class="layui-form-item"><label class="layui-form-label">单批条数</label><div class="layui-input-block"><input class="layui-input" name="feishu_attendance_batch_size" value="<?php echo settingValue('feishu_attendance_batch_size'); ?>" placeholder="1-50"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">每轮批次</label><div class="layui-input-block"><input class="layui-input" name="feishu_attendance_cron_max_batches" value="<?php echo settingValue('feishu_attendance_cron_max_batches'); ?>" placeholder="例如 20"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">批次间隔ms</label><div class="layui-input-block"><input class="layui-input" name="feishu_attendance_batch_interval_ms" value="<?php echo settingValue('feishu_attendance_batch_interval_ms'); ?>" placeholder="例如 100"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">刷卡提醒</label><div class="layui-input-block"><input type="checkbox" name="feishu_message_enabled" value="true" lay-skin="switch" <?php echo checked('feishu_message_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">卡片标题</label><div class="layui-input-block"><input class="layui-input" name="feishu_message_template" value="<?php echo settingValue('feishu_message_template'); ?>" placeholder="刷卡成功"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">卡片内容</label><div class="layui-input-block"><textarea class="layui-textarea" name="feishu_message_card_template" rows="7" placeholder="支持 Markdown；也可填写飞书卡片 JSON，变量：{time} {date} {datetime} {name} {device} {location} {card_id} {card_mask}"><?php echo settingValue('feishu_message_card_template'); ?></textarea></div></div>
								<div class="layui-form-item"><label class="layui-form-label">提醒批量</label><div class="layui-input-block"><input class="layui-input" name="feishu_message_batch_size" value="<?php echo settingValue('feishu_message_batch_size'); ?>"></div></div>
								<p>飞书凭证：<?php echo $feishuCredentialReady ? '已在 config.php 配置' : '未配置'; ?></p>
								<p>流水备注：已在 config.php 配置 attendanceFlowComment</p>
								<p>飞书自定义考勤端点：<?php echo $feishuAttendanceEndpointReady ? '已在 config.php 配置' : '未配置'; ?></p>
							</div>
						</div>
						<hr>
						<div class="layui-row layui-col-space20">
							<div class="layui-col-md6">
								<h5>考勤模块</h5>
								<div class="layui-form-item"><label class="layui-form-label">启用模块</label><div class="layui-input-block"><input type="checkbox" name="attendance_module_enabled" value="true" lay-skin="switch" <?php echo checked('attendance_module_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">配对间隔秒</label><div class="layui-input-block"><input class="layui-input" name="attendance_pair_interval_seconds" value="<?php echo settingValue('attendance_pair_interval_seconds'); ?>" placeholder="默认300"></div></div>
								<div class="layui-form-item">
									<label class="layui-form-label">有效时间</label>
									<div class="layui-input-block">
										<select name="attendance_pair_effective_time_rule">
											<option value="latest" <?php echo Settings::get('attendance_pair_effective_time_rule', 'latest') === 'latest' ? 'selected' : ''; ?>>最后一次认证</option>
											<option value="earliest" <?php echo Settings::get('attendance_pair_effective_time_rule', 'latest') === 'earliest' ? 'selected' : ''; ?>>最早一次认证</option>
										</select>
									</div>
								</div>
								<div class="layui-form-item"><label class="layui-form-label">迟到宽限秒</label><div class="layui-input-block"><input class="layui-input" name="attendance_late_grace_seconds" value="<?php echo settingValue('attendance_late_grace_seconds'); ?>" placeholder="默认60"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">默认考勤组</label><div class="layui-input-block"><input class="layui-input" name="attendance_default_group_name" value="<?php echo settingValue('attendance_default_group_name'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">默认上班</label><div class="layui-input-block"><input class="layui-input" name="attendance_default_start_time" value="<?php echo settingValue('attendance_default_start_time'); ?>" placeholder="09:30"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">默认下班</label><div class="layui-input-block"><input class="layui-input" name="attendance_default_end_time" value="<?php echo settingValue('attendance_default_end_time'); ?>" placeholder="18:30"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">直记点位</label><div class="layui-input-block"><textarea class="layui-textarea" name="attendance_exempt_location_prefixes" rows="5" placeholder="飞书 location_name 或考勤机点位前缀，逗号、分号或换行分隔；命中后直接算有效考勤"><?php echo settingValue('attendance_exempt_location_prefixes'); ?></textarea></div></div>
								<div class="layui-form-item" pane>
									<label class="layui-form-label">直记Type</label>
									<div class="layui-input-block">
										<?php foreach ($attendanceTypeOptions as $typeValue => $typeLabel) { ?>
											<input type="checkbox" name="attendance_direct_success_flow_types[]" value="<?php echo htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($typeValue, $attendanceDirectTypes, true) ? 'checked' : ''; ?>>
										<?php } ?>
									</div>
								</div>
								<div class="layui-form-item" pane>
									<label class="layui-form-label">直记考勤组</label>
									<div class="layui-input-block" style="max-height:180px;overflow:auto;padding:8px 10px;">
										<?php if (count($attendanceGroups) === 0) { ?>
											<span class="text-muted">暂无已同步考勤组，请先在考勤板块同步。</span>
										<?php } ?>
										<?php foreach ($attendanceGroups as $groupRow) {
											$groupKey = trim((string)($groupRow['feishu_group_id'] ?: $groupRow['group_key']));
											if ($groupKey === '') { continue; }
											$groupTitle = trim((string)($groupRow['name'] ?? $groupKey));
											$groupMeta = trim((string)($groupRow['start_time'] ?? '')) . ' - ' . trim((string)($groupRow['end_time'] ?? '')) . ' / ' . intval($groupRow['member_count'] ?? 0) . '人';
											$groupChecked = in_array($groupKey, $attendanceDirectGroups, true) || in_array('feishu:' . $groupKey, $attendanceDirectGroups, true);
										?>
											<input type="checkbox" name="attendance_direct_success_group_keys[]" value="<?php echo htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($groupTitle . '（' . $groupMeta . '）', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $groupChecked ? 'checked' : ''; ?>>
										<?php } ?>
									</div>
								</div>
								<div class="layui-form-item"><label class="layui-form-label">有效提醒</label><div class="layui-input-block"><input type="checkbox" name="attendance_effective_message_enabled" value="true" lay-skin="switch" <?php echo checked('attendance_effective_message_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">提醒标题</label><div class="layui-input-block"><input class="layui-input" name="attendance_effective_message_template" value="<?php echo settingValue('attendance_effective_message_template'); ?>" placeholder="有效考勤"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">提醒卡片</label><div class="layui-input-block"><textarea class="layui-textarea" name="attendance_effective_message_card_template" rows="7" placeholder="支持 Markdown；也可填写飞书卡片 JSON，变量：{time} {date} {datetime} {name} {location} {badge_datetime} {face_datetime} {interval_seconds} {group} {employee_no}"><?php echo settingValue('attendance_effective_message_card_template'); ?></textarea></div></div>
								<div class="layui-form-item"><label class="layui-form-label">提醒批量</label><div class="layui-input-block"><input class="layui-input" name="attendance_effective_message_batch_size" value="<?php echo settingValue('attendance_effective_message_batch_size'); ?>" placeholder="1-200"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">补刷提醒</label><div class="layui-input-block"><input type="checkbox" name="attendance_incomplete_message_enabled" value="true" lay-skin="switch" <?php echo checked('attendance_incomplete_message_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">补刷标题</label><div class="layui-input-block"><input class="layui-input" name="attendance_incomplete_message_template" value="<?php echo settingValue('attendance_incomplete_message_template'); ?>" placeholder="双验证考勤提醒"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">补刷卡片</label><div class="layui-input-block"><textarea class="layui-textarea" name="attendance_incomplete_message_card_template" rows="7" placeholder="支持 Markdown；也可填写飞书卡片 JSON，卡片头部固定红色。变量：{phase} {done_method} {missing_method} {punch_datetime} {deadline_datetime} {remaining_minutes} {name} {location} {group} {employee_no}"><?php echo settingValue('attendance_incomplete_message_card_template'); ?></textarea></div></div>
								<div class="layui-form-item"><label class="layui-form-label">提前秒数</label><div class="layui-input-block"><input class="layui-input" name="attendance_incomplete_message_lead_seconds" value="<?php echo settingValue('attendance_incomplete_message_lead_seconds'); ?>" placeholder="默认120"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">补刷批量</label><div class="layui-input-block"><input class="layui-input" name="attendance_incomplete_message_batch_size" value="<?php echo settingValue('attendance_incomplete_message_batch_size'); ?>" placeholder="1-200"></div></div>
							</div>
							<div class="layui-col-md6">
								<h5>考勤同步与导出</h5>
								<div class="layui-form-item"><label class="layui-form-label">全量同步</label><div class="layui-input-block"><input type="checkbox" name="attendance_full_sync_enabled" value="true" lay-skin="switch" <?php echo checked('attendance_full_sync_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">同步时间</label><div class="layui-input-block"><textarea class="layui-textarea" name="attendance_full_sync_times" rows="3" placeholder="13:00,13:30,14:00"><?php echo settingValue('attendance_full_sync_times'); ?></textarea></div></div>
								<div class="layui-form-item"><label class="layui-form-label">同步天数</label><div class="layui-input-block"><input class="layui-input" name="attendance_full_sync_window_days" value="<?php echo settingValue('attendance_full_sync_window_days'); ?>" placeholder="默认2"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">单批员工</label><div class="layui-input-block"><input class="layui-input" name="attendance_full_sync_batch_size" value="<?php echo settingValue('attendance_full_sync_batch_size'); ?>" placeholder="1-50"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">重算批量</label><div class="layui-input-block"><input class="layui-input" name="attendance_recalculate_batch_size" value="<?php echo settingValue('attendance_recalculate_batch_size'); ?>" placeholder="50-500"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">AMT推送</label><div class="layui-input-block"><input type="checkbox" name="attendance_oa_push_enabled" value="true" lay-skin="switch" <?php echo checked('attendance_oa_push_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">AMT路径</label><div class="layui-input-block"><input class="layui-input" name="attendance_oa_push_path" value="<?php echo settingValue('attendance_oa_push_path'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">AMT批量</label><div class="layui-input-block"><input class="layui-input" name="attendance_oa_batch_size" value="<?php echo settingValue('attendance_oa_batch_size'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">导出字段</label><div class="layui-input-block"><textarea class="layui-textarea" name="attendance_export_fields" rows="4" placeholder="逗号分隔字段"><?php echo settingValue('attendance_export_fields'); ?></textarea></div></div>
								<p>飞书假勤接口端点在 config.php 的 feishu.appEndpoint 中维护；考勤组可在考勤板块手动同步。</p>
							</div>
						</div>
						<hr>
						<div class="layui-row layui-col-space20">
							<div class="layui-col-md6">
								<h5>飞书事件与登录</h5>
								<div class="layui-form-item"><label class="layui-form-label">事件订阅</label><div class="layui-input-block"><input type="checkbox" name="feishu_event_enabled" value="true" lay-skin="switch" <?php echo checked('feishu_event_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">通讯录同步</label><div class="layui-input-block"><input type="checkbox" name="feishu_contact_sync_enabled" value="true" lay-skin="switch" <?php echo checked('feishu_contact_sync_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">同步时间</label><div class="layui-input-block"><input class="layui-input" name="feishu_contact_sync_daily_time" value="<?php echo settingValue('feishu_contact_sync_daily_time'); ?>" placeholder="03:25"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">删除缺失</label><div class="layui-input-block"><input type="checkbox" name="feishu_contact_sync_release_missing" value="true" lay-skin="switch" <?php echo checked('feishu_contact_sync_release_missing'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">一键登录</label><div class="layui-input-block"><input type="checkbox" name="feishu_oauth_enabled" value="true" lay-skin="switch" <?php echo checked('feishu_oauth_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">回调地址</label><div class="layui-input-block"><input class="layui-input" name="feishu_oauth_redirect_uri" value="<?php echo settingValue('feishu_oauth_redirect_uri'); ?>" placeholder="留空自动生成"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">登录Scope</label><div class="layui-input-block"><input class="layui-input" name="feishu_oauth_scope" value="<?php echo settingValue('feishu_oauth_scope'); ?>" placeholder="留空使用应用默认授权范围"></div></div>
								<div class="layui-form-item">
									<label class="layui-form-label">授权确认</label>
									<div class="layui-input-block">
										<select name="feishu_oauth_prompt">
											<option value="" <?php echo Settings::get('feishu_oauth_prompt') === '' ? 'selected' : ''; ?>>默认</option>
											<option value="consent" <?php echo Settings::get('feishu_oauth_prompt') === 'consent' ? 'selected' : ''; ?>>每次显示授权确认</option>
										</select>
									</div>
								</div>
								<p>飞书登录授权端点：<?php echo $feishuOauthEndpointReady ? '已在 config.php 配置' : '未配置'; ?></p>
							</div>
							<div class="layui-col-md6">
								<h5>门禁与队列</h5>
								<div class="layui-form-item"><label class="layui-form-label">远程开门</label><div class="layui-input-block"><input type="checkbox" name="remote_open_enabled" value="true" lay-skin="switch" <?php echo checked('remote_open_enabled'); ?>></div></div>
								<div class="layui-form-item">
									<label class="layui-form-label">请求方式</label>
									<div class="layui-input-block">
										<select name="remote_open_method">
											<option value="GET" <?php echo strtoupper(Settings::get('remote_open_method', 'GET')) === 'GET' ? 'selected' : ''; ?>>GET</option>
											<option value="POST" <?php echo strtoupper(Settings::get('remote_open_method', 'GET')) === 'POST' ? 'selected' : ''; ?>>POST</option>
										</select>
									</div>
								</div>
								<div class="layui-form-item"><label class="layui-form-label">开门路径</label><div class="layui-input-block"><input class="layui-input" name="remote_open_path" value="<?php echo settingValue('remote_open_path'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">请求体</label><div class="layui-input-block"><textarea class="layui-textarea" name="remote_open_body" rows="4" placeholder="POST 时可填写 JSON 或表单内容；GET 可留空"><?php echo settingValue('remote_open_body'); ?></textarea></div></div>
								<div class="layui-form-item"><label class="layui-form-label">成功关键字</label><div class="layui-input-block"><input class="layui-input" name="remote_open_success_text" value="<?php echo settingValue('remote_open_success_text'); ?>" placeholder="留空则按 HTTP 状态和常见失败字样判断"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">超时秒数</label><div class="layui-input-block"><input class="layui-input" name="remote_open_timeout" value="<?php echo settingValue('remote_open_timeout'); ?>"></div></div>
								<p>远程开门路径和请求体支持变量：{ip} {device_id} {device_name} {did} {mac} {oemcode} {open_time} {timestamp}</p>
								<hr>
								<h5>端侧卡库同步</h5>
								<div class="layui-form-item"><label class="layui-form-label">卡库路径</label><div class="layui-input-block"><input class="layui-input" name="device_card_edit_path" value="<?php echo settingValue('device_card_edit_path'); ?>" placeholder="/EditCard.shtm"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">单轮条数</label><div class="layui-input-block"><input class="layui-input" name="device_card_sync_batch_size" value="<?php echo settingValue('device_card_sync_batch_size'); ?>" placeholder="1-1000"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">间隔ms</label><div class="layui-input-block"><input class="layui-input" name="device_card_sync_interval_ms" value="<?php echo settingValue('device_card_sync_interval_ms'); ?>" placeholder="例如 100"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">超时秒数</label><div class="layui-input-block"><input class="layui-input" name="device_card_sync_timeout" value="<?php echo settingValue('device_card_sync_timeout'); ?>" placeholder="1-30"></div></div>
								<p>端侧卡库凭证复用 config.php 的 remoteOpen.username / remoteOpen.password；是否启用由设备管理中的设备开关控制。</p>
								<div class="layui-form-item"><label class="layui-form-label">重试基准</label><div class="layui-input-block"><input class="layui-input" name="queue_retry_base_seconds" value="<?php echo settingValue('queue_retry_base_seconds'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">重试上限</label><div class="layui-input-block"><input class="layui-input" name="queue_retry_max_seconds" value="<?php echo settingValue('queue_retry_max_seconds'); ?>"></div></div>
								<p>远程开门凭证：<?php echo $remoteCredentialReady ? '已在 config.php 配置' : '未配置'; ?></p>
							</div>
						</div>
						<div class="layui-form-item">
							<button type="button" class="layui-btn" onclick="saveSystemSettings()">保存设置</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
<script src="asset/layui/layui.js"></script>
<script>
var csrf_token = "<?php echo $_SESSION['token']; ?>";
layui.use(['layer', 'form'], function(){
	window.saveSystemSettings = function() {
		$.ajax({
			type: 'POST',
			url: '?action=saveSystemSettings&page=panel&module=system&csrf=' + csrf_token,
			data: $('#systemForm').serialize(),
			success: function(resp) {
				layui.layer.msg(resp);
			},
			error: function(xhr) {
				layui.layer.msg('保存失败：' + xhr.responseText);
			}
		});
	}
});
</script>
