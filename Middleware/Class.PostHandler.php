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
			anim210System\OperationLog::capturePostAction($params);
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
				case "apiCenter":
					$apiCenter = new anim210System\ApiCenter();
					$apiCenter->handle($params);
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
				case "getFeishuJsSdkConfig":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						Header("Content-Type: application/json; charset=utf-8");
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit(json_encode(['ok' => false, 'message' => '你没有足够的权限这么做'], JSON_UNESCAPED_UNICODE));
						}
						$jsApiList = [];
						$feishu = new anim210System\appLinkFeishu(true);
						$result = $feishu->getJsSdkConfig($_POST['url'] ?? '', $jsApiList);
						exit(json_encode($result, JSON_UNESCAPED_UNICODE));
					}
					Header("HTTP/1.1 403 Forbidden");
					Header("Content-Type: application/json; charset=utf-8");
					exit(json_encode(['ok' => false, 'message' => '登录会话已超时，请重新登录'], JSON_UNESCAPED_UNICODE));
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
							if (!in_array($_POST['group'], ['admin', 'readonly'], true)) {
								Header("HTTP/1.1 400 Bad Request");
								exit("本地管理员只允许 admin、readonly");
							}
								$update = Database::insert("user", Array(
			                    "id"             => null,
			                    "username"       => $_POST['username'],
			                    "password"       => $_POST['password'],
			                    "mail"          => $_POST['mail'],
			                    "type"           => $_POST['group'],
								"open_id"        => '',
								"employee_id"    => '',
								"display_name"   => $_POST['display_name'] ?? ''
	                    	));
								if($update === true) {
									exit("创建本地管理员 ".$_POST['username']." 成功！");
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
				case "setFeishuUserRole":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$openId = trim($_POST['open_id'] ?? '');
						$role = $_POST['role'] ?? 'user';
						if ($openId === '') {
							Header("HTTP/1.1 400 Bad Request");
							exit("飞书 OpenID 不能为空");
						}
						if (!in_array($role, ['admin', 'readonly', 'user'], true)) {
							Header("HTTP/1.1 400 Bad Request");
							exit("用户组只允许 admin、readonly、user");
						}
						$employee = Database::querySingleLine("employee", Array("open_id" => $openId));
						if (!$employee) {
							Header("HTTP/1.1 404 Not Found");
							exit("未找到飞书成员，请先同步通讯录");
						}
						$user = Database::querySingleLine("user", Array("open_id" => $openId));
						if (!$user && !empty($employee['employee_id'])) {
							$user = Database::querySingleLine("user", Array("employee_id" => $employee['employee_id']));
						}
						$data = Array(
							"type"         => $role,
							"username"     => $this->feishuReadableUsername($employee, $openId, intval($user['id'] ?? 0)),
							"open_id"      => $openId,
							"employee_id"  => $employee['employee_id'] ?? '',
							"display_name" => $employee['name'] ?: ($employee['realname'] ?? ''),
							"mail"         => $employee['email'] ?? ''
						);
						if ($user) {
							$update = Database::update("user", $data, Array("id" => $user['id']));
						} else {
							$data["id"] = null;
							$data["password"] = md5($openId.time().mt_rand(1000, 9999));
							$update = Database::insert("user", $data);
						}
						if($update === true) {
							$roleName = $role === 'admin' ? '管理员' : ($role === 'readonly' ? '只读管理员' : '普通用户');
							exit("飞书成员后台权限已设置为 ".$roleName);
						}
						Header("HTTP/1.1 500 Internal Error");
						exit("飞书成员后台权限设置失败：".$update);
					}
					Header("HTTP/1.1 403 Forbidden");
					exit("登录会话已超时，请重新登录");
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
					$this->requireAdminUser();
					$name = trim((string)($_POST['name'] ?? ''));
					$phone = trim((string)($_POST['phone'] ?? ''));
					$inviterOpenId = trim((string)($_POST['inviter_open_id'] ?? ''));
					$permanent = $this->truthy($_POST['guest_permanent'] ?? 'true');
					$expiresAt = 0;
					if (!$permanent) {
						$expiresAt = $this->parseDateEndOfDay($_POST['expires_date'] ?? '', '访客过期时间');
						if ($expiresAt <= time()) {
							Header("HTTP/1.1 400 Bad Request");
							exit("访客过期时间必须晚于当前时间");
						}
					}
					if ($name === '') {
						Header("HTTP/1.1 400 Bad Request");
						exit("访客姓名不能为空");
					}
					if ($this->utf8Length($name) > 100) {
						Header("HTTP/1.1 400 Bad Request");
						exit("访客姓名过长");
					}
					if ($this->utf8Length($phone) > 64) {
						Header("HTTP/1.1 400 Bad Request");
						exit("访客手机号过长");
					}
					if ($inviterOpenId === '') {
						Header("HTTP/1.1 400 Bad Request");
						exit("请选择邀约人");
					}
					$inviter = Database::querySingleLine('employee', ['open_id' => $inviterOpenId, 'status' => 'true']);
					if (!$inviter) {
						Header("HTTP/1.1 400 Bad Request");
						exit("邀约人不存在或已禁用");
					}
					$now = time();
					$localGuestId = 'local-guest_'.substr(hash('sha256', $phone.'|'.$name.'|'.$inviterOpenId.'|'.$now.'|'.mt_rand()), 0, 24);
					$departmentId = trim((string)($inviter['department_id'] ?? ''));
					$departmentName = $this->employeeDepartmentPathText($inviter);
					$update = Database::insert("guest", Array(
						"id" => null,
						"open_id" => $localGuestId,
						"name" => $name,
						"phone" => $phone,
						"status" => 'true',
						"expires_at" => $expiresAt,
						"inviter_open_id" => $inviterOpenId,
						"inviter_name" => $inviter['name'] ?? '',
						"inviter_department_id" => $departmentId,
						"inviter_department_name" => $departmentName,
						"updated_at" => $now
					));
					if($update === true) {
						exit("创建新访客 ".$name." 成功！");
					}
					Header("HTTP/1.1 404 Not Found");
					exit("访客创建失败！{$update}");
				break;
				case "saveLearner":
					$this->requireAdminUser();
					$id = intval($_POST['id'] ?? 0);
					$name = trim((string)($_POST['name'] ?? ''));
					$realname = trim((string)($_POST['realname'] ?? ''));
					$mobile = trim((string)($_POST['mobile'] ?? ''));
					$className = trim((string)($_POST['class_name'] ?? ''));
					$trainingCenter = trim((string)($_POST['training_center'] ?? ''));
					$enrolledAt = $this->parseDateInput($_POST['enrolled_date'] ?? '', '入学时间');
					$studentNo = $this->normalizeStudentNo($_POST['student_no'] ?? '');
					$remark = trim((string)($_POST['remark'] ?? ''));
					if ($name === '') {
						Header("HTTP/1.1 400 Bad Request");
						exit("学员花名不能为空");
					}
					if ($realname === '') {
						Header("HTTP/1.1 400 Bad Request");
						exit("学员真实姓名不能为空");
					}
					if ($studentNo === '') {
						Header("HTTP/1.1 400 Bad Request");
						exit("学号只能包含字母、数字、下划线和中划线，长度1-64位");
					}
					if ($mobile !== '' && !preg_match('/^[0-9\+\-\s]{1,32}$/', $mobile)) {
						Header("HTTP/1.1 400 Bad Request");
						exit("手机号格式不合法");
					}
					if ($this->utf8Length($name) > 100 || $this->utf8Length($realname) > 100 || strlen($mobile) > 32 || $this->utf8Length($className) > 100 || $this->utf8Length($trainingCenter) > 100 || $this->utf8Length($remark) > 200) {
						Header("HTTP/1.1 400 Bad Request");
						exit("学员花名、真实姓名、手机号、班级、培养中心或备注过长");
					}
					$studentNoEsc = Database::escape($studentNo);
					$duplicateSql = "SELECT * FROM `learner` WHERE `student_no`='{$studentNoEsc}' AND `id`<>{$id} LIMIT 1";
					if (Database::querySingleLine('learner', $duplicateSql, true)) {
						Header("HTTP/1.1 400 Bad Request");
						exit("学号已存在");
					}
					$now = time();
					$data = [
						'student_no' => $studentNo,
						'name' => $name,
						'realname' => $realname,
						'mobile' => $mobile,
						'class_name' => $className,
						'training_center' => $trainingCenter,
						'enrolled_at' => $enrolledAt > 0 ? $enrolledAt : strtotime(date('Y-m-d')),
						'remark' => $remark,
						'updated_at' => $now
					];
					if ($id > 0) {
						$learner = Database::querySingleLine('learner', ['id' => $id]);
						if (!$learner) {
							Header("HTTP/1.1 404 Not Found");
							exit("学员不存在");
						}
						$result = Database::update('learner', $data, ['id' => $id]);
						if ($result === true && ($learner['student_no'] ?? '') !== $studentNo) {
							Database::update('access_role_members', ['employee_open_id' => $studentNo], [
								'member_kind' => 'learner',
								'employee_open_id' => $learner['student_no']
							]);
							Database::update('access_policies', ['subject_value' => $studentNo], [
								'subject_kind' => 'learner',
								'subject_type' => 'learner',
								'subject_value' => $learner['student_no']
							]);
						}
					} else {
						$data['id'] = null;
						$data['card_id'] = '';
						$data['status'] = 'true';
						$data['created_at'] = $now;
						$result = Database::insert('learner', $data);
					}
					if ($result === true) {
						exit($id > 0 ? "学员资料已保存" : "创建学员 ".$name." 成功");
					}
					Header("HTTP/1.1 500 Internal Error");
					exit("学员资料保存失败：".$result);
				break;
				case "setLearnerStatus":
					$this->requireAdminUser();
					$id = intval($_POST['id'] ?? 0);
					$status = ($_POST['status'] ?? '') === 'true' ? 'true' : 'false';
					$learner = Database::querySingleLine('learner', ['id' => $id]);
					if (!$learner) {
						Header("HTTP/1.1 404 Not Found");
						exit("学员不存在");
					}
					$result = Database::update('learner', ['status' => $status, 'updated_at' => time()], ['id' => $id]);
					if ($result === true) {
						exit($status === 'true' ? "学员通行权限已启用" : "学员通行权限已禁用");
					}
					Header("HTTP/1.1 500 Internal Error");
					exit("学员状态更新失败：".$result);
				break;
				case "deleteLearner":
					$this->requireAdminUser();
					$id = intval($_POST['id'] ?? 0);
					$learner = Database::querySingleLine('learner', ['id' => $id]);
					if (!$learner) {
						Header("HTTP/1.1 404 Not Found");
						exit("学员不存在");
					}
					$studentNo = $learner['student_no'] ?? '';
					if ($studentNo !== '') {
						Database::delete('access_role_members', [
							'member_kind' => 'learner',
							'employee_open_id' => $studentNo
						]);
						Database::delete('access_policies', [
							'subject_kind' => 'learner',
							'subject_type' => 'learner',
							'subject_value' => $studentNo
						]);
					}
					$result = Database::delete('learner', ['id' => $id]);
					if ($result === true) {
						exit("学员已删除");
					}
					Header("HTTP/1.1 500 Internal Error");
					exit("学员删除失败：".$result);
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
						if (!in_array($type, ['employee', 'learner', 'guest'], true)) {
							Header("HTTP/1.1 400 Bad Request");
							exit("发卡对象类型不合法");
						}
						$targetTable = $type === 'learner' ? 'learner' : $type;
						$targetInfo = Database::querySingleLine($targetTable, Array("id" => $id));
						if (!$targetInfo) {
							Header("HTTP/1.1 404 Not Found");
							exit("发卡对象不存在");
						}
						if ($type === 'guest' && intval($targetInfo['expires_at'] ?? 0) > 0 && intval($targetInfo['expires_at']) < time()) {
							Header("HTTP/1.1 400 Bad Request");
							exit("访客已过期，不能发卡");
						}
						$cardId = AttendanceService::normalizeCardNumber($_POST['cardid'] ?? '');
						if (!preg_match('/^[0-9]{10}$/', $cardId)) {
							Header("HTTP/1.1 400 Bad Request");
							exit("工牌ID必须是10位数字");
						}
						$employeeInfo = Database::querySingleLine("employee", Array("card_id" => $cardId));
						$guestInfo = Database::querySingleLine("guest", Array("card_id" => $cardId));
						$learnerInfo = Database::querySingleLine("learner", Array("card_id" => $cardId));
						if ($employeeInfo && !($type === 'employee' && intval($employeeInfo['id']) === intval($id))) {
							Header("HTTP/1.1 400 Bad Request");
							exit("卡已经发给了员工 ".$employeeInfo['name']);
						}
						if ($learnerInfo && !($type === 'learner' && intval($learnerInfo['id']) === intval($id))) {
							Header("HTTP/1.1 400 Bad Request");
							exit("卡已经发给了学员 ".$learnerInfo['name']);
						}
						if ($guestInfo && !($type === 'guest' && intval($guestInfo['id']) === intval($id))) {
							Header("HTTP/1.1 400 Bad Request");
							exit("卡已经发给了访客 ".$guestInfo['name']);
						}
						$data = Array(
						    "card_id" => $cardId,
						);
						$update = false;
						if ($type == 'guest') {
							$update = Database::update("guest", array_merge($data, ['updated_at' => time()]), Array("id" => $id));
						}
						if ($type == 'employee') {
							$update = Database::update("employee", $data, Array("id" => $id));
						}
						if ($type == 'learner') {
							$update = Database::update("learner", array_merge($data, ['updated_at' => time()]), Array("id" => $id));
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
				case "releasecard":
					$um = new anim210System\UserCheck();
					if($um->isLogged()) {
						anim210System\Utils::checkCsrf();
						$us = $um->getInfoByUser($_SESSION['user']);
						if($us['type'] !== "admin") {
							Header("HTTP/1.1 403 Forbidden");
							exit("你没有足够的权限这么做");
						}
						$cardId = AttendanceService::normalizeCardNumber($_POST['cardid'] ?? '');
						if (!preg_match('/^[0-9]{10}$/', $cardId)) {
							Header("HTTP/1.1 400 Bad Request");
							exit("工牌ID必须是10位数字");
						}
						$released = [];
						$employeeInfo = Database::querySingleLine("employee", Array("card_id" => $cardId));
						if ($employeeInfo) {
							$update = Database::update("employee", Array("card_id" => ''), Array("id" => $employeeInfo['id']));
							if ($update !== true) {
								Header("HTTP/1.1 500 Internal Server Error");
								exit("员工工牌回收失败：{$update}");
							}
							$released[] = "员工 ".$employeeInfo['name'];
						}
						$guestInfo = Database::querySingleLine("guest", Array("card_id" => $cardId));
						if ($guestInfo) {
							$update = Database::update("guest", Array("card_id" => '', "updated_at" => time()), Array("id" => $guestInfo['id']));
							if ($update !== true) {
								Header("HTTP/1.1 500 Internal Server Error");
								exit("访客工牌回收失败：{$update}");
							}
							$released[] = "访客 ".$guestInfo['name'];
						}
						$learnerInfo = Database::querySingleLine("learner", Array("card_id" => $cardId));
						if ($learnerInfo) {
							$update = Database::update("learner", Array("card_id" => '', "updated_at" => time()), Array("id" => $learnerInfo['id']));
							if ($update !== true) {
								Header("HTTP/1.1 500 Internal Server Error");
								exit("学员工牌回收失败：{$update}");
							}
							$released[] = "学员 ".$learnerInfo['name'];
						}
						if (count($released) === 0) {
							Header("HTTP/1.1 404 Not Found");
							exit("未找到绑定该工牌的员工、学员或访客");
						}
						exit("工牌 {$cardId} 已回收：".implode("，", $released));
					} else {
						exit("登录会话已超时，请重新登录");
					}
				break;
				case "searchBadgeEmployees":
					$this->requireAdminUser();
					Header("Content-Type: application/json; charset=utf-8");
					$keyword = trim((string)($_POST['q'] ?? ''));
					if (preg_match_all('/./u', $keyword, $matches) !== false) {
						$keyword = implode('', array_slice($matches[0], 0, 40));
					} else {
						$keyword = substr($keyword, 0, 40);
					}
					$where = ["`status`='true'"];
					$loadAll = isset($_POST['all']) && (string)$_POST['all'] === '1';
					if ($keyword !== '' && !$loadAll) {
						$safeKeyword = Database::escape($keyword);
						$like = "'%{$safeKeyword}%'";
						$where[] = "(`name` LIKE {$like} OR `realname` LIKE {$like} OR `employee_id` LIKE {$like} OR `department_name` LIKE {$like})";
					}
					$limit = $loadAll ? 3000 : 20;
					$sql = "SELECT `id`, `open_id`, `name`, `employee_id`, `realname`, `department_name`, `card_id`, `avatar_url` FROM `employee` WHERE " . implode(' AND ', $where) . " ORDER BY CASE WHEN `card_id`='' THEN 0 ELSE 1 END, `name` ASC LIMIT {$limit}";
					$rs = Database::query('employee', $sql, '', true);
					$items = [];
					if ($rs instanceof \mysqli_result) {
						while ($row = mysqli_fetch_assoc($rs)) {
							$items[] = [
								'id' => intval($row['id']),
								'open_id' => $row['open_id'] ?? '',
								'name' => $row['name'] ?? '',
								'employee_id' => $row['employee_id'] ?? '',
								'realname' => $row['realname'] ?? '',
								'department_name' => $row['department_name'] ?? '',
								'card_id' => $row['card_id'] ?? '',
								'avatar_url' => $row['avatar_url'] ?? ''
							];
						}
						mysqli_free_result($rs);
					}
					exit(json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE));
				break;
				case "searchBadgeLearners":
					$this->requireAdminUser();
					Header("Content-Type: application/json; charset=utf-8");
					$keyword = trim((string)($_POST['q'] ?? ''));
					if (preg_match_all('/./u', $keyword, $matches) !== false) {
						$keyword = implode('', array_slice($matches[0], 0, 40));
					} else {
						$keyword = substr($keyword, 0, 40);
					}
					$where = ["`status`='true'"];
					$loadAll = isset($_POST['all']) && (string)$_POST['all'] === '1';
					if ($keyword !== '' && !$loadAll) {
						$safeKeyword = Database::escape($keyword);
						$like = "'%{$safeKeyword}%'";
						$where[] = "(`name` LIKE {$like} OR `realname` LIKE {$like} OR `student_no` LIKE {$like} OR `mobile` LIKE {$like} OR `class_name` LIKE {$like} OR `training_center` LIKE {$like})";
					}
					$limit = $loadAll ? 3000 : 20;
					$sql = "SELECT `id`, `student_no`, `name`, `realname`, `mobile`, `class_name`, `training_center`, `card_id`, `status` FROM `learner` WHERE " . implode(' AND ', $where) . " ORDER BY CASE WHEN `card_id`='' THEN 0 ELSE 1 END, `name` ASC LIMIT {$limit}";
					$rs = Database::query('learner', $sql, '', true);
					$items = [];
					if ($rs instanceof \mysqli_result) {
						while ($row = mysqli_fetch_assoc($rs)) {
							$items[] = [
								'id' => intval($row['id']),
								'student_no' => $row['student_no'] ?? '',
								'name' => $row['name'] ?? '',
								'realname' => $row['realname'] ?? '',
								'mobile' => $row['mobile'] ?? '',
								'class_name' => $row['class_name'] ?? '',
								'training_center' => $row['training_center'] ?? '',
								'card_id' => $row['card_id'] ?? '',
								'status' => $row['status'] ?? ''
							];
						}
						mysqli_free_result($rs);
					}
					exit(json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE));
				break;
				case "searchBadgeGuests":
					$this->requireAdminUser();
					Header("Content-Type: application/json; charset=utf-8");
					$keyword = trim((string)($_POST['q'] ?? ''));
					if (preg_match_all('/./u', $keyword, $matches) !== false) {
						$keyword = implode('', array_slice($matches[0], 0, 40));
					} else {
						$keyword = substr($keyword, 0, 40);
					}
					$now = time();
					$where = ["`status`='true'", "(`expires_at`=0 OR `expires_at`>={$now})"];
					$loadAll = isset($_POST['all']) && (string)$_POST['all'] === '1';
					if ($keyword !== '' && !$loadAll) {
						$safeKeyword = Database::escape($keyword);
						$like = "'%{$safeKeyword}%'";
						$where[] = "(`name` LIKE {$like} OR `phone` LIKE {$like} OR `inviter_name` LIKE {$like} OR `inviter_department_name` LIKE {$like} OR `card_id` LIKE {$like})";
					}
					$limit = $loadAll ? 3000 : 20;
					$sql = "SELECT `id`, `open_id`, `name`, `phone`, `card_id`, `status`, `expires_at`, `inviter_name`, `inviter_department_name` FROM `guest` WHERE " . implode(' AND ', $where) . " ORDER BY CASE WHEN `card_id`='' THEN 0 ELSE 1 END, `name` ASC LIMIT {$limit}";
					$rs = Database::query('guest', $sql, '', true);
					$items = [];
					if ($rs instanceof \mysqli_result) {
						while ($row = mysqli_fetch_assoc($rs)) {
							$expiresAt = intval($row['expires_at'] ?? 0);
							$items[] = [
								'id' => intval($row['id']),
								'open_id' => $row['open_id'] ?? '',
								'name' => $row['name'] ?? '',
								'phone' => $row['phone'] ?? '',
								'card_id' => $row['card_id'] ?? '',
								'status' => $row['status'] ?? '',
								'expires_at' => $expiresAt,
								'expires_text' => $expiresAt > 0 ? date('Y-m-d', $expiresAt) : '永久有效',
								'inviter_name' => $row['inviter_name'] ?? '',
								'inviter_department_name' => $row['inviter_department_name'] ?? ''
							];
						}
						mysqli_free_result($rs);
					}
					exit(json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE));
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
						$result = anim210System\FeishuContactSync::enqueueFullSync($us['username'], 'manual');
						if ($result['ok']) {
							exit($result['message'].'，任务ID：'.$result['job_id']);
						}
						Header("HTTP/1.1 500 Internal Error");
						exit("提交通讯录同步任务失败：".$result['message']);
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
							'feishu_attendance_enabled', 'card_as_attendance_enabled', 'feishu_attendance_mode',
							'feishu_employee_id_type', 'feishu_attendance_batch_size', 'feishu_attendance_cron_max_batches', 'feishu_attendance_batch_interval_ms',
							'feishu_message_enabled', 'feishu_message_template', 'feishu_message_card_template', 'feishu_message_batch_size',
							'feishu_event_enabled',
							'feishu_contact_sync_enabled', 'feishu_contact_sync_daily_time', 'feishu_contact_sync_release_missing',
							'feishu_oauth_enabled', 'feishu_oauth_redirect_uri', 'feishu_oauth_scope', 'feishu_oauth_prompt',
							'remote_open_enabled', 'remote_open_method', 'remote_open_path', 'remote_open_body', 'remote_open_success_text', 'remote_open_timeout',
							'queue_retry_base_seconds', 'queue_retry_max_seconds'
						];
						$data = [];
						foreach ($allowedKeys as $key) {
							if (isset($_POST[$key])) {
								$data[$key] = $_POST[$key];
							}
						}
						foreach (['oa_attendance_enabled','feishu_attendance_enabled','card_as_attendance_enabled','feishu_message_enabled','feishu_event_enabled','feishu_contact_sync_enabled','feishu_contact_sync_release_missing','feishu_oauth_enabled','remote_open_enabled'] as $boolKey) {
							if (!isset($data[$boolKey])) {
								$data[$boolKey] = 'false';
							}
						}
						if (isset($data['feishu_contact_sync_daily_time']) && !preg_match('/^\d{2}:\d{2}$/', $data['feishu_contact_sync_daily_time'])) {
							Header("HTTP/1.1 400 Bad Request");
							exit("通讯录同步时间格式应为 HH:MM，例如 03:25");
						}
						if (isset($data['feishu_attendance_mode']) && !in_array($data['feishu_attendance_mode'], ['flow', 'custom'], true)) {
							Header("HTTP/1.1 400 Bad Request");
							exit("飞书考勤推送模式不合法");
						}
						if (isset($data['feishu_oauth_prompt']) && !in_array($data['feishu_oauth_prompt'], ['', 'consent'], true)) {
							Header("HTTP/1.1 400 Bad Request");
							exit("飞书授权确认参数不合法");
						}
						if (isset($data['remote_open_method'])) {
							$data['remote_open_method'] = strtoupper($data['remote_open_method']);
							if (!in_array($data['remote_open_method'], ['GET', 'POST'], true)) {
								Header("HTTP/1.1 400 Bad Request");
								exit("远程开门请求方式只允许 GET 或 POST");
							}
						}
						$intRanges = [
							'feishu_attendance_batch_size' => [1, 50, '飞书考勤单批条数应为 1-50'],
							'feishu_attendance_cron_max_batches' => [1, 100, '飞书考勤每轮批次应为 1-100'],
							'feishu_attendance_batch_interval_ms' => [0, 2000, '飞书考勤批次间隔应为 0-2000 毫秒'],
							'remote_open_timeout' => [1, 30, '远程开门超时秒数应为 1-30']
						];
						foreach ($intRanges as $intKey => $range) {
							if (!isset($data[$intKey]) || $data[$intKey] === '') {
								continue;
							}
							if (!is_numeric($data[$intKey])) {
								Header("HTTP/1.1 400 Bad Request");
								exit($range[2]);
							}
							$value = intval($data[$intKey]);
							if ($value < $range[0] || $value > $range[1]) {
								Header("HTTP/1.1 400 Bad Request");
								exit($range[2]);
							}
							$data[$intKey] = (string)$value;
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
				case "saveAccessRole":
					$us = $this->requireAdminUser();
					$roleId = intval($_POST['role_id'] ?? 0);
					$name = trim($_POST['name'] ?? '');
					$description = trim($_POST['description'] ?? '');
					$subjectKind = $this->normalizeSubjectKind($_POST['subject_kind'] ?? 'employee');
					$allowAll = $this->truthy($_POST['allow_all'] ?? 'false') ? 1 : 0;
					$rolePermanent = $this->truthy($_POST['role_permanent'] ?? 'true');
					$expiresAt = 0;
					if (!$rolePermanent) {
						$expiresAt = $this->parseDateEndOfDay($_POST['expires_date'] ?? '', '角色过期时间');
						if ($expiresAt <= time()) {
							Header("HTTP/1.1 400 Bad Request");
							exit("角色过期时间必须晚于当前时间");
						}
					}
					$members = json_decode($_POST['members'] ?? '[]', true);
					$devices = json_decode($_POST['devices'] ?? '[]', true);
					if (!is_array($members)) {
						Header("HTTP/1.1 400 Bad Request");
						exit("角色成员格式错误");
					}
					if (!is_array($devices)) {
						Header("HTTP/1.1 400 Bad Request");
						exit("下发门禁格式错误");
					}
					if ($name === '') {
						Header("HTTP/1.1 400 Bad Request");
						exit("角色名称不能为空");
					}
					if ($this->utf8Length($name) > 100) {
						Header("HTTP/1.1 400 Bad Request");
						exit("角色名称过长");
					}
					$currentRole = null;
					if ($roleId > 0) {
						$currentRole = Database::querySingleLine('access_roles', ['id' => $roleId]);
						if (!$currentRole) {
							Header("HTTP/1.1 404 Not Found");
							exit("角色不存在");
						}
						if (($currentRole['builtin_key'] ?? '') !== '') {
							Header("HTTP/1.1 403 Forbidden");
							exit("系统内置角色不可编辑");
						}
					}

					$nameEsc = Database::escape($name);
					$duplicateSql = "SELECT * FROM `access_roles` WHERE `name`='{$nameEsc}' AND `id`<>{$roleId} LIMIT 1";
					if (Database::querySingleLine('access_roles', $duplicateSql, true)) {
						Header("HTTP/1.1 400 Bad Request");
						exit("角色名称已存在");
					}

					$now = time();
					$data = [
						'name' => $name,
						'description' => $description,
						'subject_kind' => $subjectKind,
						'allow_all' => $allowAll,
						'builtin_key' => '',
						'expires_at' => $expiresAt,
						'enabled' => 1,
						'updated_at' => $now
					];
					if ($roleId > 0) {
						$result = Database::update('access_roles', $data, ['id' => $roleId]);
					} else {
						global $conn;
						$data['created_at'] = $now;
						$result = Database::insert('access_roles', $data);
						$roleId = intval(mysqli_insert_id($conn));
					}
					if ($result !== true) {
						Header("HTTP/1.1 500 Internal Error");
						exit("角色保存失败：".$result);
					}

					Database::delete('access_role_members', ['role_id' => $roleId]);
					if ($allowAll === 0) {
						$seenMembers = [];
						foreach ($members as $openId) {
							$openId = trim((string)$openId);
							if ($openId === '' || isset($seenMembers[$openId])) {
								continue;
							}
							$member = $this->findAccessSubject($subjectKind, $openId);
							if (!$member) {
								continue;
							}
							$seenMembers[$openId] = true;
							Database::insert('access_role_members', [
								'role_id' => $roleId,
								'member_kind' => $subjectKind,
								'employee_open_id' => $openId,
								'created_at' => $now
							]);
						}
					}
					$roleIdEsc = Database::escape((string)$roleId);
					Database::delete('access_policies', "DELETE FROM `access_policies` WHERE `subject_type`='role' AND `subject_value`='{$roleIdEsc}'", '', true);
					$seenDevices = [];
					foreach ($devices as $deviceId) {
						$deviceId = intval($deviceId);
						if ($deviceId <= 0 || isset($seenDevices[$deviceId])) {
							continue;
						}
						if (!Database::querySingleLine('devices', ['id' => $deviceId])) {
							continue;
						}
						$seenDevices[$deviceId] = true;
						Database::insert('access_policies', [
							'device_id' => $deviceId,
							'subject_kind' => $subjectKind,
							'subject_type' => 'role',
							'subject_value' => (string)$roleId,
							'subject_extra' => '',
							'enabled' => 1,
							'note' => '角色管理批量下发',
							'created_at' => $now,
							'updated_at' => $now
						]);
					}
					exit("门禁角色已保存");
				break;
				case "saveAccessRoleDevices":
					$this->requireAdminUser();
					$roleId = intval($_POST['role_id'] ?? 0);
					$devices = json_decode($_POST['devices'] ?? '[]', true);
					if ($roleId <= 0) {
						Header("HTTP/1.1 400 Bad Request");
						exit("角色ID不能为空");
					}
					if (!is_array($devices)) {
						Header("HTTP/1.1 400 Bad Request");
						exit("下发门禁格式错误");
					}
					$role = Database::querySingleLine('access_roles', ['id' => $roleId, 'enabled' => 1]);
					if (!$role) {
						Header("HTTP/1.1 404 Not Found");
						exit("角色不存在");
					}
					$now = time();
					$subjectKind = $this->normalizeSubjectKind($role['subject_kind'] ?? 'employee');
					$roleIdEsc = Database::escape((string)$roleId);
					Database::delete('access_policies', "DELETE FROM `access_policies` WHERE `subject_type`='role' AND `subject_value`='{$roleIdEsc}'", '', true);
					$seenDevices = [];
					foreach ($devices as $deviceId) {
						$deviceId = intval($deviceId);
						if ($deviceId <= 0 || isset($seenDevices[$deviceId])) {
							continue;
						}
						if (!Database::querySingleLine('devices', ['id' => $deviceId])) {
							continue;
						}
						$seenDevices[$deviceId] = true;
						Database::insert('access_policies', [
							'device_id' => $deviceId,
							'subject_kind' => $subjectKind,
							'subject_type' => 'role',
							'subject_value' => (string)$roleId,
							'subject_extra' => '',
							'enabled' => 1,
							'note' => '角色管理批量下发',
							'created_at' => $now,
							'updated_at' => $now
						]);
					}
					exit("角色门禁下发已保存");
				break;
				case "deleteAccessRole":
					$this->requireAdminUser();
					$roleId = intval($_POST['role_id'] ?? 0);
					if ($roleId <= 0) {
						Header("HTTP/1.1 400 Bad Request");
						exit("角色ID不能为空");
					}
					$role = Database::querySingleLine('access_roles', ['id' => $roleId]);
					if (!$role) {
						Header("HTTP/1.1 404 Not Found");
						exit("角色不存在");
					}
					if (($role['builtin_key'] ?? '') !== '') {
						Header("HTTP/1.1 403 Forbidden");
						exit("系统内置角色不可删除");
					}
					Database::delete('access_role_members', ['role_id' => $roleId]);
					$roleIdEsc = Database::escape((string)$roleId);
					Database::delete('access_policies', "DELETE FROM `access_policies` WHERE `subject_type`='role' AND `subject_value`='{$roleIdEsc}'", '', true);
					$result = Database::delete('access_roles', ['id' => $roleId]);
					if ($result === true) {
						exit("门禁角色已删除");
					}
					Header("HTTP/1.1 500 Internal Error");
					exit("门禁角色删除失败：".$result);
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
							if (!in_array($kind, ['employee', 'learner', 'guest'], true)) {
								continue;
							}
							if (!in_array($type, ['all', 'employee', 'learner', 'guest', 'department', 'group', 'role', 'department_group'], true)) {
								continue;
							}
							if ($type !== 'all' && $value === '') {
								continue;
							}
							if (in_array($type, ['department', 'group', 'department_group'], true) && $kind !== 'employee') {
								continue;
							}
							if ($type === 'employee' && $kind !== 'employee') {
								continue;
							}
							if ($type === 'learner' && $kind !== 'learner') {
								continue;
							}
							if ($type === 'guest' && $kind !== 'guest') {
								continue;
							}
							if ($type === 'department') {
								$department = Database::querySingleLine('feishu_departments', [
									'department_id' => $value,
									'status' => 'active'
								]);
								if (!$department) {
									continue;
								}
							}
							if ($type === 'learner') {
								$learner = Database::querySingleLine('learner', [
									'student_no' => $value,
									'status' => 'true'
								]);
								if (!$learner) {
									continue;
								}
							}
							if ($type === 'role') {
								if (!preg_match('/^[0-9]+$/', $value)) {
									continue;
								}
								$role = Database::querySingleLine('access_roles', [
									'id' => intval($value),
									'enabled' => 1
								]);
								if (!$role || ($role['subject_kind'] ?? 'employee') !== $kind) {
									continue;
								}
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

	private function requireAdminUser()
	{
		$um = new anim210System\UserCheck();
		if(!$um->isLogged()) {
			Header("HTTP/1.1 403 Forbidden");
			exit("登录会话已超时，请重新登录");
		}
		anim210System\Utils::checkCsrf();
		$us = $um->getInfoByUser($_SESSION['user']);
		if(!$us || $us['type'] !== "admin") {
			Header("HTTP/1.1 403 Forbidden");
			exit("你没有足够的权限这么做");
		}
		return $us;
	}

	private function truthy($value)
	{
		return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
	}

	private function normalizeSubjectKind($value)
	{
		$value = (string)$value;
		if (in_array($value, ['employee', 'learner', 'guest'], true)) {
			return $value;
		}
		return 'employee';
	}

	private function findAccessSubject($subjectKind, $value)
	{
		$subjectKind = $this->normalizeSubjectKind($subjectKind);
		if ($subjectKind === 'learner') {
			return Database::querySingleLine('learner', ['student_no' => $value]);
		}
		if ($subjectKind === 'guest') {
			return Database::querySingleLine('guest', ['open_id' => $value]);
		}
		return Database::querySingleLine('employee', ['open_id' => $value]);
	}

	private function employeeDepartmentPathText($employee)
	{
		foreach ($this->employeeDepartmentIds($employee) as $departmentId) {
			$path = $this->departmentPathById($departmentId);
			if (count($path) > 0) {
				return implode('/', $path);
			}
		}
		$fallback = trim((string)($employee['department_name'] ?? ''));
		return $fallback !== '' ? $fallback : '--';
	}

	private function employeeDepartmentIds($employee)
	{
		$ids = [];
		$raw = $employee['department_ids'] ?? '';
		if (is_string($raw) && trim($raw) !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				$raw = $decoded;
			} else if (strpos($raw, ',') !== false) {
				$raw = array_map('trim', explode(',', $raw));
			}
		}
		if (is_array($raw)) {
			foreach ($raw as $item) {
				if (is_scalar($item) && trim((string)$item) !== '') {
					$ids[] = trim((string)$item);
				} else if (is_array($item)) {
					foreach (['department_id', 'open_department_id', 'id'] as $key) {
						if (!empty($item[$key])) {
							$ids[] = trim((string)$item[$key]);
							break;
						}
					}
				}
			}
		}
		if (!empty($employee['department_id'])) {
			$ids[] = trim((string)$employee['department_id']);
		}
		return array_values(array_unique(array_filter($ids, function($id) {
			return $id !== '';
		})));
	}

	private function departmentPathById($departmentId)
	{
		$path = [];
		$seen = [];
		$department = $this->findDepartment($departmentId);
		while (is_array($department)) {
			$id = trim((string)($department['department_id'] ?? ''));
			$openId = trim((string)($department['open_department_id'] ?? ''));
			$seenKey = $id !== '' ? $id : $openId;
			if ($seenKey !== '' && isset($seen[$seenKey])) {
				break;
			}
			if ($seenKey !== '') {
				$seen[$seenKey] = true;
			}
			$name = trim((string)($department['name'] ?? ''));
			if ($name !== '') {
				array_unshift($path, $name);
			}
			$parentId = trim((string)($department['parent_department_id'] ?? ''));
			if ($parentId === '' || $parentId === '0') {
				break;
			}
			$department = $this->findDepartment($parentId);
		}
		return $path;
	}

	private function findDepartment($departmentId)
	{
		static $cache = [];
		$departmentId = trim((string)$departmentId);
		if ($departmentId === '') {
			return null;
		}
		if (array_key_exists($departmentId, $cache)) {
			return $cache[$departmentId];
		}
		$escaped = Database::escape($departmentId);
		$sql = "SELECT * FROM `feishu_departments` WHERE `department_id`='{$escaped}' OR `open_department_id`='{$escaped}' LIMIT 1";
		$row = Database::querySingleLine('feishu_departments', $sql, true);
		$cache[$departmentId] = is_array($row) ? $row : null;
		return $cache[$departmentId];
	}

	private function normalizeStudentNo($value)
	{
		$value = trim((string)$value);
		if (!preg_match('/^[A-Za-z0-9\_\-]{1,64}$/', $value)) {
			return '';
		}
		return $value;
	}

	private function parseDateInput($value, $label)
	{
		$value = trim((string)$value);
		if ($value === '') {
			return 0;
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			Header("HTTP/1.1 400 Bad Request");
			exit($label."格式必须是YYYY-MM-DD");
		}
		$parts = explode('-', $value);
		if (!checkdate(intval($parts[1]), intval($parts[2]), intval($parts[0]))) {
			Header("HTTP/1.1 400 Bad Request");
			exit($label."不是有效日期");
		}
		return strtotime($value.' 00:00:00');
	}

	private function parseDateEndOfDay($value, $label)
	{
		$start = $this->parseDateInput($value, $label);
		if ($start <= 0) {
			Header("HTTP/1.1 400 Bad Request");
			exit($label."不能为空");
		}
		return $start + 86399;
	}

	private function feishuReadableUsername($employee, $openId, $currentUserId = 0)
	{
		$displayName = $employee['name'] ?? '';
		if ($displayName === '' && isset($employee['realname']) && $employee['realname'] !== '--') {
			$displayName = $employee['realname'];
		}
		if ($displayName === '' && !empty($employee['email'])) {
			$displayName = explode('@', $employee['email'])[0];
		}
		$displayName = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($displayName));
		if ($displayName === '') {
			$displayName = '飞书用户';
		}

		$base = $this->utf8Limit($displayName, 24);
		$suffixes = [''];
		if (!empty($employee['employee_id'])) {
			$suffixes[] = '_' . preg_replace('/[^A-Za-z0-9\_\-]/', '', $employee['employee_id']);
		}
		$suffixes[] = '_' . substr(hash('sha256', $openId), 0, 6);

		foreach ($suffixes as $suffix) {
			$candidate = $this->utf8Limit($base, 32 - $this->utf8Length($suffix)) . $suffix;
			$exists = Database::querySingleLine("user", Array("username" => $candidate));
			if (!$exists || intval($exists['id']) === intval($currentUserId)) {
				return $candidate;
			}
		}

		return '飞书用户_' . substr(hash('sha256', $openId), 0, 8);
	}

	private function utf8Limit($value, $limit)
	{
		$limit = max(1, intval($limit));
		if (preg_match_all('/./u', $value, $matches) === false) {
			return substr($value, 0, $limit);
		}
		return implode('', array_slice($matches[0], 0, $limit));
	}

	private function utf8Length($value)
	{
		if (preg_match_all('/./u', $value, $matches) === false) {
			return strlen($value);
		}
		return count($matches[0]);
	}
}
