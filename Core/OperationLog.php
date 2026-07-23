<?php
/*

后台操作日志模块
Ver 1.0.0.0 20260717
Code by Jason / Codex

*/
namespace anim210System;

class OperationLog {

    private static $moduleLabels = [
        '' => '首页',
        'home' => '首页',
        'admininfo' => '用户信息',
        'submitcard' => '发卡管理',
        'learner' => '学员管理',
        'deviceopt' => '设备管理',
        'doorcontrol' => '门禁控制',
        'role' => '角色管理',
        'accesslog' => '出入日志',
        'operationlog' => '操作日志',
        'useropt' => '本地管理员',
        'system' => '系统设置'
    ];

    public static function record($actionCode, $actionName, $detail = '', $context = [], $user = null)
    {
        $user = is_array($user) ? $user : self::currentPrivilegedUser();
        if (!$user) {
            return false;
        }

        $now = time();
        $data = [
            'id' => null,
            'user_id' => intval($user['id'] ?? 0),
            'username' => self::limit((string)($user['username'] ?? ''), 128),
            'display_name' => self::limit((string)(($user['display_name'] ?? '') ?: ($user['username'] ?? '')), 255),
            'role' => self::limit((string)($user['type'] ?? ''), 32),
            'action_code' => self::limit((string)$actionCode, 64),
            'action_name' => self::limit((string)$actionName, 128),
            'module' => self::limit((string)($context['module'] ?? ($_GET['module'] ?? '')), 64),
            'target_type' => self::limit((string)($context['target_type'] ?? ''), 64),
            'target_id' => self::limit((string)($context['target_id'] ?? ''), 128),
            'target_name' => self::limit((string)($context['target_name'] ?? ''), 255),
            'detail' => self::limit((string)$detail, 1000),
            'method' => self::limit((string)($_SERVER['REQUEST_METHOD'] ?? ''), 16),
            'request_path' => self::limit(self::requestPath(), 512),
            'ip' => self::limit(self::clientIp(), 64),
            'user_agent' => self::limit((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255),
            'status_code' => intval($context['status_code'] ?? 200),
            'created_at' => $now
        ];

        $result = Database::insert('operation_logs', $data);
        return $result === true;
    }

    public static function logPanelView($module, $user = null)
    {
        $module = self::normalizeModule($module);
        $label = self::$moduleLabels[$module] ?? $module;
        $detail = '查看' . $label;
        $context = [
            'module' => $module,
            'target_type' => 'module',
            'target_id' => $module,
            'target_name' => $label
        ];

        if ($module === 'accesslog') {
            $keyword = trim((string)($_GET['q'] ?? ($_GET['search'] ?? '')));
            if ($keyword !== '') {
                $keyword = self::limit($keyword, 80);
                $detail = '查看 ' . $keyword . ' 的通行记录';
                $context['target_type'] = 'access_record_query';
                $context['target_id'] = $keyword;
                $context['target_name'] = $keyword;
            } else {
                $detail = '查看出入日志';
            }
        }

        return self::record('view_module', '查看页面', $detail, $context, $user);
    }

    public static function capturePostAction($params)
    {
        $user = self::currentPrivilegedUser();
        if (!$user) {
            return;
        }

        $action = (string)($params['action'] ?? '');
        if ($action === '' || in_array($action, ['api', 'apiCenter', 'login', 'feishuWebhook'], true)) {
            return;
        }

        $descriptor = self::describePostAction($action);
        register_shutdown_function(function() use ($descriptor, $user) {
            $statusCode = http_response_code();
            if ($statusCode <= 0) {
                $statusCode = 200;
            }
            $context = $descriptor;
            $context['status_code'] = $statusCode;
            unset($context['action_code'], $context['action_name'], $context['detail']);
            OperationLog::record(
                $descriptor['action_code'],
                $descriptor['action_name'],
                $descriptor['detail'],
                $context,
                $user
            );
        });
    }

    public static function roleLabel($role)
    {
        if ($role === 'admin') {
            return '管理员';
        }
        if ($role === 'readonly') {
            return '只读';
        }
        return (string)$role;
    }

    public static function moduleLabel($module)
    {
        $module = self::normalizeModule($module);
        return self::$moduleLabels[$module] ?? $module;
    }

    private static function currentPrivilegedUser()
    {
        if (empty($_SESSION['user'])) {
            return null;
        }
        $user = Database::querySingleLine('user', ['username' => $_SESSION['user']]);
        if (!$user || !in_array($user['type'] ?? '', ['admin', 'readonly'], true)) {
            return null;
        }
        return $user;
    }

    private static function describePostAction($action)
    {
        $map = [
            'getFeishuJsSdkConfig' => '获取飞书 JSAPI 配置',
            'updateinfo' => '修改个人信息',
            'createuser' => '创建本地管理员',
            'setFeishuUserRole' => '设置飞书成员后台权限',
            'deleteuser' => '删除本地管理员',
            'createguest' => '创建访客',
            'saveLearner' => '保存学员',
            'importLearners' => '导入学员Excel',
            'setLearnerStatus' => '切换学员状态',
            'deleteLearner' => '删除学员',
            'submitcard' => '发卡',
            'releasecard' => '回收工牌',
            'searchBadgeEmployees' => '搜索可发卡员工',
            'searchBadgeLearners' => '搜索可发卡学员',
            'searchBadgeGuests' => '搜索可发卡访客',
            'addDevice' => '添加设备',
            'syncFeishuMember' => '同步飞书通讯录',
            'editPassPermission' => '修改通行权限',
            'saveSystemSettings' => '保存系统设置',
            'remoteOpenDoor' => '远程开门',
            'saveAccessRole' => '保存门禁角色',
            'saveAccessRoleDevices' => '下发角色门禁',
            'deleteAccessRole' => '删除门禁角色',
            'saveAccessPolicy' => '保存通行策略',
            'addFaceDevice' => '添加人脸设备',
            'registerFaceUser' => '下发人脸注册'
        ];

        $descriptor = [
            'action_code' => $action,
            'action_name' => $map[$action] ?? $action,
            'detail' => $map[$action] ?? $action,
            'module' => self::normalizeModule($_GET['module'] ?? ''),
            'target_type' => '',
            'target_id' => '',
            'target_name' => ''
        ];

        switch ($action) {
            case 'remoteOpenDoor':
                $device = self::findById('devices', intval($_POST['device_id'] ?? 0));
                $descriptor = self::withTarget($descriptor, 'device', $_POST['device_id'] ?? '', $device['name'] ?? '');
                $descriptor['detail'] = '远程开门：' . (($device['name'] ?? '') ?: ('设备#' . intval($_POST['device_id'] ?? 0)));
                break;
            case 'submitcard':
                $type = (string)($_POST['type'] ?? '');
                $target = self::findBadgeSubject($type, intval($_POST['id'] ?? 0));
                $descriptor = self::withTarget($descriptor, $type, $_POST['id'] ?? '', $target['name'] ?? '');
                $descriptor['detail'] = '为 ' . (($target['name'] ?? '') ?: ('ID#' . intval($_POST['id'] ?? 0))) . ' 发工牌 ' . self::limit((string)($_POST['cardid'] ?? ''), 32);
                break;
            case 'releasecard':
                $descriptor = self::withTarget($descriptor, 'card', $_POST['cardid'] ?? '', $_POST['cardid'] ?? '');
                $descriptor['detail'] = '回收工牌 ' . self::limit((string)($_POST['cardid'] ?? ''), 32);
                break;
            case 'setFeishuUserRole':
                $employee = self::findByField('employee', 'open_id', $_POST['open_id'] ?? '');
                $descriptor = self::withTarget($descriptor, 'employee', $_POST['open_id'] ?? '', $employee['name'] ?? '');
                $descriptor['detail'] = '设置 ' . (($employee['name'] ?? '') ?: ($_POST['open_id'] ?? '')) . ' 为 ' . self::roleLabel($_POST['role'] ?? '');
                break;
            case 'createuser':
                $descriptor = self::withTarget($descriptor, 'user', $_POST['username'] ?? '', $_POST['username'] ?? '');
                $descriptor['detail'] = '创建本地管理员 ' . self::limit((string)($_POST['username'] ?? ''), 80);
                break;
            case 'deleteuser':
                $user = self::findById('user', intval($_POST['id'] ?? 0));
                $descriptor = self::withTarget($descriptor, 'user', $_POST['id'] ?? '', $user['username'] ?? '');
                $descriptor['detail'] = '删除本地管理员 ' . (($user['username'] ?? '') ?: ('ID#' . intval($_POST['id'] ?? 0)));
                break;
            case 'createguest':
                $descriptor = self::withTarget($descriptor, 'guest', $_POST['phone'] ?? '', $_POST['name'] ?? '');
                $descriptor['detail'] = '创建访客 ' . self::limit((string)($_POST['name'] ?? ''), 80);
                break;
            case 'saveLearner':
                $learner = self::findById('learner', intval($_POST['id'] ?? 0));
                $name = (string)(($_POST['name'] ?? '') ?: ($learner['name'] ?? ''));
                $descriptor = self::withTarget($descriptor, 'learner', ($_POST['student_no'] ?? ($_POST['id'] ?? '')), $name);
                $descriptor['detail'] = (intval($_POST['id'] ?? 0) > 0 ? '编辑学员 ' : '创建学员 ') . self::limit($name, 80);
                break;
            case 'importLearners':
                $filename = '';
                if (isset($_FILES['learner_excel']) && is_array($_FILES['learner_excel'])) {
                    $filename = (string)($_FILES['learner_excel']['name'] ?? '');
                }
                $descriptor = self::withTarget($descriptor, 'learner_import', $filename, $filename);
                $descriptor['detail'] = '导入学员Excel' . ($filename !== '' ? '：' . self::limit($filename, 120) : '');
                break;
            case 'setLearnerStatus':
            case 'deleteLearner':
                $learner = self::findById('learner', intval($_POST['id'] ?? 0));
                $descriptor = self::withTarget($descriptor, 'learner', $_POST['id'] ?? '', $learner['name'] ?? '');
                $descriptor['detail'] = ($action === 'deleteLearner' ? '删除学员 ' : '切换学员状态 ') . (($learner['name'] ?? '') ?: ('ID#' . intval($_POST['id'] ?? 0)));
                break;
            case 'addDevice':
                $descriptor = self::withTarget($descriptor, 'device', $_POST['ipaddr'] ?? '', $_POST['devicename'] ?? '');
                $descriptor['detail'] = '添加设备 ' . self::limit((string)($_POST['devicename'] ?? ''), 80);
                break;
            case 'syncFeishuMember':
                $descriptor['detail'] = '手动同步飞书通讯录';
                break;
            case 'saveSystemSettings':
                $descriptor['detail'] = '保存系统设置';
                break;
            case 'saveAccessPolicy':
                $device = self::findById('devices', intval($_POST['device_id'] ?? 0));
                $descriptor = self::withTarget($descriptor, 'device', $_POST['device_id'] ?? '', $device['name'] ?? '');
                $descriptor['detail'] = '保存 ' . (($device['name'] ?? '') ?: ('设备#' . intval($_POST['device_id'] ?? 0))) . ' 的通行策略';
                break;
            case 'saveAccessRole':
            case 'saveAccessRoleDevices':
            case 'deleteAccessRole':
                $role = self::findById('access_roles', intval($_POST['role_id'] ?? 0));
                $name = (string)(($_POST['name'] ?? '') ?: ($role['name'] ?? ''));
                $descriptor = self::withTarget($descriptor, 'access_role', $_POST['role_id'] ?? '', $name);
                $descriptor['detail'] = ($map[$action] ?? $action) . ($name !== '' ? '：' . self::limit($name, 80) : '');
                break;
            case 'searchBadgeEmployees':
            case 'searchBadgeLearners':
            case 'searchBadgeGuests':
                $keyword = self::limit((string)($_POST['q'] ?? ''), 80);
                $descriptor = self::withTarget($descriptor, 'search', $keyword, $keyword);
                $descriptor['detail'] = ($map[$action] ?? $action) . ($keyword !== '' ? '：' . $keyword : '');
                break;
            case 'registerFaceUser':
                $descriptor = self::withTarget($descriptor, 'employee', $_POST['employeeNumber'] ?? '', $_POST['name'] ?? '');
                $descriptor['detail'] = '下发人脸注册：' . self::limit((string)($_POST['name'] ?? $_POST['employeeNumber'] ?? ''), 80);
                break;
        }

        return $descriptor;
    }

    private static function withTarget($descriptor, $type, $id, $name)
    {
        $descriptor['target_type'] = self::limit((string)$type, 64);
        $descriptor['target_id'] = self::limit((string)$id, 128);
        $descriptor['target_name'] = self::limit((string)$name, 255);
        return $descriptor;
    }

    private static function findById($table, $id)
    {
        if ($id <= 0) {
            return null;
        }
        $row = Database::querySingleLine($table, ['id' => $id]);
        return is_array($row) ? $row : null;
    }

    private static function findByField($table, $field, $value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $row = Database::querySingleLine($table, [$field => $value]);
        return is_array($row) ? $row : null;
    }

    private static function findBadgeSubject($type, $id)
    {
        if ($type === 'learner') {
            return self::findById('learner', $id);
        }
        if ($type === 'guest') {
            return self::findById('guest', $id);
        }
        return self::findById('employee', $id);
    }

    private static function normalizeModule($module)
    {
        $module = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$module);
        return substr($module, 0, 30);
    }

    private static function clientIp()
    {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function requestPath()
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return '';
        }
        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return self::redactSensitive($uri);
        }
        $path = $parts['path'] ?? '';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($query as $key => $value) {
                if (preg_match('/csrf|token|key|secret|password/i', (string)$key)) {
                    $query[$key] = '***';
                }
            }
        }
        return $path . (count($query) > 0 ? '?' . http_build_query($query) : '');
    }

    private static function redactSensitive($value)
    {
        return preg_replace('/(csrf|token|key|secret|password)=([^&]+)/i', '$1=***', (string)$value);
    }

    private static function limit($value, $length)
    {
        $value = (string)$value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}
