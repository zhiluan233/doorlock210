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
			if (!empty($userInfo['open_id'])) {
				Database::delete("access_role_members", Array(
					"member_kind" => "guest",
					"employee_open_id" => $userInfo['open_id']
				));
			}
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
		case 'deactivateGuest':
			anim210System\Utils::checkCsrf();
			$update = Database::update("guest", Array("status" => 'false'), Array("id" => $_GET['updateGuestId']));
			if($update == true) {
				ob_clean();
				exit("访客权限禁用成功");
			} else {
				ob_clean();
				Header("HTTP/1.1 404 Not Found");
				exit("访客资料更新失败");
			}
		break;
		case 'activateGuest':
			anim210System\Utils::checkCsrf();
			$update = Database::update("guest", Array("status" => 'true'), Array("id" => $_GET['updateGuestId']));
			if($update == true) {
				ob_clean();
				exit("访客权限启用成功");
			} else {
				ob_clean();
				Header("HTTP/1.1 404 Not Found");
				exit("访客资料更新失败");
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
$latestFullSyncJob = Database::querySingleLine("feishu_sync_jobs", "SELECT * FROM `feishu_sync_jobs` WHERE `job_type`='full_contact' ORDER BY `id` DESC LIMIT 1", true);
$lastSuccessfulFullSyncJob = Database::querySingleLine("feishu_sync_jobs", "SELECT * FROM `feishu_sync_jobs` WHERE `job_type`='full_contact' AND `status`='success' AND `finished_at`>0 ORDER BY `finished_at` DESC, `id` DESC LIMIT 1", true);
$lastFullSyncAt = intval(Settings::get('feishu_contact_sync_last_full_at', '0'));
if ($lastSuccessfulFullSyncJob && intval($lastSuccessfulFullSyncJob['finished_at']) > $lastFullSyncAt) {
	$lastFullSyncAt = intval($lastSuccessfulFullSyncJob['finished_at']);
}
$lastIncrementalAt = intval(Settings::get('feishu_contact_incremental_last_at', '0'));
$lastIncrementalEvent = Settings::get('feishu_contact_incremental_last_event', '');

function submitcardH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function submitcardFormatSyncTime($timestamp, $emptyText) {
	$timestamp = intval($timestamp);
	if ($timestamp <= 0) {
		return $emptyText;
	}
	return date('Y-m-d H:i:s', $timestamp);
}

function submitcardLatestSyncJobText($job) {
	if (!$job) {
		return '暂无任务';
	}

	$statusMap = [
		'pending' => '等待执行',
		'running' => '执行中',
		'success' => '已完成',
		'failed' => '失败'
	];
	$status = $statusMap[$job['status']] ?? $job['status'];
	$time = intval($job['finished_at']) > 0 ? intval($job['finished_at']) : intval($job['updated_at']);
	$text = $status;
	if ($time > 0) {
		$text .= '，'.date('Y-m-d H:i:s', $time);
	}
	if (!empty($job['message'])) {
		$text .= '，'.$job['message'];
	}
	return $text;
}
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
					<button class="btn btn-default" style="margin-left: 8px;" onclick="openReleaseCard()">回收工牌</button>
					<div class="text-muted" style="margin-top: 12px; line-height: 1.8;">
						<span>最后一次全量同步：<strong><?php echo submitcardH(submitcardFormatSyncTime($lastFullSyncAt, '暂未同步')); ?></strong></span>
						<span style="margin-left: 24px;">最近全量任务：<strong><?php echo submitcardH(submitcardLatestSyncJobText($latestFullSyncJob)); ?></strong></span>
						<span style="margin-left: 24px;">最后一次有效增量状态更新：<strong><?php echo submitcardH(submitcardFormatSyncTime($lastIncrementalAt, '暂未接收')); ?></strong></span>
						<span style="margin-left: 24px;">增量事件：<strong><?php echo submitcardH($lastIncrementalEvent !== '' ? $lastIncrementalEvent : '暂无事件'); ?></strong></span>
					</div>

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
									$gStatusBtn = '<button class="btn btn-default" onclick="deactivateGuest('.$gData['id'].')">禁用</button>';
                                    if ($gData['status'] != 'true') {
                                        $gStatus = '已禁用';
										$gStatusBtn = '<button class="btn btn-default" onclick="activateGuest('.$gData['id'].')">启用</button>';
                                    }
                                    echo "<tr>
                                    <td>{$gData['id']}</td>
                                    <td>{$gData['name']}</td>
                                    <td>{$gData['phone']}</td>
                                    <td>{$gStatus}</td>
                                    <td>{$gData['card_id']}</td>
                                    <td><button class=\"btn btn-default\" onclick=\"submitguestcard({$gData['id']})\">发卡</button>&nbsp{$gStatusBtn}&nbsp<button class=\"btn btn-default\" onclick=\"deleteGuest({$gData['id']})\">删除</button></td>
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
        <input type="text" id="cardnum" class="layui-input js-card-id-input" placeholder="选中输入框 连接读卡器读取工牌" autocomplete="off">
        <div class="card-input-hint"></div>
        <div class="card-nfc-status"></div>
      </div>
    </div>
    <div class="layui-form-item">
      <div class="layui-input-block">
        <button type="button" class="layui-btn layui-btn-primary card-nfc-button" onclick="startCardNfcScan('#cardnum', submitCard)">手机NFC读取</button>
        <button class="layui-btn" lay-filter="submit" lay-submit onclick="submitCard()">发卡</button>
        <button class="layui-btn layui-btn-primary" onclick="closeDialog()">取消</button>
      </div>
    </div>
  </div>
</script>
<!-- 工牌回收Dialog模板 -->
<script type="text/html" id="releaseCardDialogModal">
  <div class="layui-form layui-form-pane" style="padding: 20px;">
    <div class="layui-form-item">
      <label class="layui-form-label">电子工牌ID</label>
      <div class="layui-input-block">
        <input type="text" id="release_cardnum" class="layui-input js-card-id-input" placeholder="选中输入框 连接读卡器读取工牌" autocomplete="off">
        <div class="card-input-hint"></div>
        <div class="card-nfc-status"></div>
      </div>
    </div>
    <div class="layui-form-item">
      <div class="layui-input-block">
        <button type="button" class="layui-btn layui-btn-primary card-nfc-button" onclick="startCardNfcScan('#release_cardnum', releaseCard)">手机NFC读取</button>
        <button class="layui-btn" lay-filter="submit" lay-submit onclick="releaseCard()">保存</button>
        <button class="layui-btn layui-btn-primary" onclick="closeDialog()">取消</button>
      </div>
    </div>
  </div>
</script>
<script src="asset/layui/layui.js"></script>
<style>
  .card-input-hint {
    display: none;
    margin-top: 8px;
    color: #FF5722;
    line-height: 1.4;
  }
  .card-nfc-status {
    display: none;
    margin-top: 8px;
    color: #16baaa;
    line-height: 1.4;
  }
  .card-nfc-button {
    display: none;
    margin-right: 8px;
  }
  @media screen and (max-width: 768px) {
    .layui-layer-page .layui-form-pane {
      padding: 16px !important;
    }
    .layui-layer-page .layui-form-label {
      width: 100px;
    }
    .layui-layer-page .layui-input-block {
      margin-left: 100px;
    }
  }
</style>
<script>
  var employeeid;
  var guestid;
  layui.use(['layer', 'form'], function() {
    var layer = layui.layer;
    var form = layui.form;
	var FEISHU_H5_SDK_URL = 'https://lf-scm-cn.feishucdn.com/lark/op/h5-js-sdk-1.5.30.js';
	var feishuH5SdkPromise = null;
	var activeNfcSession = null;

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

	function deactivateGuest(id) {
		updateGuestStatus(id, 'deactivateGuest', '是否禁用该访客的门禁通行权限？');
	}

	function activateGuest(id) {
		updateGuestStatus(id, 'activateGuest', '是否启用该访客的门禁通行权限？');
	}

	function updateGuestStatus(id, action, message) {
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

					layer.confirm(message + '：' + guestname, {
						icon: 3,
						title: '确定吗？',
						btn: ['确定', '取消'],
						yes: function(index, layero){
							var updateObj = $.ajax({
								type: 'GET',
								url: "?page=panel&module=submitcard&updateGuestAction="+action+"&updateGuestId="+guestid+"&csrf=" + "<?php echo $_SESSION['token']; ?>",
								async:true,
								error: function() {
									vt.error("错误：" + updateObj.responseText, {
										position: "top-center",
									});
									return;
								},
								success: function() {
									vt.success(updateObj.responseText, {
										position: "top-center",
									});
									layer.close(index);
									location.reload();
									return;
								}
							});
						},
						btn2: function(index, layero){
							layer.close(index);
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

	function isMobileClient() {
		return /Android|Mobile|iPhone|iPad|iPod|iOS|OpenHarmony/i.test(navigator.userAgent || '');
	}

	function isIosClient() {
		return /iPhone|iPad|iPod|iOS/i.test(navigator.userAgent || '');
	}

	function isFeishuClient() {
		return /Feishu|Lark/i.test(navigator.userAgent || '');
	}

	function cardDialogArea(height) {
		if (isMobileClient()) {
			return ['92%', height + 'px'];
		}
		return ['420px', height + 'px'];
	}

	function leftPad(value, length) {
		value = String(value || '');
		while (value.length < length) {
			value = '0' + value;
		}
		return value;
	}

	function normalizeCardInput(value) {
		return String(value || '').replace(/[\r\n]/g, '').trim();
	}

	function isValidCardInput(value) {
		return /^[0-9]{10}$/.test(normalizeCardInput(value));
	}

	function showCardInputHint($input, message) {
		var $hint = $input.closest('.layui-input-block').find('.card-input-hint');
		$hint.text(message).show();
	}

	function clearCardInputHint($input) {
		var $hint = $input.closest('.layui-input-block').find('.card-input-hint');
		$hint.hide().text('');
	}

	function validateCardInput($input) {
		var cardnum = normalizeCardInput($input.val());
		if (!isValidCardInput(cardnum)) {
			$input.val('');
			showCardInputHint($input, '工牌ID必须是10位数字，已忽略本次输入');
			return '';
		}
		$input.val(cardnum);
		clearCardInputHint($input);
		return cardnum;
	}

	function showCardNfcStatus($input, message) {
		var $status = $input.closest('.layui-input-block').find('.card-nfc-status');
		clearCardInputHint($input);
		$status.text(message).show();
	}

	function clearCardNfcStatus($input) {
		var $status = $input.closest('.layui-input-block').find('.card-nfc-status');
		$status.hide().text('');
	}

	function showNfcFallback($input, message) {
		clearCardNfcStatus($input);
		showCardInputHint($input, message);
	}

	function loadFeishuH5Sdk() {
		if (window.tt && typeof window.tt.getNFCAdapter === 'function') {
			return Promise.resolve(window.tt);
		}
		if (!isFeishuClient()) {
			return Promise.reject(new Error('当前不是飞书内置浏览器，已切换手动输入'));
		}
		if (feishuH5SdkPromise) {
			return feishuH5SdkPromise;
		}

		feishuH5SdkPromise = new Promise(function(resolve, reject) {
			var finished = false;
			var timeout = setTimeout(function() {
				finish(new Error('飞书 H5 SDK 加载超时，已切换手动输入'));
			}, 8000);

			function finish(error, sdk) {
				if (finished) {
					return;
				}
				finished = true;
				clearTimeout(timeout);
				if (error) {
					reject(error);
				} else {
					resolve(sdk);
				}
			}

			function hasBridge() {
				return window.tt && typeof window.tt.getNFCAdapter === 'function';
			}

			function waitBridge() {
				var retry = 0;
				(function tick() {
					if (hasBridge()) {
						finish(null, window.tt);
						return;
					}
					retry++;
					if (retry > 80) {
						finish(new Error('当前飞书客户端未开放 H5 NFC Bridge，已切换手动输入'));
						return;
					}
					setTimeout(tick, 50);
				})();
			}

			if (hasBridge()) {
				finish(null, window.tt);
				return;
			}

			var existed = document.querySelector('script[data-feishu-h5-sdk="1"]');
			if (existed) {
				existed.addEventListener('load', waitBridge);
				existed.addEventListener('error', function() {
					finish(new Error('飞书 H5 SDK 加载失败，已切换手动输入'));
				});
				waitBridge();
				return;
			}

			var script = document.createElement('script');
			script.src = FEISHU_H5_SDK_URL;
			script.async = true;
			script.setAttribute('data-feishu-h5-sdk', '1');
			script.onload = waitBridge;
			script.onerror = function() {
				finish(new Error('飞书 H5 SDK 加载失败，已切换手动输入'));
			};
			document.head.appendChild(script);
		});
		feishuH5SdkPromise.catch(function() {
			feishuH5SdkPromise = null;
		});

		return feishuH5SdkPromise;
	}

	function base64ToBytes(value) {
		try {
			var binary = window.atob(String(value || ''));
			var bytes = [];
			for (var i = 0; i < binary.length; i++) {
				bytes.push(binary.charCodeAt(i) & 255);
			}
			return bytes;
		} catch (e) {
			return [];
		}
	}

	function hexToBytes(hex) {
		hex = String(hex || '').replace(/[^0-9a-fA-F]/g, '');
		if (hex === '' || hex.length % 2 !== 0) {
			return [];
		}
		var bytes = [];
		for (var i = 0; i < hex.length; i += 2) {
			bytes.push(parseInt(hex.substr(i, 2), 16) & 255);
		}
		return bytes;
	}

	function normalizeBytes(value) {
		var bytes = [];
		if (!value) {
			return bytes;
		}
		for (var i = 0; i < value.length; i++) {
			var item = parseInt(value[i], 10);
			if (!isNaN(item)) {
				bytes.push(item & 255);
			}
		}
		return bytes;
	}

	function uidBytesToWiegand34Card(bytes) {
		bytes = normalizeBytes(bytes);
		if (!bytes.length) {
			return '';
		}
		while (bytes.length < 4) {
			bytes.unshift(0);
		}
		if (bytes.length > 4) {
			bytes = bytes.slice(bytes.length - 4);
		}
		var value = 0;
		for (var i = 0; i < bytes.length; i++) {
			value = (value * 256) + bytes[i];
		}
		if (!isFinite(value) || value < 0 || value > 4294967295) {
			return '';
		}
		return leftPad(String(Math.floor(value)), 10);
	}

	function normalizeUidToWiegand34Candidate(value) {
		if (value === null || typeof value === 'undefined') {
			return '';
		}
		if (typeof value === 'number') {
			if (!isFinite(value) || value < 0 || value > 4294967295) {
				return '';
			}
			return leftPad(String(Math.floor(value)), 10);
		}
		if (typeof ArrayBuffer !== 'undefined' && value instanceof ArrayBuffer) {
			return uidBytesToWiegand34Card(Array.prototype.slice.call(new Uint8Array(value)));
		}
		if (typeof ArrayBuffer !== 'undefined' && ArrayBuffer.isView && ArrayBuffer.isView(value)) {
			return uidBytesToWiegand34Card(Array.prototype.slice.call(new Uint8Array(value.buffer, value.byteOffset, value.byteLength)));
		}
		if (Array.isArray(value)) {
			return uidBytesToWiegand34Card(value);
		}
		if (typeof value === 'object') {
			if (typeof value.base64 === 'string') {
				return uidBytesToWiegand34Card(base64ToBytes(value.base64));
			}
			if (typeof value.value !== 'undefined') {
				return normalizeUidToWiegand34Candidate(value.value);
			}
			if (typeof value.data !== 'undefined') {
				return normalizeUidToWiegand34Candidate(value.data);
			}
			return '';
		}
		var text = String(value || '').trim();
		var bytes = hexToBytes(text);
		if (bytes.length) {
			return uidBytesToWiegand34Card(bytes);
		}
		if (/^[0-9]{1,10}$/.test(text)) {
			return leftPad(text, 10);
		}
		return '';
	}

	function normalizeNfcCandidate(value) {
		if (value === null || typeof value === 'undefined') {
			return '';
		}
		if (typeof value === 'number') {
			if (!isFinite(value) || value < 0 || value > 9999999999) {
				return '';
			}
			return leftPad(String(Math.floor(value)), 10);
		}
		if (typeof ArrayBuffer !== 'undefined' && value instanceof ArrayBuffer) {
			return uidBytesToWiegand34Card(Array.prototype.slice.call(new Uint8Array(value)));
		}
		if (typeof ArrayBuffer !== 'undefined' && ArrayBuffer.isView && ArrayBuffer.isView(value)) {
			return uidBytesToWiegand34Card(Array.prototype.slice.call(new Uint8Array(value.buffer, value.byteOffset, value.byteLength)));
		}
		if (Array.isArray(value)) {
			return uidBytesToWiegand34Card(value);
		}
		if (typeof value === 'object') {
			if (Object.prototype.hasOwnProperty.call(value, '__wiegand34Uid')) {
				return normalizeUidToWiegand34Candidate(value.__wiegand34Uid);
			}
			if (typeof value.base64 === 'string') {
				return uidBytesToWiegand34Card(base64ToBytes(value.base64));
			}
			if (typeof value.value !== 'undefined') {
				return normalizeNfcCandidate(value.value);
			}
			if (typeof value.data !== 'undefined') {
				return normalizeNfcCandidate(value.data);
			}
			return '';
		}

		var text = String(value || '').trim();
		var numericText = text.replace(/\D/g, '');
		if (/^[0-9]{1,10}$/.test(text)) {
			return leftPad(text, 10);
		}
		if (/^[0-9]{10}$/.test(numericText)) {
			return numericText;
		}
		return uidBytesToWiegand34Card(hexToBytes(text));
	}

	function collectNfcCandidates(payload, candidates, depth) {
		if (!payload || depth > 3) {
			return;
		}
		candidates.push(payload);
		if (typeof payload !== 'object') {
			return;
		}
		var cardKeys = ['cardId', 'card_id', 'cardNo', 'card_no', 'cardNumber', 'card_number', 'cardnum'];
		for (var k = 0; k < cardKeys.length; k++) {
			if (typeof payload[cardKeys[k]] !== 'undefined') {
				candidates.push(payload[cardKeys[k]]);
			}
		}
		var uidKeys = ['uid', 'id', 'serialNumber'];
		for (var i = 0; i < uidKeys.length; i++) {
			if (typeof payload[uidKeys[i]] !== 'undefined') {
				candidates.push({__wiegand34Uid: payload[uidKeys[i]]});
			}
		}
		var nestedKeys = ['detail', 'data', 'payload', 'result'];
		for (var j = 0; j < nestedKeys.length; j++) {
			if (typeof payload[nestedKeys[j]] !== 'undefined') {
				collectNfcCandidates(payload[nestedKeys[j]], candidates, depth + 1);
			}
		}
	}

	function extractCardFromNfcPayload(payload) {
		var candidates = [];
		collectNfcCandidates(payload, candidates, 0);
		for (var i = 0; i < candidates.length; i++) {
			var card = normalizeNfcCandidate(candidates[i]);
			if (card !== '' && /^[0-9]{10}$/.test(card)) {
				return card;
			}
		}
		return '';
	}

	function feishuNfcErrorMessage(error) {
		var raw = '';
		if (error) {
			raw = error.errMsg || error.message || error.errorMessage || JSON.stringify(error);
		}
		if (isIosClient()) {
			return '当前 iPhone 飞书客户端暂未开放 H5 NFC 或未授权，已切换手动输入';
		}
		if (/nfc|NFC/i.test(raw)) {
			return '当前设备未开启 NFC 或飞书客户端不支持读取工牌，已切换手动输入';
		}
		return raw ? raw + '，已切换手动输入' : '当前设备不支持手机 NFC，已切换手动输入';
	}

	function stopActiveNfcSession() {
		var session = activeNfcSession;
		activeNfcSession = null;
		if (!session || !session.adapter) {
			return;
		}
		if (session.timeout) {
			clearTimeout(session.timeout);
		}
		try {
			if (session.listener && typeof session.adapter.offDiscovered === 'function') {
				session.adapter.offDiscovered(session.listener);
			}
		} catch (e) {}
		try {
			if (typeof session.adapter.stopDiscovery === 'function') {
				session.adapter.stopDiscovery({});
			}
		} catch (e) {}
	}

	function startCardNfcScan(inputSelector, submitHandler) {
		var $input = $(inputSelector);
		if (!$input.length) {
			return Promise.resolve(false);
		}
		if (!isMobileClient()) {
			showNfcFallback($input, '当前不是手机端，请使用发卡器或手动输入工牌ID');
			return Promise.resolve(false);
		}

		stopActiveNfcSession();
		showCardNfcStatus($input, '正在启动手机 NFC，请将工牌贴近手机背部感应区');

		return loadFeishuH5Sdk().then(function(tt) {
			var adapter = tt.getNFCAdapter();
			if (!adapter || typeof adapter.startDiscovery !== 'function' || typeof adapter.onDiscovered !== 'function') {
				throw new Error('当前飞书客户端未开放 H5 NFC Bridge，已切换手动输入');
			}

			return new Promise(function(resolve) {
				var settled = false;
				var session = {
					adapter: adapter,
					listener: null,
					timeout: null
				};
				activeNfcSession = session;

				function finish(ok, message) {
					if (settled) {
						return;
					}
					settled = true;
					if (activeNfcSession === session) {
						activeNfcSession = null;
					}
					if (session.timeout) {
						clearTimeout(session.timeout);
					}
					try {
						if (session.listener && typeof adapter.offDiscovered === 'function') {
							adapter.offDiscovered(session.listener);
						}
					} catch (e) {}
					try {
						adapter.stopDiscovery({});
					} catch (e) {}
					if (!ok && message) {
						showNfcFallback($input, message);
					}
					resolve(ok);
				}

				session.listener = function(payload) {
					var card = extractCardFromNfcPayload(payload);
					if (card === '') {
						finish(false, '已读取到 NFC 标签，但无法转换为韦根34的10位数字工牌ID，已切换手动输入');
						return;
					}
					$input.val(card);
					clearCardInputHint($input);
					showCardNfcStatus($input, '已读取韦根34工牌 ' + card + '，正在提交');
					finish(true, '');
					setTimeout(function() {
						submitHandler();
					}, 80);
				};

				try {
					adapter.onDiscovered(session.listener);
					adapter.startDiscovery({
						success: function() {
							showCardNfcStatus($input, 'NFC 已启动，请将工牌贴近手机背部感应区');
						},
						fail: function(error) {
							finish(false, feishuNfcErrorMessage(error));
						}
					});
				} catch (error) {
					finish(false, feishuNfcErrorMessage(error));
				}

				session.timeout = setTimeout(function() {
					finish(false, '超过 15 秒未读取到工牌，已切换手动输入');
				}, 15000);
			});
		}).catch(function(error) {
			showNfcFallback($input, feishuNfcErrorMessage(error));
			return false;
		});
	}

	function prepareCardDialog(inputSelector, submitHandler) {
		var $input = $(inputSelector);
		bindCardIdInput(inputSelector, submitHandler);
		$input.closest('.layui-form').find('.card-nfc-button').toggle(isMobileClient());
		if (!isMobileClient()) {
			return;
		}
		setTimeout(function() {
			startCardNfcScan(inputSelector, submitHandler);
		}, 300);
	}

	function bindCardIdInput(selector, submitHandler) {
		var $input = $(selector);
		if (!$input.length) {
			return;
		}
		$input.off('.cardReader');
		$input.on('input.cardReader', function() {
			if ($(this).val() !== '') {
				clearCardInputHint($(this));
				clearCardNfcStatus($(this));
			}
		});
		$input.on('keydown.cardReader', function(event) {
			if (event.key === 'Enter' || event.keyCode === 13) {
				event.preventDefault();
				if (validateCardInput($(this)) !== '') {
					submitHandler();
				}
			}
		});
		setTimeout(function() {
			$input.trigger('focus');
		}, 50);
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
                        area: cardDialogArea(260),
						success: function() {
							prepareCardDialog('#cardnum', submitCard);
						}
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
                        area: cardDialogArea(260),
						success: function() {
							prepareCardDialog('#cardnum', submitCard);
						}
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

	function openReleaseCard() {
		layer.open({
			type: 1,
			title: '回收工牌',
			content: $('#releaseCardDialogModal').html(),
			area: cardDialogArea(260),
			success: function() {
				prepareCardDialog('#release_cardnum', releaseCard);
			}
		});
	}

    // 关闭对话框
    function closeDialog() {
	  stopActiveNfcSession();
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
      var cardnum = validateCardInput($('#cardnum'));
	  if (cardnum === '') {
		return;
	  }
      
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

	function releaseCard() {
	  var cardnum = validateCardInput($('#release_cardnum'));
	  if (cardnum === '') {
		return;
	  }

      var htmlobj = $.ajax({
		type: 'POST',
		url: "?action=releasecard&page=panel&module=submitcard&csrf=<?php echo $_SESSION['token']; ?>",
		async:true,
		data: {
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
	window.deactivateGuest = deactivateGuest;
	window.activateGuest = activateGuest;
	window.deactivate = deactivate;
	window.activate = activate;
	window.createGuest = createGuest;
	window.closeDialog = closeDialog;
	window.addGuest = addGuest;
    window.submitguestcard = submitguestcard;
    window.submitemployeecard = submitemployeecard;
    window.submitCard = submitCard;
	window.openReleaseCard = openReleaseCard;
	window.releaseCard = releaseCard;
	window.startCardNfcScan = startCardNfcScan;
	window.syncFeishuMember = syncFeishuMember;
  });
</script>
