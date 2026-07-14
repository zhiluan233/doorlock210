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

function dcListValues($value) {
	if (is_array($value)) {
		return $value;
	}
	$value = trim((string)$value);
	if ($value === '') {
		return [];
	}
	$json = json_decode($value, true);
	if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
		return array_values(array_filter(array_map('strval', array_filter($json, 'is_scalar')), function($item) { return $item !== ''; }));
	}
	return array_values(array_filter(array_map('trim', explode(',', $value)), function($item) { return $item !== ''; }));
}

$devices = fetchAssocRows('SELECT * FROM `devices` ORDER BY `id` ASC', 'devices');
$employeesRaw = fetchAssocRows("SELECT * FROM `employee` WHERE `status`='true' ORDER BY `name` ASC", 'employee');
$guestsRaw = fetchAssocRows("SELECT * FROM `guest` WHERE `status`='true' ORDER BY `name` ASC", 'guest');
$departmentsRaw = fetchAssocRows("SELECT * FROM `feishu_departments` WHERE `status`='active' ORDER BY `name` ASC, `department_id` ASC", 'feishu_departments');
$rolesRaw = fetchAssocRows("SELECT r.*, (SELECT COUNT(*) FROM `access_role_members` m WHERE m.`role_id`=r.`id`) AS member_count FROM `access_roles` r WHERE r.`enabled`=1 ORDER BY r.`id` ASC", 'access_roles');
$policiesRaw = fetchAssocRows('SELECT * FROM `access_policies` WHERE `enabled`=1 ORDER BY `id` ASC', 'access_policies');

$employees = [];
$groups = [];
foreach ($employeesRaw as $employee) {
	$employees[] = ['value' => $employee['open_id'], 'title' => $employee['name'].'（'.($employee['employee_id'] ?: '无工号').'）'];
	$groupItems = dcListValues($employee['groups'] ?? '');
	foreach ($groupItems as $item) {
		if ($item !== '') { $groups[$item] = $item; }
	}
}

$departments = [];
$departmentNames = [];
foreach ($departmentsRaw as $department) {
	$departmentId = $department['department_id'] ?? '';
	if ($departmentId === '') { continue; }
	$title = ($department['name'] ?: $departmentId).'（'.$departmentId.'）';
	$departmentNames[$departmentId] = $department['name'] ?: $departmentId;
	$departments[] = ['value' => $departmentId, 'title' => $title];
}

$groupList = [];
foreach ($groups as $group) {
	$groupList[] = ['value' => $group, 'title' => $group];
}

$departmentGroups = [];
foreach ($employeesRaw as $employee) {
	$groupItems = dcListValues($employee['groups'] ?? '');
	if (count($groupItems) === 0) {
		continue;
	}
	$departmentIds = dcListValues($employee['department_ids'] ?? '');
	if (!empty($employee['department_id'])) {
		$departmentIds[] = $employee['department_id'];
	}
	$departmentIds = array_values(array_unique(array_filter($departmentIds)));
	foreach ($departmentIds as $departmentId) {
		foreach ($groupItems as $group) {
			$key = $departmentId.'|'.$group;
			$departmentName = $departmentNames[$departmentId] ?? $departmentId;
			$departmentGroups[$key] = $departmentName.' / '.$group;
		}
	}
}
$departmentGroupList = [];
foreach ($departmentGroups as $value => $title) {
	$departmentGroupList[] = ['value' => $value, 'title' => $title];
}

$roles = [];
foreach ($rolesRaw as $role) {
	$roleId = (string)$role['id'];
	$subjectKind = ($role['subject_kind'] ?? 'employee') === 'guest' ? 'guest' : 'employee';
	$scope = intval($role['allow_all'] ?? 0) === 1 ? ($subjectKind === 'guest' ? '全体访客' : '全体员工') : (intval($role['member_count'] ?? 0).'人');
	$roles[] = ['value' => $roleId, 'title' => $role['name'].'（'.($subjectKind === 'guest' ? '访客，' : '员工，').$scope.'）', 'subject_kind' => $subjectKind];
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

$knownGroups = [];
foreach ($groupList as $item) {
	$knownGroups[$item['value']] = true;
}
$knownDepartmentGroups = [];
foreach ($departmentGroupList as $item) {
	$knownDepartmentGroups[$item['value']] = true;
}
foreach ($policyMap as $policy) {
	foreach ($policy['groups'] as $group) {
		if ($group !== '' && !isset($knownGroups[$group])) {
			$groupList[] = ['value' => $group, 'title' => $group];
			$knownGroups[$group] = true;
		}
	}
	foreach ($policy['department_groups'] as $departmentGroup) {
		if ($departmentGroup !== '' && !isset($knownDepartmentGroups[$departmentGroup])) {
			$parts = explode('|', $departmentGroup, 2);
			$title = count($parts) === 2 ? (($departmentNames[$parts[0]] ?? $parts[0]).' / '.$parts[1]) : $departmentGroup;
			$departmentGroupList[] = ['value' => $departmentGroup, 'title' => $title];
			$knownDepartmentGroups[$departmentGroup] = true;
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
var departmentList = <?php echo json_encode($departments, JSON_UNESCAPED_UNICODE); ?>;
var groupList = <?php echo json_encode($groupList, JSON_UNESCAPED_UNICODE); ?>;
var departmentGroupList = <?php echo json_encode($departmentGroupList, JSON_UNESCAPED_UNICODE); ?>;
var roleList = <?php echo json_encode($roles, JSON_UNESCAPED_UNICODE); ?>;
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
			+ '<div id="departmentTransfer"></div>'
			+ '<div id="groupTransfer"></div>'
			+ '<div id="roleTransfer"></div>'
			+ '<div id="departmentGroupTransfer"></div>'
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
				transfer.render({
					elem: '#departmentTransfer',
					title: ['可选部门', '已允许部门'],
					data: departmentList,
					value: policy.departments || [],
					width: 300,
					height: 220,
					showSearch: true,
					id: 'departmentPolicy'
				});
				transfer.render({
					elem: '#groupTransfer',
					title: ['可选组', '已允许组'],
					data: groupList,
					value: policy.groups || [],
					width: 300,
					height: 220,
					showSearch: true,
					id: 'groupPolicy'
				});
				transfer.render({
					elem: '#roleTransfer',
					title: ['可选角色', '已允许角色'],
					data: roleList,
					value: policy.roles || [],
					width: 300,
					height: 220,
					showSearch: true,
					id: 'rolePolicy'
				});
				transfer.render({
					elem: '#departmentGroupTransfer',
					title: ['可选部门+组', '已允许部门+组'],
					data: departmentGroupList,
					value: policy.department_groups || [],
					width: 300,
					height: 220,
					showSearch: true,
					id: 'departmentGroupPolicy'
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
		transfer.getData('departmentPolicy').forEach(function(item) {
			policies.push({subject_kind: 'employee', subject_type: 'department', subject_value: item.value, title: item.title});
		});
		transfer.getData('groupPolicy').forEach(function(item) {
			policies.push({subject_kind: 'employee', subject_type: 'group', subject_value: item.value, title: item.title});
		});
		transfer.getData('rolePolicy').forEach(function(item) {
			policies.push({subject_kind: item.subject_kind || 'employee', subject_type: 'role', subject_value: item.value, title: item.title});
		});
		transfer.getData('departmentGroupPolicy').forEach(function(item) {
			var parts = String(item.value || '').split('|');
			if (parts.length >= 2) {
				policies.push({subject_kind: 'employee', subject_type: 'department_group', subject_value: parts[0].trim(), subject_extra: parts.slice(1).join('|').trim(), title: item.title});
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

});
</script>
