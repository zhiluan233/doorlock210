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
            <title>银影 | 忘记密码</title>
            
            <link rel="stylesheet" href="asset/css/pages/afc-Login.css">
            <link href="https://gcore.jsdelivr.net/npm/remixicon@2.5.0/fonts/remixicon.css" rel="stylesheet">

            <script src="asset/plugins/jQuery/jquery-3.6.1.min.js"></script>
            <script type="module" src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.esm.js"></script>
            <script nomodule src="https://unpkg.com/ionicons@6.0.2/dist/ionicons/ionicons.js"></script>

            <script src="asset/plugins/Notification/Vanilla.Login.min.js"></script>
            <script src="asset/js/ciPanel-forgetpsw.js"></script>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?compat=recaptcha" async defer></script>

        </head>

        <body class="body-flex">
            <div class="container">
                <div class="afclogbox wrapper-flex">
                    <div class="afc-bg">
                        <h1>修改密码</h1>
                        <p class="afcfontwgt5 afcblock">请在右侧表单中填写账户信息</p>
                    </div>
    
                    <div class="main">
                        <form action="">
                            <p> <input type="input" placeholder="手机号(仅支持中国大陆)" id="phone"> </p>
                            <p class="password">
                                <input type="password" placeholder="重新设置密码" id="password">
                                <i class="ri-eye-off-line" id="eye"></i>
                            </p>
                            <input type="password" placeholder="确认密码" id="password_confirm">
                            <input style="display: none" type=input id="captcha-token">
                            <div style="display: flex;justify-content: center;align-items: center;" class="g-recaptcha" data-sitekey="0x4AAAAAAAUwcGMAqtJYX5ix"></div>
                            <p><input id="code" type="input" placeholder="验证码"></p>
                            <p><input type="button" class="submit" value="获取短信验证码"  id="codeBtn"></p>
                            <p> <input type="button" class="submit afcfontwgt7" value="提交" id="forgetpsw"> </p>
                        </form>
                    </div>

                </div>
            </div>
            
            <footer class="footer">
                <p>Copyright © 2024 上海翎迹网络科技有限公司. All Rights Reserved.</p>
                <p>
                    <a href="https://beian.miit.gov.cn" style="margin-top: 5px; color:#505050;">沪 ICP 备 2021004658 号 - 2</a>
                </p>
                <p>
                    <a target="_blank" href="http://www.beian.gov.cn/portal/registerSystemInfo?recordcode=37090202000954"><img style="vertical-align:middle;" src="//cn-oss-sd.c.tailnet.cn/landentertainment/beianicon.png" />鲁公网安备 37090202000954 号</a>
                </p>
                <p>Front Page Modified By Gxdung.</p>
            </footer>

            <div class="separate"> </div>
            <script type="text/javascript" src="/asset/js/md5.js"></script>
        </body>



    </html>