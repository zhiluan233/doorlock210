<?php
/*

门禁角色管理模块
Ver 1.0.0.0 20260708
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

Database::delete('access_role_members', "DELETE m FROM `access_role_members` m LEFT JOIN `employee` e ON e.`open_id`=m.`employee_open_id` WHERE e.`open_id` IS NULL", '', true);

$employeesRaw = roleFetchRows("SELECT `open_id`, `name`, `employee_id`, `status` FROM `employee` WHERE `open_id`<>'' ORDER BY `status` DESC, `name` ASC", 'employee');
$rolesRaw = roleFetchRows("SELECT r.*, (SELECT COUNT(*) FROM `access_role_members` m WHERE m.`role_id`=r.`id`) AS member_count FROM `access_roles` r WHERE r.`enabled`=1 ORDER BY r.`id` ASC", 'access_roles');
$membersRaw = roleFetchRows("SELECT m.`role_id`, m.`employee_open_id` FROM `access_role_members` m INNER JOIN `access_roles` r ON r.`id`=m.`role_id` INNER JOIN `employee` e ON e.`open_id`=m.`employee_open_id` WHERE r.`enabled`=1 ORDER BY m.`role_id` ASC, e.`name` ASC", 'access_role_members');

$employees = [];
foreach ($employeesRaw as $employee) {
	$statusText = ($employee['status'] ?? '') === 'true' ? '' : '，禁用';
	$employees[] = [
		'value' => $employee['open_id'],
		'title' => $employee['name'].'（'.($employee['employee_id'] ?: '无工号').$statusText.'）'
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

$roles = [];
foreach ($rolesRaw as $role) {
	$roleId = intval($role['id']);
	$roles[] = [
		'id' => $roleId,
		'name' => $role['name'],
		'description' => $role['description'],
		'allow_all' => intval($role['allow_all']),
		'member_count' => intval($role['member_count']),
		'members' => $membersByRole[$roleId] ?? []
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
								<th>范围</th>
								<th>成员数</th>
								<th>备注</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php foreach ($roles as $role) { ?>
								<tr>
									<td><?php echo intval($role['id']); ?></td>
									<td><?php echo roleH($role['name']); ?></td>
									<td><?php echo intval($role['allow_all']) === 1 ? '全员' : '指定成员'; ?></td>
									<td><?php echo intval($role['allow_all']) === 1 ? '动态全员' : intval($role['member_count']); ?></td>
									<td><?php echo roleH($role['description']); ?></td>
									<td>
										<button class="btn btn-default" onclick="openRoleDialog(<?php echo intval($role['id']); ?>)">编辑</button>
										<button class="btn btn-default" onclick="deleteAccessRole(<?php echo intval($role['id']); ?>)">删除</button>
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

	function setMemberVisible() {
		if ($('#allow_all').is(':checked')) {
			$('#roleMemberWrap').hide();
		} else {
			$('#roleMemberWrap').show();
		}
	}

	window.openRoleDialog = function(roleId) {
		var role = roleId ? findRole(roleId) : null;
		if (!role) {
			role = {id: 0, name: '', description: '', allow_all: 0, members: []};
		}
		var html = '<div class="layui-form layui-form-pane" style="padding:16px;">'
			+ '<div class="layui-form-item"><label class="layui-form-label">角色名</label><div class="layui-input-block"><input type="text" id="role_name" class="layui-input" value="' + escapeHtml(role.name) + '"></div></div>'
			+ '<div class="layui-form-item"><label class="layui-form-label">备注</label><div class="layui-input-block"><input type="text" id="role_description" class="layui-input" value="' + escapeHtml(role.description) + '"></div></div>'
			+ '<div class="layui-form-item"><input type="checkbox" id="allow_all" title="全员角色" lay-skin="primary" ' + (parseInt(role.allow_all, 10) === 1 ? 'checked' : '') + '></div>'
			+ '<div id="roleMemberWrap"><div id="roleMemberTransfer"></div></div>'
			+ '<div style="text-align:center;margin-top:16px;"><button class="layui-btn layui-btn-normal" onclick="saveAccessRole(' + parseInt(role.id, 10) + ')">保存</button><button class="layui-btn layui-btn-primary" onclick="layui.layer.closeAll()">取消</button></div>'
			+ '</div>';

		layer.open({
			type: 1,
			title: role.id ? '编辑门禁角色' : '创建门禁角色',
			area: ['760px', '620px'],
			content: html,
			success: function() {
				transfer.render({
					elem: '#roleMemberTransfer',
					title: ['可选员工', '角色成员'],
					data: employeeList,
					value: role.members || [],
					width: 300,
					height: 340,
					showSearch: true,
					id: 'roleMembers'
				});
				$('#allow_all').on('change', setMemberVisible);
				setMemberVisible();
				form.render();
			}
		});
	};

	window.saveAccessRole = function(roleId) {
		var members = [];
		if (!$('#allow_all').is(':checked')) {
			transfer.getData('roleMembers').forEach(function(item) {
				members.push(item.value);
			});
		}
		$.ajax({
			type: 'POST',
			url: '?action=saveAccessRole&page=panel&module=role&csrf=' + csrf_token,
			data: {
				role_id: roleId,
				name: $('#role_name').val(),
				description: $('#role_description').val(),
				allow_all: $('#allow_all').is(':checked') ? 'true' : 'false',
				members: JSON.stringify(members)
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
