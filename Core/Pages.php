<?php
/*

后端页面指向模块
Ver 1.2.0.1 20210205
Code by Jason

*/
namespace anim210System;

class Pages {
	
	public function loadPage($name, $data = null)
	{
		if(file_exists(ROOT . "/Pages/{$name}.php")) {
			include(ROOT . "/Pages/{$name}.php");
		} else {
			include(ROOT . "/Pages/404.php");
		}
	}
	
	public function loadModule($name, $data = null)
	{
		if(file_exists(ROOT . "/Modules/{$name}.php")) {
			include(ROOT . "/Modules/{$name}.php");
		} else {
			include(ROOT . "/Modules/404.php");
		}
	}
}