<?php

namespace anim210System;

use anim210System;

?>
<!DOCTYPE html>
    <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
            <title>210门禁管理系统 | 登录</title>
            
            <link rel="stylesheet" href="asset/css/pages/afc-Login.css">
            <link href="/jsdeliver/remixicon.css" rel="stylesheet">

            <script src="asset/plugins/jQuery/jquery-3.6.1.min.js"></script>
            <script type="module" src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.esm.js"></script>
            <script nomodule src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.js"></script>

            <script src="asset/plugins/Notification/Vanilla.Login.min.js"></script>
            <script src="asset/js/ciPanel-login.js"></script>

            <style>
                @media screen and (max-width: 768px) {
                    .fixdisplay {
                        margin-top: 120px !important;
                    }
                }
            </style>

        </head>

        <body class="body-flex">
            <div class="container">
                <div class="afclogbox wrapper-flex fixdisplay">
                    <div class="afc-bg">
                        <h1>两点十分门禁</h1>
                        <p class="afcfontwgt5 afcblock">——Dev by zhiluan</p>
                    </div>
    
                    <div class="main">
                        <form action="">
                            <p> <input type="input" placeholder="账号" id="username"> </p>
                            <p class="password">
                                <input type="password" placeholder="密码" id="password">
                                <i class="ri-eye-off-line" id="eye"></i>
                            </p>
                            <p> <input type="button" class="submit afcfontwgt7" value="登录" id="login"> </p>
                            <p> <input type="button" class="submit afcfontwgt7" value="飞书一键登录" onclick="location='/?page=feishu_oauth_start'"> </p>
                        </form>
                    </div>

                </div>
            </div>
            
            <footer class="footer">
                <p>Copyright © 2024 武汉两点十分文化传播有限公司. All Rights Reserved.</p>
                <p>Core Version: <?php echo $data ?></p>
                <p>Front Page Modified By Gxdung.</p>
                <p>Backend Developed By 秩乱.</p>
            </footer>

            <div class="separate"> </div>
            <script type="text/javascript" src="/asset/js/md5.js"></script>
        </body>

    </html>
