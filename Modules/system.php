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
								<div class="layui-form-item"><label class="layui-form-label">刷卡异步推</label><div class="layui-input-block"><input type="checkbox" name="swipe_async_feishu_enabled" value="true" lay-skin="switch" <?php echo checked('swipe_async_feishu_enabled'); ?>></div></div>
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
								<div class="layui-form-item"><label class="layui-form-label">批量条数</label><div class="layui-input-block"><input class="layui-input" name="feishu_attendance_batch_size" value="<?php echo settingValue('feishu_attendance_batch_size'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">刷卡提醒</label><div class="layui-input-block"><input type="checkbox" name="feishu_message_enabled" value="true" lay-skin="switch" <?php echo checked('feishu_message_enabled'); ?>></div></div>
								<div class="layui-form-item"><label class="layui-form-label">卡片标题</label><div class="layui-input-block"><input class="layui-input" name="feishu_message_template" value="<?php echo settingValue('feishu_message_template'); ?>" placeholder="刷卡成功"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">提醒批量</label><div class="layui-input-block"><input class="layui-input" name="feishu_message_batch_size" value="<?php echo settingValue('feishu_message_batch_size'); ?>"></div></div>
								<p>飞书凭证：<?php echo $feishuCredentialReady ? '已在 config.php 配置' : '未配置'; ?></p>
								<p>飞书自定义考勤端点：<?php echo $feishuAttendanceEndpointReady ? '已在 config.php 配置' : '未配置'; ?></p>
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
								<div class="layui-form-item"><label class="layui-form-label">开门路径</label><div class="layui-input-block"><input class="layui-input" name="remote_open_path" value="<?php echo settingValue('remote_open_path'); ?>"></div></div>
								<div class="layui-form-item"><label class="layui-form-label">超时秒数</label><div class="layui-input-block"><input class="layui-input" name="remote_open_timeout" value="<?php echo settingValue('remote_open_timeout'); ?>"></div></div>
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
