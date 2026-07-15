<?php
/*

学员门禁管理模块
Ver 1.0.0.0 20260715
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));
if(!$rs || $rs['type'] !== 'admin') {
	exit("<script>location='/?page=panel&module=accesslog';</script>");
}

function learnerH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function learnerTime($timestamp) {
	$timestamp = intval($timestamp);
	return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '--';
}

$learnerData = [];
$learnerRs = Database::query('learner', 'SELECT * FROM `learner` ORDER BY `id` DESC', '', true);
if ($learnerRs && $learnerRs instanceof \mysqli_result) {
	while ($row = mysqli_fetch_assoc($learnerRs)) {
		$learnerData[] = $row;
	}
}

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, <?php echo learnerH($rs['username']); ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;">
					<h4 style="font-weight: 400">学员管理</h4><br>
					<button class="btn btn-default" onclick="openLearnerDialog()">添加学员</button>
					<table id="learner1" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="10" data-page-list="[5, 10, 20, 30, 50, 'All']" data-sortable="true" data-search="true" style="clear: both;margin-top: 20px;">
						<thead>
							<tr>
								<th>ID</th>
								<th>花名</th>
								<th>真实姓名</th>
								<th>学号</th>
								<th>手机号</th>
								<th>班级</th>
								<th>培养中心</th>
								<th>状态</th>
								<th>门禁卡号</th>
								<th>备注</th>
								<th>更新时间</th>
								<th>操作</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($learnerData as $learner) { ?>
								<?php
									$isEnabled = ($learner['status'] ?? '') === 'true';
									$statusText = $isEnabled ? '已启用' : '已禁用';
								?>
								<tr>
									<td><?php echo intval($learner['id']); ?></td>
									<td><?php echo learnerH($learner['name']); ?></td>
									<td><?php echo learnerH($learner['realname'] ?? ''); ?></td>
									<td><?php echo learnerH($learner['student_no']); ?></td>
									<td><?php echo learnerH($learner['mobile'] ?? ''); ?></td>
									<td><?php echo learnerH($learner['class_name'] ?? ''); ?></td>
									<td><?php echo learnerH($learner['training_center'] ?? ''); ?></td>
									<td><?php echo $statusText; ?></td>
									<td><?php echo learnerH($learner['card_id']); ?></td>
									<td><?php echo learnerH($learner['remark']); ?></td>
									<td><?php echo learnerH(learnerTime($learner['updated_at'])); ?></td>
									<td>
										<button class="btn btn-default" onclick="openLearnerDialog(<?php echo intval($learner['id']); ?>)">编辑</button>
										<button class="btn btn-default" onclick="openLearnerCardDialog(<?php echo intval($learner['id']); ?>)">发卡</button>
										<?php if (!empty($learner['card_id'])) { ?>
											<button class="btn btn-default" onclick="releaseLearnerCard('<?php echo learnerH($learner['card_id']); ?>')">回收</button>
										<?php } ?>
										<button class="btn btn-default" onclick="setLearnerStatus(<?php echo intval($learner['id']); ?>, '<?php echo $isEnabled ? 'false' : 'true'; ?>')"><?php echo $isEnabled ? '禁用' : '启用'; ?></button>
										<button class="btn btn-default" onclick="deleteLearner(<?php echo intval($learner['id']); ?>)">删除</button>
									</td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<script type="text/html" id="learnerDialogTpl">
	<div class="layui-form layui-form-pane" style="padding: 20px;">
		<input type="hidden" id="learner_id">
		<div class="layui-form-item">
			<label class="layui-form-label">花名</label>
			<div class="layui-input-block">
				<input type="text" id="learner_name" class="layui-input" placeholder="学员花名">
			</div>
		</div>
		<div class="layui-form-item">
			<label class="layui-form-label">真实姓名</label>
			<div class="layui-input-block">
				<input type="text" id="learner_realname" class="layui-input" placeholder="必填">
			</div>
		</div>
		<div class="layui-form-item">
			<label class="layui-form-label">学号</label>
			<div class="layui-input-block">
				<input type="text" id="learner_student_no" class="layui-input" placeholder="唯一学号">
			</div>
		</div>
		<div class="layui-form-item">
			<label class="layui-form-label">手机号</label>
			<div class="layui-input-block">
				<input type="text" id="learner_mobile" class="layui-input" placeholder="选填">
			</div>
		</div>
		<div class="layui-form-item">
			<label class="layui-form-label">班级</label>
			<div class="layui-input-block">
				<input type="text" id="learner_class_name" class="layui-input" placeholder="选填">
			</div>
		</div>
		<div class="layui-form-item">
			<label class="layui-form-label">培养中心</label>
			<div class="layui-input-block">
				<input type="text" id="learner_training_center" class="layui-input" placeholder="选填">
			</div>
		</div>
		<div class="layui-form-item">
			<label class="layui-form-label">备注</label>
			<div class="layui-input-block">
				<input type="text" id="learner_remark" class="layui-input" placeholder="可留空">
			</div>
		</div>
		<div class="layui-form-item">
			<div class="layui-input-block">
				<button class="layui-btn" type="button" onclick="saveLearner()">保存</button>
				<button class="layui-btn layui-btn-primary" type="button" onclick="layui.layer.closeAll()">取消</button>
			</div>
		</div>
	</div>
</script>

<script type="text/html" id="learnerCardDialogTpl">
	<div class="layui-form layui-form-pane" style="padding: 20px;">
		<input type="hidden" id="learner_card_id">
		<div class="layui-form-item">
			<label class="layui-form-label">电子工牌ID</label>
			<div class="layui-input-block">
				<input type="text" id="learner_cardnum" class="layui-input js-card-id-input" placeholder="选中输入框 连接读卡器读取工牌" autocomplete="off">
				<div class="card-input-hint"></div>
			</div>
		</div>
		<div class="layui-form-item">
			<div class="layui-input-block card-dialog-actions">
				<button class="layui-btn" type="button" onclick="submitLearnerCard()">保存</button>
			</div>
		</div>
	</div>
</script>

<script src="asset/layui/layui.js"></script>
<style>
	.card-input-hint {
		display: none;
		margin-top: 8px;
		color: #FF5722;
		line-height: 1.4;
	}
	.card-dialog-actions {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
	}
	.card-dialog-actions .layui-btn {
		margin-left: 0;
		margin-right: 0;
	}
</style>
<script>
var csrf_token = "<?php echo $_SESSION['token']; ?>";
var learnerData = <?php echo json_encode($learnerData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

layui.use(['layer', 'form'], function(){
	var layer = layui.layer;
	var form = layui.form;

	function findLearner(id) {
		for (var i = 0; i < learnerData.length; i++) {
			if (String(learnerData[i].id) === String(id)) {
				return learnerData[i];
			}
		}
		return null;
	}

	function normalizeCardInput(value) {
		return String(value || '').replace(/[\r\n]/g, '').trim();
	}

	function showCardInputHint($input, message) {
		$input.closest('.layui-input-block').find('.card-input-hint').text(message).show();
	}

	function clearCardInputHint($input) {
		$input.closest('.layui-input-block').find('.card-input-hint').hide().text('');
	}

	function validateCardInput($input) {
		var cardnum = normalizeCardInput($input.val());
		if (!/^[0-9]{10}$/.test(cardnum)) {
			$input.val('');
			showCardInputHint($input, '工牌ID必须是10位数字，已忽略本次输入');
			return '';
		}
		$input.val(cardnum);
		clearCardInputHint($input);
		return cardnum;
	}

	function bindCardIdInput(selector, submitHandler) {
		var $input = $(selector);
		$input.off('.cardReader');
		$input.on('input.cardReader', function() {
			if ($(this).val() !== '') {
				clearCardInputHint($(this));
			}
		});
		$input.on('keydown.cardReader', function(event) {
			if (event.key === 'Enter' || event.keyCode === 13) {
				event.preventDefault();
				if (validateCardInput($(this)) !== '') {
					submitHandler();
				}
			}
		});
		setTimeout(function() { $input.trigger('focus'); }, 50);
	}

	function notifySuccess(message) {
		if (window.vt && vt.success) {
			vt.success(message, {position: 'top-center'});
			return;
		}
		layer.msg(message);
	}

	function notifyError(message) {
		if (window.vt && vt.error) {
			vt.error(message, {position: 'top-center'});
			return;
		}
		layer.msg(message);
	}

	window.openLearnerDialog = function(id) {
		var learner = id ? findLearner(id) : null;
		layer.open({
			type: 1,
			title: id ? '编辑学员' : '添加学员',
			content: $('#learnerDialogTpl').html(),
			area: ['440px', '560px'],
			success: function() {
				$('#learner_id').val(learner ? learner.id : '');
				$('#learner_name').val(learner ? learner.name : '');
				$('#learner_realname').val(learner ? (learner.realname || '') : '');
				$('#learner_student_no').val(learner ? learner.student_no : '');
				$('#learner_mobile').val(learner ? (learner.mobile || '') : '');
				$('#learner_class_name').val(learner ? (learner.class_name || '') : '');
				$('#learner_training_center').val(learner ? (learner.training_center || '') : '');
				$('#learner_remark').val(learner ? learner.remark : '');
				form.render();
			}
		});
	};

	window.saveLearner = function() {
		var htmlobj = $.ajax({
			type: 'POST',
			url: '?action=saveLearner&page=panel&module=learner&csrf=' + csrf_token,
			data: {
				id: $('#learner_id').val(),
				name: $('#learner_name').val(),
				realname: $('#learner_realname').val(),
				student_no: $('#learner_student_no').val(),
				mobile: $('#learner_mobile').val(),
				class_name: $('#learner_class_name').val(),
				training_center: $('#learner_training_center').val(),
				remark: $('#learner_remark').val()
			},
			success: function(resp) {
				notifySuccess(resp);
				setTimeout(function(){ location.reload(); }, 600);
			},
			error: function() {
				notifyError('错误：' + htmlobj.responseText);
			}
		});
	};

	window.setLearnerStatus = function(id, status) {
		var learner = findLearner(id);
		var actionText = status === 'true' ? '启用' : '禁用';
		layer.confirm('确认' + actionText + '学员 ' + (learner ? learner.name : id) + ' 的门禁通行权限？', {icon: 3, title: '确定吗？'}, function(index) {
			layer.close(index);
			var htmlobj = $.ajax({
				type: 'POST',
				url: '?action=setLearnerStatus&page=panel&module=learner&csrf=' + csrf_token,
				data: {id: id, status: status},
				success: function(resp) {
					notifySuccess(resp);
					setTimeout(function(){ location.reload(); }, 600);
				},
				error: function() {
					notifyError('错误：' + htmlobj.responseText);
				}
			});
		});
	};

	window.deleteLearner = function(id) {
		var learner = findLearner(id);
		layer.confirm('确认删除学员 ' + (learner ? learner.name : id) + '？删除后会同步清理角色和通行策略中的该学员。', {icon: 3, title: '删除学员'}, function(index) {
			layer.close(index);
			var htmlobj = $.ajax({
				type: 'POST',
				url: '?action=deleteLearner&page=panel&module=learner&csrf=' + csrf_token,
				data: {id: id},
				success: function(resp) {
					notifySuccess(resp);
					setTimeout(function(){ location.reload(); }, 600);
				},
				error: function() {
					notifyError('错误：' + htmlobj.responseText);
				}
			});
		});
	};

	window.openLearnerCardDialog = function(id) {
		var learner = findLearner(id);
		layer.open({
			type: 1,
			title: '为学员 ' + (learner ? learner.name : id) + ' 发卡',
			content: $('#learnerCardDialogTpl').html(),
			area: ['420px', '240px'],
			success: function() {
				$('#learner_card_id').val(id);
				bindCardIdInput('#learner_cardnum', submitLearnerCard);
			}
		});
	};

	window.submitLearnerCard = function() {
		var cardnum = validateCardInput($('#learner_cardnum'));
		if (cardnum === '') {
			return;
		}
		var htmlobj = $.ajax({
			type: 'POST',
			url: '?action=submitcard&page=panel&module=learner&csrf=' + csrf_token,
			data: {
				id: $('#learner_card_id').val(),
				type: 'learner',
				cardid: cardnum
			},
			success: function(resp) {
				notifySuccess(resp);
				setTimeout(function(){ location.reload(); }, 600);
			},
			error: function() {
				notifyError('错误：' + htmlobj.responseText);
			}
		});
	};

	window.releaseLearnerCard = function(cardId) {
		layer.confirm('确认回收工牌 ' + cardId + '？', {icon: 3, title: '回收工牌'}, function(index) {
			layer.close(index);
			var htmlobj = $.ajax({
				type: 'POST',
				url: '?action=releasecard&page=panel&module=learner&csrf=' + csrf_token,
				data: {cardid: cardId},
				success: function(resp) {
					notifySuccess(resp);
					setTimeout(function(){ location.reload(); }, 600);
				},
				error: function() {
					notifyError('错误：' + htmlobj.responseText);
				}
			});
		});
	};
});
</script>
