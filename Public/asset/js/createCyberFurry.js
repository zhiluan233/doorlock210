layui.use(['layer', 'util', 'form', 'transfer', 'upload'], function(){
    var layer = layui.layer;
    var form = layui.form;
    var transfer = layui.transfer;
    var util = layui.util;
    var upload = layui.upload;

    function showCreateCyberFurryDialog() {
        layer.open({
            type: 1,
            title: '创建你的CyberFurry角色（简易模式）',
            area: '400px',
            content: '<form class="layui-form" style="padding: 20px;">' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">昵称</label>' +
                           '<div class="layui-input-block">' +
                             '<input type="text" name="cyberFurryNickname" required lay-verify="required" placeholder="请输入CyberFurry角色的昵称（不能超过10个字）" autocomplete="off" class="layui-input">' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">物种</label>' +
                           '<div class="layui-input-block">' +
                             '<input type="text" name="cyberFurrySpecies" required lay-verify="required" placeholder="请输入CyberFurry角色的物种（不能超过5个字）" autocomplete="off" class="layui-input">' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">对话</label>' +
                           '<div class="layui-input-block">' +
                             '<select name="cyberFurryConversationAge" lay-verify="required">' +
                               '<option value="">请选择</option>' +
                               '<option required value="child">可爱兽太</option>' +
                               '<option required value="young">青年</option>' +
                               '<option required value="adult">成熟稳重</option>' +
                             '</select>' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">风格</label>' +
                           '<div class="layui-input-block">' +
                             '<select name="cyberFurryConversationStyle" lay-verify="required">' +
                               '<option value="">请选择</option>' +
                               '<option required value="vivid">活泼</option>' +
                               '<option required value="sentiment">富有情感</option>' +
                               '<option required value="assistant">助理</option>' +
                               '<option required value="chilly">冷酷无情</option>' +
                               '<option required value="social_anxiety">社恐</option>' +
                             '</select>' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">背景</label>' +
                           '<div class="layui-input-block">' +
                             '<textarea name="cyberFurryStory" required lay-verify="required" placeholder="请输入CyberFurry角色的背景故事（年龄、设定、长相或来源故事，1000字以内）" autocomplete="off" class="layui-textarea"></textarea>' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">私密</label>' +
                           '<div class="layui-input-block">' +
                             '<input type="checkbox" name="cyberFurryPrivate" lay-skin="switch" lay-filter="switchPrivate" title="是|否">' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                              '<div style="text-align: center;">' +
                             '<button class="layui-btn layui-btn-normal" lay-submit lay-filter="submitForm">创建</button>' +
                             '<button type="button" class="layui-btn layui-bg-orange" onclick="showCreateCyberFurryDialogAdvance()">专家模式</button>' +
                             '<button type="button" class="layui-btn layui-btn-primary" onclick="layer.closeAll()">取消</button>' +
                          '</div>' +
                         '</div>' +
                       '</form>'
        });
        form.render();
        // 监听表单提交
        form.on('submit(submitForm)', function(data){
            // 提交表单后的操作
            //console.log(data.field);
            var htmlobj = $.ajax({
                type: 'POST',
                url: "?action=createCyberFurry&page=panel&module=create_cyberfurry&csrf=" + csrf_token,
                async:true,
                data: data.field,
                error: function() {
                    layer.msg("错误：" + htmlobj.responseText);
                    return;
                },
                success: function() {
                    vt.success(htmlobj.responseText, {
                          position: "top-center",
                    });
                    layer.closeAll(); // 关闭对话框
                    location.reload();
                    return;
                }
            });
            return false; // 阻止表单默认提交
        });
    }
    function showCreateCyberFurryDialogAdvance() {
        layer.closeAll(); // 关闭对话框
        layer.open({
            type: 1,
            title: '创建你的CyberFurry角色（专家模式）',
            area: '400px',
            content: '<form class="layui-form" style="padding: 20px;">' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">昵称</label>' +
                           '<div class="layui-input-block">' +
                             '<input type="text" name="cyberFurryNickname" required lay-verify="required" placeholder="请输入CyberFurry角色的昵称（不能超过10个字）" autocomplete="off" class="layui-input">' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">提示词</label>' +
                           '<div class="layui-input-block">' +
                             '<textarea name="cyberFurryPrompt" required lay-verify="required" placeholder="请输入角色的Prompt（系统指令）、包含名字、对话风格、角色设定、内容安全要求的提示词（2000字以内）" autocomplete="off" class="layui-textarea"></textarea>' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">私密</label>' +
                           '<div class="layui-input-block">' +
                             '<input type="checkbox" name="cyberFurryPrivate" lay-skin="switch" lay-filter="switchPrivate" title="是|否">' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                              '<div style="text-align: center;">' +
                             '<button class="layui-btn layui-btn-normal" lay-submit lay-filter="submitForm">创建</button>' +
                             '<button type="button" class="layui-btn layui-btn-primary" onclick="layer.closeAll()">取消</button>' +
                          '</div>' +
                         '</div>' +
                       '</form>'
        });
        form.render();
        // 监听表单提交
        form.on('submit(submitForm)', function(data){
            // 提交表单后的操作
            //console.log(data.field);
            var htmlobj = $.ajax({
                type: 'POST',
                url: "?action=createAdvanceCyberFurry&page=panel&module=create_cyberfurry&csrf=" + csrf_token,
                async:true,
                data: data.field,
                error: function() {
                    layer.msg("错误：" + htmlobj.responseText);
                    return;
                },
                success: function() {
                    vt.success(htmlobj.responseText, {
                        position: "top-center",
                    });
                    layer.closeAll(); // 关闭对话框
                    location.reload();
                    return;
                }
            });
            return false; // 阻止表单默认提交
        });
    }
    function editCyberFurry(id) {
      var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=create_cyberfurry&getchar=" + id + "&csrf=" + csrf_token,
			async:true,
			error: function() {
				layer.msg("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					charid = json.id;
					cfnickname = json.cfnickname;
					cfprompt = json.cfprompt;
          user = json.user;
          isprivate = json.isprivate;
          model = json.model;

                    if (model == 'v-cyberfurry-001') {
                        // EasyCyberFurry Config
                        var easyCyberFurryJson = JSON.parse(cfprompt);
                        easycfnickname = easyCyberFurryJson.cfNickname;
                        easycfspecies = easyCyberFurryJson.cfSpecies;
                        easycfconversationage = easyCyberFurryJson.cfConAge;
                        easycfconversationstyle = easyCyberFurryJson.cfConStyle;
                        easycfstory = easyCyberFurryJson.cfStory;
                        layer.open({
                            type: 1,
                            title: '修改你的CyberFurry角色',
                            area: '400px',
                            content: '<form class="layui-form" style="padding: 20px;" lay-filter="edit-cyberfurry">' +
                                       '<div class="layui-form-item">' +
                                       '<input type="text" name="id" placeholder="" autocomplete="off" class="layui-input" style="display: none;">' +
                                         '<label class="layui-form-label">昵称</label>' +
                                         '<div class="layui-input-block">' +
                                           '<input type="text" name="cyberFurryNickname" required lay-verify="required" placeholder="请输入CyberFurry角色的昵称（不能超过10个字）" autocomplete="off" class="layui-input">' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                         '<label class="layui-form-label">物种</label>' +
                                         '<div class="layui-input-block">' +
                                           '<input type="text" name="cyberFurrySpecies" required lay-verify="required" placeholder="请输入CyberFurry角色的物种（不能超过5个字）" autocomplete="off" class="layui-input">' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                         '<label class="layui-form-label">对话</label>' +
                                         '<div class="layui-input-block">' +
                                           '<select name="cyberFurryConversationAge" lay-verify="required">' +
                                             '<option value="">请选择</option>' +
                                             '<option required value="child">可爱兽太</option>' +
                                             '<option required value="young">青年</option>' +
                                             '<option required value="adult">成熟稳重</option>' +
                                           '</select>' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                         '<label class="layui-form-label">风格</label>' +
                                         '<div class="layui-input-block">' +
                                           '<select name="cyberFurryConversationStyle" lay-verify="required">' +
                                             '<option value="">请选择</option>' +
                                             '<option required value="vivid">活泼</option>' +
                                             '<option required value="sentiment">富有情感</option>' +
                                             '<option required value="assistant">助理</option>' +
                                             '<option required value="chilly">冷酷无情</option>' +
                                             '<option required value="social_anxiety">社恐</option>' +
                                           '</select>' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                         '<label class="layui-form-label">背景</label>' +
                                         '<div class="layui-input-block">' +
                                           '<textarea name="cyberFurryStory" required lay-verify="required" placeholder="请输入CyberFurry角色的背景故事（年龄、设定、长相或来源故事，1000字以内）" autocomplete="off" class="layui-textarea"></textarea>' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                         '<label class="layui-form-label">私密</label>' +
                                         '<div class="layui-input-block">' +
                                           '<input type="checkbox" name="cyberFurryPrivate" lay-skin="switch" lay-filter="switchPrivate" title="是|否">' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                            '<div style="text-align: center;">' +
                                           '<button class="layui-btn layui-btn-normal" lay-submit lay-filter="submitForm">保存</button>' +
                                           '<button type="button" class="layui-btn layui-btn-primary" onclick="layer.closeAll()">取消</button>' +
                                        '</div>' +
                                       '</div>' +
                                     '</form>'
                        });
                        form.val('edit-cyberfurry', {
                            "id": charid,
                            "cyberFurryNickname": easycfnickname, 
                            "cyberFurrySpecies": easycfspecies,
                            "cyberFurryConversationAge": easycfconversationage,
                            "cyberFurryConversationStyle": easycfconversationstyle,
                            "cyberFurryStory": easycfstory,
                            "cyberFurryPrivate": isprivate,
                        });
                        form.render();
                        // 监听表单提交
                        form.on('submit(submitForm)', function(data){
                            // 提交表单后的操作
                            //console.log(data.field);
                            var htmlobj = $.ajax({
                                type: 'POST',
                                url: "?action=editCyberFurry&page=panel&module=create_cyberfurry&csrf=" + csrf_token,
                                async:true,
                                data: data.field,
                                error: function() {
                                    layer.msg("错误：" + htmlobj.responseText);
                                    return;
                                },
                                success: function() {
                                    vt.success(htmlobj.responseText, {
                                        position: "top-center",
                                    });
                                    layer.closeAll(); // 关闭对话框
                                    location.reload();
                                    return;
                                }
                            });
                            return false; // 阻止表单默认提交
                        });
                    } else {
                        layer.open({
                            type: 1,
                            title: '修改你的CyberFurry角色',
                            area: '400px',
                            content: '<form class="layui-form" style="padding: 20px;" lay-filter="edit-cyberfurry">' +
                                       '<div class="layui-form-item">' +
                                       '<input type="text" name="id" placeholder="" autocomplete="off" class="layui-input" style="display: none;">' +
                                         '<label class="layui-form-label">昵称</label>' +
                                         '<div class="layui-input-block">' +
                                           '<input type="text" name="cyberFurryNickname" required lay-verify="required" placeholder="请输入CyberFurry角色的昵称（不能超过10个字）" autocomplete="off" class="layui-input">' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                         '<label class="layui-form-label">提示词</label>' +
                                         '<div class="layui-input-block">' +
                                           '<textarea name="cyberFurryPrompt" required lay-verify="required" placeholder="请输入角色的Prompt（系统指令）、包含名字、对话风格、角色设定、内容安全要求的提示词（2000字以内）" autocomplete="off" class="layui-textarea"></textarea>' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                         '<label class="layui-form-label">私密</label>' +
                                         '<div class="layui-input-block">' +
                                           '<input type="checkbox" name="cyberFurryPrivate" lay-skin="switch" lay-filter="switchPrivate" title="是|否">' +
                                         '</div>' +
                                       '</div>' +
                                       '<div class="layui-form-item">' +
                                            '<div style="text-align: center;">' +
                                           '<button class="layui-btn layui-btn-normal" lay-submit lay-filter="submitForm">创建</button>' +
                                           '<button type="button" class="layui-btn layui-btn-primary" onclick="layer.closeAll()">取消</button>' +
                                        '</div>' +
                                       '</div>' +
                                     '</form>'
                        });
                        form.val('edit-cyberfurry', {
                            "id": charid,
                            "cyberFurryNickname": cfnickname, 
                            "cyberFurryPrompt": cfprompt,
                            "cyberFurryPrivate": isprivate,
                        });
                        form.render();
                          // 监听表单提交
                        form.on('submit(submitForm)', function(data){
                            // 提交表单后的操作
                            //console.log(data.field);
                            var htmlobj = $.ajax({
                                type: 'POST',
                                url: "?action=editAdvanceCyberFurry&page=panel&module=create_cyberfurry&csrf=" + csrf_token,
                                async:true,
                                data: data.field,
                                error: function() {
                                    layer.msg("错误：" + htmlobj.responseText);
                                    return;
                                },
                                success: function() {
                                    vt.success(htmlobj.responseText, {
                                        position: "top-center",
                                    });
                                    layer.closeAll(); // 关闭对话框
                                    location.reload();
                                    return;
                                }
                            });
                            return false; // 阻止表单默认提交
                        });
                    }
				} catch(e) {
					alert("错误：无法解析服务器返回的数据");
				}
				return;
			}
		});
    }
    function deleteCyberFurry(id) {
        var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=create_cyberfurry&getchar=" + id + "&csrf=" + csrf_token,
			async:true,
			error: function() {
				layer.msg("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					charid = json.id;
					cfnickname = json.cfnickname;
					cfprompt = json.cfprompt;
                    user = json.user;
                    isprivate = json.isprivate;
                    model = json.model;

                    layer.confirm('是否要删除角色：'+cfnickname, {
						icon: 3, // 问号图标
						title: '确定吗？',
						btn: ['确定', '取消'], // 按钮
						yes: function(index, layero){ // 点击确定按钮的回调函数
						// 执行封禁流程
						var htmlobj = $.ajax({
							type: 'POST',
							url: "?action=deleteCyberFurry&page=panel&module=create_cyberfurry&csrf=" + csrf_token,
							async:true,
                            data: {
                                id: charid
                            },
							error: function() {
								layer.msg("错误：" + htmlobj.responseText);
								return;
							},
							success: function() {
								vt.success(htmlobj.responseText, {
									position: "top-center",
								});
								layer.close(index); // 关闭询问框
								location.reload();
								return;
							}
						});
						},
						btn2: function(index, layero){ // 点击取消按钮的回调函数
						layer.close(index); // 关闭询问框
						}
					});
				} catch(e) {
					alert("错误：无法解析服务器返回的数据");
				}
				return;
			}
		});
    }
    function editUserPermission(id) {
      var htmlobj = $.ajax({
			type: 'GET',
			url: "?page=panel&module=create_cyberfurry&getchar=" + id + "&csrf=" + csrf_token,
			async:true,
			error: function() {
				layer.msg("错误：" + htmlobj.responseText);
				return;
			},
			success: function() {
				try {
					var json = JSON.parse(htmlobj.responseText);
					charid = json.id;
					cfnickname = json.cfnickname;
					cfprompt = json.cfprompt;
          user = json.user;
          isprivate = json.isprivate;
          model = json.model;
          
          editCyberFurryUser(charid, cfnickname, user);
				} catch(e) {
          layer.msg('错误：无法解析服务器返回的数据');
				}
				return;
			}
		});
    }
    function editCyberFurryUser(id, cfnickname, user) {
        let userArr = JSON.parse(user);
        let userListArr = [];
        var htmlobj = $.ajax({
            type: 'GET',
            url: "?page=panel&module=create_cyberfurry&getuser=all&csrf=" + csrf_token,
            async:true,
            error: function(response) {
                layer.msg("错误：" + response.responseText);
                return;
            },
            success: function(response) {
              userListArr = JSON.parse(response);
              var valuesArray = userArr.map(function(item) {
                  return item.value;
              });
              // 弹出窗口
              layer.open({
                type: 1,
                offset: 'auto',
                title: '您正在编辑 ' + cfnickname + ' 的用户权限',
                content: '<div id="transferContainer"></div>' + 
                '<div style="text-align: center;margin: 10px">' +
                '<button class="layui-btn layui-btn-normal" lay-on="deleteUser">保存</button>' + 
                '<button class="layui-btn layui-btn-danger" onclick="layer.closeAll();">取消</button>' +
                '</div>',
                area: '400px',
                success: function (layero, index) {
                    // 配置项
                    var userMonifyOptions = {
                        elem: '#transferContainer',
                        title: ['可添加的用户', '已添加的用户'],  // 穿梭框的标题
                        data: userListArr,  // 数据源
                        value: valuesArray,
                        width: 160,
                        height: 400,
                        showSearch: true,
                        id: 'userManage'
                    };
                    // 初始化transfer组件
                    transfer.render(userMonifyOptions);
                }
                });
                util.on('lay-on', {
                    deleteUser: function(othis){
                        var getData = transfer.getData('userManage');
                        let userJson = JSON.stringify(getData);
                        var htmlobj = $.ajax({
                            type: 'POST',
                            url: "?action=editCyberFurryCUP&page=panel&module=create_cyberfurry&csrf=" + csrf_token,
                            async:true,
                            data: {
                                id: id,
                                user: userJson
                            },
                            error: function() {
                                layer.msg("错误：" + htmlobj.responseText);
                                return;
                            },
                            success: function() {
                                vt.success(htmlobj.responseText, {
                                    position: "top-center",
                                });
                                layer.msg(htmlobj.responseText);
                                layer.closeAll();
                                layer.msg('操作成功，数据同步中...', {
                                  icon: 16,
                                  shade: 0.2,
                                  time: 500
                                }, function(){
                                  location.reload();
                                });
                                return;
                            }
                        });
                    },
                });
            }
        });
    }
    function setCyberFurryInfo(id) {
      var htmlobj = $.ajax({
        type: 'GET',
        url: "?page=panel&module=create_cyberfurry&getchar=" + id + "&csrf=" + csrf_token,
        async:true,
        error: function() {
          layer.msg("错误：" + htmlobj.responseText);
          return;
        },
        success: function() {
          try {
            var json = JSON.parse(htmlobj.responseText);
            charid = json.id;
            cfnickname = json.cfnickname;
            cfintro = json.cfintro;
            cfwelcome = json.cfwelcome;
            cfavatar = json.cfavatar;
            layer.closeAll(); // 关闭对话框
            layer.open({
            type: 1,
            title: '设置CyberFurry角色 ' + cfnickname + ' 的信息',
            area: '400px',
            content: '<form class="layui-form" style="padding: 20px;" lay-filter="cyberfurry-info">' +
                         '<div class="layui-form-item">' +
                         '<input type="text" name="id" placeholder="" autocomplete="off" class="layui-input" style="display: none;">' +
                           '<label class="layui-form-label">开场白</label>' +
                           '<div class="layui-input-block">' +
                             '<textarea name="cyberFurryWelcome" required lay-verify="required" placeholder="请输入角色的开场白（用户开始对话前，角色说的第一句话，100字以内）" autocomplete="off" class="layui-textarea"></textarea>' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">介绍</label>' +
                           '<div class="layui-input-block">' +
                             '<textarea name="cyberFurryIntro" required lay-verify="required" placeholder="请输入角色的描述，用于向用户介绍你的角色（800字以内）" autocomplete="off" class="layui-textarea"></textarea>' +
                           '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                           '<label class="layui-form-label">头像</label>' +
                           '<div class="layui-input-block">' +
                             '<button type="button" class="layui-btn" id="upload-avatar">' +
                                '<i class="layui-icon layui-icon-upload"></i> 上传头像' +
                             '</button>' +
                             '<div style="width: 132px;">' +
                                '<div class="layui-upload-list">' +
                                  '<img class="layui-upload-img" id="upload-avatar-img" style="width: 118.81px; height: 118.81px;">' +
                                  '<div style="display: none;"><input id="upload-avatar-base64" type="text" name="cyberFurryAvatar" required lay-verify="required" autocomplete="off" class="layui-input"></div>' +
                                '</div>' +
                             '</div>' +
                            '</div>' +
                         '</div>' +
                         '<div class="layui-form-item">' +
                              '<div style="text-align: center;">' +
                             '<button class="layui-btn layui-btn-normal" lay-submit lay-filter="submitForm">保存</button>' +
                             '<button type="button" class="layui-btn layui-btn-primary" onclick="layer.closeAll()">取消</button>' +
                          '</div>' +
                         '</div>' +
                       '</form>'
              });
              form.val('cyberfurry-info', {
                "id": charid,
                "cyberFurryWelcome": cfwelcome,
                "cyberFurryIntro": cfintro,
              });
              form.render();
              convertImageToBase64(cfavatar, function(base64String) {
                $('#upload-avatar-img').attr('src', base64String); // 图片链接（base64）
                $('#upload-avatar-base64').val(base64String);
              });
              upload.render({
                elem: '#upload-avatar',
                size: 8192,
                accept: 'images',
                auto: false,
                choose: function(obj){
                  obj.preview(function(index, file, result){
                    $('#upload-avatar-img').attr('src', result); // 图片链接（base64）
                    $('#upload-avatar-base64').val(result);
                  });
                },
              });
              // 监听表单提交
              form.on('submit(submitForm)', function(data){
                  // 提交表单后的操作
                  //console.log(data.field);
                  var htmlobj = $.ajax({
                      type: 'POST',
                      url: "?action=setCyberFurryInfo&page=panel&module=create_cyberfurry&csrf=" + csrf_token,
                      async:true,
                      data: data.field,
                      error: function() {
                          layer.msg("错误：" + htmlobj.responseText);
                          return;
                      },
                      success: function() {
                          vt.success(htmlobj.responseText, {
                              position: "top-center",
                          });
                          layer.closeAll(); // 关闭对话框
                          location.reload();
                          return;
                      }
                  });
                  return false; // 阻止表单默认提交
              });
        } catch(e) {
          alert("错误：无法解析服务器返回的数据");
        }
        return;
      }
    });
    }
    window.showCreateCyberFurryDialog = showCreateCyberFurryDialog;
    window.showCreateCyberFurryDialogAdvance = showCreateCyberFurryDialogAdvance;
    window.editCyberFurry = editCyberFurry;
    window.deleteCyberFurry = deleteCyberFurry;
    window.editUserPermission = editUserPermission;
    window.removeCyberFurryUser = editCyberFurryUser;
    window.setCyberFurryInfo = setCyberFurryInfo;
});

function convertImageToBase64(url, callback) {
  var xhr = new XMLHttpRequest();
  xhr.onload = function() {
    var reader = new FileReader();
    reader.onloadend = function() {
      callback(reader.result);
    };
    reader.readAsDataURL(xhr.response);
  };
  xhr.open('GET', url);
  xhr.responseType = 'blob';
  xhr.send();
}