<?php
/*

考勤管理页面模块
Ver 1.0.0.0 20260728
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));
if(!$rs || !in_array($rs['type'] ?? '', ['admin', 'readonly'], true)) {
	exit("<script>location='/?page=login';</script>");
}
$isAdmin = ($rs['type'] ?? '') === 'admin';

function attendanceH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function attendanceDateParam($key, $default) {
	$value = trim((string)($_GET[$key] ?? ''));
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || strtotime($value) === false) {
		return $default;
	}
	return date('Y-m-d', strtotime($value));
}

function attendanceKeywordParam() {
	$value = trim((string)($_GET['q'] ?? ''));
	if ($value === '') {
		return '';
	}
	if (preg_match_all('/./u', $value, $matches) !== false) {
		return implode('', array_slice($matches[0], 0, 80));
	}
	return substr($value, 0, 80);
}

function attendanceStatusParam() {
	$status = trim((string)($_GET['status'] ?? 'all'));
	return in_array($status, ['all', 'normal', 'late', 'absent', 'missing_checkout'], true) ? $status : 'all';
}

function attendanceFetchRows($table, $sql) {
	$rs = Database::query($table, $sql, '', true);
	$rows = [];
	if ($rs instanceof \mysqli_result) {
		while ($row = mysqli_fetch_assoc($rs)) {
			$rows[] = $row;
		}
		mysqli_free_result($rs);
	}
	return $rows;
}

function attendanceText($value, $empty = '-') {
	$value = trim((string)$value);
	return $value !== '' ? $value : $empty;
}

function attendanceTimeText($timestamp) {
	$timestamp = intval($timestamp);
	return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '-';
}

function attendanceShortTime($timestamp) {
	$timestamp = intval($timestamp);
	return $timestamp > 0 ? date('H:i:s', $timestamp) : '-';
}

function attendanceNumber($value) {
	return number_format(intval($value));
}

$today = date('Y-m-d');
$dateFrom = attendanceDateParam('date_from', $today);
$dateTo = attendanceDateParam('date_to', $dateFrom);
if (strtotime($dateFrom) > strtotime($dateTo)) {
	$tmp = $dateFrom;
	$dateFrom = $dateTo;
	$dateTo = $tmp;
}
$status = attendanceStatusParam();
$keyword = attendanceKeywordParam();
$pageNo = max(1, intval($_GET['p'] ?? 1));
$pageSize = 50;
$filters = [
	'date_from' => $dateFrom,
	'date_to' => $dateTo,
	'status' => $status,
	'keyword' => $keyword
];
$summary = class_exists(__NAMESPACE__ . '\\AttendanceModuleService') ? AttendanceModuleService::reportSummary($filters) : [];
$total = class_exists(__NAMESPACE__ . '\\AttendanceModuleService') ? AttendanceModuleService::reportCount($filters) : 0;
$rows = class_exists(__NAMESPACE__ . '\\AttendanceModuleService') ? AttendanceModuleService::reportRows($filters, $pageSize, ($pageNo - 1) * $pageSize) : [];
$worker = class_exists(__NAMESPACE__ . '\\AttendanceModuleService') ? AttendanceModuleService::workerStatus() : [];
$groups = attendanceFetchRows('attendance_groups', "SELECT * FROM `attendance_groups` ORDER BY `enabled` DESC, `source` DESC, `name` ASC LIMIT 30");
$jobs = attendanceFetchRows('attendance_sync_jobs', "SELECT * FROM `attendance_sync_jobs` ORDER BY `id` DESC LIMIT 10");
$lastFull = intval(Settings::get('attendance_last_full_sync_at', '0'));
$lastIncremental = intval(Settings::get('attendance_last_incremental_at', '0'));
$lastGroup = intval(Settings::get('attendance_last_group_sync_at', '0'));
$totalPages = max(1, intval(ceil(max(0, $total) / $pageSize)));
?>
<style>
	.attendance-wrap {
		color: #1f2937;
	}
	.attendance-head {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 16px;
		margin-bottom: 18px;
	}
	.attendance-subtitle {
		color: #667085;
		margin-top: 8px;
		line-height: 1.7;
	}
	.attendance-panel {
		background: #fff;
		border: 1px solid #e6e9ef;
		border-radius: 8px;
		padding: 16px;
		margin-bottom: 16px;
		box-shadow: 0 8px 24px rgba(31, 41, 55, .05);
	}
	.attendance-grid {
		display: grid;
		grid-template-columns: repeat(4, minmax(150px, 1fr));
		gap: 12px;
		margin-bottom: 16px;
	}
	.attendance-card {
		border: 1px solid #e6e9ef;
		border-radius: 8px;
		padding: 14px;
		background: #fafbfc;
	}
	.attendance-card span {
		color: #667085;
	}
	.attendance-card strong {
		display: block;
		margin-top: 6px;
		font-size: 26px;
		color: #111827;
	}
	.attendance-toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 10px;
	}
	.attendance-toolbar .form-control {
		min-width: 140px;
	}
	.attendance-table {
		width: 100%;
		white-space: nowrap;
	}
	.attendance-table th {
		color: #667085;
		font-weight: 500;
	}
	.attendance-status {
		display: inline-block;
		min-width: 46px;
		padding: 3px 8px;
		border-radius: 999px;
		text-align: center;
		font-size: 12px;
	}
	.attendance-status.normal {
		background: #ecfdf3;
		color: #027a48;
	}
	.attendance-status.late,
	.attendance-status.missing_checkout {
		background: #fff7ed;
		color: #b54708;
	}
	.attendance-status.absent {
		background: #fef3f2;
		color: #b42318;
	}
	.attendance-section-grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 16px;
	}
	.attendance-empty {
		text-align: center;
		color: #98a2b3;
		padding: 18px 0;
	}
	.attendance-trace-wrap {
		padding: 16px;
		overflow-x: auto;
	}
	.attendance-trace-wrap table {
		width: 100%;
		white-space: nowrap;
	}
	@media screen and (max-width: 900px) {
		.attendance-head,
		.attendance-section-grid {
			display: block;
		}
		.attendance-grid {
			grid-template-columns: 1fr 1fr;
		}
		.attendance-toolbar .form-group,
		.attendance-toolbar .form-control,
		.attendance-toolbar .btn {
			width: 100%;
		}
	}
	@media screen and (max-width: 560px) {
		.attendance-grid {
			grid-template-columns: 1fr;
		}
	}
</style>
<div class="page-title">
	<h3 class="breadcrumb-header">考勤管理</h3>
</div>
<div id="main-wrapper" class="attendance-wrap">
	<div class="attendance-head">
		<div>
			<h3 style="margin:0;font-weight:600;">有效考勤日报</h3>
			<div class="attendance-subtitle">
				模块：<?php echo Settings::getBool('attendance_module_enabled') ? '已启用' : '未启用'; ?>，
				最后全量：<?php echo $lastFull > 0 ? date('Y-m-d H:i:s', $lastFull) : '-'; ?>，
				最后增量：<?php echo $lastIncremental > 0 ? date('Y-m-d H:i:s', $lastIncremental) : '-'; ?>，
				考勤组同步：<?php echo $lastGroup > 0 ? date('Y-m-d H:i:s', $lastGroup) : '-'; ?>
			</div>
		</div>
		<div class="attendance-subtitle">数据来源：飞书假勤流水 + 本地工牌刷卡</div>
	</div>

	<div class="attendance-grid">
		<div class="attendance-card"><span>日报人数</span><strong><?php echo attendanceNumber($summary['total'] ?? 0); ?></strong></div>
		<div class="attendance-card"><span>正常</span><strong><?php echo attendanceNumber($summary['normal_total'] ?? 0); ?></strong></div>
		<div class="attendance-card"><span>迟到</span><strong><?php echo attendanceNumber($summary['late_total'] ?? 0); ?></strong></div>
		<div class="attendance-card"><span>有效考勤</span><strong><?php echo attendanceNumber($summary['effective_total'] ?? 0); ?></strong></div>
	</div>

	<div class="attendance-panel">
		<form class="form-inline attendance-toolbar" method="get" action="/">
			<input type="hidden" name="page" value="panel">
			<input type="hidden" name="module" value="attendance">
			<div class="form-group"><input type="date" class="form-control" name="date_from" value="<?php echo attendanceH($dateFrom); ?>"></div>
			<div class="form-group"><input type="date" class="form-control" name="date_to" value="<?php echo attendanceH($dateTo); ?>"></div>
			<div class="form-group">
				<select class="form-control" name="status">
					<option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>全部状态</option>
					<option value="normal" <?php echo $status === 'normal' ? 'selected' : ''; ?>>正常</option>
					<option value="late" <?php echo $status === 'late' ? 'selected' : ''; ?>>迟到</option>
					<option value="absent" <?php echo $status === 'absent' ? 'selected' : ''; ?>>缺勤</option>
					<option value="missing_checkout" <?php echo $status === 'missing_checkout' ? 'selected' : ''; ?>>缺少下班</option>
				</select>
			</div>
			<div class="form-group"><input type="text" class="form-control" name="q" value="<?php echo attendanceH($keyword); ?>" placeholder="搜索花名/工号/考勤组"></div>
			<button class="btn btn-primary" type="submit">查询</button>
			<button class="btn btn-default" type="button" onclick="submitAttendanceExport()">导出Excel</button>
			<?php if ($isAdmin) { ?>
				<button class="btn btn-warning" type="button" onclick="syncAttendanceFlows()">同步流水</button>
				<button class="btn btn-default" type="button" onclick="syncAttendanceGroups()">同步考勤组</button>
			<?php } ?>
		</form>
		<form method="post" target="_blank" id="attendanceExportForm" action="/?action=exportAttendanceReports&page=panel&module=attendance&csrf=<?php echo attendanceH($_SESSION['token']); ?>" style="display:none;">
			<input type="hidden" name="date_from" value="<?php echo attendanceH($dateFrom); ?>">
			<input type="hidden" name="date_to" value="<?php echo attendanceH($dateTo); ?>">
			<input type="hidden" name="status" value="<?php echo attendanceH($status); ?>">
			<input type="hidden" name="q" value="<?php echo attendanceH($keyword); ?>">
		</form>
	</div>

	<div class="attendance-panel" style="overflow-x:auto;">
		<table class="table table-bordered attendance-table">
			<thead>
				<tr>
					<th>日期</th>
					<th>花名</th>
					<th>工号</th>
					<th>考勤组</th>
					<th>应上班</th>
					<th>首次有效</th>
					<th>最后有效</th>
					<th>有效次数</th>
					<th>状态</th>
					<th>迟到分钟</th>
					<th>操作</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row) { $rowStatus = $row['status'] ?? ''; ?>
				<tr>
					<td><?php echo attendanceH($row['work_date'] ?? '-'); ?></td>
					<td><?php echo attendanceH(attendanceText($row['employee_name'] ?? '')); ?></td>
					<td><?php echo attendanceH(attendanceText($row['employee_no'] ?? '')); ?></td>
					<td><?php echo attendanceH(attendanceText($row['group_name'] ?? '')); ?></td>
					<td><?php echo attendanceH(attendanceShortTime($row['scheduled_start'] ?? 0)); ?></td>
					<td><?php echo attendanceH(attendanceShortTime($row['first_effective_at'] ?? 0)); ?></td>
					<td><?php echo attendanceH(attendanceShortTime($row['last_effective_at'] ?? 0)); ?></td>
					<td><?php echo attendanceNumber($row['effective_count'] ?? 0); ?></td>
					<td><span class="attendance-status <?php echo attendanceH($rowStatus); ?>"><?php echo attendanceH(AttendanceModuleService::statusText($rowStatus)); ?></span></td>
					<td><?php echo attendanceNumber($row['late_minutes'] ?? 0); ?></td>
					<td><button type="button" class="btn btn-xs btn-default" onclick="showAttendanceTrace(<?php echo intval($row['id']); ?>)">溯源</button></td>
				</tr>
				<?php } ?>
				<?php if (count($rows) === 0) { ?>
				<tr><td colspan="11"><div class="attendance-empty">没有符合条件的考勤日报</div></td></tr>
				<?php } ?>
			</tbody>
		</table>
		<div class="attendance-subtitle">第 <?php echo intval($pageNo); ?> / <?php echo intval($totalPages); ?> 页，共 <?php echo attendanceNumber($total); ?> 条</div>
		<div>
			<?php if ($pageNo > 1) { ?><a class="btn btn-default btn-sm" href="/?page=panel&module=attendance&date_from=<?php echo attendanceH($dateFrom); ?>&date_to=<?php echo attendanceH($dateTo); ?>&status=<?php echo attendanceH($status); ?>&q=<?php echo urlencode($keyword); ?>&p=<?php echo $pageNo - 1; ?>">上一页</a><?php } ?>
			<?php if ($pageNo < $totalPages) { ?><a class="btn btn-default btn-sm" href="/?page=panel&module=attendance&date_from=<?php echo attendanceH($dateFrom); ?>&date_to=<?php echo attendanceH($dateTo); ?>&status=<?php echo attendanceH($status); ?>&q=<?php echo urlencode($keyword); ?>&p=<?php echo $pageNo + 1; ?>">下一页</a><?php } ?>
		</div>
	</div>

	<div class="attendance-section-grid">
		<div class="attendance-panel">
			<h4 style="margin-top:0;">飞书考勤组</h4>
			<table class="table table-bordered attendance-table">
				<thead><tr><th>名称</th><th>来源</th><th>时间</th><th>成员</th><th>状态</th></tr></thead>
				<tbody>
					<?php foreach ($groups as $group) { ?>
					<tr>
						<td><?php echo attendanceH(attendanceText($group['name'] ?? '')); ?></td>
						<td><?php echo attendanceH(attendanceText($group['source'] ?? '')); ?></td>
						<td><?php echo attendanceH(($group['start_time'] ?? '-') . ' - ' . ($group['end_time'] ?? '-')); ?></td>
						<td><?php echo attendanceNumber($group['member_count'] ?? 0); ?></td>
						<td><?php echo intval($group['enabled'] ?? 0) === 1 ? '启用' : '停用'; ?></td>
					</tr>
					<?php } ?>
					<?php if (count($groups) === 0) { ?><tr><td colspan="5"><div class="attendance-empty">暂无考勤组，请先同步飞书考勤组</div></td></tr><?php } ?>
				</tbody>
			</table>
		</div>
		<div class="attendance-panel">
			<h4 style="margin-top:0;">任务状态</h4>
			<div class="attendance-subtitle">Worker：<?php echo !empty($worker['worker_running']) ? '运行中' : '空闲'; ?>，待执行 <?php echo attendanceNumber($worker['pending'] ?? 0); ?>，失败待重试 <?php echo attendanceNumber($worker['failed'] ?? 0); ?></div>
			<table class="table table-bordered attendance-table">
				<thead><tr><th>ID</th><th>类型</th><th>状态</th><th>进度</th><th>信息</th></tr></thead>
				<tbody>
					<?php foreach ($jobs as $job) { ?>
					<tr>
						<td><?php echo intval($job['id']); ?></td>
						<td><?php echo attendanceH($job['job_type'] ?? ''); ?></td>
						<td><?php echo attendanceH($job['status'] ?? ''); ?></td>
						<td><?php echo attendanceNumber($job['processed_count'] ?? 0); ?> / <?php echo attendanceNumber($job['total_count'] ?? 0); ?></td>
						<td><?php echo attendanceH(attendanceText($job['message'] ?? '')); ?></td>
					</tr>
					<?php } ?>
					<?php if (count($jobs) === 0) { ?><tr><td colspan="5"><div class="attendance-empty">暂无任务</div></td></tr><?php } ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<script src="asset/layui/layui.js"></script>
<script>
var attendanceCsrf = "<?php echo attendanceH($_SESSION['token']); ?>";
function submitAttendanceExport() {
	document.getElementById('attendanceExportForm').submit();
}
function syncAttendanceFlows() {
	layui.use('layer', function() {
		$.ajax({
			type: 'POST',
			url: '?action=syncAttendanceFlows&page=panel&module=attendance&csrf=' + attendanceCsrf,
			dataType: 'json',
			data: {date_from: '<?php echo attendanceH($dateFrom); ?>', date_to: '<?php echo attendanceH($dateTo); ?>'},
			success: function(resp) {
				layui.layer.msg(resp.message || '已提交同步任务');
				setTimeout(function(){ location.reload(); }, 800);
			},
			error: function(xhr) {
				layui.layer.msg('提交失败：' + xhr.responseText);
			}
		});
	});
}
function syncAttendanceGroups() {
	layui.use('layer', function() {
		$.ajax({
			type: 'POST',
			url: '?action=syncAttendanceGroups&page=panel&module=attendance&csrf=' + attendanceCsrf,
			dataType: 'json',
			success: function(resp) {
				layui.layer.msg(resp.message || '已提交考勤组同步');
				setTimeout(function(){ location.reload(); }, 800);
			},
			error: function(xhr) {
				layui.layer.msg('提交失败：' + xhr.responseText);
			}
		});
	});
}
function showAttendanceTrace(reportId) {
	layui.use('layer', function() {
		$.ajax({
			type: 'POST',
			url: '?action=attendanceTrace&page=panel&module=attendance&csrf=' + attendanceCsrf,
			dataType: 'json',
			data: {report_id: reportId},
			success: function(resp) {
				var data = resp.data || {};
				var sources = data.sources || [];
				var effective = data.effective || [];
				var html = '<div class="attendance-trace-wrap">';
				html += '<h4>有效考勤</h4><table class="table table-bordered"><thead><tr><th>时间</th><th>工牌时间</th><th>人脸时间</th><th>间隔秒</th><th>状态</th></tr></thead><tbody>';
				if (!effective.length) {
					html += '<tr><td colspan="5">-</td></tr>';
				}
				effective.forEach(function(row) {
					html += '<tr><td>' + fmtTime(row.effective_time) + '</td><td>' + fmtTime(row.badge_time) + '</td><td>' + fmtTime(row.face_time) + '</td><td>' + esc(row.interval_seconds || '0') + '</td><td>' + esc(row.status || '-') + '</td></tr>';
				});
				html += '</tbody></table><h4>源流水</h4><table class="table table-bordered"><thead><tr><th>ID</th><th>来源</th><th>类型</th><th>时间</th><th>点位</th><th>外部ID</th></tr></thead><tbody>';
				if (!sources.length) {
					html += '<tr><td colspan="6">-</td></tr>';
				}
				sources.forEach(function(row) {
					html += '<tr><td>' + esc(row.id) + '</td><td>' + esc(row.source) + '</td><td>' + esc(row.source_kind) + '</td><td>' + fmtTime(row.punch_time) + '</td><td>' + esc(row.location_name || '-') + '</td><td>' + esc(row.external_id || '-') + '</td></tr>';
				});
				html += '</tbody></table></div>';
				layui.layer.open({type: 1, title: '考勤溯源', area: ['900px', '70vh'], content: html});
			},
			error: function(xhr) {
				layui.layer.msg('读取失败：' + xhr.responseText);
			}
		});
	});
}
function fmtTime(value) {
	var ts = parseInt(value || 0, 10);
	if (!ts) return '-';
	var d = new Date(ts * 1000);
	var pad = function(n){ return n < 10 ? '0' + n : '' + n; };
	return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}
function esc(value) {
	return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) {
		return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
	});
}
</script>
