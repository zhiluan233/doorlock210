<?php
/*

门禁数据大屏模块
Ver 1.0.0.0 20260727
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));
if(!$rs || !in_array($rs['type'] ?? '', ['admin', 'readonly'], true)) {
	exit("<script>location='/?page=login';</script>");
}

function dashboardH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dashboardDateParam($key, $default) {
	$value = trim((string)($_GET[$key] ?? ''));
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		return $default;
	}
	$time = strtotime($value);
	if ($time === false) {
		return $default;
	}
	return date('Y-m-d', $time);
}

function dashboardKeyword() {
	$keyword = trim((string)($_GET['q'] ?? ''));
	if ($keyword === '') {
		return '';
	}
	if (preg_match_all('/./u', $keyword, $matches) !== false) {
		return implode('', array_slice($matches[0], 0, 80));
	}
	return substr($keyword, 0, 80);
}

function dashboardActionType() {
	$type = trim((string)($_GET['action_type'] ?? 'all'));
	return in_array($type, ['all', 'success', 'failed'], true) ? $type : 'all';
}

function dashboardFetchRows($table, $sql) {
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

function dashboardFetchOne($table, $sql) {
	$rows = dashboardFetchRows($table, $sql);
	return count($rows) > 0 ? $rows[0] : [];
}

function dashboardWhereSql($startTs, $endTs, $keyword, $actionType) {
	$where = [
		"`time` BETWEEN " . intval($startTs) . " AND " . intval($endTs),
		"IFNULL(`passdoor`, '')<>''",
		"IFNULL(`cardid`, '')<>''",
		"`passdoor`<>'系统'"
	];
	if ($keyword !== '') {
		$safeKeyword = Database::escape($keyword);
		$like = "'%{$safeKeyword}%'";
		$where[] = "(`passusername` LIKE {$like} OR `passusertype` LIKE {$like} OR `passdoor` LIKE {$like} OR `cardid` LIKE {$like} OR `action` LIKE {$like})";
	}
	if ($actionType === 'success') {
		$where[] = "`action` LIKE '%成功%'";
	} elseif ($actionType === 'failed') {
		$where[] = "`action` LIKE '%失败%'";
	}
	return ' WHERE ' . implode(' AND ', $where);
}

function dashboardNumber($value) {
	return number_format(intval($value));
}

function dashboardPercent($value, $total) {
	$total = intval($total);
	if ($total <= 0) {
		return '0.0%';
	}
	return number_format((intval($value) / $total) * 100, 1) . '%';
}

function dashboardHourText($hour) {
	return str_pad((string)intval($hour), 2, '0', STR_PAD_LEFT) . ':00';
}

function dashboardActionTypeText($type) {
	if ($type === 'success') { return '仅成功'; }
	if ($type === 'failed') { return '仅失败'; }
	return '全部';
}

$today = date('Y-m-d');
$startDate = dashboardDateParam('start_date', $today);
$endDate = dashboardDateParam('end_date', $today);
if (strtotime($startDate) > strtotime($endDate)) {
	$tmp = $startDate;
	$startDate = $endDate;
	$endDate = $tmp;
}
$startTs = strtotime($startDate . ' 00:00:00');
$endTs = strtotime($endDate . ' 23:59:59');
$keyword = dashboardKeyword();
$actionType = dashboardActionType();
$whereSql = dashboardWhereSql($startTs, $endTs, $keyword, $actionType);

$summary = dashboardFetchOne('logs', "SELECT
	COUNT(*) AS `total`,
	COUNT(DISTINCT CONCAT(IFNULL(`passusertype`, ''), '|', IFNULL(`passusername`, ''), '|', IFNULL(`cardid`, ''))) AS `people`,
	SUM(CASE WHEN `action` LIKE '%成功%' THEN 1 ELSE 0 END) AS `success_total`,
	SUM(CASE WHEN `action` LIKE '%失败%' THEN 1 ELSE 0 END) AS `failed_total`,
	COUNT(DISTINCT CASE WHEN `passusertype`='员工' AND `action` LIKE '%成功%' THEN CONCAT(IFNULL(`passusername`, ''), '|', IFNULL(`cardid`, '')) ELSE NULL END) AS `employee_present`
	FROM `logs`{$whereSql}");
$activeEmployee = dashboardFetchOne('employee', "SELECT COUNT(*) AS `total` FROM `employee` WHERE `status`='true'");
$activeEmployeeTotal = intval($activeEmployee['total'] ?? 0);

$typeRows = dashboardFetchRows('logs', "SELECT IFNULL(NULLIF(`passusertype`, ''), '未知') AS `label`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `label` ORDER BY `total` DESC");
$doorRows = dashboardFetchRows('logs', "SELECT IFNULL(NULLIF(`passdoor`, ''), '未知门禁') AS `label`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `label` ORDER BY `total` DESC LIMIT 8");
$personRows = dashboardFetchRows('logs', "SELECT IFNULL(NULLIF(`passusername`, ''), '未知人员') AS `name`, IFNULL(NULLIF(`passusertype`, ''), '未知') AS `kind`, IFNULL(NULLIF(`cardid`, ''), '-') AS `cardid`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `name`, `kind`, `cardid` ORDER BY `total` DESC LIMIT 8");
$hourRows = dashboardFetchRows('logs', "SELECT HOUR(FROM_UNIXTIME(`time`)) AS `hour`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `hour` ORDER BY `hour` ASC");
$dayRows = dashboardFetchRows('logs', "SELECT DATE(FROM_UNIXTIME(`time`)) AS `day`, COUNT(*) AS `total`, COUNT(DISTINCT CONCAT(IFNULL(`passusertype`, ''), '|', IFNULL(`passusername`, ''), '|', IFNULL(`cardid`, ''))) AS `people` FROM `logs`{$whereSql} GROUP BY `day` ORDER BY `day` ASC");
$latestRows = dashboardFetchRows('logs', "SELECT * FROM `logs`{$whereSql} ORDER BY `time` DESC LIMIT 12");

$hours = [];
for ($i = 0; $i < 24; $i++) {
	$hours[$i] = 0;
}
foreach ($hourRows as $row) {
	$hours[intval($row['hour'] ?? 0)] = intval($row['total'] ?? 0);
}
$peakHour = 0;
$peakTotal = 0;
foreach ($hours as $hour => $total) {
	if ($total > $peakTotal) {
		$peakHour = $hour;
		$peakTotal = $total;
	}
}

$totalSwipes = intval($summary['total'] ?? 0);
$totalPeople = intval($summary['people'] ?? 0);
$successTotal = intval($summary['success_total'] ?? 0);
$failedTotal = intval($summary['failed_total'] ?? 0);
$employeePresent = intval($summary['employee_present'] ?? 0);
$successRate = dashboardPercent($successTotal, $totalSwipes);
$attendanceRate = dashboardPercent($employeePresent, $activeEmployeeTotal);
$maxHourTotal = max(1, max($hours));
$maxDoorTotal = 1;
foreach ($doorRows as $row) {
	$maxDoorTotal = max($maxDoorTotal, intval($row['total'] ?? 0));
}
$maxDayTotal = 1;
foreach ($dayRows as $row) {
	$maxDayTotal = max($maxDayTotal, intval($row['total'] ?? 0));
}
$rangeText = $startDate === $endDate ? $startDate : ($startDate . ' 至 ' . $endDate);
?>
<style>
	.dash-wrap {
		color: #1f2937;
	}
	.dash-header {
		display: flex;
		justify-content: space-between;
		align-items: flex-end;
		gap: 16px;
		margin-bottom: 18px;
	}
	.dash-title {
		margin: 0;
		font-weight: 600;
		color: #1b2430;
	}
	.dash-subtitle {
		margin-top: 8px;
		color: #667085;
	}
	.dash-filter {
		background: #fff;
		border: 1px solid #e6e9ef;
		border-radius: 8px;
		padding: 14px;
		margin-bottom: 18px;
		box-shadow: 0 8px 24px rgba(31, 41, 55, .05);
	}
	.dash-filter .form-control {
		min-width: 150px;
	}
	.dash-grid {
		display: grid;
		grid-template-columns: repeat(4, minmax(160px, 1fr));
		gap: 14px;
		margin-bottom: 18px;
	}
	.dash-card {
		background: #fff;
		border: 1px solid #e6e9ef;
		border-radius: 8px;
		padding: 16px;
		box-shadow: 0 8px 24px rgba(31, 41, 55, .05);
	}
	.dash-card strong {
		display: block;
		font-size: 28px;
		line-height: 1.1;
		margin-top: 8px;
		color: #111827;
	}
	.dash-card span {
		color: #667085;
	}
	.dash-card.orange {
		border-top: 3px solid #f67302;
	}
	.dash-card.green {
		border-top: 3px solid #22a06b;
	}
	.dash-card.blue {
		border-top: 3px solid #2f80ed;
	}
	.dash-card.red {
		border-top: 3px solid #d92d20;
	}
	.dash-section-grid {
		display: grid;
		grid-template-columns: 1.25fr .75fr;
		gap: 16px;
		margin-bottom: 16px;
	}
	.dash-panel {
		background: #fff;
		border: 1px solid #e6e9ef;
		border-radius: 8px;
		padding: 16px;
		box-shadow: 0 8px 24px rgba(31, 41, 55, .05);
	}
	.dash-panel h4 {
		margin: 0 0 14px 0;
		font-weight: 600;
	}
	.hour-chart {
		display: grid;
		grid-template-columns: repeat(24, minmax(12px, 1fr));
		align-items: end;
		height: 210px;
		gap: 5px;
		padding-top: 10px;
		border-bottom: 1px solid #e6e9ef;
	}
	.hour-col {
		display: flex;
		flex-direction: column;
		justify-content: flex-end;
		align-items: center;
		height: 100%;
	}
	.hour-bar {
		width: 100%;
		min-height: 3px;
		border-radius: 6px 6px 0 0;
		background: #f67302;
	}
	.hour-col.peak .hour-bar {
		background: #22a06b;
	}
	.hour-label {
		font-size: 10px;
		color: #98a2b3;
		margin-top: 6px;
		transform: rotate(-45deg);
		white-space: nowrap;
	}
	.rank-row,
	.trend-row {
		display: grid;
		grid-template-columns: minmax(110px, 1fr) 72px;
		gap: 12px;
		align-items: center;
		margin-bottom: 12px;
	}
	.rank-name,
	.trend-name {
		color: #344054;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.rank-line,
	.trend-line {
		grid-column: 1 / 3;
		height: 8px;
		background: #edf0f5;
		border-radius: 999px;
		overflow: hidden;
		margin-top: -6px;
	}
	.rank-fill,
	.trend-fill {
		height: 100%;
		background: #2f80ed;
		border-radius: 999px;
	}
	.rank-row:nth-child(odd) .rank-fill,
	.trend-row:nth-child(odd) .trend-fill {
		background: #f67302;
	}
	.type-pills {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
	}
	.type-pill {
		border: 1px solid #e6e9ef;
		border-radius: 8px;
		padding: 10px 12px;
		background: #fafbfc;
		min-width: 120px;
	}
	.type-pill b {
		display: block;
		font-size: 20px;
		color: #111827;
	}
	.latest-table {
		width: 100%;
		white-space: nowrap;
	}
	.latest-table th {
		color: #667085;
		font-weight: 500;
	}
	.empty-state {
		color: #98a2b3;
		padding: 20px 0;
		text-align: center;
	}
	@media screen and (max-width: 1100px) {
		.dash-grid,
		.dash-section-grid {
			grid-template-columns: 1fr 1fr;
		}
	}
	@media screen and (max-width: 760px) {
		.dash-header,
		.dash-filter .form-inline {
			display: block;
		}
		.dash-grid,
		.dash-section-grid {
			grid-template-columns: 1fr;
		}
		.dash-filter .form-group,
		.dash-filter .form-control,
		.dash-filter .btn {
			width: 100%;
			margin: 0 0 8px 0;
		}
		.hour-chart {
			gap: 3px;
		}
		.hour-label {
			display: none;
		}
	}
</style>
<div class="page-title">
	<h3 class="breadcrumb-header">门禁数据大屏</h3>
</div>
<div id="main-wrapper" class="dash-wrap">
	<div class="dash-header">
		<div>
			<h3 class="dash-title">刷卡与通行概览</h3>
			<div class="dash-subtitle">统计范围：<?php echo dashboardH($rangeText); ?>，动作：<?php echo dashboardH(dashboardActionTypeText($actionType)); ?><?php echo $keyword !== '' ? '，关键词：' . dashboardH($keyword) : ''; ?></div>
		</div>
		<div class="dash-subtitle">数据来源：出入日志</div>
	</div>

	<div class="dash-filter">
		<form class="form-inline" method="GET" action="/">
			<input type="hidden" name="page" value="panel">
			<input type="hidden" name="module" value="dashboard">
			<div class="form-group">
				<label>开始日期</label>
				<input type="date" class="form-control" name="start_date" value="<?php echo dashboardH($startDate); ?>">
			</div>
			<div class="form-group" style="margin-left: 8px;">
				<label>结束日期</label>
				<input type="date" class="form-control" name="end_date" value="<?php echo dashboardH($endDate); ?>">
			</div>
			<div class="form-group" style="margin-left: 8px;">
				<label>动作</label>
				<select class="form-control" name="action_type">
					<option value="all" <?php echo $actionType === 'all' ? 'selected' : ''; ?>>全部</option>
					<option value="success" <?php echo $actionType === 'success' ? 'selected' : ''; ?>>仅成功</option>
					<option value="failed" <?php echo $actionType === 'failed' ? 'selected' : ''; ?>>仅失败</option>
				</select>
			</div>
			<div class="form-group" style="margin-left: 8px;">
				<label>关键词</label>
				<input type="text" class="form-control" name="q" value="<?php echo dashboardH($keyword); ?>" placeholder="姓名、卡号、门禁、动作">
			</div>
			<button type="submit" class="btn btn-default" style="margin-left: 8px;">查询</button>
			<a class="btn btn-default" href="/?page=panel&module=dashboard" style="margin-left: 4px;">今日</a>
		</form>
	</div>

	<div class="dash-grid">
		<div class="dash-card orange"><span>区间刷卡次数</span><strong><?php echo dashboardNumber($totalSwipes); ?></strong><small>成功率 <?php echo dashboardH($successRate); ?></small></div>
		<div class="dash-card blue"><span>区间刷卡人数</span><strong><?php echo dashboardNumber($totalPeople); ?></strong><small>按姓名、类型、卡号去重</small></div>
		<div class="dash-card green"><span>员工出勤率</span><strong><?php echo dashboardH($attendanceRate); ?></strong><small><?php echo dashboardNumber($employeePresent); ?> / <?php echo dashboardNumber($activeEmployeeTotal); ?> 名在职员工</small></div>
		<div class="dash-card red"><span>失败记录</span><strong><?php echo dashboardNumber($failedTotal); ?></strong><small>成功 <?php echo dashboardNumber($successTotal); ?> 次</small></div>
	</div>

	<div class="dash-section-grid">
		<div class="dash-panel">
			<h4>高峰时段</h4>
			<div class="dash-subtitle">峰值：<?php echo dashboardH(dashboardHourText($peakHour)); ?>，<?php echo dashboardNumber($peakTotal); ?> 次</div>
			<div class="hour-chart">
				<?php foreach ($hours as $hour => $total) {
					$height = max(3, intval(($total / $maxHourTotal) * 100));
				?>
					<div class="hour-col <?php echo $hour === $peakHour && $total > 0 ? 'peak' : ''; ?>" title="<?php echo dashboardH(dashboardHourText($hour) . ' ' . $total . '次'); ?>">
						<div class="hour-bar" style="height: <?php echo $height; ?>%;"></div>
						<div class="hour-label"><?php echo dashboardH(str_pad((string)$hour, 2, '0', STR_PAD_LEFT)); ?></div>
					</div>
				<?php } ?>
			</div>
		</div>
		<div class="dash-panel">
			<h4>人员类型</h4>
			<?php if (count($typeRows) === 0) { ?>
				<div class="empty-state">暂无数据</div>
			<?php } else { ?>
				<div class="type-pills">
					<?php foreach ($typeRows as $row) { ?>
						<div class="type-pill"><span><?php echo dashboardH($row['label'] ?? '未知'); ?></span><b><?php echo dashboardNumber($row['total'] ?? 0); ?></b></div>
					<?php } ?>
				</div>
			<?php } ?>
		</div>
	</div>

	<div class="dash-section-grid">
		<div class="dash-panel">
			<h4>门禁点位排行</h4>
			<?php if (count($doorRows) === 0) { ?>
				<div class="empty-state">暂无数据</div>
			<?php } else { ?>
				<?php foreach ($doorRows as $row) {
					$total = intval($row['total'] ?? 0);
					$width = max(3, intval(($total / $maxDoorTotal) * 100));
				?>
					<div class="rank-row">
						<div class="rank-name"><?php echo dashboardH($row['label'] ?? '未知门禁'); ?></div>
						<div><?php echo dashboardNumber($total); ?></div>
						<div class="rank-line"><div class="rank-fill" style="width: <?php echo $width; ?>%;"></div></div>
					</div>
				<?php } ?>
			<?php } ?>
		</div>
		<div class="dash-panel">
			<h4>高频人员</h4>
			<?php if (count($personRows) === 0) { ?>
				<div class="empty-state">暂无数据</div>
			<?php } else { ?>
				<?php foreach ($personRows as $row) { ?>
					<div class="rank-row">
						<div class="rank-name"><?php echo dashboardH(($row['name'] ?? '未知人员') . ' / ' . ($row['kind'] ?? '未知')); ?></div>
						<div><?php echo dashboardNumber($row['total'] ?? 0); ?></div>
						<div class="rank-line"><div class="rank-fill" style="width: <?php echo max(3, min(100, intval(($row['total'] / max(1, intval($personRows[0]['total'] ?? 1))) * 100))); ?>%;"></div></div>
					</div>
				<?php } ?>
			<?php } ?>
		</div>
	</div>

	<div class="dash-section-grid">
		<div class="dash-panel">
			<h4>日期趋势</h4>
			<?php if (count($dayRows) === 0) { ?>
				<div class="empty-state">暂无数据</div>
			<?php } else { ?>
				<?php foreach ($dayRows as $row) {
					$total = intval($row['total'] ?? 0);
					$width = max(3, intval(($total / $maxDayTotal) * 100));
				?>
					<div class="trend-row">
						<div class="trend-name"><?php echo dashboardH($row['day'] ?? '-'); ?>，<?php echo dashboardNumber($row['people'] ?? 0); ?> 人</div>
						<div><?php echo dashboardNumber($total); ?></div>
						<div class="trend-line"><div class="trend-fill" style="width: <?php echo $width; ?>%;"></div></div>
					</div>
				<?php } ?>
			<?php } ?>
		</div>
		<div class="dash-panel">
			<h4>关键指标</h4>
			<div class="type-pills">
				<div class="type-pill"><span>成功记录</span><b><?php echo dashboardNumber($successTotal); ?></b></div>
				<div class="type-pill"><span>失败记录</span><b><?php echo dashboardNumber($failedTotal); ?></b></div>
				<div class="type-pill"><span>员工到岗</span><b><?php echo dashboardNumber($employeePresent); ?></b></div>
				<div class="type-pill"><span>峰值小时</span><b><?php echo dashboardH(dashboardHourText($peakHour)); ?></b></div>
			</div>
		</div>
	</div>

	<div class="dash-panel" style="overflow-x:auto;">
		<h4>最近刷卡记录</h4>
		<table class="table table-bordered latest-table">
			<thead>
				<tr>
					<th>时间</th>
					<th>姓名/花名</th>
					<th>类型</th>
					<th>门禁</th>
					<th>卡号</th>
					<th>动作</th>
				</tr>
			</thead>
			<tbody>
				<?php if (count($latestRows) === 0) { ?>
					<tr><td colspan="6" class="empty-state">暂无数据</td></tr>
				<?php } else { ?>
					<?php foreach ($latestRows as $row) { ?>
						<tr>
							<td><?php echo dashboardH(intval($row['time'] ?? 0) > 0 ? date('Y-m-d H:i:s', intval($row['time'])) : '-'); ?></td>
							<td><?php echo dashboardH($row['passusername'] ?? ''); ?></td>
							<td><?php echo dashboardH($row['passusertype'] ?? ''); ?></td>
							<td><?php echo dashboardH($row['passdoor'] ?? ''); ?></td>
							<td><?php echo dashboardH($row['cardid'] ?? ''); ?></td>
							<td><?php echo dashboardH($row['action'] ?? ''); ?></td>
						</tr>
					<?php } ?>
				<?php } ?>
			</tbody>
		</table>
	</div>
</div>
