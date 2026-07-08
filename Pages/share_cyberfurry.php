<?php

namespace anim210System;

use anim210System;

$notfound = '
<html lang="en">
	<head>
		<title>404 Notfound</title>
		<style type="text/css">
			body {
				background: #F1F1F1;
				font-weight: 200 ! important;
				padding: 32px;
			}
			h1 {
				font-weight: 200 ! important;
			}
			logo {
				font-size: 100px;
			}
		</style>
	</head>
	<body>
		<logo>:(</logo>
		<h1>404 未找到</h1>
		<p><b>Error:</b> 没有对应的CyberFurry角色、角色未通过审核、角色未公开、CFID有误或没有填写CFID，请填写正确的地址。<a href="/">返回主站</a></p>
		<p><em>Powered by Wingmark PHP Framework</em></p>
	</body>
</html>';

if (isset($_GET['cfid'])) {
    $cyberFurryIdentify = htmlspecialchars($_GET['cfid']);
    $cyberFurryData = Database::querySingleLine("cyberfurry", Array("id" => (int)$cyberFurryIdentify));
    if (!$cyberFurryData) {
        exit($notfound);
    }
    if ($cyberFurryData['isPrivate'] == 'true' || $cyberFurryData['enabled'] == 'false') {
	    exit($notfound);
    }
    $easyCyberFurryTips = '。';
    $cyberFurryCreateUser = Database::querySingleLine("user", Array("phone" => $cyberFurryData['createUser']));

    // 初始化变量
    $cyberFurryNickname = $cyberFurryData['cfnickname'];
    $cyberFurryId = $cyberFurryIdentify . '-' . base64_encode($cyberFurryIdentify);
    $cyberFurryModel = $cyberFurryData['model'];
    $cyberFurryWelcomeMessage = $cyberFurryData['cfwelcome'];
    $cyberFurryAvatar = $cyberFurryData['cfavatar'];
    $cyberFurryIntro = $cyberFurryData['cfintro'];
    $cyberFurryCreateUserName = $cyberFurryCreateUser['nickname'];
    if (strpos($cyberFurryModel, 'v-cyberfurry') !== FALSE) {
        $cyberFurryCreateUserName = $cyberFurryCreateUserName.'<br>———使用EasyCyberFurry构建。';
    }
} else {
    exit($notfound);
}
?>
<!DOCTYPE html>
    <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
            <title><?php echo $cyberFurryNickname ?> | CyberFurry</title>
            
            <link rel="stylesheet" href="asset/css/pages/afc-Login.css">
            <link href="/jsdeliver/remixicon.css" rel="stylesheet">

            <script src="asset/plugins/jQuery/jquery-3.6.1.min.js"></script>
            <script type="module" src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.esm.js"></script>
            <script nomodule src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.js"></script>

            <script src="asset/plugins/Notification/Vanilla.Login.min.js"></script>
            <script src="asset/js/ciPanel-login.js"></script>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?compat=recaptcha" async defer></script>
            <style>
                .cyberfurry-avatar {
                    width: 60px;
                    margin-top: -10px;
                    display: inline-block;
                    vertical-align: top;
                    max-width: none;
                    border-radius: 50%;
                }
                .main form {
                    display: flex;
                    flex-direction: column;
                    gap: 20px;
                }
                @media screen and (max-width: 768px) {
                    .fix-content {
                        margin-top: 0 !important;
                    }
                }
            </style>

        </head>

        <body class="body-flex">
            <div class="container">
                <div class="afclogbox wrapper-flex">
                    <div class="afc-bg" style="margin-top: 5px;">
                        <img src="https://cravatar.cn/avatar/" alt="" class="cyberfurry-avatar" />
                        <h1 style="margin-top: -40px;"><?php echo $cyberFurryNickname ?></h1>
                        <p class="afcfontwgt5 afcblock" style="margin-top: -40px;">创建者：<?php echo $cyberFurryCreateUserName ?></p>
                    </div>
    
                    <div class="main fix-content" style="margin-top: 6vh;">
                        <form action="">
                            <h2><?php echo $cyberFurryWelcomeMessage ?></h2>
                            <p>身份证号：<?php echo $cyberFurryId ?></p>
                            <p>角色介绍：<?php echo $cyberFurryIntro ?></p>
                            <p style="margin-top: 10px"> <input type="button" class="submit afcfontwgt7" value="登录用户中心立即与ta聊天" onclick="location.href='/?page=login'"> </p>
                        </form>
                    </div>

                </div>
            </div>
            
            <footer class="footer">
                <p>Copyright © 2024 上海翎迹网络科技有限公司. All Rights Reserved.</p>
                <p>"CyberFurry" 是 上海翎迹网络科技有限公司 的注册商标</p>
                <p>Core Version: <?php echo $data ?></p>
                <p>
                    <a href="https://beian.miit.gov.cn" style="margin-top: 5px; color:#505050;">沪 ICP 备 2021004658 号 - 2</a>
                </p>
                <p>
                    <a target="_blank" href="http://www.beian.gov.cn/portal/registerSystemInfo?recordcode=37090202000954"><img style="vertical-align:middle;" src="//cn-oss-sd.c.tailnet.cn/landentertainment/beianicon.png" />鲁公网安备 37090202000954 号</a>
                </p>
                <p>Front Page Modified By Gxdung.</p>
                <br />
                <br />
            </footer>

            <div class="separate"> </div>
        </body>

    </html>