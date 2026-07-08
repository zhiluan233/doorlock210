<?php

/*

后端页面路由模块
Ver 1.2.0.1 20210205
Code by Jason

*/

namespace anim210System;

use anim210System;

$pages = new anim210System\Pages();
$phdle = new anim210System\PostHandler();

global $_config;

if($_SERVER['REQUEST_METHOD'] == "POST") {
	$phdle->switcher($_GET);
	exit;
}


// Router
if(isset($_GET['page']) && preg_match("/^[A-Za-z0-9\-\_]{1,20}$/", $_GET['page'])) {
	$um = new anim210System\UserCheck();
	if($um->isLogged()) {
		if($_GET['page'] == "login") {
			exit("<script>location='/?page=panel&module=home';</script>");
		}
		$pages->loadPage($_GET['page'], $_config['appVersion']);
	} else {
		if($_GET['page'] !== "login" && $_GET['page'] !== "register" && $_GET['page'] !== "forgetpsw" && $_GET['page'] !== "share_cyberfurry") {
			exit("<script>location='/?page=login';</script>");
		} else {
			$pages->loadPage($_GET['page'], $_config['appVersion']);
		}
	}
} else {
	$pages->loadPage('login', $_config['appVersion']);
}
exit;


$pages->loadPage('login', $_config['appVersion']);