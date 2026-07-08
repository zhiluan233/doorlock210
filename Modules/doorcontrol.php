<?php
/*

门禁控制与通行权限模块
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

function fetchAssocRows($sql, $table = 'devices') {
	$rs = Database::query($table, $sql, '', true);
	$rows = [];
	if ($rs) {
		while ($row = mysqli_fetch_assoc($rs)) {
			$rows[] = $row;
		}
	}
	return $rows;
}

$devices = fetchAssocRows('SELECT * FROM `devices` ORDER BY `id` ASC', 'devices');
$employeesRaw = fetchAssocRows("SELECT * FROM `employee` WHERE `status`='true' ORDER BY `name` ASC", 'employee');
$guestsRaw = fetchAssocRows("SELECT * FROM `guest` WHERE `status`='true' ORDER BY `name` ASC", 'guest');
$policiesRaw = fetchAssocRows('SELECT * FROM `access_policies` WHERE `enabled`=1 ORDER BY `id` ASC', 'access_policies');

$employees = [];
$departments = [];
$groups = [];
$roles = [];
foreach ($employeesRaw as $employee) {
	$employees[] = ['value' => $employee['open_id'], 'title' => $employee['name'].'（'.($employee['employee_id'] ?: '无工号').'）'];
	foreach ([$employee['department_id'] ?? '', $employee['department_name'] ?? ''] as $dept) {
		if ($dept !== '') { $departments[$dept] = $dept; }
	}
	$groupItems = json_decode($employee['groups'] ?? '', true);
	if (!is_array($groupItems)) {
		$groupItems = array_filter(array_map('trim', explode(',', $employee['groups'] ?? '')));
	}
	foreach ($groupItems as $item) {
		if ($item !== '') { $groups[$item] = $item; }
	}
	$roleItems = json_decode($employee['roles'] ?? '', true);
	if (!is_array($roleItems)) {
		$roleItems = array_filter(array_map('trim', explode(',', $employee['roles'] ?? '')));
	}
	foreach ($roleItems as $item) {
		if ($item !== '') { $roles[$item] = $item; }
	}
}

$guests = [];
foreach ($guestsRaw as $guest) {
	$guests[] = ['value' => $guest['open_id'], 'title' => $guest['name']];
}

$policyMap = [];
foreach ($devices as $device) {
	$policyMap[$device['id']] = [
		'all_employee' => false,
		'all_guest' => false,
		'employees' => [],
		'guests' => [],
		'departments' => [],
		'groups' => [],
		'roles' => [],
		'department_groups' => []
	];
	$legacyEmployees = json_decode($device['allowedEmployee'] ?? '[]', true);
	if (is_array($legacyEmployees)) {
		foreach ($legacyEmployees as $item) {
			if (isset($item['value'])) { $policyMap[$device['id']]['employees'][] = $item['value']; }
		}
	}
	$legacyGuests = json_decode($device['allowedGuest'] ?? '[]', true);
	if (is_array($legacyGuests)) {
		foreach ($legacyGuests as $item) {
			if (isset($item['value'])) { $policyMap[$device['id']]['guests'][] = $item['value']; }
		}
	}
}

foreach ($policiesRaw as $policy) {
	$deviceId = $policy['device_id'];
	if (!isset($policyMap[$deviceId])) {
		continue;
	}
	if ($policy['subject_kind'] === 'employee' && $policy['subject_type'] === 'all') {
		$policyMap[$deviceId]['all_employee'] = true;
	}
	if ($policy['subject_kind'] === 'guest' && $policy['subject_type'] === 'all') {
		$policyMap[$deviceId]['all_guest'] = true;
	}
	if ($policy['subject_kind'] === 'employee' && $policy['subject_type'] === 'employee') {
		$policyMap[$deviceId]['employees'][] = $policy['subject_value'];
	}
	if ($policy['subject_kind'] === 'guest' && $policy['subject_type'] === 'guest') {
		$policyMap[$deviceId]['guests'][] = $policy['subject_value'];
	}
	if ($policy['subject_type'] === 'department') {
		$policyMap[$deviceId]['departments'][] = $policy['subject_value'];
	}
	if ($policy['subject_type'] === 'group') {
		$policyMap[$deviceId]['groups'][] = $policy['subject_value'];
	}
	if ($policy['subject_type'] === 'role') {
		$policyMap[$deviceId]['roles'][] = $policy['subject_value'];
	}
	if ($policy['subject_type'] === 'department_group') {
		$policyMap[$deviceId]['department_groups'][] = $policy['subject_value'].'|'.$policy['subject_extra'];
	}
}
foreach ($policyMap as $deviceId => $policy) {
	foreach ($policy as $key => $value) {
		if (is_array($value)) {
			$policyMap[$deviceId][$key] = array_values(array_unique($value));
		}
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
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;">
					<h4 style="font-weight: 400">门禁控制与权限</h4><br>
					<h6>按设备设置员工、访客、部门、组、角色和部门+组通行策略</h6><br />
					<table id="devices1" class="table table-bordered table-auto" style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>设备名</th>
								<th>IP</th>
								<th>最后一次心跳</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php foreach ($devices as $dData) { ?>
								<tr>
									<td><?php echo $dData['id']; ?></td>
									<td><?php echo htmlspecialchars($dData['name']); ?></td>
									<td><?php echo htmlspecialchars($dData['ip']); ?></td>
									<td><?php echo htmlspecialchars($dData['hbtime']); ?></td>
									<td>
										<button style="margin-left:5px;margin-top:5px" class="btn btn-default" onclick="editPolicy(<?php echo $dData['id']; ?>)">编辑通行策略</button>
										<button style="margin-left:5px;margin-top:5px" class="btn btn-default" onclick="remoteOpen(<?php echo $dData['id']; ?>)">远程开门</button>
									</td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
    </div>
</div>
<script src="asset/layui/layui.js"></script>
<script>
var csrf_token = "<?php echo $_SESSION['token']; ?>";
var employeeList = <?php echo json_encode($employees, JSON_UNESCAPED_UNICODE); ?>;
var guestList = <?php echo json_encode($guests, JSON_UNESCAPED_UNICODE); ?>;
var policyMap = <?php echo json_encode($policyMap, JSON_UNESCAPED_UNICODE); ?>;
var deviceMap = <?php echo json_encode(array_column($devices, 'name', 'id'), JSON_UNESCAPED_UNICODE); ?>;

layui.use(['layer', 'util', 'form', 'transfer'], function(){
	var layer = layui.layer;
	var form = layui.form;
	var transfer = layui.transfer;

	window.remoteOpen = function(deviceId) {
		layer.confirm('确认远程打开 ' + (deviceMap[deviceId] || deviceId) + '？', {icon: 3, title: '远程开门'}, function(index) {
			layer.close(index);
			$.ajax({
				type: 'POST',
				url: '?action=remoteOpenDoor&page=panel&module=doorcontrol&csrf=' + csrf_token,
				data: {device_id: deviceId},
				success: function(resp) { layer.msg(resp); },
				error: function(xhr) { layer.msg('远程开门失败：' + xhr.responseText); }
			});
		});
	};

	window.editPolicy = function(deviceId) {
		var policy = policyMap[deviceId] || {};
		var html = '<div class="layui-form layui-form-pane" style="padding:16px;">'
			+ '<div class="layui-form-item"><input type="checkbox" id="all_employee" title="允许全体员工通行" lay-skin="primary" ' + (policy.all_employee ? 'checked' : '') + '></div>'
			+ '<div id="employeeTransfer"></div>'
			+ '<hr><div class="layui-form-item"><input type="checkbox" id="all_guest" title="允许全体访客通行" lay-skin="primary" ' + (policy.all_guest ? 'checked' : '') + '></div>'
			+ '<div id="guestTransfer"></div>'
			+ '<hr>'
			+ '<div class="layui-form-item"><label class="layui-form-label">部门</label><div class="layui-input-block"><textarea id="departments" class="layui-textarea" placeholder="每行一个部门ID或部门名">' + escapeHtml((policy.departments || []).join("\n")) + '</textarea></div></div>'
			+ '<div class="layui-form-item"><label class="layui-form-label">组</label><div class="layui-input-block"><textarea id="groups" class="layui-textarea" placeholder="每行一个组名">' + escapeHtml((policy.groups || []).join("\n")) + '</textarea></div></div>'
			+ '<div class="layui-form-item"><label class="layui-form-label">角色</label><div class="layui-input-block"><textarea id="roles" class="layui-textarea" placeholder="每行一个角色名">' + escapeHtml((policy.roles || []).join("\n")) + '</textarea></div></div>'
			+ '<div class="layui-form-item"><label class="layui-form-label">部门+组</label><div class="layui-input-block"><textarea id="department_groups" class="layui-textarea" placeholder="每行一个：部门|组">' + escapeHtml((policy.department_groups || []).join("\n")) + '</textarea></div></div>'
			+ '<div style="text-align:center;"><button class="layui-btn layui-btn-normal" onclick="savePolicy(' + deviceId + ')">保存</button><button class="layui-btn layui-btn-primary" onclick="layui.layer.closeAll()">取消</button></div>'
			+ '</div>';

		layer.open({
			type: 1,
			title: '编辑通行策略 - ' + (deviceMap[deviceId] || deviceId),
			area: ['760px', '720px'],
			content: html,
			success: function() {
				transfer.render({
					elem: '#employeeTransfer',
					title: ['可选员工', '已允许员工'],
					data: employeeList,
					value: policy.employees || [],
					width: 300,
					height: 260,
					showSearch: true,
					id: 'employeePolicy'
				});
				transfer.render({
					elem: '#guestTransfer',
					title: ['可选访客', '已允许访客'],
					data: guestList,
					value: policy.guests || [],
					width: 300,
					height: 200,
					showSearch: true,
					id: 'guestPolicy'
				});
				form.render();
			}
		});
	};

	window.savePolicy = function(deviceId) {
		var policies = [];
		if ($('#all_employee').is(':checked')) {
			policies.push({subject_kind: 'employee', subject_type: 'all', subject_value: ''});
		}
		if ($('#all_guest').is(':checked')) {
			policies.push({subject_kind: 'guest', subject_type: 'all', subject_value: ''});
		}
		transfer.getData('employeePolicy').forEach(function(item) {
			policies.push({subject_kind: 'employee', subject_type: 'employee', subject_value: item.value, title: item.title});
		});
		transfer.getData('guestPolicy').forEach(function(item) {
			policies.push({subject_kind: 'guest', subject_type: 'guest', subject_value: item.value, title: item.title});
		});
		lines('#departments').forEach(function(value) {
			policies.push({subject_kind: 'employee', subject_type: 'department', subject_value: value});
		});
		lines('#groups').forEach(function(value) {
			policies.push({subject_kind: 'employee', subject_type: 'group', subject_value: value});
		});
		lines('#roles').forEach(function(value) {
			policies.push({subject_kind: 'employee', subject_type: 'role', subject_value: value});
		});
		lines('#department_groups').forEach(function(value) {
			var parts = value.split('|');
			if (parts.length >= 2) {
				policies.push({subject_kind: 'employee', subject_type: 'department_group', subject_value: parts[0].trim(), subject_extra: parts.slice(1).join('|').trim()});
			}
		});
		$.ajax({
			type: 'POST',
			url: '?action=saveAccessPolicy&page=panel&module=doorcontrol&csrf=' + csrf_token,
			data: {device_id: deviceId, policies: JSON.stringify(policies)},
			success: function(resp) {
				layer.msg(resp);
				setTimeout(function(){ location.reload(); }, 600);
			},
			error: function(xhr) {
				layer.msg('保存失败：' + xhr.responseText);
			}
		});
	};

	function lines(selector) {
		return ($(selector).val() || '').split(/\n|,/).map(function(item) { return item.trim(); }).filter(Boolean);
	}
	function escapeHtml(text) {
		return String(text || '').replace(/[&<>"']/g, function(s) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];
		});
	}
});
</script>
