<?php

/*

用户管理模块
Ver 1.2.0.1 20210205
Code by Jason

*/
namespace anim210System;

use anim210System;

class UserCheck {
    
    public function isLogged()
	{
		return (isset($_SESSION['user']) && !empty($_SESSION['user']));
	}
	
	public function doLogin($data)
	{
		if(empty($data['username']) || empty($data['password'])) {
			return Array("status" => false, "message" => "请将信息填写完整");
		}
		
		if(!$this->checkusername($data['username'])) {
			return Array("status" => false, "message" => "用户名不能为空");
		}
		
		// 获取用户的信息（以用户名）
		$rs = $this->getInfoByUser($data['username']);
		if ($rs == null) {
			return Array("status" => false, "message" => "用户名或密码错误");
		}
		if(!$this->checkPassword($data['password'], $rs['password'])) {
			return Array("status" => false, "message" => "用户名或密码错误");
		}
		if ($rs['type'] == 'hold') {
			return Array("status" => false, "message" => "账户未激活！");
		}
		if ($rs['type'] == 'blocked') {
			return Array("status" => false, "message" => "该账户已被封禁！！");
		}
		
		return Array("status" => true, "message" => "登录成功", "username" => $rs['username'], "mail" => $rs['email'], "qq" => $rs['qq']);
	}
	
	public function generatePassword($password)
	{
		return md5($password);
	}
	
	public function getInfoByUser($username)
	{
		return Database::querySingleLine("user", Array("username" => $username));
	}
	
	public function getInfoByEmail($email)
	{
		return Database::querySingleLine("user", Array("email" => $email));
	}
	
	public function checkusername($username)
	{
		return preg_match("/^[A-Za-z0-9\_\-]{3,32}$/", $username) ? true : false;
	}
	
	public function checkPassword($password, $encrypted)
	{
	    //$password = md5($password); BUG!!重复加密
		if ($password == $encrypted) {
		    return true;
		} else {
		    return false;
		}
	}

	public function checkReCaptcha($userToken)
	{

        $post_data = [
            "secret" => "",
            "response" => $userToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://challenges.cloudflare.com/turnstile/v0/siteverify");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36 Wingmark-CloudInsight');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $result = curl_exec($ch);

        $error = false;
        if (curl_errno($ch)) {
            $error = curl_error($ch);
        }

        curl_close($ch);
		
        if ($error !== false) {
			//return curl_errno($ch);
            return array('success' => false);
        }
		

        return json_decode($result,true);
	}
}
