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

function doorcontrolSubjectKind($value) {
	return in_array($value, ['employee', 'learner', 'guest'], true) ? $value : 'employee';
}

function doorcontrolSubjectLabel($value) {
	$value = doorcontrolSubjectKind($value);
	if ($value === 'learner') { return '学员'; }
	if ($value === 'guest') { return '访客'; }
	return '员工';
}

function doorcontrolAllScopeLabel($value) {
	$value = doorcontrolSubjectKind($value);
	if ($value === 'learner') { return '全体学员'; }
	if ($value === 'guest') { return '全体访客'; }
	return '全体员工';
}

$devices = fetchAssocRows('SELECT * FROM `devices` ORDER BY `id` ASC', 'devices');
$employeesRaw = fetchAssocRows("SELECT * FROM `employee` WHERE `status`='true' ORDER BY `name` ASC", 'employee');
$learnersRaw = fetchAssocRows("SELECT * FROM `learner` WHERE `status`='true' ORDER BY `name` ASC", 'learner');
$guestsRaw = fetchAssocRows("SELECT * FROM `guest` WHERE `status`='true' ORDER BY `name` ASC", 'guest');
$departmentsRaw = fetchAssocRows("SELECT * FROM `feishu_departments` WHERE `status`='active' ORDER BY `name` ASC, `department_id` ASC", 'feishu_departments');
$rolesRaw = fetchAssocRows("SELECT r.*, (SELECT COUNT(*) FROM `access_role_members` m WHERE m.`role_id`=r.`id`) AS member_count FROM `access_roles` r WHERE r.`enabled`=1 ORDER BY r.`id` ASC", 'access_roles');
$policiesRaw = fetchAssocRows('SELECT * FROM `access_policies` WHERE `enabled`=1 ORDER BY `id` ASC', 'access_policies');

$employees = [];
foreach ($employeesRaw as $employee) {
	$employees[] = ['value' => $employee['open_id'], 'title' => $employee['name'].'（'.($employee['employee_id'] ?: '无工号').'）'];
}

$departments = [];
foreach ($departmentsRaw as $department) {
	$departmentId = $department['department_id'] ?? '';
	if ($departmentId === '') { continue; }
	$title = ($department['name'] ?: $departmentId).'（'.$departmentId.'）';
	$departments[] = ['value' => $departmentId, 'title' => $title];
}

$roles = [];
foreach ($rolesRaw as $role) {
	$roleId = (string)$role['id'];
	$subjectKind = doorcontrolSubjectKind($role['subject_kind'] ?? 'employee');
	$scope = intval($role['allow_all'] ?? 0) === 1 ? doorcontrolAllScopeLabel($subjectKind) : (intval($role['member_count'] ?? 0).'人');
	$roles[] = ['value' => $roleId, 'title' => $role['name'].'（'.doorcontrolSubjectLabel($subjectKind).'，'.$scope.'）', 'subject_kind' => $subjectKind];
}

$learners = [];
foreach ($learnersRaw as $learner) {
	$meta = array_filter([
		$learner['student_no'] ?? '',
		$learner['realname'] ?? '',
		$learner['class_name'] ?? '',
		$learner['training_center'] ?? ''
	], function($item) { return $item !== ''; });
	$learners[] = ['value' => $learner['student_no'], 'title' => $learner['name'].'（'.implode('，', $meta).'）'];
}

$guests = [];
foreach ($guestsRaw as $guest) {
	$guests[] = ['value' => $guest['open_id'], 'title' => $guest['name']];
}

$policyMap = [];
foreach ($devices as $device) {
	$policyMap[$device['id']] = [
		'all_employee' => false,
		'all_learner' => false,
		'all_guest' => false,
		'employees' => [],
		'learners' => [],
		'guests' => [],
		'departments' => [],
		'roles' => []
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
	if ($policy['subject_kind'] === 'learner' && $policy['subject_type'] === 'all') {
		$policyMap[$deviceId]['all_learner'] = true;
	}
	if ($policy['subject_kind'] === 'employee' && $policy['subject_type'] === 'employee') {
		$policyMap[$deviceId]['employees'][] = $policy['subject_value'];
	}
	if ($policy['subject_kind'] === 'learner' && $policy['subject_type'] === 'learner') {
		$policyMap[$deviceId]['learners'][] = $policy['subject_value'];
	}
	if ($policy['subject_kind'] === 'guest' && $policy['subject_type'] === 'guest') {
		$policyMap[$deviceId]['guests'][] = $policy['subject_value'];
	}
	if ($policy['subject_type'] === 'department') {
		$policyMap[$deviceId]['departments'][] = $policy['subject_value'];
	}
	if ($policy['subject_type'] === 'role') {
		$policyMap[$deviceId]['roles'][] = $policy['subject_value'];
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
					<h6>按设备设置员工、学员、访客、部门和角色通行策略</h6><br />
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
<style>
.access-policy-form {
	padding: 18px 18px 20px;
}
.access-policy-section {
	margin: 0 0 26px;
}
.access-policy-section + .access-policy-section {
	padding-top: 2px;
}
.access-policy-title {
	margin: 0 0 12px;
	padding-left: 8px;
	border-left: 3px solid #1e9fff;
	font-size: 14px;
	font-weight: 600;
	color: #2f3a4a;
}
.access-policy-switch {
	margin-bottom: 12px;
}
.access-policy-actions {
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid #edf0f5;
	text-align: center;
}
</style>
<script>
var csrf_token = "<?php echo $_SESSION['token']; ?>";
var employeeList = <?php echo json_encode($employees, JSON_UNESCAPED_UNICODE); ?>;
var learnerList = <?php echo json_encode($learners, JSON_UNESCAPED_UNICODE); ?>;
var guestList = <?php echo json_encode($guests, JSON_UNESCAPED_UNICODE); ?>;
var departmentList = <?php echo json_encode($departments, JSON_UNESCAPED_UNICODE); ?>;
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
		var html = '<div class="layui-form layui-form-pane access-policy-form">'
			+ '<div class="access-policy-section"><div class="access-policy-title">员工</div>'
			+ '<div class="layui-form-item access-policy-switch"><input type="checkbox" id="all_employee" title="允许全体员工通行" lay-skin="primary" ' + (policy.all_employee ? 'checked' : '') + '></div>'
			+ '<div id="employeeTransfer"></div></div>'
			+ '<div class="access-policy-section"><div class="access-policy-title">学员</div>'
			+ '<div class="layui-form-item access-policy-switch"><input type="checkbox" id="all_learner" title="允许全体学员通行" lay-skin="primary" ' + (policy.all_learner ? 'checked' : '') + '></div>'
			+ '<div id="learnerTransfer"></div></div>'
			+ '<div class="access-policy-section"><div class="access-policy-title">访客</div>'
			+ '<div class="layui-form-item access-policy-switch"><input type="checkbox" id="all_guest" title="允许全体访客通行" lay-skin="primary" ' + (policy.all_guest ? 'checked' : '') + '></div>'
			+ '<div id="guestTransfer"></div></div>'
			+ '<div class="access-policy-section"><div class="access-policy-title">部门</div><div id="departmentTransfer"></div></div>'
			+ '<div class="access-policy-section"><div class="access-policy-title">角色</div><div id="roleTransfer"></div></div>'
			+ '<div class="access-policy-actions"><button class="layui-btn layui-btn-normal" onclick="savePolicy(' + deviceId + ')">保存</button><button class="layui-btn layui-btn-primary" onclick="layui.layer.closeAll()">取消</button></div>'
			+ '</div>';

		layer.open({
			type: 1,
			title: '编辑通行策略 - ' + (deviceMap[deviceId] || deviceId),
			area: ['780px', '760px'],
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
					elem: '#learnerTransfer',
					title: ['可选学员', '已允许学员'],
					data: learnerList,
					value: policy.learners || [],
					width: 300,
					height: 220,
					showSearch: true,
					id: 'learnerPolicy'
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
					elem: '#roleTransfer',
					title: ['可选角色', '已允许角色'],
					data: roleList,
					value: policy.roles || [],
					width: 300,
					height: 220,
					showSearch: true,
					id: 'rolePolicy'
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
		if ($('#all_learner').is(':checked')) {
			policies.push({subject_kind: 'learner', subject_type: 'all', subject_value: ''});
		}
		if ($('#all_guest').is(':checked')) {
			policies.push({subject_kind: 'guest', subject_type: 'all', subject_value: ''});
		}
		transfer.getData('employeePolicy').forEach(function(item) {
			policies.push({subject_kind: 'employee', subject_type: 'employee', subject_value: item.value, title: item.title});
		});
		transfer.getData('learnerPolicy').forEach(function(item) {
			policies.push({subject_kind: 'learner', subject_type: 'learner', subject_value: item.value, title: item.title});
		});
		transfer.getData('guestPolicy').forEach(function(item) {
			policies.push({subject_kind: 'guest', subject_type: 'guest', subject_value: item.value, title: item.title});
		});
		transfer.getData('departmentPolicy').forEach(function(item) {
			policies.push({subject_kind: 'employee', subject_type: 'department', subject_value: item.value, title: item.title});
		});
		transfer.getData('rolePolicy').forEach(function(item) {
			policies.push({subject_kind: item.subject_kind || 'employee', subject_type: 'role', subject_value: item.value, title: item.title});
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
