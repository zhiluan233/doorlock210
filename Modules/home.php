<?php
/*

后台首页模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

$page_title = "管理面板";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
}

$um = new anim210System\UserCheck();

?>
<style>
    .tips-layui {
        background-color: #333;
        color: #fff;
    }
</style>
<div class="page-title">
	<h3 class="breadcrumb-header">门禁系统首页</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400">
					<h4 style="font-weight: 400">账户信息</h4>

					<table id="user1" class="table table-bordered table-striped" style="clear: both;margin-top: 20px;">
						<tbody>
							<tr>
								<td>用户名</td>
								<td><?php echo $rs['username'] ?></td>
							</tr>
							<tr>
								<td>用户组</td>
								<td><?php echo $rs['type'] ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="row"></div>
		<!-- Row -->
</div>
