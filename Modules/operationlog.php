<?php
/*

后台操作日志查询模块
Ver 1.0.0.0 20260717
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));
if(!$rs || !in_array($rs['type'] ?? '', ['admin', 'readonly'], true)) {
	exit("<script>location='/?page=login';</script>");
}

function operationLogH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$keyword = trim((string)($_GET['q'] ?? ''));
$where = [];
if ($keyword !== '') {
	if (preg_match_all('/./u', $keyword, $matches) !== false) {
		$keyword = implode('', array_slice($matches[0], 0, 80));
	} else {
		$keyword = substr($keyword, 0, 80);
	}
	$safeKeyword = Database::escape($keyword);
	$like = "'%{$safeKeyword}%'";
	$where[] = "(`username` LIKE {$like} OR `display_name` LIKE {$like} OR `action_name` LIKE {$like} OR `target_name` LIKE {$like} OR `target_id` LIKE {$like} OR `detail` LIKE {$like} OR `ip` LIKE {$like})";
}

$whereSql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT * FROM `operation_logs`{$whereSql} ORDER BY `created_at` DESC, `id` DESC LIMIT 1000";
$logData = Database::query("operation_logs", $sql, '', true);
$rows = [];
if ($logData instanceof \mysqli_result) {
	while ($row = mysqli_fetch_assoc($logData)) {
		$rows[] = $row;
	}
	mysqli_free_result($logData);
}

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, <?php echo operationLogH($rs['username']); ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;">
					<h4 style="font-weight: 400">操作日志</h4><br>
					<h6>记录管理员和只读管理员在后台的页面访问、查询与提交类操作，最多展示最近 1000 条</h6><br />
					<form class="form-inline" method="GET" action="/" style="margin-bottom: 16px;">
						<input type="hidden" name="page" value="panel">
						<input type="hidden" name="module" value="operationlog">
						<div class="form-group">
							<input type="text" class="form-control" name="q" value="<?php echo operationLogH($keyword); ?>" placeholder="搜索操作者、动作、对象、IP">
						</div>
						<button type="submit" class="btn btn-default">搜索</button>
						<?php if ($keyword !== '') { ?><a class="btn btn-default" href="/?page=panel&module=operationlog">清空</a><?php } ?>
					</form>

					<table id="operationLogs" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="20" data-page-list="[10, 20, 50, 100, 'All']" data-sortable="true" data-search="true" style="clear: both;margin-top: 20px;">
						<thead>
							<tr>
								<th>时间</th>
								<th>操作者</th>
								<th>权限组</th>
								<th>模块</th>
								<th>操作</th>
								<th>对象</th>
								<th>详情</th>
								<th>状态</th>
								<th>IP</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($rows as $log) { ?>
								<tr>
									<td><?php echo operationLogH(intval($log['created_at'] ?? 0) > 0 ? date('Y-m-d H:i:s', intval($log['created_at'])) : '-'); ?></td>
									<td><?php echo operationLogH(($log['display_name'] ?? '') ?: ($log['username'] ?? '')); ?></td>
									<td><?php echo operationLogH(OperationLog::roleLabel($log['role'] ?? '')); ?></td>
									<td><?php echo operationLogH(OperationLog::moduleLabel($log['module'] ?? '')); ?></td>
									<td><?php echo operationLogH($log['action_name'] ?? ''); ?></td>
									<td><?php echo operationLogH(($log['target_name'] ?? '') ?: ($log['target_id'] ?? '')); ?></td>
									<td><?php echo operationLogH($log['detail'] ?? ''); ?></td>
									<td><?php echo operationLogH($log['status_code'] ?? ''); ?></td>
									<td><?php echo operationLogH($log['ip'] ?? ''); ?></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>
