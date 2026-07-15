<?php

/*

设备对接模块
Ver 1.0.0.0 20240705
Code by Jason

*/

namespace anim210System;

use anim210System;

class deviceApi {

    public function deviceMethod($params) 
    {
        Header("Content-Type: application/json");
        error_reporting(E_ALL & ~E_WARNING);
        global $_config;

        if(isset($params['method']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $params['method'])) {
            $jsonData = file_get_contents('php://input');
            $data = json_decode($jsonData,true);
            if ($data === null) {
                // 解码失败
                http_response_code(400);
			    exit("invalid post data");
            }
			switch($params['method']) {
                case "heartBeat": 
                    if (isset($data['Serial'],$data['IP'],$data['MAC'],$data['Now'],$data['Key'])) {
                        if (empty($_SERVER['REMOTE_ADDR'])) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        if ($_SERVER['REMOTE_ADDR'] !== $data['IP']) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        $deviceInfo = Database::querySingleLine("devices", Array("ip" => $data['IP']));
                        if ($deviceInfo == null) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        $updatedata = Array(
                            "did"      => $data['Serial'],
                            "mac"      => $data['MAC'],
                            "hbtime"   => $data['Now'],
                            "apikey"   => $data['Key']
                        );
                        $update = Database::update("devices", $updatedata, Array("id" => $deviceInfo['id']));
                        if($update !== true) {
                            Header("HTTP/1.1 500 Internal Error");
                            exit("[U]更新数据库时遇到错误，请联系管理员");
                        }
                        $resp = [
                            'Key' => $data['Key'],
                            'OEM' => (string)$deviceInfo['oemcode']
                        ];
                        http_response_code(200);
			            exit(json_encode($resp));
                    } else {
                        http_response_code(400);
			            exit("uncomplete params");
                    }
                break;
                case "verifyCard": 
                    if (isset($data['Serial'],$data['MAC'],$data['Card'])) {
                        if (empty($_SERVER['REMOTE_ADDR'])) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        $deviceInfo = Database::querySingleLine("devices", Array("did" => $data['Serial']));
                        if ($deviceInfo == null) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        if ($_SERVER['REMOTE_ADDR'] !== $deviceInfo['ip']) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }

                        $eventTime = time();
                        $card = $data['Card'];
                        if ($this->is_base64($data['Card'])) {
                            $card = base64_decode($data['Card']);
                        }
                        $card = AttendanceService::normalizeCardNumber($card);

                        $employeeInfo = Database::querySingleLine("employee", Array("card_id" => $card));
                        $learnerInfo = Database::querySingleLine("learner", Array("card_id" => $card));
                        $guestInfo = Database::querySingleLine("guest", Array("card_id" => $card));

                        if ($guestInfo != null) {
                            $reason = '';
                            $allowPass = AttendanceService::canGuestPass($guestInfo, $deviceInfo, $reason);
                            if ($allowPass === false) {
                                http_response_code(200);
                                $resp = [
                                    'ActIndex' => '0',
                                    'AcsRes' => '0',
                                    'Time' => (string)$_config['doorOpenTime'],
                                    'OEM' => (string)$deviceInfo['oemcode']
                                ];
                                AttendanceService::writeAccessLog($guestInfo['name'], '访客', $deviceInfo['name'], $card, '开门失败：'.$reason, $eventTime);
                                exit(json_encode($resp));
                            }
                                http_response_code(200);
                                $resp = [
                                    'ActIndex' => '0',
                                    'AcsRes' => '1',
                                    'Time' => (string)$_config['doorOpenTime'],
                                    'OEM' => (string)$deviceInfo['oemcode']
                                ];

                                AttendanceService::writeAccessLog($guestInfo['name'], '访客', $deviceInfo['name'], $card, '开门成功', $eventTime);
                                exit(json_encode($resp));
                        }

                        if ($learnerInfo != null) {
                            $reason = '';
                            $allowPass = AttendanceService::canLearnerPass($learnerInfo, $deviceInfo, $reason);
                            if ($allowPass === false) {
                                http_response_code(200);
                                $resp = [
                                    'ActIndex' => '0',
                                    'AcsRes' => '0',
                                    'Time' => (string)$_config['doorOpenTime'],
                                    'OEM' => (string)$deviceInfo['oemcode']
                                ];
                                AttendanceService::writeAccessLog($learnerInfo['name'], '学员', $deviceInfo['name'], $card, '开门失败：'.$reason, $eventTime);
                                exit(json_encode($resp));
                            }
                                http_response_code(200);
                                $resp = [
                                    'ActIndex' => '0',
                                    'AcsRes' => '1',
                                    'Time' => (string)$_config['doorOpenTime'],
                                    'OEM' => (string)$deviceInfo['oemcode']
                                ];

                                AttendanceService::writeAccessLog($learnerInfo['name'], '学员', $deviceInfo['name'], $card, '开门成功', $eventTime);
                                exit(json_encode($resp));
                        }

                        if ($employeeInfo != null) {
                            $reason = '';
                            $allowPass = AttendanceService::canEmployeePass($employeeInfo, $deviceInfo, $reason);
                            if ($allowPass === false) {
                                http_response_code(200);
                                $resp = [
                                    'ActIndex' => '0',
                                    'AcsRes' => '0',
                                    'Time' => (string)$_config['doorOpenTime'],
                                    'OEM' => (string)$deviceInfo['oemcode']
                                ];
                                AttendanceService::writeAccessLog($employeeInfo['name'], '员工', $deviceInfo['name'], $card, '开门失败：'.$reason, $eventTime);
                                exit(json_encode($resp));
                            }
                                http_response_code(200);
                                $resp = [
                                    'ActIndex' => '0',
                                    'AcsRes' => '1',
                                    'Time' => (string)$_config['doorOpenTime'],
                                    'OEM' => (string)$deviceInfo['oemcode']
                                ];
                                AttendanceService::writeAccessLog($employeeInfo['name'], '员工', $deviceInfo['name'], $card, '开门成功', $eventTime);
                                AttendanceService::enqueueSwipe($employeeInfo, $deviceInfo, $card, 'card', $eventTime);
                                exit(json_encode($resp));
                        }

                        http_response_code(200);
                        $resp = [
                            'ActIndex' => '0',
                            'AcsRes' => '0',
                            'Time' => (string)$_config['doorOpenTime'],
                            'OEM' => (string)$deviceInfo['oemcode']
                        ];
			            exit(json_encode($resp));
                    } else {
                        http_response_code(400);
			            exit("uncomplete params");
                    }
                break;
				default:
					Header("HTTP/1.1 404 Not Found");
					exit("Undefined method {$params['method']}");
			}
		} else {
            Header("HTTP/1.1 400 Bad Request");
			exit("Illegal method.");
        }
    }

    private function is_base64($string) {
        // 检查字符串是否为空
        if (empty($string)) {
            return false;
        }
    
        // 检查字符串长度是否是4的倍数
        if (strlen($string) % 4 !== 0) {
            return false;
        }
    
            // 使用正则表达式匹配Base64编码的模式
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string)) {
            return false;
        }

        // 尝试解码并检查解码后的字符串是否是原始字符串的有效Base64编码
        $decoded = base64_decode($string, true);
        if ($decoded === false) {
            return false;
        }

        // 检查解码后的字符串是否重新编码为原始字符串
        if (base64_encode($decoded) !== $string) {
            return false;
        }

        return true;
    }
}
