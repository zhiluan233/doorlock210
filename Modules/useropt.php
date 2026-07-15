<?php
/*

本地管理员与飞书后台权限管理模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

$page_title = "本地管理员";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if($rs['type'] != 'admin') {
    unset($_SESSION['user']);
    unset($_SESSION['mail']);
	exit("<script>location='/?page=login';</script>");
}

if(isset($_GET['getchar']) && preg_match("/^[0-9]{1,10}$/", $_GET['getchar'])) {
	anim210System\Utils::checkCsrf();
	$userinfo = Database::querySingleLine("user", Array("id" => $_GET['getchar']));
	if($userinfo) {
		ob_clean();
		exit(json_encode(Array(
			"id"       => $userinfo['id'],
			"username" => $userinfo['username']
		)));
	} else {
		ob_clean();
		Header("HTTP/1.1 403");
		exit("未找到用户");
	}
}

if(isset($_GET['updateUserId']) && isset($_GET['updateUserAction']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $_GET['updateUserAction'])) {
	anim210System\Utils::checkCsrf();
	$userInfo = Database::querySingleLine("user", Array("id" => $_GET['updateUserId']));
	if (!$userInfo) {
		ob_clean();
		Header("HTTP/1.1 403 Forbidden");
		exit("账号不存在");
	}
	switch($_GET['updateUserAction']) {
		case 'deleteUser':
			anim210System\Utils::checkCsrf();
			$update = Database::delete("user", Array("id" => $_GET['updateUserId']));
			if($update == true) {
				ob_clean();
				exit("删除用户成功");
			} else {
				ob_clean();
				Header("HTTP/1.1 404 Not Found");
				exit("用户资料更新失败");
			}
		break;
		default:
			ob_clean();
			Header("HTTP/1.1 404 Not Found");
			exit("Undefined action {$_GET['updateUserAction']}");
	}
}

$um = new anim210System\UserCheck();

$userData = Database::query("user", "SELECT * FROM `user` ORDER BY `id` ASC", true);
$employeeData = Database::query("employee", "SELECT * FROM `employee` ORDER BY `name` ASC", true);
$localAdminData = [];
$feishuRoleByOpenId = [];
$feishuRoleByEmployeeId = [];

if ($userData && $userData instanceof \mysqli_result) {
	while ($user = mysqli_fetch_assoc($userData)) {
		$hasFeishuLink = !empty($user['open_id']) || !empty($user['employee_id']);
		if ($hasFeishuLink) {
			if (!empty($user['open_id'])) {
				$feishuRoleByOpenId[$user['open_id']] = $user['type'];
			}
			if (!empty($user['employee_id'])) {
				$feishuRoleByEmployeeId[$user['employee_id']] = $user['type'];
			}
			continue;
		}
		if (in_array($user['type'], ['admin', 'readonly'], true)) {
			$localAdminData[] = $user;
		}
	}
}

function useroptH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function useroptJs($value) {
	return htmlspecialchars(json_encode((string)$value, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

function useroptRoleName($role) {
	if ($role === 'admin') {
		return '管理员';
	}
	if ($role === 'readonly') {
		return '只读管理员';
	}
	return '普通用户';
}

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, 管理员：<?php echo $rs['username'] ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;max-width: ;">
					<h4 style="font-weight: 400">本地管理员</h4><br />
					<button class="btn btn-default" onclick="createNewUser()">创建本地管理员</button>

					<table id="user1" class="table table-bordered table-auto" style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>账号</th>
                                <th>用户组</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php
                                foreach ($localAdminData as $uData) {
									$type = useroptRoleName($uData['type']);
                                    echo "<tr>
                                    <td>".useroptH($uData['id'])."</td>
                                    <td>".useroptH($uData['username'])."</td>
                                    <td>{$type}</td>
                                    <td><button class=\"btn btn-default\" onclick=\"deleteUser(".intval($uData['id']).")\">删除</button></td>
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
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;">
					<h4 style="font-weight: 400">飞书成员后台权限</h4><br />
					<table id="feishuUser1" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="10" data-page-list="[5, 10, 20, 30, 50, 'All']" data-sortable="true" data-search="true" style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>花名</th>
                                <th>工号</th>
                                <th>员工状态</th>
                                <th>后台权限</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php
                                if ($employeeData && $employeeData instanceof \mysqli_result) {
									foreach ($employeeData as $employee) {
										$role = 'user';
										if (!empty($employee['open_id']) && isset($feishuRoleByOpenId[$employee['open_id']])) {
											$role = $feishuRoleByOpenId[$employee['open_id']];
										} else if (!empty($employee['employee_id']) && isset($feishuRoleByEmployeeId[$employee['employee_id']])) {
											$role = $feishuRoleByEmployeeId[$employee['employee_id']];
										}
										$status = ($employee['status'] ?? '') === 'true' ? '启用' : '禁用';
										$openIdArg = useroptJs($employee['open_id'] ?? '');
										$actions = '缺少飞书 OpenID，请先同步通讯录';
										if (!empty($employee['open_id'])) {
											$actions = "
											<button class=\"btn btn-default\" onclick=\"setFeishuUserRole({$openIdArg}, 'admin')\">设为管理员</button>
											<button class=\"btn btn-default\" onclick=\"setFeishuUserRole({$openIdArg}, 'readonly')\">设为只读</button>
											<button class=\"btn btn-default\" onclick=\"setFeishuUserRole({$openIdArg}, 'user')\">设为普通用户</button>";
										}
										echo "<tr>
										<td>".useroptH($employee['name'])."</td>
										<td>".useroptH($employee['employee_id'] ?: '未分配工号')."</td>
										<td>{$status}</td>
										<td>".useroptRoleName($role)."</td>
										<td>{$actions}</td>
										</tr>";
									}
								}
                            ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
		<!-- Row -->
</div>
<!-- 对话框模板 -->
<script type="text/html" id="createUserDialogTpl">
  <div class="layui-form layui-form-pane" style="padding: 20px;">
    <div class="layui-form-item">
      <label class="layui-form-label">账号</label>
      <div class="layui-input-block">
        <input type="text" id="account" class="layui-input" placeholder="纯英文">
      </div>
    </div>
    <div class="layui-form-item">
      <label class="layui-form-label">初始密码</label>
      <div class="layui-input-block">
        <input type="password" id="password" class="layui-input" placeholder="210210..">
      </div>
    </div>
    <div class="layui-form-item">
      <label class="layui-form-label">邮箱</label>
      <div class="layui-input-block">
        <input type="text" id="email" class="layui-input" placeholder="xxx@2-10.cn">
      </div>
    </div>
	<div class="layui-form-item">
      <label class="layui-form-label">用户组</label>
      <div class="layui-input-block">
        <select id="group">
			<option value="admin">管理员</option>
			<option value="readonly">只读管理员</option>
		</select>
      </div>
    </div>
    <div class="layui-form-item">
      <div class="layui-input-block">
        <button type="button" class="layui-btn" onclick="createUser()">创建</button>
        <button type="button" class="layui-btn layui-btn-primary" onclick="closeDialog()">取消</button>
      </div>
    </div>
  </div>
</script>
<script type="text/javascript" src="/asset/js/md5.js"></script>
<script src="asset/layui/layui.js"></script>
<script>
  var userid;
  var nickname;
  var character;
  layui.use(['layer', 'form'], function() {
    var layer = layui.layer;
    var form = layui.form;

	function deleteUser(id) {
		var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=useropt&getchar=" + id + "&csrf=" + "<?php echo $_SESSION['token']; ?>",
			async:true,
			error: function() {
				alert("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					userid = json.id;
					username = json.username;

					layer.confirm('是否要删除用户：'+username, {
						icon: 3, // 问号图标
						title: '确定吗？',
						btn: ['确定', '取消'], // 按钮
						yes: function(index, layero){ // 点击确定按钮的回调函数
						// 执行封禁流程
						var htmlobj = $.ajax({
							type: 'GET',
							url: "?page=panel&module=useropt&updateUserAction=deleteUser&updateUserId="+userid+"&csrf=" + "<?php echo $_SESSION['token']; ?>",
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
    function createNewUser() {
      layer.open({
        type: 1,
        title: '创建本地管理员',
        content: $('#createUserDialogTpl').html(),
        area: ['440px', '380px'],
		success: function() {
			form.render('select');
		}
      });
    }

    // 关闭对话框
    function closeDialog() {
      layer.closeAll();
    }

    // 创建用户
    function createUser() {
      var username = $('#account').val();
      var password = md5($('#password').val()); // 使用md5()处理密码
      var mail = $('#email').val();
	  var group = $('#group').val();
      
      var htmlobj = $.ajax({
		type: 'POST',
		url: "?action=createuser&page=panel&module=useropt&csrf=<?php echo $_SESSION['token']; ?>",
		async:true,
		data: {
            username: username,
			password: password,
			mail: mail,
			group: group,
			display_name: username
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

	function setFeishuUserRole(openId, role) {
		if (!openId) {
			vt.error("该员工缺少飞书 OpenID，请先同步通讯录", {
				position: "top-center",
			});
			return;
		}
		var roleName = role === 'admin' ? '管理员' : (role === 'readonly' ? '只读管理员' : '普通用户');
		layer.confirm('确认将该飞书成员后台权限设置为 '+roleName+'？', {
			icon: 3,
			title: '确定吗？',
			btn: ['确定', '取消']
		}, function(index) {
			layer.close(index);
			var htmlobj = $.ajax({
				type: 'POST',
				url: "?action=setFeishuUserRole&page=panel&module=useropt&csrf=<?php echo $_SESSION['token']; ?>",
				async: true,
				data: {
					open_id: openId,
					role: role
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
				}
			});
		});
	}

	// global
	window.deleteUser = deleteUser;
	window.createNewUser = createNewUser;
	window.closeDialog = closeDialog;
	window.createUser = createUser;
	window.setFeishuUserRole = setFeishuUserRole;
  });
</script>
