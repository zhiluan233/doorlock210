<?php
/*

门禁角色管理模块
Ver 1.1.0.0 20260714
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));
if(!$rs || $rs['type'] !== 'admin') {
	exit("<script>location='/?page=panel&module=accesslog';</script>");
}

function roleFetchRows($sql, $table = 'access_roles') {
	$rs = Database::query($table, $sql, '', true);
	$rows = [];
	if ($rs && $rs instanceof \mysqli_result) {
		while ($row = mysqli_fetch_assoc($rs)) {
			$rows[] = $row;
		}
	}
	return $rows;
}

function roleH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function roleSubjectKind($value) {
	return in_array($value, ['employee', 'learner', 'guest'], true) ? $value : 'employee';
}

function roleSubjectLabel($value) {
	$value = roleSubjectKind($value);
	if ($value === 'learner') { return '学员'; }
	if ($value === 'guest') { return '访客'; }
	return '员工';
}

function roleAllScopeLabel($value) {
	$value = roleSubjectKind($value);
	if ($value === 'learner') { return '全体学员'; }
	if ($value === 'guest') { return '全体访客'; }
	return '全体员工';
}

function roleExpiresText($expiresAt) {
	$expiresAt = intval($expiresAt);
	return $expiresAt > 0 ? date('Y-m-d', $expiresAt) : '永久有效';
}

Database::delete('access_role_members', "DELETE m FROM `access_role_members` m LEFT JOIN `employee` e ON e.`open_id`=m.`employee_open_id` WHERE IFNULL(m.`member_kind`, 'employee')='employee' AND e.`open_id` IS NULL", '', true);
Database::delete('access_role_members', "DELETE m FROM `access_role_members` m LEFT JOIN `learner` l ON l.`student_no`=m.`employee_open_id` WHERE m.`member_kind`='learner' AND l.`student_no` IS NULL", '', true);
Database::delete('access_role_members', "DELETE m FROM `access_role_members` m LEFT JOIN `guest` g ON g.`open_id`=m.`employee_open_id` WHERE m.`member_kind`='guest' AND g.`open_id` IS NULL", '', true);

$employeesRaw = roleFetchRows("SELECT `open_id`, `name`, `employee_id`, `status` FROM `employee` WHERE `open_id`<>'' ORDER BY `status` DESC, `name` ASC", 'employee');
$learnersRaw = roleFetchRows("SELECT `student_no`, `name`, `realname`, `class_name`, `training_center`, `enrolled_at`, `status` FROM `learner` WHERE `student_no`<>'' ORDER BY `status` DESC, `name` ASC", 'learner');
$guestsRaw = roleFetchRows("SELECT `open_id`, `name`, `status` FROM `guest` WHERE `open_id`<>'' ORDER BY `status` DESC, `name` ASC", 'guest');
$devicesRaw = roleFetchRows("SELECT `id`, `name`, `ip` FROM `devices` ORDER BY `id` ASC", 'devices');
$rolesRaw = roleFetchRows("SELECT r.*, (SELECT COUNT(*) FROM `access_role_members` m WHERE m.`role_id`=r.`id`) AS member_count FROM `access_roles` r WHERE r.`enabled`=1 ORDER BY r.`builtin_key` DESC, r.`id` ASC", 'access_roles');
$membersRaw = roleFetchRows("SELECT m.`role_id`, IFNULL(m.`member_kind`, 'employee') AS `member_kind`, m.`employee_open_id` FROM `access_role_members` m INNER JOIN `access_roles` r ON r.`id`=m.`role_id` WHERE r.`enabled`=1 ORDER BY m.`role_id` ASC, m.`id` ASC", 'access_role_members');
$rolePoliciesRaw = roleFetchRows("SELECT `device_id`, `subject_value` FROM `access_policies` WHERE `enabled`=1 AND `subject_type`='role' ORDER BY `device_id` ASC", 'access_policies');

$employees = [];
foreach ($employeesRaw as $employee) {
	$statusText = ($employee['status'] ?? '') === 'true' ? '' : '，禁用';
	$employees[] = [
		'value' => $employee['open_id'],
		'title' => $employee['name'].'（'.($employee['employee_id'] ?: '无工号').$statusText.'）'
	];
}

$guests = [];
foreach ($guestsRaw as $guest) {
	$statusText = ($guest['status'] ?? '') === 'true' ? '' : '，禁用';
	$guests[] = [
		'value' => $guest['open_id'],
		'title' => $guest['name'].$statusText
	];
}

$learners = [];
foreach ($learnersRaw as $learner) {
	$statusText = ($learner['status'] ?? '') === 'true' ? '' : '，禁用';
	$meta = array_filter([
		$learner['student_no'] ?? '',
		$learner['realname'] ?? '',
		$learner['class_name'] ?? '',
		$learner['training_center'] ?? '',
		intval($learner['enrolled_at'] ?? 0) > 0 ? '入学'.date('Y-m-d', intval($learner['enrolled_at'])) : ''
	], function($item) { return $item !== ''; });
	$learners[] = [
		'value' => $learner['student_no'],
		'title' => $learner['name'].'（'.implode('，', $meta).$statusText.'）'
	];
}

$devices = [];
foreach ($devicesRaw as $device) {
	$devices[] = [
		'value' => (string)$device['id'],
		'title' => $device['name'].'（'.($device['ip'] ?: '无IP').'）'
	];
}

$membersByRole = [];
foreach ($membersRaw as $member) {
	$roleId = intval($member['role_id']);
	if (!isset($membersByRole[$roleId])) {
		$membersByRole[$roleId] = [];
	}
	$membersByRole[$roleId][] = $member['employee_open_id'];
}

$devicesByRole = [];
foreach ($rolePoliciesRaw as $policy) {
	$roleId = intval($policy['subject_value']);
	if (!isset($devicesByRole[$roleId])) {
		$devicesByRole[$roleId] = [];
	}
	$devicesByRole[$roleId][] = (string)$policy['device_id'];
}

$roles = [];
foreach ($rolesRaw as $role) {
	$roleId = intval($role['id']);
	$subjectKind = roleSubjectKind($role['subject_kind'] ?? 'employee');
	$roles[] = [
		'id' => $roleId,
		'name' => $role['name'],
		'description' => $role['description'],
		'subject_kind' => $subjectKind,
		'allow_all' => intval($role['allow_all']),
		'builtin_key' => $role['builtin_key'] ?? '',
		'expires_at' => intval($role['expires_at'] ?? 0),
		'member_count' => intval($role['member_count']),
		'members' => $membersByRole[$roleId] ?? [],
		'device_ids' => array_values(array_unique($devicesByRole[$roleId] ?? []))
	];
}

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, <?php echo roleH($rs['username']) ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;">
					<h4 style="font-weight: 400">门禁角色</h4><br>
					<button class="btn btn-default" onclick="openRoleDialog()">创建角色</button>
					<table id="role1" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="10" data-page-list="[5, 10, 20, 30, 50, 'All']" data-sortable="true" data-search="true" style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>角色名</th>
								<th>对象</th>
								<th>范围</th>
								<th>成员数</th>
								<th>已下发门禁</th>
								<th>过期时间</th>
								<th>备注</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php foreach ($roles as $role) { ?>
								<tr>
									<td><?php echo intval($role['id']); ?></td>
									<td><?php echo roleH($role['name']); ?></td>
									<td><?php echo roleSubjectLabel($role['subject_kind']); ?></td>
									<td><?php echo intval($role['allow_all']) === 1 ? roleAllScopeLabel($role['subject_kind']) : '指定成员'; ?></td>
									<td><?php echo intval($role['allow_all']) === 1 ? '动态全体' : intval($role['member_count']); ?></td>
									<td><?php echo count($role['device_ids']); ?></td>
									<td><?php echo roleH(roleExpiresText($role['expires_at'])); ?></td>
									<td><?php echo roleH($role['description']); ?></td>
									<td>
										<?php if ($role['builtin_key'] === '') { ?>
											<button class="btn btn-default" onclick="openRoleDialog(<?php echo intval($role['id']); ?>)">编辑</button>
											<button class="btn btn-default" onclick="deleteAccessRole(<?php echo intval($role['id']); ?>)">删除</button>
										<?php } else { ?>
											<button class="btn btn-default" onclick="openRoleDeployDialog(<?php echo intval($role['id']); ?>)">下发门禁</button>
										<?php } ?>
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
var learnerList = <?php echo json_encode($learners, JSON_UNESCAPED_UNICODE); ?>;
var guestList = <?php echo json_encode($guests, JSON_UNESCAPED_UNICODE); ?>;
var deviceList = <?php echo json_encode($devices, JSON_UNESCAPED_UNICODE); ?>;
var roleData = <?php echo json_encode($roles, JSON_UNESCAPED_UNICODE); ?>;

layui.use(['layer', 'form', 'transfer'], function(){
	var layer = layui.layer;
	var form = layui.form;
	var transfer = layui.transfer;

	function escapeHtml(text) {
		return String(text || '').replace(/[&<>"']/g, function(s) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];
		});
	}

	function findRole(roleId) {
		for (var i = 0; i < roleData.length; i++) {
			if (String(roleData[i].id) === String(roleId)) {
				return roleData[i];
			}
		}
		return null;
	}

	function currentSubjectKind() {
		var kind = $('#subject_kind').val();
		return kind === 'learner' || kind === 'guest' ? kind : 'employee';
	}

	function subjectLabel(kind) {
		if (kind === 'learner') { return '学员'; }
		if (kind === 'guest') { return '访客'; }
		return '员工';
	}

	function roleExpiresDate(role) {
		var expiresAt = parseInt((role && role.expires_at) || 0, 10);
		if (!expiresAt) {
			return '';
		}
		var d = new Date(expiresAt * 1000);
		var month = String(d.getMonth() + 1);
		var day = String(d.getDate());
		return d.getFullYear() + '-' + (month.length === 1 ? '0' + month : month) + '-' + (day.length === 1 ? '0' + day : day);
	}

	function syncRoleExpiresDate() {
		var isPermanent = $('#role_permanent').is(':checked');
		$('#role_expires_date').prop('disabled', isPermanent).toggle(!isPermanent);
	}

	function memberListByKind(kind) {
		if (kind === 'learner') { return learnerList; }
		if (kind === 'guest') { return guestList; }
		return employeeList;
	}

	function renderMemberTransfer(values) {
		var kind = currentSubjectKind();
		$('#roleMemberTransfer').empty();
		transfer.render({
			elem: '#roleMemberTransfer',
			title: ['可选' + subjectLabel(kind), '角色成员'],
			data: memberListByKind(kind),
			value: values || [],
			width: 300,
			height: 300,
			showSearch: true,
			id: 'roleMembers'
		});
	}

	function setMemberVisible() {
		if ($('#allow_all').is(':checked')) {
			$('#roleMemberWrap').hide();
		} else {
			$('#roleMemberWrap').show();
		}
	}

	window.openRoleDialog = function(roleId) {
		var role = roleId ? findRole(roleId) : null;
		if (role && role.builtin_key) {
			layer.msg('系统内置角色不可编辑');
			return;
		}
		if (!role) {
			role = {id: 0, name: '', description: '', subject_kind: 'employee', allow_all: 0, expires_at: 0, members: [], device_ids: []};
		}
		var rolePermanent = parseInt(role.expires_at || 0, 10) <= 0;
		var html = '<div class="layui-form layui-form-pane" style="padding:16px;">'
			+ '<div class="layui-form-item"><label class="layui-form-label">角色名</label><div class="layui-input-block"><input type="text" id="role_name" class="layui-input" value="' + escapeHtml(role.name) + '"></div></div>'
			+ '<div class="layui-form-item"><label class="layui-form-label">对象</label><div class="layui-input-block"><select id="subject_kind"><option value="employee" ' + (role.subject_kind === 'employee' ? 'selected' : '') + '>员工</option><option value="learner" ' + (role.subject_kind === 'learner' ? 'selected' : '') + '>学员</option><option value="guest" ' + (role.subject_kind === 'guest' ? 'selected' : '') + '>访客</option></select></div></div>'
			+ '<div class="layui-form-item"><label class="layui-form-label">备注</label><div class="layui-input-block"><input type="text" id="role_description" class="layui-input" value="' + escapeHtml(role.description) + '"></div></div>'
			+ '<div class="layui-form-item"><label class="layui-form-label">有效期</label><div class="layui-input-block"><input type="checkbox" id="role_permanent" title="永久有效" lay-skin="primary" lay-filter="role_permanent" ' + (rolePermanent ? 'checked' : '') + '><input type="date" id="role_expires_date" class="layui-input" style="margin-top:10px;' + (rolePermanent ? 'display:none;' : '') + '" value="' + escapeHtml(roleExpiresDate(role)) + '" ' + (rolePermanent ? 'disabled' : '') + '></div></div>'
			+ '<div class="layui-form-item"><input type="checkbox" id="allow_all" title="全体角色" lay-skin="primary" ' + (parseInt(role.allow_all, 10) === 1 ? 'checked' : '') + '></div>'
			+ '<div id="roleMemberWrap"><div id="roleMemberTransfer"></div></div>'
			+ '<hr><div id="roleDeviceTransfer"></div>'
			+ '<div style="text-align:center;margin-top:16px;"><button class="layui-btn layui-btn-normal" onclick="saveAccessRole(' + parseInt(role.id, 10) + ')">保存</button><button class="layui-btn layui-btn-primary" onclick="layui.layer.closeAll()">取消</button></div>'
			+ '</div>';

		layer.open({
			type: 1,
			title: role.id ? '编辑门禁角色' : '创建门禁角色',
			area: ['760px', '800px'],
			content: html,
			success: function() {
				renderMemberTransfer(role.members || []);
				transfer.render({
					elem: '#roleDeviceTransfer',
					title: ['可选门禁', '允许通行门禁'],
					data: deviceList,
					value: role.device_ids || [],
					width: 300,
					height: 220,
					showSearch: true,
					id: 'roleDevices'
				});
				$('#allow_all').on('change', setMemberVisible);
				$('#subject_kind').on('change', function() {
					renderMemberTransfer([]);
					form.render();
				});
				form.on('checkbox(role_permanent)', function() {
					syncRoleExpiresDate();
				});
				$('#role_expires_date').on('change', function() {
					if ($(this).val() !== '') {
						$('#role_permanent').prop('checked', false);
						syncRoleExpiresDate();
						form.render('checkbox');
					}
				});
				setMemberVisible();
				syncRoleExpiresDate();
				form.render();
			}
		});
	};

	window.saveAccessRole = function(roleId) {
		var members = [];
		var devices = [];
		if (!$('#allow_all').is(':checked')) {
			transfer.getData('roleMembers').forEach(function(item) {
				members.push(item.value);
			});
		}
		transfer.getData('roleDevices').forEach(function(item) {
			devices.push(item.value);
		});
		$.ajax({
			type: 'POST',
			url: '?action=saveAccessRole&page=panel&module=role&csrf=' + csrf_token,
			data: {
				role_id: roleId,
				name: $('#role_name').val(),
				description: $('#role_description').val(),
				subject_kind: currentSubjectKind(),
				allow_all: $('#allow_all').is(':checked') ? 'true' : 'false',
				role_permanent: $('#role_permanent').is(':checked') ? 'true' : 'false',
				expires_date: $('#role_expires_date').val(),
				members: JSON.stringify(members),
				devices: JSON.stringify(devices)
			},
			success: function(resp) {
				layer.msg(resp);
				setTimeout(function(){ location.reload(); }, 600);
			},
			error: function(xhr) {
				layer.msg('保存失败：' + xhr.responseText);
			}
		});
	};

	window.openRoleDeployDialog = function(roleId) {
		var role = findRole(roleId);
		if (!role) {
			layer.msg('角色不存在');
			return;
		}
		var html = '<div class="layui-form layui-form-pane" style="padding:16px;">'
			+ '<div id="builtinRoleDeviceTransfer"></div>'
			+ '<div style="text-align:center;margin-top:16px;"><button class="layui-btn layui-btn-normal" onclick="saveAccessRoleDevices(' + parseInt(role.id, 10) + ')">保存</button><button class="layui-btn layui-btn-primary" onclick="layui.layer.closeAll()">取消</button></div>'
			+ '</div>';
		layer.open({
			type: 1,
			title: '下发门禁 - ' + escapeHtml(role.name),
			area: ['760px', '520px'],
			content: html,
			success: function() {
				transfer.render({
					elem: '#builtinRoleDeviceTransfer',
					title: ['可选门禁', '允许通行门禁'],
					data: deviceList,
					value: role.device_ids || [],
					width: 300,
					height: 320,
					showSearch: true,
					id: 'builtinRoleDevices'
				});
				form.render();
			}
		});
	};

	window.saveAccessRoleDevices = function(roleId) {
		var devices = [];
		transfer.getData('builtinRoleDevices').forEach(function(item) {
			devices.push(item.value);
		});
		$.ajax({
			type: 'POST',
			url: '?action=saveAccessRoleDevices&page=panel&module=role&csrf=' + csrf_token,
			data: {
				role_id: roleId,
				devices: JSON.stringify(devices)
			},
			success: function(resp) {
				layer.msg(resp);
				setTimeout(function(){ location.reload(); }, 600);
			},
			error: function(xhr) {
				layer.msg('保存失败：' + xhr.responseText);
			}
		});
	};

	window.deleteAccessRole = function(roleId) {
		layer.confirm('确认删除该门禁角色？', {icon: 3, title: '删除角色'}, function(index) {
			layer.close(index);
			$.ajax({
				type: 'POST',
				url: '?action=deleteAccessRole&page=panel&module=role&csrf=' + csrf_token,
				data: {role_id: roleId},
				success: function(resp) {
					layer.msg(resp);
					setTimeout(function(){ location.reload(); }, 600);
				},
				error: function(xhr) {
					layer.msg('删除失败：' + xhr.responseText);
				}
			});
		});
	};
});
</script>
