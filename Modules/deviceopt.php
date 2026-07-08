<?php

namespace anim210System;

use anim210System;

global $_config;

$page_title = "门禁设备管理";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
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
                                foreach ($deviceData as $dData) {
                                    echo "<tr>
                                    <td>{$dData['id']}</td>
                                    <td>{$dData['name']}</td>
									<td>{$dData['did']}</td>
									<td>{$dData['oemcode']}</td>
									<td>{$dData['ip']}</td>
									<td>{$dData['mac']}</td>
									<td>{$dData['hbtime']}</td>
									<td>{$dData['apikey']}</td>
                                    <td><button class=\"btn btn-default\" onclick=\"deleteDevice({$dData['id']})\">删除</button></td>
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
<script>
  var deviceid;
  var devicename;
  layui.use(['layer', 'form'], function() {
    var layer = layui.layer;
    var form = layui.form;

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

	// global
	window.deleteDevice = deleteDevice;
	window.addNewDevice = addNewDevice;
	window.closeDialog = closeDialog;
	window.addDevice = addDevice;
  });
</script>