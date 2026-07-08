<?php

namespace anim210System;

use anim210System;

global $_config;

$page_title = "用户信息";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
}

$um = new anim210System\UserCheck();

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, <?php echo $rs['username'] ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400">
					<h4 style="font-weight: 400">密码修改</h4>

					<table id="user1" class="table table-bordered table-striped" style="clear: both;margin-top: 20px">
                        <tbody>
                            <tr>
								<td>原密码</td>
								<td><input id="opsw" type="password" placeholder="原密码"></td>
							</tr>
                            <tr>
								<td>新密码</td>
								<td><input id="npsw" type="password" placeholder="新密码"></td>
							</tr>
							<tr>
								<td>确认密码</td>
								<td><input id="rnpsw" type="password" placeholder="确认密码"></td>
							</tr>
						</tbody>
					</table>
                    <button class="btn btn-default" id="save_password"><i class="fa fa-save" aria-hidden="true" style="margin-right: 8px; color: #ff5a1f"></i>保存</button>
				</div>
			</div>
		</div>
    </div>
	<div class="row">
    </div>
		<!-- Row -->
</div>
<script>
$("#save_password").click(function () {
    save_password();
});
function save_password() {
    var csrf_token = "<?php echo $_SESSION['token']; ?>";
    var userid = "<?php echo $rs['id']; ?>";
    if($("#npsw").val() == '' || $("#rnpsw").val() == '' || $("#opsw").val() == '') {
	    vt.error("请不要输入空密码", {
            position: "top-center",
        });
	    return;
	}
	if($("#npsw").val() != $("#rnpsw").val()) {
	    vt.error("两次输入的密码不一致！", {
            position: "top-center",
        });
	    return;
	}
    if($("#npsw").val() == $("#opsw").val()) {
	    vt.error("原密码不能与新密码相同！", {
            position: "top-center",
        });
	    return;
	}
    encryptOPsw = md5($("#opsw").val());
    encryptNPsw = md5($("#npsw").val());
	var htmlobj = $.ajax({
		type: 'POST',
		url: "?action=updateinfo&page=panel&module=userinfo&csrf=" + csrf_token,
		async:true,
		data: {
			id: userid,
            oPassword: encryptOPsw,
			nPassword: encryptNPsw
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
			setTimeout(function(){ window.location.href = '/?page=logout&csrf=' + csrf_token; }, 1000);
			return;
		}
	});
}
</script>