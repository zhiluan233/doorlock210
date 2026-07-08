//
//          用户系统 
//       登录页面处理逻辑
//----------------------------
//   Code     JavaScript
//   Ver      1.0.0
//   Author   乐乐龙果冻, 秩乱
//   Time     230418 00:07

// 导入 Vanilla(JavaScript) 库 消息通知类
import("/asset/plugins/Notification/Vanilla.Login.min.js");
// 导入 Vanilla(JavaScript) 库 消息通知类
import("/asset/plugins/Notification/Vanilla.Furry.min.js");
var flag = 0; // 标记

$(document).ready(function () {
    // 禁止缩放
    $(document).on('touchmove', function(e) {
        if (e.scale !== 1) {
          e.preventDefault();
        }
    });

    $("#eye").click(function () {
        if (flag == 0) {
            $("#eye").attr("class", "ri-eye-line");
            $("#password").attr("type", "input");
            flag = 1;
        } else {
            $("#eye").attr("class", "ri-eye-off-line");
            $("#password").attr("type", "password");
            flag = 0;
        }
    });

    $("#login").click(function () {
        var account = $.trim($("#username").val()); // 获取输入框中的值
        var psw = $.trim($("#password").val()); // 获取输入框中的值

        if (account === "" || psw === "") {
            vt.error("账号或密码不能为空", {
                position: "top-center",
            });
        } else {
            vt.info("正在登录...", {
                position: "top-center",
            });

            var htmlobj = $.ajax({
                type: 'POST',
                url: "?action=login&page=login",
                async:true,
                data: {
                    username: account,
                    password: md5(psw),
                },
                error: function() {
                    vt.error("错误：" + htmlobj.responseText, {
                        position: "top-center",
                    });

                    return;
                },
                success: function() {
                    vt.success("登录成功", {
                        position: "top-center",
                    });
                    window.location.href='/?page=panel&module=home';
                    return;
                }
            });
        }
    });
});
