# doorlock210
基于CyberFurry用户中心魔改的公司员工工牌刷卡系统

**主分支代码已由Codex介入修改，非AI版本参见v1分支**

PHP开发，工牌加密由门禁系统硬件实现，主要是防手机复制。
- 对接深圳凯帕斯的读卡门禁系统，实现门禁主机心跳、远控。
- 对接飞书，实现员工入离职门权限全局开关，同时推送考勤数据。
- 对接公司OA实现考勤验证数据推送。

特色功能
- 支持角色管理
- 支持访客、学生等角色配置
- 学员支持文件导入，员工支持飞书推送
- 手机碰一碰工牌可实现快捷菜单，工牌NFC跳转链接入口https://系统部署地址/?page=openfeishu&cardid=16进制UID

部署说明
- 参见config.php.example

## License

This project is licensed under the PolyForm Noncommercial License 1.0.0.

Commercial use is not permitted without prior written authorization.
For commercial licensing, please contact: jason_han@wingmark.cn
