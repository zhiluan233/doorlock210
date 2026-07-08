<?php

/*

后端post模块
Ver 2.0.0.1 20240403
Code by Jason

*/

namespace anim210System;

use anim210System;

class PostHandler {
	
	public function switcher($params)
	{
		global $_config;
		
		if(isset($params['action']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $params['action'])) {
			switch($params['action']) {
				case "api":
					if (!isset($params['key'])) {
						Header("HTTP/1.1 403 Forbidden");
						Header("Content-Type: application/json");
                    	exit("Key不能为空");
					}
					if ($params['key'] == $_config['apiCommonKey']) {
						$apiMethod = new anim210System\deviceApi();
						$apiMethod->deviceMethod($params);
					} else {
						Header("HTTP/1.1 403 Forbidden");
						Header("Content-Type: application/json");
                    	exit("Key不正确！");
					}
				break;
				case "login":
				    //Header("HTTP/1.1 403 Forbidden");
                    //exit("银影暂时终止对外服务，请在公测群等候通知");
					$um = new anim210System\UserCheck();
					if (!isset($_POST['username']) || !isset($_POST['password'])) {
						Header("HTTP/1.1 403 Forbidden");
                    	exit("请求参数不完整");
					}
					$data = $um->doLogin($_POST);
					if(isset($data['status']) && $data['status'] === true) { // 建立SESSION会话
						$_SESSION['user'] = $data['username'];
						$_SESSION['token'] = md5(mt_rand(0, 999999) . time() . $data['username']);
						exit("<script>location='/?page=panel';</script>"); // 跳转进用户中心
					}
					Header("HTTP/1.1 403 Forbidden");
                    exit($data['message']);
				break;
				case "feishuWebhook":
					anim210System\FeishuEventHandler::handle();
				break;
				case "updateinfo":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						$id = $_POST['id'];
						if ($us['id'] != $id) { // 防止跨用户改密码
						    Header("HTTP/1.1 403 Forbidden");
						    exit("安全拦截");
						}
						if (!preg_match("/^[A-Za-z0-9\_\-]{1,32}$/", $_POST['oPassword'])) {
							Header("HTTP/1.1 403 Forbidden");
							exit("请求不合法！");
						}
						if (!preg_match("/^[A-Za-z0-9\_\-]{1,32}$/", $_POST['nPassword'])) {
							Header("HTTP/1.1 403 Forbidden");
							exit("请求不合法！");
						}
						if ($us['password'] != $_POST['oPassword']) {
							Header("HTTP/1.1 403 Forbidden");
							exit("原密码不正确！");
						}
						$data = Array(
						    "password" => $_POST['nPassword'],
						);
						$update = Database::update("user", $data, Array("id" => $id));
						if($update === true) {
							exit("修改密码成功！请重新登录！");
						} else {
							Header("HTTP/1.1 404 Not Found");
							exit("用户资料更新失败，请联系管理员");
						}
					} else {
						Header("HTTP/1.1 403 Forbidden");
						exit("登录会话已超时，请重新登录");
					}
				break;
				case "createuser":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] == "admin") {
							if (!in_array($_POST['group'], ['admin', 'user', 'readonly'], true)) {
								Header("HTTP/1.1 400 Bad Request");
								exit("用户组只允许 admin、readonly、user");
							}
								$update = Database::insert("user", Array(
			                    "id"             => null,
			                    "username"       => $_POST['username'],
			                    "password"       => $_POST['password'],
			                    "mail"          => $_POST['mail'],
			                    "type"           => $_POST['group'],
								"open_id"        => $_POST['open_id'] ?? '',
								"employee_id"    => $_POST['employee_id'] ?? '',
								"display_name"   => $_POST['display_name'] ?? ''
	                    	));
								if($update === true) {
									exit("创建新用户 ".$_POST['username']." 成功！");
								} else {
									Header("HTTP/1.1 404 Not Found");
									exit("无法创建用户！{$update}");
								}
						} else {
							exit("你没有足够的权限这么做");
						}
					} else {
						exit("登录会话已超时，请重新登录");
					}
				break;
				case "deleteuser":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] == "admin") { // 权限组控制
							$result = Database::delete("user", Array("id" => $_POST['id']));
							if($result === true) {
								exit("用户删除成功！");
							} else {
								Header("HTTP/1.1 404 Not Found");
								exit("用户删除失败！{$result}");
							}
						} else {
							exit("你没有足够的权限这么做");
						}
					} else {
						exit("登录会话已超时，请重新登录");
					}
				break;
				case "createguest":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$open_id = '210-door-sys_'.$_POST['phone'].'_'.time();
						$update = Database::insert("guest", Array(
			                    "id"         => null,
								"open_id"    => $open_id,
			                    "name"       => $_POST['name'],
			                    "phone"      => $_POST['phone'],
								"status"     => 'true'
	                    ));
						if($update === true) {
							exit("创建新访客 ".$_POST['name']." 成功！");
						} else {
							Header("HTTP/1.1 404 Not Found");
							exit("该用户不存在！{$update}");
						}
					} else {
						exit("登录会话已超时，请重新登录");
					}
				break;
				case "submitcard":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$id = $_POST['id'];
						$type = $_POST['type'];
						$employeeInfo = Database::querySingleLine("employee", Array("card_id" => $_POST['cardid']));
						$guestInfo = Database::querySingleLine("guest", Array("card_id" => $_POST['cardid']));
						if ($employeeInfo !== null && $_POST['cardid'] !== '') {
							Header("HTTP/1.1 400 Bad Request");
							exit("卡已经发给了员工 ".$employeeInfo['name']);
						}
						if ($guestInfo !== null && $_POST['cardid'] !== '') {
							Header("HTTP/1.1 400 Bad Request");
							exit("卡已经发给了访客 ".$guestInfo['name']);
						}
						$data = Array(
						    "card_id" => $_POST['cardid'],
						);
						$update = false;
						if ($type == 'guest') {
							$update = Database::update("guest", $data, Array("id" => $id));
						}
						if ($type == 'employee') {
							$update = Database::update("employee", $data, Array("id" => $id));
						}
						
						if($update === true) {
							exit("发卡成功！");
						} else {
							Header("HTTP/1.1 404 Not Found");
							exit("发卡失败，请联系管理员");
						}
					} else {
						exit("登录会话已超时，请重新登录");
					}
				break;
				case "addDevice":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$update = Database::insert("devices", Array(
			                "id"               => null,
			                "name"             => $_POST['devicename'],
							"oemcode"          => $_POST['oemcode'],
			                "ip"               => $_POST['ipaddr'],
							"allowedEmployee"  => [],
							"allowedGuest"     => []
	                    ));
						if($update === true) {
							exit("添加新设备 ".$_POST['devicename']." 成功！");
						} else {
							Header("HTTP/1.1 404 Not Found");
							exit("无法添加设备！{$update}");
						}
					} else {
						exit("登录会话已超时，请重新登录");
					}
				break;
				case "syncFeishuMember":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$feishuMethod = new anim210System\appLinkFeishu();
						$feishuMembers = $feishuMethod->getFeishuMemberList();
						$totalSyncNum = 0;
						$totalUpdateNum = 0;
						$totalInsertNum = 0;
						foreach ($feishuMembers as $members) {
							$totalSyncNum += 1;
							if ($members['status'] === true) {
								$memberStatus = 'true';
							} else {
								$memberStatus = 'false';
							}
							$memberInfo = Database::querySingleLine("employee", Array("open_id" => $members['open_id']));
							if ($memberInfo == null) {
								$update = Database::insert("employee", Array(
									"open_id"      => $members['open_id'],
									"user_id"      => $members['user_id'],
									"union_id"     => $members['union_id'],
									"name"         => $members['name'],
									"employee_id"  => $members['employee_no'],
									"realname"     => $members['real_name'],
									"status"       => $memberStatus,
									"department_id" => $members['department_id'],
									"department_name" => $members['department_name'],
									"department_ids" => json_encode($members['department_ids'], JSON_UNESCAPED_UNICODE),
									"email"        => $members['email'],
									"mobile"       => $members['mobile'],
									"tenant_key"   => $members['tenant_key'],
									"updated_at"   => time()
								));
								if($update !== true) {
									Header("HTTP/1.1 500 Internal Error");
									exit("[C]更新数据库时遇到错误，请联系管理员");
								}
								$totalInsertNum += 1;
							} else {
								$data = Array(
									"name"         => $members['name'],
									"user_id"      => $members['user_id'],
									"union_id"     => $members['union_id'],
									"employee_id"  => $members['employee_no'],
									"realname"     => $members['real_name'],
									"status"       => $memberStatus,
									"department_id" => $members['department_id'],
									"department_name" => $members['department_name'],
									"department_ids" => json_encode($members['department_ids'], JSON_UNESCAPED_UNICODE),
									"email"        => $members['email'],
									"mobile"       => $members['mobile'],
									"tenant_key"   => $members['tenant_key'],
									"updated_at"   => time()
								);
								$update = Database::update("employee", $data, Array("open_id" => $members['open_id']));
								if($update !== true) {
									Header("HTTP/1.1 500 Internal Error");
									exit("[U]更新数据库时遇到错误，请联系管理员");
								}
								$totalUpdateNum += 1;
							}
						}
						exit ('本次共处理飞书通讯录：'.$totalSyncNum.'人（新增 '.$totalInsertNum.' 人/更新 '.$totalUpdateNum.' 人）');
					} else {
						Header("HTTP/1.1 403");
						exit("你没有足够的权限这么做");
					}
				break;
				case "editPassPermission":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						if (!isset($_POST['user'], $_POST['type'], $_POST['device'])) {
							Header("HTTP/1.1 403 Forbidden");
							exit("参数不完整");
						}
						if ($_POST['type'] !== 'employee' && $_POST['type'] !== 'guest') {
							Header("HTTP/1.1 403 Forbidden");
							exit("参数不合法");
						}

						$userList = json_decode($_POST['user'], true); // 将user的json解码
						$deviceList = json_decode($_POST['device'], true);
						$seenElements = array();
						$seenElements1 = array();
						foreach ($userList as $uList) { // 遍历user数组
							if (!isset($uList['value']) || !isset($uList['title'])) { // 不符合标准
								Header("HTTP/1.1 400 Bad Request");
								exit("提交的数组不合法！");
							}
							if (in_array($uList['value'], $seenElements)) { // 检查重复
								Header("HTTP/1.1 400 Bad Request");
								exit("发现重复添加的用户");
							} else {
								$seenElements[] = $uList['value'];
							}
							
							$userinfo = Database::querySingleLine($_POST['type'], Array("open_id" => $uList['value'])); // 获取用户信息
							if (!$userinfo) {
								Header("HTTP/1.1 404 Not Found");
								exit("用户权限表中有已失效的用户：".$uList['title']."，请移除后重新操作"); // 检查失效用户
							}
							if (($userinfo['status'] ?? '') !== 'true') {
								Header("HTTP/1.1 404 Not Found");
								exit("用户权限表中有已禁用的用户：".$uList['title']."，请移除后重新操作"); // 检查失效用户
							}
						}

						foreach ($deviceList as $dList) { // 遍历device数组
							if (!isset($dList['value']) || !isset($dList['title'])) { // 不符合标准
								Header("HTTP/1.1 400 Bad Request");
								exit("提交的数组不合法！");
							}
							if (in_array($dList['value'], $seenElements1)) { // 检查重复
								Header("HTTP/1.1 400 Bad Request");
								exit("发现重复添加的设备");
							} else {
								$seenElements1[] = $dList['value'];
							}
							
							$deviceinfo = Database::querySingleLine("devices", Array("id" => $dList['value'])); // 获取设备信息
							if (!$deviceinfo) {
								Header("HTTP/1.1 404 Not Found");
								exit("设备表中有已失效的设备：".$dList['title']."，请移除后重新操作"); // 检查失效设备
							}

							if ($_POST['type'] == 'employee') {
								$data = Array(
									'allowedEmployee' => $_POST['user']
								);
							}
							if ($_POST['type'] == 'guest') {
								$data = Array(
									'allowedGuest' => $_POST['user']
								);
							}
							
							$update = Database::update("devices", $data, Array("id" => $dList['value'])); // 更新数据
							if($update !== true) {
								Header("HTTP/1.1 500 Internel Server Error");
								exit("修改失败，内部服务器错误");
							}
						}
						exit("修改成功");
					} else {
						Header("HTTP/1.1 403");
						exit("你没有足够的权限这么做");
					}
				break;
				case "saveSystemSettings":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$allowedKeys = [
							'oa_attendance_enabled', 'oa_base_url', 'oa_auth_path', 'oa_upload_path', 'oa_location_default', 'oa_batch_size',
							'feishu_attendance_enabled', 'card_as_attendance_enabled', 'swipe_async_feishu_enabled', 'feishu_attendance_mode',
							'feishu_employee_id_type', 'feishu_attendance_batch_size',
							'feishu_message_enabled', 'feishu_message_template', 'feishu_message_batch_size',
							'feishu_event_enabled',
							'feishu_oauth_enabled', 'feishu_oauth_redirect_uri',
							'remote_open_enabled', 'remote_open_path', 'remote_open_timeout',
							'queue_retry_base_seconds', 'queue_retry_max_seconds'
						];
						$data = [];
						foreach ($allowedKeys as $key) {
							if (isset($_POST[$key])) {
								$data[$key] = $_POST[$key];
							}
						}
						foreach (['oa_attendance_enabled','feishu_attendance_enabled','card_as_attendance_enabled','swipe_async_feishu_enabled','feishu_message_enabled','feishu_event_enabled','feishu_oauth_enabled','remote_open_enabled'] as $boolKey) {
							if (!isset($data[$boolKey])) {
								$data[$boolKey] = 'false';
							}
						}
						$result = Settings::setMany($data);
						if ($result === true) {
							exit("系统设置已保存");
						}
						Header("HTTP/1.1 500 Internal Error");
						exit("系统设置保存失败：".$result);
					}
					Header("HTTP/1.1 403 Forbidden");
					exit("登录会话已超时，请重新登录");
				break;
				case "remoteOpenDoor":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$result = AttendanceService::remoteOpen(intval($_POST['device_id'] ?? 0), $us['username']);
						if ($result['ok']) {
							exit($result['message']);
						}
						Header("HTTP/1.1 500 Internal Error");
						exit($result['message']);
					}
					Header("HTTP/1.1 403 Forbidden");
					exit("登录会话已超时，请重新登录");
				break;
				case "saveAccessPolicy":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$deviceId = intval($_POST['device_id'] ?? 0);
						$deviceInfo = Database::querySingleLine('devices', ['id' => $deviceId]);
						if (!$deviceInfo) {
							Header("HTTP/1.1 404 Not Found");
							exit("设备不存在");
						}
						$items = json_decode($_POST['policies'] ?? '[]', true);
						if (!is_array($items)) {
							Header("HTTP/1.1 400 Bad Request");
							exit("策略格式错误");
						}
						$delete = Database::delete('access_policies', ['device_id' => $deviceId]);
						if ($delete !== true) {
							Header("HTTP/1.1 500 Internal Error");
							exit("清理旧策略失败：".$delete);
						}
						$now = time();
						$employeeLegacy = [];
						$guestLegacy = [];
						foreach ($items as $item) {
							$kind = $item['subject_kind'] ?? 'employee';
							$type = $item['subject_type'] ?? '';
							$value = trim($item['subject_value'] ?? '');
							$extra = trim($item['subject_extra'] ?? '');
							if (!in_array($kind, ['employee', 'guest'], true)) {
								continue;
							}
							if (!in_array($type, ['all', 'employee', 'guest', 'department', 'group', 'role', 'department_group'], true)) {
								continue;
							}
							if ($type !== 'all' && $value === '') {
								continue;
							}
							Database::insert('access_policies', [
								'device_id' => $deviceId,
								'subject_kind' => $kind,
								'subject_type' => $type,
								'subject_value' => $value,
								'subject_extra' => $extra,
								'enabled' => 1,
								'note' => $item['note'] ?? '',
								'created_at' => $now,
								'updated_at' => $now
							]);
							if ($kind === 'employee' && $type === 'employee') {
								$employeeLegacy[] = ['value' => $value, 'title' => $item['title'] ?? $value];
							}
							if ($kind === 'guest' && $type === 'guest') {
								$guestLegacy[] = ['value' => $value, 'title' => $item['title'] ?? $value];
							}
						}
						Database::update('devices', [
							'allowedEmployee' => json_encode($employeeLegacy, JSON_UNESCAPED_UNICODE),
							'allowedGuest' => json_encode($guestLegacy, JSON_UNESCAPED_UNICODE)
						], ['id' => $deviceId]);
						exit("通行策略已保存");
					}
					Header("HTTP/1.1 403 Forbidden");
					exit("登录会话已超时，请重新登录");
				break;

				case 'addFaceDevice':
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$name = $_POST['devicename']; 
						$deviceSn = $_POST['deviceSn']; 
						$oemcode = $_POST['oemcode'];
						// 可选覆盖默认的 mqtt 连接参数
						$host = $_POST['mqtt_host'] ?: $_config['mqtt']['host'];
						$port = intval($_POST['mqtt_port'] ?: $_config['mqtt']['port']);
						$user = $_POST['mqtt_username'] ?? $_config['mqtt']['username'];
						$pass = $_POST['mqtt_password'] ?? $_config['mqtt']['password'];
						$qos  = isset($_POST['mqtt_qos']) ? intval($_POST['mqtt_qos']) : $_config['mqtt']['qos'];

						$ins = [
							'name'=>$name, 'oemcode'=>$oemcode, 'dtype'=>'face_mqtt',
							'device_sn'=>$deviceSn, 'mqtt_host'=>$host, 'mqtt_port'=>$port,
							'mqtt_username'=>$user, 'mqtt_password'=>$pass, 'mqtt_qos'=>$qos,
							'status'=>'unknown', 'hbtime'=>time()
						];
						$ok = Database::insert('devices', $ins);
						exit($ok ? "创建扫脸设备成功" : "[I]数据库写入失败");
					}
				break;

				case 'registerFaceUser':
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$deviceId   = $_POST['deviceId'];                 // 选定设备
						$empId      = $_POST['employeeNumber'];           // 人员ID
						$name       = $_POST['name'];
						$base64Img  = $_POST['registerBase64'];           // 直接传Base64（不带头）
						$photoFromCapture = 0;                             // 我们走“上传照片”路径

						$dev = Database::querySingleLine('devices',['id'=>$deviceId]);
						if (!$dev || $dev['dtype'] !== 'face_mqtt') {
							Header("HTTP/1.1 404 Not Found"); exit("设备不存在或不是扫脸设备");
						}
						$serial = strtoupper(bin2hex(random_bytes(5)));   // 10位序列号
						$payload = [
							"serialNo" => $serial,
							"deviceSn" => $dev['device_sn'],
							"data" => [
							"employeeNumber"  => $empId,
							"name"            => $name,
							"photoFromCapture"=> $photoFromCapture,
							"registerBase64"  => $base64Img
							]
						];
						$topic = $_config['mqtt']['topicPrefix'] . "/cmd/{$dev['device_sn']}/personCreate"; // 下行 topic
						// ↑ 文档要求该 topic，回执为 cmd/personCreate_reply。:contentReference[oaicite:7]{index=7} :contentReference[oaicite:8]{index=8}

						try {
							$clientId = $_config['mqtt']['clientPrefix'] . $dev['id'] . '-' . time();
							$cli = new anim210System\MqttClient($dev['mqtt_host'],$dev['mqtt_port'],$clientId,$dev['mqtt_username'],$dev['mqtt_password'],$_config['mqtt']['keepalive']);
							$ok = $cli->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE), intval($dev['mqtt_qos'] ?: $_config['mqtt']['qos']));
							$cli->close();
							if (!$ok) throw new \Exception("publish failed");
							exit("已下发人员注册请求（$empId -> {$dev['name']}）");
						} catch (\Throwable $e) {
							Header("HTTP/1.1 500 Internal Error"); exit("MQTT发送失败: ".$e->getMessage());
						}
					}
				default:
					Header("HTTP/1.1 404 Not Found");
					exit("Undefined action {$params['action']}");
			}
		}
	}

	function getDateFormat() {

        date_default_timezone_set('Asia/Shanghai');

        $currentDateTime = date('Y-m-d H:i:s');
        $currentWeekday = date('N');

        // 转换星期几为中文
        $weekdayMap = array(
            1 => '星期一',
            2 => '星期二',
            3 => '星期三',
            4 => '星期四',
            5 => '星期五',
            6 => '星期六',
            7 => '星期日'
        );
        $currentWeekdayChinese = $weekdayMap[$currentWeekday];

        // 获取小时和补充描述
        $currentHour = date('G');
        $hourDescription = '';
        if ($currentHour >= 0 && $currentHour < 6) {
            $hourDescription = '凌晨';
        } elseif ($currentHour >= 6 && $currentHour < 12) {
            $hourDescription = '上午';
        } elseif ($currentHour >= 12 && $currentHour < 14) {
            $hourDescription = '中午';
        } elseif ($currentHour >= 14 && $currentHour < 18) {
            $hourDescription = '下午';
        } else {
            $hourDescription = '晚上';
        }

        // 格式化时间
        $formattedDateTime = date("Y年m月d日 $currentWeekdayChinese $hourDescription g点i ");

        return $formattedDateTime;
        
    }
}
