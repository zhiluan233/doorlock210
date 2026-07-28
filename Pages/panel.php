<?php

namespace anim210System;

use anim210System;

$module = $_GET['module'] ?? "";

$um = new anim210System\UserCheck();

if(!$um->isLogged()) {
    unset($_SESSION['user']);
    unset($_SESSION['mail']);
    unset($_SESSION['token']);
    exit ("<script>alart('你还没有登录！');</script>");
}
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));
$isAdmin = $rs && $rs['type'] === 'admin';
$isReadonly = $rs && $rs['type'] === 'readonly';

if (!$isAdmin && !$isReadonly) {
    unset($_SESSION['user']);
    unset($_SESSION['token']);
    exit("<script>location='/?page=login';</script>");
}

if ($isReadonly && !in_array($module, ['home', 'admininfo', 'dashboard', 'attendance', 'accesslog', 'operationlog', ''], true)) {
    $module = 'accesslog';
    $_GET['module'] = 'accesslog';
}

OperationLog::logPanelView($module, $rs);

?>
<!DOCTYPE html>
<html lang="zh-CN">
	<head>
		<meta charset="utf-8" />
		<meta http-equiv="X-UA-Compatible" content="IE=edge" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
		<meta name="description" content="Responsive Admin Dashboard Template" />
		<meta name="keywords" content="admin,dashboard" />
		<meta name="author" content="skcats" />
		<!--上面的6个元标签必须放在首位 任何其他Header内容则放在这些标签之后 -->

		<!-- 标题 -->
		<title>两点十分门禁 ｜ 管理后台</title>

		<!-- CSS 样式 -->
		<link href="https://fonts.googleapis.com/css?family=Ubuntu" rel="stylesheet" />

		<link href="asset/plugins/Bootstrap/css/bootstrap.min.css" rel="stylesheet" />
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-table@1.23.0/dist/bootstrap-table.min.css">
		<link href="asset/plugins/Font-awesome/css/all.min.css" rel="stylesheet" />
        <link href="asset/plugins/Font-awesome/css/fontawesome.min.css" rel="stylesheet" />


		<link href="asset/plugins/Icomoon/style.css" rel="stylesheet" />
		<link href="asset/plugins/Uniform/css/default.css" rel="stylesheet" />
		<!-- <link href="asset/plugins/Switchery/switchery.min.css" rel="stylesheet" /> -->
		
		<link href="asset/plugins/X-editable/bootstrap3-editable/css/bootstrap-editable.css" rel="stylesheet" />
		<link href="asset/plugins/X-editable/inputs-ext/typeaheadjs/lib/typeahead.js-bootstrap.css" rel="stylesheet" />
		<link href="asset/plugins/Toastr/toastr.min.css" rel="stylesheet" />
		<link href="asset/layui/css/layui.css" rel="stylesheet" />

		<!-- 主题样式 -->
		<link href="asset/css/ecaps.css" rel="stylesheet" />
		<link href="asset/css/custom.css" rel="stylesheet" />

		<style>
			.fullscreen-sidebar {
				min-height: 100vh; /* 设置高度为视口的高度 */
    			overflow-y: auto; /* 允许垂直滚动 */
			}
			.table-auto {
				width: 100%;
				white-space: nowrap;
			}
			.panel .table td:empty:not([colspan])::before,
			.panel dd:empty::before {
				content: "-";
				color: #98a2b3;
			}
			.page-header .navbar-nav>li>a.panel-icon-button {
				width: 40px;
				height: 40px;
				padding: 0;
				margin: 8px 4px;
				display: flex;
				align-items: center;
				justify-content: center;
				border-radius: 8px;
				color: #637282 !important;
			}
			.page-header .navbar-nav>li>a.panel-icon-button:hover {
				background: #f4f6f8;
				color: #ff5a1f !important;
			}
			.page-header .navbar-nav>li>a.panel-icon-button>i {
				line-height: 1;
				vertical-align: middle;
			}
			@media screen and (min-width: 992px) {
				.page-header .navbar-default .container-fluid {
					height: 56px !important;
					display: flex;
					align-items: center;
					padding-left: 0;
					padding-right: 0;
				}
				.page-header .navbar-header {
					display: none;
				}
				.page-header .navbar-collapse {
					height: 56px !important;
					display: flex !important;
					align-items: center;
					justify-content: space-between;
					width: 100%;
					padding-left: 0;
					padding-right: 0;
				}
				.page-header .navbar-nav {
					height: 56px;
					display: flex;
					align-items: center;
					margin: 0;
					padding: 0;
				}
				.page-header .navbar-nav.navbar-right {
					margin-left: auto;
					margin-right: 0;
				}
				.page-header .navbar-nav>li {
					height: 56px;
					display: flex;
					align-items: center;
				}
				.page-header .navbar-nav>li>a.panel-icon-button {
					margin: 0 4px;
				}
				.page-header .navbar-nav>li.user-dropdown>a {
					height: 56px;
					display: flex;
					align-items: center;
					padding: 0 0 0 12px;
				}
				.page-header .navbar-nav>li.user-dropdown>a>img {
					width: 36px;
					height: 36px;
					margin: 0;
					display: block;
				}
			}
			.page-sidebar-menu ul li a i.fa,
			.page-sidebar-menu ul li a i.fa-solid {
				width: 18px;
				min-width: 18px;
				margin-right: 10px;
				padding-left: 0 !important;
				padding-right: 0 !important;
				text-align: center;
			}
			body.page-sidebar-collapsed .page-sidebar > .logo-box {
				width: 60px;
				height: 81px;
				padding: 0;
				display: flex;
				align-items: center;
				justify-content: center;
			}
			body.page-sidebar-collapsed .page-sidebar > .logo-box h4 {
				display: none;
			}
			body.page-sidebar-collapsed .page-sidebar > .logo-box::before {
				content: "门";
				display: block;
				width: 30px;
				height: 30px;
				line-height: 30px;
				text-align: center;
				border-radius: 8px;
				background: #ff5a1f;
				color: #fff;
				font-weight: 600;
			}
			body.page-sidebar-collapsed .page-sidebar-menu>ul>li>a {
				width: 60px;
				padding: 14px 0;
				display: flex;
				justify-content: center;
				align-items: center;
			}
			body.page-sidebar-collapsed .page-sidebar-menu ul li a i.fa,
			body.page-sidebar-collapsed .page-sidebar-menu ul li a i.fa-solid {
				margin-right: 0;
			}
			body.page-sidebar-collapsed .page-sidebar-menu ul li a span,
			body.page-sidebar-collapsed .page-sidebar-menu ul li:hover a span,
			body.page-sidebar-collapsed .page-sidebar-menu ul li a:hover span {
				display: none !important;
			}
			body.page-sidebar-collapsed .page-sidebar-menu>ul>li:hover>a {
				width: 60px;
			}
			@media screen and (max-width: 991px) {
				#collapsed-sidebar-toggle-button {
					display: none !important;
				}
				.logo-sm {
					display: flex !important;
					align-items: center;
					min-height: 56px;
				}
				.logo-sm #sidebar-toggle-button {
					width: 40px;
					height: 40px;
					padding: 0 !important;
					margin: 8px 10px 8px 0 !important;
					display: flex;
					align-items: center;
					justify-content: center;
					border-radius: 8px;
				}
				.logo-sm .logo-box {
					height: 40px;
					margin: 8px 0 !important;
					padding: 0 !important;
					display: flex !important;
					align-items: center;
				}
				.logo-sm .logo-box h4 {
					margin: 0;
					line-height: 1.35;
				}
			}
			.cyberfurry-table {
				clear: both;
				margin-top: 20px;
				width: 100%;
				max-width: 100%;
			}
			@media screen and (max-width: 768px){
				.cyberfurry-table {
					clear: both;
					margin-top: 20px;
					width: 1000px;
					max-width: 1000px;
				}
			}
		</style>

		<!-- Javascripts 调用 -->
        <script src="asset/plugins/jQuery/jquery-3.6.1.min.js"></script>
		<!-- Latest compiled and minified JavaScript -->
		<script src="https://cdn.jsdelivr.net/npm/bootstrap-table@1.23.0/dist/bootstrap-table.min.js"></script>
		<!-- Latest compiled and minified Locales -->
		<script src="https://cdn.jsdelivr.net/npm/bootstrap-table@1.23.0/dist/locale/bootstrap-table-zh-CN.min.js"></script>
		<script src="https://cdn.jsdelivr.net/npm/pinyin-pro@3.27.0/dist/index.min.js"></script>
		<script src="asset/js/pinyinSearch.js"></script>
        <script type="module" src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.esm.js"></script>
        <script nomodule src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.js"></script>
        <script src="asset/js/ciPanel.js"></script>

		<!-- HTML5 shim 和 Respond.js 用于 IE8 对 HTML5 元素和媒体查询的支持 -->
		<!-- 警告：如果您通过 file://查看页面，Respond.js 将不起作用 -->

		<!--[if lt IE 9]>
			<script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
			<script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
		<![endif]-->
	</head>

	<body class="page-sidebar-fixed">
		<script>
			try {
				if (window.innerWidth >= 992 && localStorage.getItem('doorlockSidebarCollapsed') === '1') {
					document.body.classList.add('page-sidebar-collapsed');
				}
			} catch (e) {}
		</script>
		<!-- 页面容器 -->
		<div class="page-container">
			<!-- 页面侧边栏 -->
			<div class="page-sidebar">
				<a class="logo-box" href="#">
                    <h4>两点十分门禁<br>管理后台</h4>
					<i class="icon-close" style="margin-top: -25px" id="sidebar-toggle-button-close"></i>
				</a>
				<div class="page-sidebar-inner">
					<div class="page-sidebar-menu">
						<ul class="accordion-menu" id="nav">
                            <li class="<?php echo $module == "home" || $module == "" ? "active-page" : ""; ?>">
                                <a href="/?page=panel&module=home"><i class="fa-solid fa-house" style="padding-left: 2.5px; padding-right: 2px"></i><span>首页</span></a>
                            </li>
                            <li class="<?php echo $module == "admininfo" || $module == "" ? "active-page" : ""; ?>">
                                <a href="/?page=panel&module=admininfo"><i class="fa-solid fa-id-card" style="padding-left: 2.5px; padding-right: 2px"></i><span>用户信息</span></a>
                            </li>
							<li class="<?php echo $module == "dashboard" || $module == "" ? "active-page" : ""; ?>">
								<a href="/?page=panel&module=dashboard"><i class="fa-solid fa-chart-line" style="padding-left: 2.5px; padding-right: 2px"></i><span>数据大屏</span></a>
							</li>
							<li class="<?php echo $module == "attendance" || $module == "" ? "active-page" : ""; ?>">
								<a href="/?page=panel&module=attendance"><i class="fa-solid fa-clipboard-check" style="padding-left: 2.5px; padding-right: 2px"></i><span>考勤管理</span></a>
							</li>
                            <?php if($isAdmin) { ?>
                            <li class="menu-divider"></li>
                            <li class="<?php echo $module == "submitcard" || $module == "" ? "active-page" : ""; ?>">
								<a href="/?page=panel&module=submitcard"><i class="fa-solid fa-credit-card" style="padding-left: 2.5px; padding-right: 2px"></i><span>发卡管理</span></a>
							</li>
							<li class="<?php echo $module == "learner" || $module == "" ? "active-page" : ""; ?>">
								<a href="/?page=panel&module=learner"><i class="fa-solid fa-user-graduate" style="padding-left: 2.5px; padding-right: 2px"></i><span>学员管理</span></a>
							</li>
							<li class="<?php echo $module == "deviceopt" || $module == "" ? "active-page" : ""; ?>">
								<a href="/?page=panel&module=deviceopt"><i class="fa-solid fa-cog" style="padding-left: 2.5px; padding-right: 2px"></i><span>设备管理</span></a>
							</li>
							<li class="<?php echo $module == "doorcontrol" || $module == "" ? "active-page" : ""; ?>">
								<a href="/?page=panel&module=doorcontrol"><i class="fa-solid fa-cubes" style="padding-left: 2.5px; padding-right: 2px"></i><span>门禁控制</span></a>
							</li>
							<li class="<?php echo $module == "role" || $module == "" ? "active-page" : ""; ?>">
								<a href="/?page=panel&module=role"><i class="fa-solid fa-users-gear" style="padding-left: 2.5px; padding-right: 2px"></i><span>角色管理</span></a>
							</li>
                            <?php } ?>
							<li class="<?php echo $module == "accesslog" || $module == "" ? "active-page" : ""; ?>">
                                <a href="/?page=panel&module=accesslog"><i class="fa-solid fa-file-text" style="padding-left: 2.5px; padding-right: 2px"></i><span> 出入日志</span></a>
                            </li>
							<li class="<?php echo $module == "operationlog" || $module == "" ? "active-page" : ""; ?>">
                                <a href="/?page=panel&module=operationlog"><i class="fa-solid fa-clipboard-list" style="padding-left: 2.5px; padding-right: 2px"></i><span> 操作日志</span></a>
                            </li>
                            <?php if($isAdmin) { ?>
                                <li class="menu-divider"></li>
                                <li class="<?php echo $module == "useropt" || $module == "" ? "active-page" : ""; ?>">
                                    <a href="/?page=panel&module=useropt"><i class="fa-solid fa-user" style="padding-left: 2.5px; padding-right: 2px"></i><span>本地管理员</span></a>
                                </li>
								<li class="<?php echo $module == "system" || $module == "" ? "active-page" : ""; ?>">
                                    <a href="/?page=panel&module=system"><i class="fa-solid fa-cog" style="padding-left: 2.5px; padding-right: 2px"></i><span>系统设置</span></a>
                                </li>
                            <?php } ?>
						</ul>
					</div>
				</div>
			</div>
			<!-- /Page Sidebar -->

			<!-- 页面内容 -->
			<div class="page-content">
				<!-- 页眉 -->
				<div class="page-header">
					<div class="search-form">
						<form action="#" method="GET">
							<div class="input-group">
								<input type="text" name="search" class="form-control search-input" placeholder="Type something..." />
								<span class="input-group-btn">
									<button class="btn btn-default" id="close-search" type="button"><i class="icon-close"></i></button>
								</span>
							</div>
						</form>
					</div>
					<nav class="navbar navbar-default">
						<div class="container-fluid">
							<!-- 品牌和切换分组以获得更好的移动显示 -->
							<div class="navbar-header">
								<div class="logo-sm">
									<a href="javascript:void(0)" id="sidebar-toggle-button"><i class="fa fa-bars"></i></a>
									<a class="logo-box" href="#"><h4>两点十分门禁 ｜ 管理后台</h4></a>
								</div>

							</div>

							<!-- Collect the nav links, forms, and other content for toggling -->

							<div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
								<ul class="nav navbar-nav">
									<li>
										<a href="javascript:void(0)" id="collapsed-sidebar-toggle-button" class="panel-icon-button" title="收窄侧边栏"><i class="fa fa-outdent"></i></a>
									</li>
									<li>
										<a href="javascript:void(0)" id="toggle-fullscreen" class="panel-icon-button" title="全屏"><i class="fa fa-expand"></i></a>
									</li>
								</ul>

								<ul class="nav navbar-nav navbar-right">
									<li class="dropdown user-dropdown">
										<a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false"
											><img src="https://cravatar.cn/avatar/" alt="" class="img-circle" id="header"
										/></a>
										<ul class="dropdown-menu">
											<li><a href="?page=logout&csrf=<?php echo $_SESSION['token']; ?>" id="logout">退出</a></li>
										</ul>
									</li>
								</ul>
							</div>
							<!-- /.navbar-collapse -->
						</div>
						<!-- /.container-fluid -->
					</nav>
				</div>
				<!-- /Page Header -->
				<!-- Page Inner -->
				<div class="page-inner" style="padding: 5px 20px 20px 20px;">
                <?php
                    $page = new anim210System\Pages();
                    if(isset($_GET['module']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $_GET['module'])) {
                        $page->loadModule($_GET['module']);
                    } else {
                        $page->loadModule("home");
                    }
                ?>
					<div class="page-footer afcFooter" style="display: flex; flex-direction: column">
						<p class="textCenter copyright" style="margin-top: -90px">Copyright © 2026 武汉两点十分文化传播有限公司. All Rights Reserved.</p>
						<p>Core Version: <?php echo $data ?></p>
						<p>Developed by 秩乱.</p>
					</div>
				</div>
				<!-- /Page Inner -->
			</div>
			<!-- /Page Content -->
		</div>
		<!-- /Page Container -->

		<!-- Javascripts -->
		<script src="asset/plugins/jQuery/jquery-3.6.1.min.js"></script>
		<script src="asset/plugins/jQuery/jquery.session.js"></script>
        <script src="asset/plugins/jquery-slimscroll/jquery.slimscroll.min.js"></script>
        <script src="asset/plugins/jquery-validation/jquery.validate.min.js"></script>

		<script src="asset/plugins/Bootstrap/js/bootstrap.min.js"></script>
        <script src="asset/plugins/X-editable/bootstrap3-editable/js/bootstrap-editable.min.js"></script>
		
		<script src="asset/plugins/Uniform/js/jquery.uniform.standalone.js"></script>
		<script src="asset/plugins/Switchery/switchery.min.js"></script>

		<script src="asset/plugins/X-editable/inputs-ext/typeaheadjs/lib/typeahead.js"></script>
		<script src="asset/plugins/X-editable/inputs-ext/typeaheadjs/typeaheadjs.js"></script>
		<script src="asset/plugins/X-editable/inputs-ext/address/address.js"></script>
		
        <script src="asset/js/ecaps.min.js"></script>
        <script src="asset/plugins/Notification/Vanilla.Furry.min.js"></script>
		<script type="text/javascript" src="/asset/js/md5.js"></script>
		<script>
			$(function() {
				var $button = $('#collapsed-sidebar-toggle-button');
				function syncSidebarButton() {
					var collapsed = $('body').hasClass('page-sidebar-collapsed');
					$button.attr('title', collapsed ? '展开侧边栏' : '收窄侧边栏');
					$button.find('i').toggleClass('fa-indent', collapsed).toggleClass('fa-outdent', !collapsed);
				}
				$button.on('click', function() {
					setTimeout(function() {
						try {
							localStorage.setItem('doorlockSidebarCollapsed', $('body').hasClass('page-sidebar-collapsed') ? '1' : '0');
						} catch (e) {}
						syncSidebarButton();
					}, 0);
				});
				syncSidebarButton();
			});
		</script>
	</body>
</html>
