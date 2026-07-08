<?php
/*

门禁发卡管理模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

$page_title = "飞书租户管理界面";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
}

if(isset($_GET['getguest']) && preg_match("/^[0-9]{1,10}$/", $_GET['getguest'])) {
	anim210System\Utils::checkCsrf();
	$userinfo = Database::querySingleLine("guest", Array("id" => $_GET['getguest']));
	if($userinfo) {
		ob_clean();
		exit(json_encode(Array(
			"id"       => $userinfo['id'],
			"name"     => $userinfo['name']
		)));
	} else {
		ob_clean();
		Header("HTTP/1.1 404");
		exit("未找到访客");
	}
}

if(isset($_GET['getemployee']) && preg_match("/^[0-9]{1,10}$/", $_GET['getemployee'])) {
	anim210System\Utils::checkCsrf();
	$userinfo = Database::querySingleLine("employee", Array("id" => $_GET['getemployee']));
	if($userinfo) {
		ob_clean();
		exit(json_encode(Array(
			"id"       => $userinfo['id'],
			"name"     => $userinfo['name']
		)));
	} else {
		ob_clean();
		Header("HTTP/1.1 404");
		exit("未找到员工");
	}
}

if(isset($_GET['updateGuestId']) && isset($_GET['updateGuestAction']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $_GET['updateGuestAction'])) {
	anim210System\Utils::checkCsrf();
	$userInfo = Database::querySingleLine("guest", Array("id" => $_GET['updateGuestId']));
	if (!$userInfo) {
		ob_clean();
		Header("HTTP/1.1 403 Forbidden");
		exit("账号不存在");
	}
	switch($_GET['updateGuestAction']) {
		case 'deleteGuest':
			anim210System\Utils::checkCsrf();
			$update = Database::delete("guest", Array("id" => $_GET['updateGuestId']));
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
			exit("Undefined action {$_GET['updateGuestAction']}");
	}
}

if(isset($_GET['updateEmployeeId']) && isset($_GET['updateEmployeeAction']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $_GET['updateEmployeeAction'])) {
	anim210System\Utils::checkCsrf();
	$userInfo = Database::querySingleLine("employee", Array("id" => $_GET['updateEmployeeId']));
	if (!$userInfo) {
		ob_clean();
		Header("HTTP/1.1 403 Forbidden");
		exit("账号不存在");
	}
	switch($_GET['updateEmployeeAction']) {
		case 'deactivateEmployee':
			anim210System\Utils::checkCsrf();
			$data = Array(
				"status"       => 'false'
			);
			$update = Database::update("employee", $data, Array("id" => $_GET['updateEmployeeId']));
			if($update == true) {
				ob_clean();
				exit("用户权限禁用成功");
			} else {
				ob_clean();
				Header("HTTP/1.1 404 Not Found");
				exit("用户资料更新失败");
			}
		break;
		case 'activateEmployee':
			anim210System\Utils::checkCsrf();
			$data = Array(
				"status"       => 'true'
			);
			$update = Database::update("employee", $data, Array("id" => $_GET['updateEmployeeId']));
			if($update == true) {
				ob_clean();
				exit("用户权限禁用成功");
			} else {
				ob_clean();
				Header("HTTP/1.1 404 Not Found");
				exit("用户资料更新失败");
			}
		break;
		default:
			ob_clean();
			Header("HTTP/1.1 404 Not Found");
			exit("Undefined action {$_GET['updateGuestAction']}");
	}
}

$um = new anim210System\UserCheck();

$mainEmployeeSQL = 'SELECT * FROM `employee`';
$employeeData = Database::query("employee", $mainEmployeeSQL, true);
$mainGuestSQL = 'SELECT * FROM `guest`';
$guestData = Database::query("guest", $mainGuestSQL, true);
?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, 门禁管理员：<?php echo $rs['username'] ?></h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;">
					<h4 style="font-weight: 400">管理飞书租户通讯录成员</h4><br />
					<button class="btn btn-default" onclick="syncFeishuMember()">同步飞书通讯录</button>

                    <table id="employee1" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="10" data-page-list="[5, 10, 20, 30, 40, 50, 'All']" data-sortable="true" data-search="true" style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>姓名</th>
                                <th>工号</th>
                                <th>真实姓名</th>
                                <th>状态</th>
                                <th>门禁卡号</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php
                                foreach ($employeeData as $eData) {
                                    $eStatus = '已启用';
									$eStatusBtn = '<button class="btn btn-default" onclick="deactivate('.$eData['id'].')">禁用</button>';
                                    if ($eData['status'] != 'true') {
                                        $eStatus = '已禁用';
										$eStatusBtn = '<button class="btn btn-default" onclick="activate('.$eData['id'].')">启用</button>';
                                    }
									$employeeId = $eData['employee_id'];
									if ($employeeId == '') {
										$employeeId = '未分配工号';
									} 
                                    echo "<tr>
                                    <td>{$eData['id']}</td>
                                    <td>{$eData['name']}</td>
                                    <td>{$employeeId}</td>
                                    <td>{$eData['realname']}</td>
                                    <td>{$eStatus}</td>
                                    <td>{$eData['card_id']}</td>
                                    <td><button class=\"btn btn-default\" onclick=\"submitemployeecard({$eData['id']})\">发卡</button>&nbsp{$eStatusBtn}</td>
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
					<h4 style="font-weight: 400">管理访客成员权限</h4><br />
                    <button class="btn btn-default" onclick="addGuest()">添加新访客</button>

                    <table id="guest1" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="10" data-page-list="[5, 10, 20, 30, 40, 50, 'All']" data-sortable="true" data-search="true" style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>姓名</th>
                                <th>手机号</th>
                                <th>状态</th>
                                <th>门禁临时卡号</th>
                                <th>操作</th>
							</tr>
                        </thead>
						<tbody>
							<?php
                                foreach ($guestData as $gData) {
                                    $gStatus = '已启用';
                                    if ($gData['status'] != 'true') {
                                        $gStatus = '已禁用';
                                    }
                                    echo "<tr>
                                    <td>{$gData['id']}</td>
                                    <td>{$gData['name']}</td>
                                    <td>{$gData['phone']}</td>
                                    <td>{$gStatus}</td>
                                    <td>{$gData['card_id']}</td>
                                    <td><button class=\"btn btn-default\" onclick=\"submitguestcard({$gData['id']})\">发卡</button>&nbsp<button class=\"btn btn-default\" onclick=\"deleteGuest({$gData['id']})\">删除</button></td>
                                    </tr>";
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
<script type="text/html" id="createGuestDialogTpl">
  <div class="layui-form layui-form-pane" style="padding: 20px;">
    <div class="layui-form-item">
      <label class="layui-form-label">姓名</label>
      <div class="layui-input-block">
        <input type="text" id="name" class="layui-input" placeholder="中文名或英文名">
      </div>
    </div>
    <div class="layui-form-item">
      <label class="layui-form-label">手机号</label>
      <div class="layui-input-block">
        <input type="text" id="phone" class="layui-input" placeholder="18888888888">
      </div>
    </div>
    <div class="layui-form-item">
      <div class="layui-input-block">
        <button class="layui-btn" lay-filter="submit" lay-submit onclick="createGuest()">创建</button>
        <button class="layui-btn layui-btn-primary" onclick="closeDialog()">取消</button>
      </div>
    </div>
  </div>
</script>
<!-- 发卡Dialog模板 -->
<script type="text/html" id="submitCardDialogModal">
  <div class="layui-form layui-form-pane" style="padding: 20px;">
    <div class="layui-form-item">
      <label class="layui-form-label">电子工牌ID</label>
      <input style="display: none" type="text" id="userid" class="layui-input">
      <input style="display: none" type="text" id="usertype" class="layui-input">
      <div class="layui-input-block">
        <input type="text" id="cardnum" class="layui-input" placeholder="选中输入框 连接读卡器读取工牌">
      </div>
    </div>
    <div class="layui-form-item">
      <div class="layui-input-block">
        <button class="layui-btn" lay-filter="submit" lay-submit onclick="submitCard()">发卡</button>
        <button class="layui-btn layui-btn-primary" onclick="closeDialog()">取消</button>
      </div>
    </div>
  </div>
</script>
<script src="asset/layui/layui.js"></script>
<script>
  var employeeid;
  var guestid;
  layui.use(['layer', 'form'], function() {
    var layer = layui.layer;
    var form = layui.form;

	function deleteGuest(id) {
		var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=submitcard&getguest=" + id + "&csrf=" + "<?php echo $_SESSION['token']; ?>",
			async:true,
			error: function() {
				alert("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					guestid = json.id;
					guestname = json.name;

					layer.confirm('是否要删除访客：'+guestname, {
						icon: 3, // 问号图标
						title: '确定吗？',
						btn: ['确定', '取消'], // 按钮
						yes: function(index, layero){ // 点击确定按钮的回调函数
						// 执行封禁流程
						var htmlobj = $.ajax({
							type: 'GET',
							url: "?page=panel&module=submitcard&updateGuestAction=deleteGuest&updateGuestId="+guestid+"&csrf=" + "<?php echo $_SESSION['token']; ?>",
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

	function deactivate(id) {
		var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=submitcard&getemployee=" + id + "&csrf=" + "<?php echo $_SESSION['token']; ?>",
			async:true,
			error: function() {
				alert("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					employeeid = json.id;
					employeename = json.name;

					layer.confirm('是否禁用员工：'+employeename+' 的所有门禁通行权限？', {
						icon: 3, // 问号图标
						title: '确定吗？',
						btn: ['确定', '取消'], // 按钮
						yes: function(index, layero){ // 点击确定按钮的回调函数
						// 执行封禁流程
						var htmlobj = $.ajax({
							type: 'GET',
							url: "?page=panel&module=submitcard&updateEmployeeAction=deactivateEmployee&updateEmployeeId="+employeeid+"&csrf=" + "<?php echo $_SESSION['token']; ?>",
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

	function activate(id) {
		var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=submitcard&getemployee=" + id + "&csrf=" + "<?php echo $_SESSION['token']; ?>",
			async:true,
			error: function() {
				alert("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					employeeid = json.id;
					employeename = json.name;

					layer.confirm('是否启用员工：'+employeename+' 的门禁通行权限？', {
						icon: 3, // 问号图标
						title: '确定吗？',
						btn: ['确定', '取消'], // 按钮
						yes: function(index, layero){ // 点击确定按钮的回调函数
						// 执行封禁流程
						var htmlobj = $.ajax({
							type: 'GET',
							url: "?page=panel&module=submitcard&updateEmployeeAction=activateEmployee&updateEmployeeId="+employeeid+"&csrf=" + "<?php echo $_SESSION['token']; ?>",
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

    function submitguestcard(id) {
		var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=submitcard&getguest=" + id + "&csrf=" + "<?php echo $_SESSION['token']; ?>",
			async:true,
			error: function() {
				alert("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					guestid = json.id;
					guestname = json.name;

					layer.open({
                        type: 1,
                        title: '为访客 '+guestname+' 发卡',
                        content: $('#submitCardDialogModal').html(),
                        area: ['400px', '200px']
                    });
                    $('#userid').val(guestid);
                    $('#usertype').val('guest');
				} catch(e) {
					alert("错误：无法解析服务器返回的数据");
				}
				return;
			}
		});
	}

    function submitemployeecard(id) {
		var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=submitcard&getemployee=" + id + "&csrf=" + "<?php echo $_SESSION['token']; ?>",
			async:true,
			error: function() {
				alert("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					employeeid = json.id;
					employeename = json.name;

					layer.open({
                        type: 1,
                        title: '为员工 '+employeename+' 发卡',
                        content: $('#submitCardDialogModal').html(),
                        area: ['400px', '200px']
                    });
                    $('#userid').val(employeeid);
                    $('#usertype').val('employee');
				} catch(e) {
					alert("错误：无法解析服务器返回的数据");
				}
				return;
			}
		});
	}

	// 打开对话框
    function addGuest() {
      layer.open({
        type: 1,
        title: '创建访客',
        content: $('#createGuestDialogTpl').html(),
        area: ['400px', '300px']
      });
    }

    // 关闭对话框
    function closeDialog() {
      layer.closeAll();
    }

    // 创建用户
    function createGuest() {
      var name = $('#name').val();
      var phone = $('#phone').val();
      
      var htmlobj = $.ajax({
		type: 'POST',
		url: "?action=createguest&page=panel&module=submitcard&csrf=<?php echo $_SESSION['token']; ?>",
		async:true,
		data: {
            name: name,
			phone: phone
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

    // 发卡
    function submitCard() {
      var userid = $('#userid').val();
      var usertype = $('#usertype').val();
      var cardnum = $('#cardnum').val();
      
      var htmlobj = $.ajax({
		type: 'POST',
		url: "?action=submitcard&page=panel&module=submitcard&csrf=<?php echo $_SESSION['token']; ?>",
		async:true,
		data: {
            id: userid,
			type: usertype,
            cardid: cardnum
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

	// 同步飞书通讯录
    function syncFeishuMember() {
	  // 弹出询问框
		layer.confirm('是否提交后台通讯录同步任务？任务会由计划任务执行，不会阻塞当前页面。', {
        btn: ['确定', '取消'],
        icon: 3, // question icon
        title: '确定吗？'
      }, function(index) {
        layer.close(index); // 关闭询问框
        startSyncFeishuMember(); // 调用同步函数
      }, function() {
        return;
      });
    }

	function startSyncFeishuMember() {
      var htmlobj = $.ajax({
		type: 'POST',
		url: "?action=syncFeishuMember&page=panel&module=submitcard&csrf=<?php echo $_SESSION['token']; ?>",
		async:true,
		data: {
            csrf: "<?php echo $_SESSION['token']; ?>"
		},
		error: function() {
			layer.confirm('遇到错误，详细信息如下，请联系截图后联系@秩乱处理：'+htmlobj.responseText, {
				icon: 2,
				title: '提示',
				btn: ['确定'], // 按钮
				yes: function(index, layero){ // 点击确定按钮的回调函数
					layer.close(index); // 关闭询问框
				},
			});
			return;
		},
		success: function() {
			layer.confirm('后台同步任务已提交：'+htmlobj.responseText, {
				icon: 1,
				title: '提示',
				btn: ['确定'], // 按钮
				yes: function(index, layero){ // 点击确定按钮的回调函数
					layer.close(index); // 关闭询问框
					location.reload();
				},
			});
			return;
		}
	  });
	}

	// global
	window.deleteGuest = deleteGuest;
	window.deactivate = deactivate;
	window.activate = activate;
	window.createGuest = createGuest;
	window.closeDialog = closeDialog;
	window.addGuest = addGuest;
    window.submitguestcard = submitguestcard;
    window.submitemployeecard = submitemployeecard;
    window.submitCard = submitCard;
	window.syncFeishuMember = syncFeishuMember;
  });
</script>
