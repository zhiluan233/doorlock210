<?php

namespace anim210System;

use anim210System;

global $_config;

$page_title = "门禁控制与权限";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
}

if(isset($_GET['getdevices']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $_GET['getdevices'])) {
	anim210System\Utils::checkCsrf();
	if ($_GET['getdevices'] !== 'all') {
		ob_clean();
		Header("HTTP/1.1 403");
		exit("未找到");
	}
	$mainSQL = 'SELECT * FROM `devices`';
	$deviceData = Database::query('devices', $mainSQL, true);
	$outArray = [];
	if($deviceData) {
		foreach($deviceData as $dData) {
			$outArray[] = [
				'value' => $dData['id'],
				'title' => $dData['name'],
			];
		}
		ob_clean();
		exit(json_encode($outArray));
	} else {
		ob_clean();
		Header("HTTP/1.1 500");
		exit("无法解析设备表");
	}
}

if(isset($_GET['getuser']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $_GET['getuser'])) {
	anim210System\Utils::checkCsrf();
	if ($_GET['getuser'] !== 'employee' && $_GET['getuser'] !== 'guest') {
		ob_clean();
		Header("HTTP/1.1 403");
		exit("未找到");
	}
	$mainSQL = 'SELECT * FROM `'.$_GET['getuser'].'`';
	$userData = Database::query($_GET['getuser'], $mainSQL, true);
	$outArray = [];
	if($userData) {
		foreach($userData as $uData) {
			if ($uData['status'] == 'true') {
				$outArray[] = [
					'value' => $uData['open_id'],
					'title' => $uData['name'],
				];
			}
		}
		ob_clean();
		exit(json_encode($outArray));
	} else {
		ob_clean();
		Header("HTTP/1.1 500");
		exit("无法解析用户表");
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
					<h4 style="font-weight: 400">门禁控制与权限</h4><br>
					<h6>设置员工和访客的通行权限表，根据人员添加权限</h6><br />
          <button style="margin-left:5px;" class="btn btn-default" onclick="setEmployeePassPermission()">设置员工通行权限</button>
          <button style="margin-left:5px;" class="btn btn-default" onclick="setGuestPassPermission()">设置访客通行权限</button>

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
							                  <?php
                                foreach ($deviceData as $dData) {
                                    echo "<tr>
                                    <td>{$dData['id']}</td>
                                    <td>{$dData['name']}</td>
									<td>{$dData['ip']}</td>
									<td>{$dData['hbtime']}</td>
                                    <td><button style=\"margin-left:5px;margin-top:5px\" class=\"btn btn-default\" onclick=\"remoteOpen({$dData['id']})\">远程开门</button></td>
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
var csrf_token = "<?php echo $_SESSION['token']; ?>";
layui.use(['layer', 'util', 'form', 'transfer', 'upload'], function(){
    var layer = layui.layer;
    var form = layui.form;
    var transfer = layui.transfer;
    var util = layui.util;
    var upload = layui.upload;

    function setEmployeePassPermission() {
      var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=doorcontrol&getdevices=all&csrf=" + csrf_token,
			async:true,
			error: function() {
				layer.msg("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var deviceList = htmlobj.responseText;

          setPassPermission(deviceList, "employee");
				} catch(e) {
          layer.msg('错误：无法解析服务器返回的数据');
				}
				return;
			}
		});
    }

    function setGuestPassPermission() {
      var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=doorcontrol&getdevices=all&csrf=" + csrf_token,
			async:true,
			error: function() {
				layer.msg("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var deviceList = htmlobj.responseText;

          setPassPermission(deviceList, "guest");
				} catch(e) {
          layer.msg('错误：无法解析服务器返回的数据');
				}
				return;
			}
		});
    }

    function setPassPermission(devicelist, type) {
        let deviceListArr = JSON.parse(devicelist);
        let userListArr = [];
        var htmlobj = $.ajax({
            type: 'GET',
            url: "?page=panel&module=doorcontrol&getuser=" + type + "&csrf=" + csrf_token,
            async:true,
            error: function(response) {
                layer.msg("错误：" + response.responseText);
                return;
            },
            success: function(response) {
              userListArr = JSON.parse(response);
              // 弹出窗口
              layer.open({
                type: 1,
                offset: 'auto',
                title: '您正在编辑通行权限',
                content: '<div id="transferContainer"></div>' + 
                '<div style="text-align: center;margin: 10px">' +
                '<button class="layui-btn layui-btn-normal" lay-on="selectDevices">保存</button>' + 
                '<button class="layui-btn layui-btn-danger" onclick="layer.closeAll();">取消</button>' +
                '</div>',
                area: '400px',
                success: function (layero, index) {
                    // 配置项
                    var userMonifyOptions = {
                        elem: '#transferContainer',
                        title: ['可添加的用户', '已选择的用户'],  // 穿梭框的标题
                        data: userListArr,  // 数据源
                        width: 160,
                        height: 400,
                        showSearch: true,
                        id: 'userManage'
                    };
                    // 初始化transfer组件
                    transfer.render(userMonifyOptions);
                }
                });
                util.on('lay-on', {
                    selectDevices: function(othis){
                      // 弹出窗口
                      layer.open({
                        type: 1,
                        offset: 'auto',
                        title: '请选择需要下发设置的设备',
                        content: '<div id="transferContainer1"></div>' + 
                        '<div style="text-align: center;margin: 10px">' +
                        '<button class="layui-btn layui-btn-normal" lay-on="savePermission">保存</button>' + 
                        '<button class="layui-btn layui-btn-danger" onclick="layer.closeAll();">取消</button>' +
                        '</div>',
                        area: '400px',
                        success: function (layero, index) {
                            // 配置项
                            var userMonifyOptions = {
                                elem: '#transferContainer1',
                                title: ['可下发的设备', '已选择的设备'],  // 穿梭框的标题
                                data: deviceListArr,  // 数据源
                                width: 160,
                                height: 400,
                                showSearch: true,
                                id: 'userManage1'
                            };
                            // 初始化transfer组件
                            transfer.render(userMonifyOptions);
                        }
                        });
                        util.on('lay-on', {
                            savePermission: function(othis){
                                var getData = transfer.getData('userManage');
                                let userJson = JSON.stringify(getData);
                                var getData1 = transfer.getData('userManage1');
                                let userJson1 = JSON.stringify(getData1);
                                var htmlobj = $.ajax({
                                    type: 'POST',
                                    url: "?action=editPassPermission&page=panel&module=doorcontrol&csrf=" + csrf_token,
                                    async:true,
                                    data: {
                                        type: type,
                                        user: userJson,
                                        device: userJson1
                                    },
                                    error: function() {
                                        layer.msg("错误：" + htmlobj.responseText);
                                        return;
                                    },
                                    success: function() {
                                        vt.success(htmlobj.responseText, {
                                            position: "top-center",
                                        });
                                        layer.msg(htmlobj.responseText);
                                        layer.closeAll();
                                        layer.msg('操作成功，数据同步中...', {
                                          icon: 16,
                                          shade: 0.2,
                                          time: 500
                                        }, function(){
                                          location.reload();
                                        });
                                        return;
                                    }
                                });
                            },
                        });
                    },
                });
            }
        });
    }

    window.setEmployeePassPermission = setEmployeePassPermission;
    window.setGuestPassPermission = setGuestPassPermission;
});
</script>