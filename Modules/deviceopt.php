<?php
/*

门禁设备管理模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

$page_title = "门禁设备管理";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

function deviceoptH($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function deviceoptControllerTypeLabel($value)
{
	switch ((string)$value) {
		case 'cloud_plus':
			return '云+控制器';
		case 'single_door':
			return '单门控制器';
		default:
			return '待心跳识别';
	}
}

function deviceoptFormatTimestamp($value)
{
	$value = intval($value);
	return $value > 0 ? date('Y-m-d H:i:s', $value) : '暂无';
}

function deviceoptLocalCardSummary($device)
{
	$summary = class_exists(__NAMESPACE__ . '\\DeviceCardSync') ? DeviceCardSync::pendingSummary($device['id']) : ['pending' => 0, 'failed' => 0, 'running' => 0];
	$parts = [
		'全量 ' . deviceoptFormatTimestamp($device['local_card_last_full_at'] ?? 0),
		'下发 ' . deviceoptFormatTimestamp($device['local_card_last_sync_at'] ?? 0),
		'待 ' . intval($summary['pending'] ?? 0),
		'失败 ' . intval($summary['failed'] ?? 0)
	];
	if (intval($summary['running'] ?? 0) > 0) {
		$parts[] = '执行中 ' . intval($summary['running']);
	}
	$message = trim((string)($device['local_card_sync_message'] ?? ''));
	if ($message !== '') {
		$parts[] = $message;
	}
	$escaped = [];
	foreach ($parts as $part) {
		$escaped[] = deviceoptH($part);
	}
	return implode('<br>', $escaped);
}

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
}

if(isset($_GET['localCardProgress'])) {
	anim210System\Utils::checkCsrf();
	if (!in_array($rs['type'] ?? '', ['admin', 'readonly'], true)) {
		ob_clean();
		Header("HTTP/1.1 403 Forbidden");
		Header("Content-Type: application/json; charset=utf-8");
		exit(json_encode(['ok' => false, 'message' => '你没有足够的权限这么做'], JSON_UNESCAPED_UNICODE));
	}
	ob_clean();
	Header("Content-Type: application/json; charset=utf-8");
	if (!class_exists(__NAMESPACE__ . '\\DeviceCardSync')) {
		Header("HTTP/1.1 500 Internal Error");
		exit(json_encode(['ok' => false, 'message' => '端侧卡库同步模块未加载'], JSON_UNESCAPED_UNICODE));
	}
	$result = DeviceCardSync::manualSyncProgress(intval($_GET['device_id'] ?? 0), intval($_GET['job_id'] ?? 0));
	if (empty($result['ok'])) {
		Header("HTTP/1.1 404 Not Found");
	}
	exit(json_encode($result, JSON_UNESCAPED_UNICODE));
}

if(isset($_GET['getdevice']) && preg_match("/^[0-9]{1,10}$/", $_GET['getdevice'])) {
	anim210System\Utils::checkCsrf();
	$deviceinfo = Database::querySingleLine("devices", Array("id" => $_GET['getdevice']));
	if($deviceinfo) {
		ob_clean();
		exit(json_encode(Array(
			"id"       => $deviceinfo['id'],
			"name" => $deviceinfo['name']
		)));
	} else {
		ob_clean();
		Header("HTTP/1.1 403");
		exit("未找到设备");
	}
}

if(isset($_GET['updateDeviceId']) && isset($_GET['updateDeviceAction']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $_GET['updateDeviceAction'])) {
	anim210System\Utils::checkCsrf();
	$userInfo = Database::querySingleLine("devices", Array("id" => $_GET['updateDeviceId']));
	if (!$userInfo) {
		ob_clean();
		Header("HTTP/1.1 403 Forbidden");
		exit("设备不存在");
	}
	switch($_GET['updateDeviceAction']) {
		case 'deleteDevice':
			anim210System\Utils::checkCsrf();
			$update = Database::delete("devices", Array("id" => $_GET['updateDeviceId']));
			if($update == true) {
				ob_clean();
				exit("删除设备成功");
			} else {
				ob_clean();
				Header("HTTP/1.1 404 Not Found");
				exit("设备信息更新失败");
			}
		break;
		default:
			ob_clean();
			Header("HTTP/1.1 404 Not Found");
			exit("Undefined action {$_GET['updateDeviceAction']}");
	}
}

$um = new anim210System\UserCheck();

$mainSQL = 'SELECT * FROM `devices`';
$countSQL = 'SELECT count(*) FROM `devices`';
$deviceData = Database::query("devices", $mainSQL, true);
$countData = Database::query("devices", $countSQL, true);
$deviceRows = [];
$deviceAdvancedData = [];
if ($deviceData instanceof \mysqli_result) {
	while ($row = mysqli_fetch_assoc($deviceData)) {
		$summary = class_exists(__NAMESPACE__ . '\\DeviceCardSync') ? DeviceCardSync::pendingSummary($row['id']) : ['pending' => 0, 'failed' => 0, 'running' => 0];
		$row['local_card_pending'] = intval($summary['pending'] ?? 0);
		$row['local_card_failed'] = intval($summary['failed'] ?? 0);
		$row['local_card_running'] = intval($summary['running'] ?? 0);
		$deviceRows[] = $row;
		$deviceAdvancedData[intval($row['id'])] = [
			'id' => intval($row['id']),
			'name' => $row['name'] ?? '',
			'did' => $row['did'] ?? '',
			'serial' => $row['serial'] ?? '',
			'model' => $row['model'] ?? '',
			'controller_type' => $row['controller_type'] ?? '',
			'controller_type_text' => deviceoptControllerTypeLabel($row['controller_type'] ?? ''),
			'oemcode' => $row['oemcode'] ?? '',
			'ip' => $row['ip'] ?? '',
			'mac' => $row['mac'] ?? '',
			'hbtime' => $row['hbtime'] ?? '',
			'apikey' => $row['apikey'] ?? '',
			'local_card_enabled' => intval($row['local_card_enabled'] ?? 0),
			'local_card_initial_full_done' => intval($row['local_card_initial_full_done'] ?? 0),
			'local_card_last_full_at' => intval($row['local_card_last_full_at'] ?? 0),
			'local_card_last_sync_at' => intval($row['local_card_last_sync_at'] ?? 0),
			'local_card_sync_message' => $row['local_card_sync_message'] ?? '',
			'local_card_pending' => intval($summary['pending'] ?? 0),
			'local_card_failed' => intval($summary['failed'] ?? 0),
			'local_card_running' => intval($summary['running'] ?? 0)
		];
	}
}

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, <?php echo $rs['username'] ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;max-width: ;">
					<h4 style="font-weight: 400">刷卡门禁设备管理</h4><br>
					<h6>注意，添加设备后，门禁设备发出心跳包，系统将自动接收并生成key，同时您将看到设备的DID、MAC地址与心跳信息</h6><br />
					<button class="btn btn-default" onclick="addNewDevice()">添加新设备</button>

					<table id="devices1" class="table table-bordered table-auto" style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>设备名</th>
                                <th>DID</th>
								<th>Serial（序列号）</th>
								<th>Model（型号）</th>
								<th>控制器类型</th>
								<th>OEM代码</th>
								<th>IP</th>
								<th>MAC</th>
								<th>最后一次心跳</th>
								<th>心跳Key</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php
                                foreach ($deviceRows as $dData) {
                                    echo "<tr>
                                    <td>".deviceoptH($dData['id'])."</td>
                                    <td>".deviceoptH($dData['name'])."</td>
									<td>".deviceoptH($dData['did'])."</td>
									<td>".deviceoptH($dData['serial'] ?? '')."</td>
									<td>".deviceoptH($dData['model'] ?? '')."</td>
									<td>".deviceoptH(deviceoptControllerTypeLabel($dData['controller_type'] ?? ''))."</td>
									<td>".deviceoptH($dData['oemcode'])."</td>
									<td>".deviceoptH($dData['ip'])."</td>
									<td>".deviceoptH($dData['mac'])."</td>
									<td>".deviceoptH($dData['hbtime'])."</td>
									<td>".deviceoptH($dData['apikey'])."</td>
                                    <td><button class=\"btn btn-default\" onclick=\"openDeviceAdvanced(".intval($dData['id']).")\">高级</button>&nbsp;<button class=\"btn btn-default\" onclick=\"deleteDevice(".intval($dData['id']).")\">删除</button></td>
                                    </tr>";
                                }
                            ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
    </div>
	<div class="row">
    </div>
		<!-- Row -->
</div>
<!-- 对话框模板 -->
<script type="text/html" id="createDeviceDialogTpl">
  <div class="layui-form layui-form-pane" style="padding: 20px;">
    <div class="layui-form-item">
      <label class="layui-form-label">IP</label>
      <div class="layui-input-block">
        <input type="text" id="ipaddr" class="layui-input" placeholder="192.168.x.x">
      </div>
    </div>
    <div class="layui-form-item">
      <label class="layui-form-label">名称</label>
      <div class="layui-input-block">
        <input type="text" id="name" class="layui-input" placeholder="xx门禁">
      </div>
    </div>
	<div class="layui-form-item">
      <label class="layui-form-label">OEM代码</label>
      <div class="layui-input-block">
        <input type="text" id="oemcode" class="layui-input" placeholder="88888">
      </div>
    </div>
    <div class="layui-form-item">
      <div class="layui-input-block">
        <button class="layui-btn" lay-filter="submit" lay-submit onclick="addDevice()">创建</button>
        <button class="layui-btn layui-btn-primary" onclick="closeDialog()">取消</button>
      </div>
    </div>
  </div>
</script>
<script type="text/javascript" src="/asset/js/md5.js"></script>
<script src="asset/layui/layui.js"></script>
<style>
	.device-advanced-wrap {
		padding: 20px;
	}
	.device-advanced-grid {
		display: grid;
		grid-template-columns: 110px minmax(0, 1fr);
		gap: 10px 14px;
		margin-bottom: 18px;
		color: #3f4a56;
	}
	.device-advanced-grid dt {
		color: #6b7785;
		font-weight: 400;
	}
	.device-advanced-grid dd {
		margin: 0;
		min-width: 0;
		word-break: break-word;
	}
	.device-advanced-section {
		border-top: 1px solid #edf0f2;
		padding-top: 16px;
		margin-top: 14px;
	}
	.device-advanced-actions {
		display: flex;
		gap: 10px;
		justify-content: flex-end;
		margin-top: 18px;
	}
	.device-sync-progress {
		display: none;
		border: 1px solid #edf0f2;
		background: #f8fafb;
		border-radius: 4px;
		padding: 14px;
		margin-top: 16px;
	}
	.device-sync-progress-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin-bottom: 10px;
		color: #3f4a56;
	}
	.device-sync-progress-head strong {
		color: #16a085;
		font-weight: 600;
	}
	.device-sync-progress-meta {
		margin-top: 10px;
		color: #6b7785;
		line-height: 1.8;
		word-break: break-word;
	}
</style>
<script>
  var deviceid;
  var devicename;
  var deviceAdvancedData = <?php echo json_encode($deviceAdvancedData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  layui.use(['layer', 'form', 'element'], function() {
    var layer = layui.layer;
    var form = layui.form;
	var element = layui.element;
	var activeSyncTimers = {};
	form.render();

	form.on('switch(advancedLocalCardSwitch)', function(data) {
		var deviceId = $(data.elem).data('device-id');
		setDeviceLocalCard(deviceId, data.elem.checked ? 1 : 0, data.elem);
	});

	function escapeHtml(text) {
		return String(text || '').replace(/[&<>"']/g, function(s) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];
		});
	}

	function formatDeviceTimestamp(value) {
		value = parseInt(value || 0, 10);
		if (!value) {
			return '暂无';
		}
		var date = new Date(value * 1000);
		var pad = function(num) {
			return String(num).length === 1 ? '0' + num : String(num);
		};
		return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
	}

	function openDeviceAdvanced(id) {
		var device = deviceAdvancedData[id];
		if (!device) {
			layer.msg('设备数据不存在，请刷新页面后重试');
			return;
		}
		var enabled = parseInt(device.local_card_enabled || 0, 10) === 1;
		var html = '<div class="layui-form layui-form-pane device-advanced-wrap">'
			+ '<dl class="device-advanced-grid">'
			+ '<dt>设备名</dt><dd>' + escapeHtml(device.name) + '</dd>'
			+ '<dt>控制器类型</dt><dd>' + escapeHtml(device.controller_type_text || '待心跳识别') + '</dd>'
			+ '<dt>Serial</dt><dd>' + escapeHtml(device.serial || device.did || '-') + '</dd>'
			+ '<dt>Model</dt><dd>' + escapeHtml(device.model || '-') + '</dd>'
			+ '<dt>IP</dt><dd>' + escapeHtml(device.ip || '-') + '</dd>'
			+ '<dt>最后心跳</dt><dd>' + escapeHtml(device.hbtime || '-') + '</dd>'
			+ '</dl>'
			+ '<div class="device-advanced-section">'
			+ '<div class="layui-form-item">'
			+ '<label class="layui-form-label">端侧卡库</label>'
			+ '<div class="layui-input-block"><input type="checkbox" lay-skin="switch" lay-text="启用|关闭" lay-filter="advancedLocalCardSwitch" data-device-id="' + parseInt(device.id, 10) + '" ' + (enabled ? 'checked' : '') + '></div>'
			+ '</div>'
			+ '<dl class="device-advanced-grid">'
			+ '<dt>最近全量</dt><dd>' + escapeHtml(formatDeviceTimestamp(device.local_card_last_full_at)) + '</dd>'
			+ '<dt>首次全量</dt><dd>' + (parseInt(device.local_card_initial_full_done || 0, 10) === 1 ? '已完成' : '未完成，自动同步暂停') + '</dd>'
			+ '<dt>最近下发</dt><dd>' + escapeHtml(formatDeviceTimestamp(device.local_card_last_sync_at)) + '</dd>'
			+ '<dt>队列状态</dt><dd>待下发 ' + parseInt(device.local_card_pending || 0, 10) + '，失败 ' + parseInt(device.local_card_failed || 0, 10) + '，执行中 ' + parseInt(device.local_card_running || 0, 10) + '</dd>'
			+ '<dt>最近消息</dt><dd>' + escapeHtml(device.local_card_sync_message || '暂无') + '</dd>'
			+ '</dl>'
			+ '<div class="device-sync-progress" id="deviceSyncProgressWrap' + parseInt(device.id, 10) + '">'
			+ '<div class="device-sync-progress-head"><span id="deviceSyncProgressTitle' + parseInt(device.id, 10) + '">等待同步任务开始</span><strong id="deviceSyncProgressPercent' + parseInt(device.id, 10) + '">0%</strong></div>'
			+ '<div class="layui-progress layui-progress-big" lay-filter="deviceSyncProgress' + parseInt(device.id, 10) + '" lay-showPercent="true"><div class="layui-progress-bar" lay-percent="0%"></div></div>'
			+ '<div class="device-sync-progress-meta" id="deviceSyncProgressMeta' + parseInt(device.id, 10) + '">已提交后会显示实时进度。</div>'
			+ '</div>'
			+ '</div>'
			+ '<div class="device-advanced-actions">'
			+ '<button type="button" class="layui-btn layui-btn-normal" onclick="syncDeviceCards(' + parseInt(device.id, 10) + ')">手动全量同步</button>'
			+ '<button type="button" class="layui-btn layui-btn-primary" onclick="layui.layer.closeAll()">关闭</button>'
			+ '</div>'
			+ '</div>';
		layer.open({
			type: 1,
			title: '高级设置 - ' + escapeHtml(device.name || id),
			content: html,
			area: ['560px', '600px'],
			success: function() {
				form.render('checkbox');
				element.render('progress');
			},
			end: function() {
				clearDeviceSyncTimer(id);
			}
		});
	}

	function deleteDevice(id) {
		var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=deviceopt&getdevice=" + id + "&csrf=" + "<?php echo $_SESSION['token']; ?>",
			async:true,
			error: function() {
				alert("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					deviceid = json.id;
					devicename = json.name;

					layer.confirm('是否要删除设备：'+devicename, {
						icon: 3, // 问号图标
						title: '确定吗？',
						btn: ['确定', '取消'], // 按钮
						yes: function(index, layero){ // 点击确定按钮的回调函数
						// 执行封禁流程
						var htmlobj = $.ajax({
							type: 'GET',
							url: "?page=panel&module=deviceopt&updateDeviceAction=deleteDevice&updateDeviceId="+deviceid+"&csrf=" + "<?php echo $_SESSION['token']; ?>",
							async:true,
							error: function() {
								vt.error("错误：" + htmlobj.responseText, {
									position: "top-center",
								});
								return;
							},
							success: function() {
								vt.success(htmlobj.responseText, {
									position: "top-center",
								});
								layer.close(index); // 关闭询问框
								location.reload();
								return;
							}
						});
						},
						btn2: function(index, layero){ // 点击取消按钮的回调函数
						layer.close(index); // 关闭询问框
						}
					});
				} catch(e) {
					alert("错误：无法解析服务器返回的数据");
				}
				return;
			}
		});
	}

	// 打开对话框
    function addNewDevice() {
      layer.open({
        type: 1,
        title: '创建设备',
        content: $('#createDeviceDialogTpl').html(),
        area: ['400px', '300px']
      });
    }

    // 关闭对话框
    function closeDialog() {
      layer.closeAll();
    }

    // 创建设备
    function addDevice() {
      var ipaddr = $('#ipaddr').val();
      var devicename = $('#name').val(); 
	  var oemcode = $('#oemcode').val(); 
      
      var htmlobj = $.ajax({
		type: 'POST',
		url: "?action=addDevice&page=panel&module=deviceopt&csrf=<?php echo $_SESSION['token']; ?>",
		async:true,
		data: {
            ipaddr: ipaddr,
			devicename: devicename,
			oemcode: oemcode
		},
		error: function() {
			vt.error("错误：" + htmlobj.responseText, {
				position: "top-center",
			});
			return;
		},
		success: function() {
			vt.success(htmlobj.responseText, {
				position: "top-center",
			});
			location.reload();
			return;
		}
	  });
    }

	function setDeviceLocalCard(id, enabled, elem) {
		var htmlobj = $.ajax({
			type: 'POST',
			url: "?action=setDeviceLocalCard&page=panel&module=deviceopt&csrf=<?php echo $_SESSION['token']; ?>",
			async:true,
			data: {device_id: id, enabled: enabled ? 'true' : 'false'},
			error: function() {
				if (elem) {
					elem.checked = !enabled;
					form.render('checkbox');
				}
				vt.error("错误：" + htmlobj.responseText, {position: "top-center"});
			},
			success: function() {
				vt.success(htmlobj.responseText, {position: "top-center"});
				location.reload();
			}
		});
	}

	function deviceSyncStageText(data) {
		switch (data.stage) {
			case 'queued':
				return '等待计划任务执行';
			case 'calculating':
				return '正在计算本次下发列表';
			case 'syncing':
				return '正在分批下发到设备';
			case 'retrying':
				return '部分失败，等待自动重试';
			case 'done':
				return '同步完成';
			case 'failed':
				return '同步任务失败';
			default:
				return '同步状态更新中';
		}
	}

	function renderDeviceSyncProgress(id, data) {
		var percent = Math.max(0, Math.min(100, parseInt(data.percent || 0, 10)));
		var total = parseInt(data.total || 0, 10);
		var completed = parseInt(data.completed || 0, 10);
		var eligibleTotal = parseInt(data.eligible_total || 0, 10);
		var pending = parseInt(data.pending || 0, 10);
		var running = parseInt(data.running || 0, 10);
		var failed = parseInt(data.failed || 0, 10);
		var success = parseInt(data.success || 0, 10);
		var cancelled = parseInt(data.cancelled || 0, 10);
		var stageText = deviceSyncStageText(data);
		$('#deviceSyncProgressWrap' + id).show();
		$('#deviceSyncProgressTitle' + id).text(stageText);
		$('#deviceSyncProgressPercent' + id).text(percent + '%');
		if (element && element.progress) {
			element.progress('deviceSyncProgress' + id, percent + '%');
		}
		$('#deviceSyncProgressMeta' + id).html(
			'当前按策略可下发人数：' + eligibleTotal + '<br>' +
			'本次下发操作：' + completed + ' / ' + total +
			'，成功 ' + success +
			'，待下发 ' + pending +
			'，执行中 ' + running +
			'，失败待重试 ' + failed +
			(cancelled > 0 ? '，已取消 ' + cancelled : '') +
			(data.message ? '<br>最近消息：' + escapeHtml(data.message) : '')
		);
	}

	function clearDeviceSyncTimer(id) {
		if (activeSyncTimers[id]) {
			clearInterval(activeSyncTimers[id]);
			delete activeSyncTimers[id];
		}
	}

	function pollDeviceSyncProgress(id, jobId) {
		$.ajax({
			type: 'GET',
			url: "?page=panel&module=deviceopt&localCardProgress=1&device_id=" + encodeURIComponent(id) + "&job_id=" + encodeURIComponent(jobId) + "&csrf=<?php echo $_SESSION['token']; ?>",
			dataType: 'json',
			async: true,
			error: function(xhr) {
				clearDeviceSyncTimer(id);
				vt.error("进度查询失败：" + (xhr.responseText || '请刷新页面后重试'), {position: "top-center"});
			},
			success: function(data) {
				if (!data || !data.ok) {
					clearDeviceSyncTimer(id);
					vt.error((data && data.message) ? data.message : '进度查询失败', {position: "top-center"});
					return;
				}
				renderDeviceSyncProgress(id, data);
				if (data.done) {
					clearDeviceSyncTimer(id);
					vt.success('端侧卡库手动全量同步完成', {position: "top-center"});
				}
			}
		});
	}

	function startDeviceSyncProgress(id, jobId) {
		clearDeviceSyncTimer(id);
		renderDeviceSyncProgress(id, {
			stage: 'queued',
			percent: 0,
			eligible_total: 0,
			total: 0,
			completed: 0,
			pending: 0,
			running: 0,
			failed: 0,
			success: 0,
			cancelled: 0,
			message: '任务已提交，等待计划任务执行'
		});
		pollDeviceSyncProgress(id, jobId);
		activeSyncTimers[id] = setInterval(function() {
			pollDeviceSyncProgress(id, jobId);
		}, 3000);
	}

	function syncDeviceCards(id) {
		layer.confirm('确认提交该设备端侧卡库手动全量同步任务？本次会重新下发所有当前有权限的工牌。', {icon: 3, title: '手动全量同步'}, function(index) {
			var htmlobj = $.ajax({
				type: 'POST',
				url: "?action=syncDeviceCards&page=panel&module=deviceopt&csrf=<?php echo $_SESSION['token']; ?>",
				dataType: 'json',
				async:true,
				data: {device_id: id},
				error: function() {
					vt.error("错误：" + htmlobj.responseText, {position: "top-center"});
				},
				success: function(data) {
					if (!data || !data.ok) {
						vt.error((data && data.message) ? data.message : '端侧卡库同步任务提交失败', {position: "top-center"});
						return;
					}
					vt.success(data.message + '，开始跟踪进度', {position: "top-center"});
					layer.close(index);
					startDeviceSyncProgress(id, data.job_id);
				}
			});
		});
	}

	// global
	window.deleteDevice = deleteDevice;
	window.addNewDevice = addNewDevice;
	window.openDeviceAdvanced = openDeviceAdvanced;
	window.closeDialog = closeDialog;
	window.addDevice = addDevice;
	window.syncDeviceCards = syncDeviceCards;
  });
</script>
