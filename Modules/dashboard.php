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

function dashboardGrainOptions() {
	return [
		60 => '1分钟',
		300 => '5分钟',
		600 => '10分钟',
		900 => '15分钟',
		1800 => '30分钟',
		3600 => '1小时',
		7200 => '2小时',
		14400 => '4小时',
		86400 => '1天'
	];
}

function dashboardTimeGrain() {
	$value = intval($_GET['time_grain'] ?? 60);
	$options = dashboardGrainOptions();
	return isset($options[$value]) ? $value : 60;
}

function dashboardChartType() {
	$type = trim((string)($_GET['chart_type'] ?? 'bar'));
	return in_array($type, ['bar', 'line'], true) ? $type : 'bar';
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

function dashboardGrainText($grain) {
	$options = dashboardGrainOptions();
	return $options[intval($grain)] ?? '1小时';
}

function dashboardChartTypeText($type) {
	return $type === 'line' ? '折线图' : '柱状图';
}

function dashboardBucketText($timestamp, $grain, $crossDay) {
	$timestamp = intval($timestamp);
	$grain = intval($grain);
	if ($grain >= 86400) {
		return date('Y-m-d', $timestamp);
	}
	if ($crossDay) {
		return date('m-d H:i', $timestamp);
	}
	return date('H:i', $timestamp);
}

function dashboardActionTypeText($type) {
	if ($type === 'success') { return '仅成功'; }
	if ($type === 'failed') { return '仅失败'; }
	return '全部';
}

function dashboardBuildSeries($startTs, $endTs, $whereSql, $grain) {
	$grain = intval($grain);
	$bucketRows = dashboardFetchRows('logs', "SELECT FLOOR((`time` - {$startTs}) / {$grain}) AS `bucket_index`, COUNT(*) AS `total`, COUNT(DISTINCT CONCAT(IFNULL(`passusertype`, ''), '|', IFNULL(`passusername`, ''), '|', IFNULL(`cardid`, ''))) AS `people`, SUM(CASE WHEN `action` LIKE '%成功%' THEN 1 ELSE 0 END) AS `success_total`, SUM(CASE WHEN `action` LIKE '%失败%' THEN 1 ELSE 0 END) AS `failed_total` FROM `logs`{$whereSql} GROUP BY `bucket_index` ORDER BY `bucket_index` ASC");
	$doorRows = dashboardFetchRows('logs', "SELECT FLOOR((`time` - {$startTs}) / {$grain}) AS `bucket_index`, IFNULL(NULLIF(`passdoor`, ''), '未知门禁') AS `door`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `bucket_index`, `door` ORDER BY `bucket_index` ASC, `total` DESC");
	$doorMap = [];
	foreach ($doorRows as $row) {
		$bucketIndex = intval($row['bucket_index'] ?? 0);
		if (!isset($doorMap[$bucketIndex])) {
			$doorMap[$bucketIndex] = [];
		}
		if (count($doorMap[$bucketIndex]) < 3) {
			$doorMap[$bucketIndex][] = [
				'name' => (string)($row['door'] ?? ''),
				'total' => intval($row['total'] ?? 0)
			];
		}
	}
	$points = [];
	foreach ($bucketRows as $row) {
		$bucketIndex = intval($row['bucket_index'] ?? 0);
		$bucketStart = $startTs + ($bucketIndex * $grain);
		$bucketEnd = min($bucketStart + $grain - 1, $endTs);
		$points[] = [
			'index' => $bucketIndex,
			'start' => $bucketStart,
			'end' => $bucketEnd,
			'total' => intval($row['total'] ?? 0),
			'people' => intval($row['people'] ?? 0),
			'success_total' => intval($row['success_total'] ?? 0),
			'failed_total' => intval($row['failed_total'] ?? 0),
			'top_doors' => $doorMap[$bucketIndex] ?? []
		];
	}
	return [
		'grain' => $grain,
		'label' => dashboardGrainText($grain),
		'bucket_count' => max(1, intval(ceil(($endTs - $startTs + 1) / $grain))),
		'points' => $points
	];
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
$selectedGrain = dashboardTimeGrain();
$chartType = dashboardChartType();
$crossDay = $startDate !== $endDate;
$whereSql = dashboardWhereSql($startTs, $endTs, $keyword, $actionType);

$summary = dashboardFetchOne('logs', "SELECT
	COUNT(*) AS `total`,
	COUNT(DISTINCT CONCAT(IFNULL(`passusertype`, ''), '|', IFNULL(`passusername`, ''), '|', IFNULL(`cardid`, ''))) AS `people`,
	COUNT(DISTINCT IFNULL(`passdoor`, '')) AS `door_count`,
	COUNT(DISTINCT IFNULL(`cardid`, '')) AS `card_count`,
	SUM(CASE WHEN `action` LIKE '%成功%' THEN 1 ELSE 0 END) AS `success_total`,
	SUM(CASE WHEN `action` LIKE '%失败%' THEN 1 ELSE 0 END) AS `failed_total`,
	COUNT(DISTINCT CASE WHEN `passusertype`='员工' AND `action` LIKE '%成功%' THEN CONCAT(IFNULL(`passusername`, ''), '|', IFNULL(`cardid`, '')) ELSE NULL END) AS `employee_present`
	FROM `logs`{$whereSql}");
$activeEmployee = dashboardFetchOne('employee', "SELECT COUNT(*) AS `total` FROM `employee` WHERE `status`='true'");
$activeEmployeeTotal = intval($activeEmployee['total'] ?? 0);

$typeRows = dashboardFetchRows('logs', "SELECT IFNULL(NULLIF(`passusertype`, ''), '未知') AS `label`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `label` ORDER BY `total` DESC");
$doorRows = dashboardFetchRows('logs', "SELECT IFNULL(NULLIF(`passdoor`, ''), '未知门禁') AS `label`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `label` ORDER BY `total` DESC LIMIT 8");
$personRows = dashboardFetchRows('logs', "SELECT IFNULL(NULLIF(`passusername`, ''), '未知人员') AS `name`, IFNULL(NULLIF(`passusertype`, ''), '未知') AS `kind`, IFNULL(NULLIF(`cardid`, ''), '-') AS `cardid`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `name`, `kind`, `cardid` ORDER BY `total` DESC LIMIT 8");
$dayRows = dashboardFetchRows('logs', "SELECT DATE(FROM_UNIXTIME(`time`)) AS `day`, COUNT(*) AS `total`, COUNT(DISTINCT CONCAT(IFNULL(`passusertype`, ''), '|', IFNULL(`passusername`, ''), '|', IFNULL(`cardid`, ''))) AS `people` FROM `logs`{$whereSql} GROUP BY `day` ORDER BY `day` ASC");
$weekdayRows = dashboardFetchRows('logs', "SELECT CASE WHEN DAYOFWEEK(FROM_UNIXTIME(`time`)) IN (1,7) THEN '周末' ELSE '工作日' END AS `label`, COUNT(*) AS `total` FROM `logs`{$whereSql} GROUP BY `label` ORDER BY `total` DESC");
$edgeTimes = dashboardFetchOne('logs', "SELECT MIN(CASE WHEN `action` LIKE '%成功%' THEN `time` ELSE NULL END) AS `first_success`, MAX(`time`) AS `last_record` FROM `logs`{$whereSql}");
$latestRows = dashboardFetchRows('logs', "SELECT * FROM `logs`{$whereSql} ORDER BY `time` DESC LIMIT 12");

$chartSeriesByGrain = [];
foreach (dashboardGrainOptions() as $grainValue => $grainLabel) {
	$chartSeriesByGrain[(string)$grainValue] = dashboardBuildSeries($startTs, $endTs, $whereSql, intval($grainValue));
}

$totalSwipes = intval($summary['total'] ?? 0);
$totalPeople = intval($summary['people'] ?? 0);
$activeDoorCount = intval($summary['door_count'] ?? 0);
$activeCardCount = intval($summary['card_count'] ?? 0);
$successTotal = intval($summary['success_total'] ?? 0);
$failedTotal = intval($summary['failed_total'] ?? 0);
$employeePresent = intval($summary['employee_present'] ?? 0);
$successRate = dashboardPercent($successTotal, $totalSwipes);
$attendanceRate = dashboardPercent($employeePresent, $activeEmployeeTotal);
$avgSwipePerPerson = $totalPeople > 0 ? number_format($totalSwipes / $totalPeople, 1) : '0.0';
$maxDoorTotal = 1;
foreach ($doorRows as $row) {
	$maxDoorTotal = max($maxDoorTotal, intval($row['total'] ?? 0));
}
$maxDayTotal = 1;
foreach ($dayRows as $row) {
	$maxDayTotal = max($maxDayTotal, intval($row['total'] ?? 0));
}
$firstSuccessAt = intval($edgeTimes['first_success'] ?? 0);
$lastRecordAt = intval($edgeTimes['last_record'] ?? 0);
$busiestDoor = $doorRows[0] ?? null;
$busiestPerson = $personRows[0] ?? null;
$rangeText = $startDate === $endDate ? $startDate : ($startDate . ' 至 ' . $endDate);
?>
<style>
	.dash-wrap {
		--dash-bg: #f5f7fb;
		--dash-panel: #fff;
		--dash-panel-soft: #fafbfc;
		--dash-border: #e6e9ef;
		--dash-text: #1f2937;
		--dash-strong: #111827;
		--dash-muted: #667085;
		--dash-faint: #98a2b3;
		--dash-grid-line: #edf0f5;
		--dash-shadow: rgba(31, 41, 55, .05);
		--dash-orange: #f67302;
		--dash-green: #22a06b;
		--dash-blue: #2f80ed;
		--dash-red: #d92d20;
		color: var(--dash-text);
	}
	.dash-wrap.dark {
		--dash-bg: #0b1120;
		--dash-panel: #111827;
		--dash-panel-soft: #172033;
		--dash-border: #263244;
		--dash-text: #dbe4f0;
		--dash-strong: #f8fafc;
		--dash-muted: #a9b4c2;
		--dash-faint: #7f8da3;
		--dash-grid-line: #27364b;
		--dash-shadow: rgba(0, 0, 0, .24);
		background: var(--dash-bg);
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
	.dash-chart-toolbar {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
		margin-bottom: 12px;
	}
	.dash-chart-scroll {
		width: 100%;
		overflow-x: auto;
		padding-bottom: 4px;
	}
	.peak-bar-chart {
		display: grid;
		align-items: end;
		height: 240px;
		gap: 5px;
		padding-top: 10px;
		border-bottom: 1px solid #e6e9ef;
	}
	.peak-col {
		display: flex;
		flex-direction: column;
		justify-content: flex-end;
		align-items: center;
		height: 100%;
	}
	.peak-bar {
		width: 100%;
		min-height: 3px;
		border-radius: 6px 6px 0 0;
		background: #f67302;
	}
	.peak-col.peak .peak-bar {
		background: #22a06b;
	}
	.peak-label {
		font-size: 10px;
		color: #98a2b3;
		margin-top: 6px;
		transform: rotate(-45deg);
		white-space: nowrap;
	}
	.peak-line-chart {
		height: 268px;
		min-width: 760px;
		border-bottom: 1px solid #e6e9ef;
	}
	.peak-line-chart svg {
		width: 100%;
		height: 230px;
		display: block;
	}
	.peak-line-grid {
		stroke: #edf0f5;
		stroke-width: 1;
	}
	.peak-line-path {
		fill: none;
		stroke: #f67302;
		stroke-width: 4;
		stroke-linecap: round;
		stroke-linejoin: round;
	}
	.peak-line-dot {
		fill: #fff;
		stroke: #f67302;
		stroke-width: 3;
	}
	.peak-line-dot.peak {
		stroke: #22a06b;
		fill: #22a06b;
	}
	.peak-axis {
		display: flex;
		justify-content: space-between;
		gap: 12px;
		color: #98a2b3;
		font-size: 10px;
		margin-top: 6px;
		white-space: nowrap;
	}
	.insight-grid {
		display: grid;
		grid-template-columns: repeat(3, minmax(160px, 1fr));
		gap: 12px;
		margin-bottom: 16px;
	}
	.insight-card {
		background: #fff;
		border: 1px solid #e6e9ef;
		border-radius: 8px;
		padding: 14px;
		box-shadow: 0 8px 24px rgba(31, 41, 55, .05);
	}
	.insight-card span {
		color: #667085;
	}
	.insight-card strong {
		display: block;
		margin-top: 6px;
		font-size: 18px;
		color: #111827;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.insight-card small {
		color: #98a2b3;
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
	.dash-panel-titlebar {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin-bottom: 12px;
	}
	.dash-panel-titlebar h4 {
		margin-bottom: 0;
	}
	.dash-chart-controls {
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 8px;
		flex-wrap: wrap;
	}
	.dash-chart-select {
		width: auto;
		min-width: 96px;
		height: 34px;
		background: var(--dash-panel);
		border-color: var(--dash-border);
		color: var(--dash-text);
	}
	.dash-chart-switch {
		display: inline-flex;
		border: 1px solid var(--dash-border);
		border-radius: 8px;
		padding: 2px;
		background: var(--dash-panel-soft);
	}
	.dash-chart-button {
		height: 30px;
		padding: 0 10px;
		border: 0;
		border-radius: 6px;
		background: transparent;
		color: var(--dash-muted);
		outline: none;
		white-space: nowrap;
	}
	.dash-chart-button.active {
		background: var(--dash-orange);
		color: #fff;
	}
	.dash-dark-button {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		border: 1px solid var(--dash-border);
		background: var(--dash-panel-soft);
	}
	.dashboard-chart-stage {
		position: relative;
		min-height: 286px;
		color: var(--dash-text);
	}
	.peak-svg-chart {
		display: block;
		height: 262px;
		min-width: 760px;
		overflow: visible;
	}
	.peak-svg-axis {
		fill: var(--dash-faint);
		font-size: 11px;
	}
	.peak-svg-grid {
		stroke: var(--dash-grid-line);
		stroke-width: 1;
	}
	.peak-svg-bar {
		fill: var(--dash-orange);
		rx: 4;
		cursor: default;
	}
	.peak-svg-bar.peak {
		fill: var(--dash-green);
	}
	.peak-svg-path {
		fill: none;
		stroke: var(--dash-orange);
		stroke-width: 3.5;
		stroke-linecap: round;
		stroke-linejoin: round;
	}
	.peak-svg-dot {
		fill: var(--dash-panel);
		stroke: var(--dash-orange);
		stroke-width: 2.5;
		cursor: default;
	}
	.peak-svg-dot.peak {
		fill: var(--dash-green);
		stroke: var(--dash-green);
	}
	.peak-svg-hit {
		fill: transparent;
		cursor: default;
	}
	.peak-empty {
		height: 240px;
		display: flex;
		align-items: center;
		justify-content: center;
		color: var(--dash-faint);
		border: 1px dashed var(--dash-border);
		border-radius: 8px;
	}
	.dashboard-tooltip {
		position: fixed;
		z-index: 9999;
		display: none;
		max-width: 260px;
		padding: 10px 12px;
		border: 1px solid var(--dash-border);
		border-radius: 8px;
		background: var(--dash-panel);
		color: var(--dash-text);
		box-shadow: 0 16px 40px rgba(15, 23, 42, .18);
		pointer-events: none;
		font-size: 12px;
		line-height: 1.55;
	}
	.dashboard-tooltip strong {
		display: block;
		margin-bottom: 4px;
		color: var(--dash-strong);
		font-size: 13px;
	}
	body.dashboard-dark-mode .page-content,
	body.dashboard-dark-mode .page-inner {
		background: #0b1120 !important;
	}
	body.dashboard-dark-mode .breadcrumb-header,
	.dash-wrap.dark .dash-title,
	.dash-wrap.dark .dash-panel h4 {
		color: var(--dash-strong);
	}
	.dash-wrap.dark .dash-filter,
	.dash-wrap.dark .dash-card,
	.dash-wrap.dark .dash-panel,
	.dash-wrap.dark .insight-card {
		background: var(--dash-panel);
		border-color: var(--dash-border);
		box-shadow: 0 8px 24px var(--dash-shadow);
	}
	.dash-wrap.dark .dash-subtitle,
	.dash-wrap.dark .dash-card span,
	.dash-wrap.dark .insight-card span,
	.dash-wrap.dark .latest-table th,
	.dash-wrap.dark .type-pill span,
	.dash-wrap.dark .empty-state {
		color: var(--dash-muted);
	}
	.dash-wrap.dark .dash-card strong,
	.dash-wrap.dark .insight-card strong,
	.dash-wrap.dark .type-pill b {
		color: var(--dash-strong);
	}
	.dash-wrap.dark .type-pill,
	.dash-wrap.dark .dash-filter .form-control,
	.dash-wrap.dark .latest-table,
	.dash-wrap.dark .latest-table td,
	.dash-wrap.dark .latest-table th {
		background: var(--dash-panel-soft);
		border-color: var(--dash-border);
		color: var(--dash-text);
	}
	.dash-wrap.dark .rank-name,
	.dash-wrap.dark .trend-name {
		color: var(--dash-text);
	}
	.dash-wrap.dark .rank-line,
	.dash-wrap.dark .trend-line {
		background: var(--dash-grid-line);
	}
	@media screen and (max-width: 1100px) {
		.dash-grid,
		.dash-section-grid,
		.insight-grid {
			grid-template-columns: 1fr 1fr;
		}
	}
	@media screen and (max-width: 760px) {
		.dash-header,
		.dash-panel-titlebar,
		.dash-chart-toolbar,
		.dash-filter .form-inline {
			display: block;
		}
		.dash-chart-controls {
			justify-content: flex-start;
			margin-top: 10px;
		}
		.dash-chart-select,
		.dash-dark-button {
			flex: 1 1 auto;
		}
		.dash-grid,
		.dash-section-grid,
		.insight-grid {
			grid-template-columns: 1fr;
		}
		.dash-filter .form-group,
		.dash-filter .form-control,
		.dash-filter .btn {
			width: 100%;
			margin: 0 0 8px 0;
		}
		.peak-bar-chart {
			gap: 3px;
		}
		.peak-label {
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

	<div class="insight-grid">
		<div class="insight-card"><span>活跃门禁点位</span><strong><?php echo dashboardNumber($activeDoorCount); ?></strong><small>区间内有刷卡记录的门禁</small></div>
		<div class="insight-card"><span>活跃工牌</span><strong><?php echo dashboardNumber($activeCardCount); ?></strong><small>按卡号去重</small></div>
		<div class="insight-card"><span>人均刷卡</span><strong><?php echo dashboardH($avgSwipePerPerson); ?> 次</strong><small>区间刷卡次数 / 刷卡人数</small></div>
		<div class="insight-card"><span>首刷/末刷</span><strong><?php echo dashboardH(($firstSuccessAt > 0 ? date('H:i', $firstSuccessAt) : '-') . ' / ' . ($lastRecordAt > 0 ? date('H:i', $lastRecordAt) : '-')); ?></strong><small><?php echo dashboardH($crossDay ? '跨日范围按实际日期统计' : $rangeText); ?></small></div>
		<div class="insight-card"><span>最忙门禁</span><strong><?php echo dashboardH($busiestDoor ? ($busiestDoor['label'] ?? '-') : '-'); ?></strong><small><?php echo dashboardNumber($busiestDoor['total'] ?? 0); ?> 次刷卡</small></div>
		<div class="insight-card"><span>最高频人员</span><strong><?php echo dashboardH($busiestPerson ? (($busiestPerson['name'] ?? '-') . ' / ' . ($busiestPerson['kind'] ?? '-')) : '-'); ?></strong><small><?php echo dashboardNumber($busiestPerson['total'] ?? 0); ?> 次刷卡</small></div>
	</div>

	<div class="dash-section-grid">
		<div class="dash-panel">
			<div class="dash-panel-titlebar">
				<h4>高峰时段</h4>
				<div class="dash-chart-controls">
					<select class="form-control dash-chart-select" id="dashboardTimeGrain">
						<?php foreach (dashboardGrainOptions() as $grainValue => $grainLabel) { ?>
							<option value="<?php echo intval($grainValue); ?>" <?php echo $selectedGrain === intval($grainValue) ? 'selected' : ''; ?>><?php echo dashboardH($grainLabel); ?></option>
						<?php } ?>
					</select>
					<div class="dash-chart-switch" role="group" aria-label="图表形式">
						<button type="button" class="dash-chart-button" data-chart-type="bar">柱状图</button>
						<button type="button" class="dash-chart-button" data-chart-type="line">折线图</button>
					</div>
					<button type="button" class="dash-chart-button dash-dark-button" id="dashboardDarkToggle"><i class="fa fa-moon"></i><span>深色模式</span></button>
				</div>
			</div>
			<div class="dash-chart-toolbar">
				<div class="dash-subtitle" id="peakSummary">峰值：计算中</div>
				<div class="dash-subtitle" id="peakStatus">图表加载中</div>
			</div>
			<div class="dash-chart-scroll">
				<div class="dashboard-chart-stage" id="dashboardChart"></div>
			</div>
			<div class="dashboard-tooltip" id="dashboardTooltip" aria-hidden="true"></div>
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
			<h4>时间构成</h4>
			<?php if (count($weekdayRows) === 0) { ?>
				<div class="empty-state">暂无数据</div>
			<?php } else { ?>
				<div class="type-pills">
					<?php foreach ($weekdayRows as $row) { ?>
						<div class="type-pill"><span><?php echo dashboardH($row['label'] ?? '-'); ?></span><b><?php echo dashboardNumber($row['total'] ?? 0); ?></b></div>
					<?php } ?>
				</div>
			<?php } ?>
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
<script>
	window.dashboardChartPayload = {
		startTs: <?php echo intval($startTs); ?>,
		endTs: <?php echo intval($endTs); ?>,
		crossDay: <?php echo $crossDay ? 'true' : 'false'; ?>,
		selectedGrain: <?php echo intval($selectedGrain); ?>,
		chartType: <?php echo json_encode($chartType, JSON_UNESCAPED_UNICODE); ?>,
		grains: <?php echo json_encode(dashboardGrainOptions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
		series: <?php echo json_encode($chartSeriesByGrain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>
	};
	(function() {
		var payload = window.dashboardChartPayload || {};
		var root = document.getElementById('main-wrapper');
		var chart = document.getElementById('dashboardChart');
		var grainSelect = document.getElementById('dashboardTimeGrain');
		var summaryEl = document.getElementById('peakSummary');
		var statusEl = document.getElementById('peakStatus');
		var tooltip = document.getElementById('dashboardTooltip');
		var darkToggle = document.getElementById('dashboardDarkToggle');
		var seriesStore = payload.series || {};
		if (!root || !chart) {
			return;
		}

		function readStorage(key, fallback) {
			try {
				var value = localStorage.getItem(key);
				return value === null ? fallback : value;
			} catch (e) {
				return fallback;
			}
		}

		function writeStorage(key, value) {
			try {
				localStorage.setItem(key, value);
			} catch (e) {}
		}

		var state = {
			grain: readStorage('doorlockDashboardGrain', String(payload.selectedGrain || 60)),
			chartType: readStorage('doorlockDashboardChartType', payload.chartType || 'bar'),
			dark: readStorage('doorlockDashboardDark', '0') === '1'
		};
		if (!seriesStore[state.grain]) {
			state.grain = String(payload.selectedGrain || 60);
		}
		if (state.chartType !== 'line') {
			state.chartType = 'bar';
		}

		function escapeHtml(value) {
			return String(value == null ? '' : value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}

		function pad(value) {
			return String(value).padStart(2, '0');
		}

		function dateParts(timestamp) {
			var d = new Date(Number(timestamp) * 1000);
			return {
				year: d.getFullYear(),
				month: pad(d.getMonth() + 1),
				day: pad(d.getDate()),
				hour: pad(d.getHours()),
				minute: pad(d.getMinutes())
			};
		}

		function shortLabel(timestamp, grain) {
			var p = dateParts(timestamp);
			if (Number(grain) >= 86400) {
				return p.year + '-' + p.month + '-' + p.day;
			}
			return payload.crossDay ? (p.month + '-' + p.day + ' ' + p.hour + ':' + p.minute) : (p.hour + ':' + p.minute);
		}

		function fullLabel(timestamp) {
			var p = dateParts(timestamp);
			return p.year + '-' + p.month + '-' + p.day + ' ' + p.hour + ':' + p.minute;
		}

		function pointRange(point, grain) {
			if (Number(grain) >= 86400) {
				return shortLabel(point.start, grain);
			}
			if (payload.crossDay) {
				return fullLabel(point.start) + ' - ' + fullLabel(point.end);
			}
			return shortLabel(point.start, grain) + ' - ' + shortLabel(point.end, grain);
		}

		function numberText(value) {
			return Number(value || 0).toLocaleString('zh-CN');
		}

		function buildSeries(grain) {
			var source = seriesStore[String(grain)] || seriesStore[String(payload.selectedGrain || 60)] || {};
			var sourceGrain = Number(source.grain || grain || 60);
			var bucketCount = Math.max(1, Number(source.bucket_count || 1));
			var map = {};
			(source.points || []).forEach(function(point) {
				map[Number(point.index || 0)] = point;
			});
			var points = [];
			for (var i = 0; i < bucketCount; i++) {
				var base = map[i] || {};
				var start = Number(payload.startTs || 0) + i * sourceGrain;
				var end = Math.min(start + sourceGrain - 1, Number(payload.endTs || start));
				points.push({
					index: i,
					start: Number(base.start || start),
					end: Number(base.end || end),
					total: Number(base.total || 0),
					people: Number(base.people || 0),
					success_total: Number(base.success_total || 0),
					failed_total: Number(base.failed_total || 0),
					top_doors: base.top_doors || []
				});
			}
			return {
				grain: sourceGrain,
				label: source.label || ((payload.grains || {})[String(sourceGrain)] || ''),
				bucketCount: bucketCount,
				points: points
			};
		}

		function findPeak(points) {
			var peak = points[0] || null;
			var maxTotal = 0;
			points.forEach(function(point) {
				if (point.total > maxTotal || !peak) {
					peak = point;
					maxTotal = point.total;
				}
			});
			return {
				point: peak,
				total: maxTotal
			};
		}

		function tooltipHtml(point, grain) {
			var doors = (point.top_doors || []).map(function(door) {
				return escapeHtml(door.name || '未知门禁') + ' ' + numberText(door.total || 0) + '次';
			}).join('、');
			return '<strong>' + escapeHtml(pointRange(point, grain)) + '</strong>'
				+ '<div>刷卡 ' + numberText(point.total) + ' 次，' + numberText(point.people) + ' 人</div>'
				+ '<div>成功 ' + numberText(point.success_total) + ' 次，失败 ' + numberText(point.failed_total) + ' 次</div>'
				+ '<div>高频门禁：' + (doors || '-') + '</div>';
		}

		function moveTooltip(event) {
			if (!tooltip) {
				return;
			}
			var source = event.touches && event.touches[0] ? event.touches[0] : event;
			var x = source.clientX || 0;
			var y = source.clientY || 0;
			var width = tooltip.offsetWidth || 240;
			var height = tooltip.offsetHeight || 96;
			var left = Math.min(window.innerWidth - width - 12, x + 14);
			var top = Math.min(window.innerHeight - height - 12, y + 14);
			tooltip.style.left = Math.max(12, left) + 'px';
			tooltip.style.top = Math.max(12, top) + 'px';
		}

		function showTooltip(event, point, grain) {
			if (!tooltip) {
				return;
			}
			tooltip.innerHTML = tooltipHtml(point, grain);
			tooltip.style.display = 'block';
			tooltip.setAttribute('aria-hidden', 'false');
			moveTooltip(event);
		}

		function hideTooltip() {
			if (!tooltip) {
				return;
			}
			tooltip.style.display = 'none';
			tooltip.setAttribute('aria-hidden', 'true');
		}

		function bindTooltip(points, grain) {
			Array.prototype.forEach.call(chart.querySelectorAll('[data-point-index]'), function(node) {
				var index = Number(node.getAttribute('data-point-index'));
				var point = points[index];
				if (!point) {
					return;
				}
				node.addEventListener('mouseenter', function(event) { showTooltip(event, point, grain); });
				node.addEventListener('mousemove', moveTooltip);
				node.addEventListener('mouseleave', hideTooltip);
				node.addEventListener('touchstart', function(event) {
					showTooltip(event, point, grain);
					setTimeout(hideTooltip, 1800);
				}, { passive: true });
			});
		}

		function svgGrid(width, top, bottom) {
			var lines = [];
			for (var i = 0; i <= 3; i++) {
				var y = top + ((bottom - top) / 3) * i;
				lines.push('<line class="peak-svg-grid" x1="0" y1="' + y.toFixed(2) + '" x2="' + width.toFixed(2) + '" y2="' + y.toFixed(2) + '"></line>');
			}
			return lines.join('');
		}

		function axisLabels(points, grain, width, chartBottom) {
			var labels = [];
			var count = points.length;
			var every = Math.max(1, Math.ceil(count / 10));
			for (var i = 0; i < count; i++) {
				if (i % every !== 0 && i !== count - 1) {
					continue;
				}
				var x = count === 1 ? width / 2 : (i / (count - 1)) * width;
				labels.push('<text class="peak-svg-axis" x="' + x.toFixed(2) + '" y="' + (chartBottom + 24) + '" text-anchor="' + (i === 0 ? 'start' : (i === count - 1 ? 'end' : 'middle')) + '">' + escapeHtml(shortLabel(points[i].start, grain)) + '</text>');
			}
			return labels.join('');
		}

		function renderBar(data, maxTotal, peakPoint) {
			var points = data.points;
			var width = Math.max(760, Math.min(48000, points.length * 10));
			var top = 18;
			var bottom = 220;
			var step = width / Math.max(1, points.length);
			var barWidth = Math.max(1, Math.min(14, step * .72));
			var bars = [];
			points.forEach(function(point, i) {
				var barHeight = point.total > 0 ? Math.max(2, (point.total / maxTotal) * (bottom - top)) : 1;
				var x = i * step + (step - barWidth) / 2;
				var y = bottom - barHeight;
				var isPeak = peakPoint && point.index === peakPoint.index && point.total > 0;
				var tooltipAttr = point.total > 0 ? ' data-point-index="' + i + '"' : '';
				bars.push('<rect class="peak-svg-bar' + (isPeak ? ' peak' : '') + '"' + tooltipAttr + ' x="' + x.toFixed(2) + '" y="' + y.toFixed(2) + '" width="' + barWidth.toFixed(2) + '" height="' + barHeight.toFixed(2) + '"></rect>');
			});
			chart.innerHTML = '<svg class="peak-svg-chart" style="width:' + width + 'px" viewBox="0 0 ' + width + ' 262" role="img" aria-label="高峰时段柱状图">'
				+ svgGrid(width, top, bottom)
				+ bars.join('')
				+ axisLabels(points, data.grain, width, bottom)
				+ '</svg>';
			bindTooltip(points, data.grain);
		}

		function renderLine(data, maxTotal, peakPoint) {
			var points = data.points;
			var width = Math.max(760, Math.min(48000, points.length * 8));
			var top = 18;
			var bottom = 220;
			var lineWidth = Math.max(1, width - 24);
			var dotEvery = Math.max(1, Math.ceil(points.length / 260));
			var polyline = [];
			var dots = [];
			points.forEach(function(point, i) {
				var x = points.length === 1 ? width / 2 : 12 + (i / (points.length - 1)) * lineWidth;
				var y = bottom - ((point.total / maxTotal) * (bottom - top));
				polyline.push(x.toFixed(2) + ',' + y.toFixed(2));
				var isPeak = peakPoint && point.index === peakPoint.index && point.total > 0;
				if (point.total > 0 && (points.length <= 1200 || i % dotEvery === 0 || isPeak)) {
					dots.push('<circle class="peak-svg-dot' + (isPeak ? ' peak' : '') + '" data-point-index="' + i + '" cx="' + x.toFixed(2) + '" cy="' + y.toFixed(2) + '" r="' + (isPeak ? 5 : 3.5) + '"></circle>');
				}
			});
			chart.innerHTML = '<svg class="peak-svg-chart" style="width:' + width + 'px" viewBox="0 0 ' + width + ' 262" role="img" aria-label="高峰时段折线图">'
				+ svgGrid(width, top, bottom)
				+ '<polyline class="peak-svg-path" points="' + polyline.join(' ') + '"></polyline>'
				+ dots.join('')
				+ axisLabels(points, data.grain, width, bottom)
				+ '</svg>';
			bindTooltip(points, data.grain);
		}

		function syncControls() {
			if (grainSelect) {
				grainSelect.value = state.grain;
			}
			Array.prototype.forEach.call(document.querySelectorAll('[data-chart-type]'), function(button) {
				button.classList.toggle('active', button.getAttribute('data-chart-type') === state.chartType);
			});
			root.classList.toggle('dark', state.dark);
			document.body.classList.toggle('dashboard-dark-mode', state.dark);
			if (darkToggle) {
				darkToggle.classList.toggle('active', state.dark);
				darkToggle.innerHTML = state.dark ? '<i class="fa fa-sun"></i><span>浅色模式</span>' : '<i class="fa fa-moon"></i><span>深色模式</span>';
			}
		}

		function render() {
			var data = buildSeries(state.grain);
			var peak = findPeak(data.points);
			var maxTotal = Math.max(1, peak.total);
			syncControls();
			if (!peak.point || peak.total <= 0) {
				chart.innerHTML = '<div class="peak-empty">暂无可展示的高峰数据</div>';
				if (summaryEl) {
					summaryEl.textContent = '峰值：暂无数据';
				}
			} else {
				if (summaryEl) {
					summaryEl.textContent = '峰值：' + pointRange(peak.point, data.grain) + '，' + numberText(peak.total) + ' 次，' + numberText(peak.point.people) + ' 人';
				}
				if (state.chartType === 'line') {
					renderLine(data, maxTotal, peak.point);
				} else {
					renderBar(data, maxTotal, peak.point);
				}
			}
			if (statusEl) {
				statusEl.textContent = '图表：' + (state.chartType === 'line' ? '折线图' : '柱状图') + '，粒度：' + (data.label || ((payload.grains || {})[state.grain] || state.grain + '秒')) + '，共 ' + numberText(data.bucketCount) + ' 个时间点';
			}
		}

		if (grainSelect) {
			grainSelect.addEventListener('change', function() {
				state.grain = grainSelect.value;
				writeStorage('doorlockDashboardGrain', state.grain);
				render();
			});
		}
		Array.prototype.forEach.call(document.querySelectorAll('[data-chart-type]'), function(button) {
			button.addEventListener('click', function() {
				state.chartType = button.getAttribute('data-chart-type') === 'line' ? 'line' : 'bar';
				writeStorage('doorlockDashboardChartType', state.chartType);
				render();
			});
		});
		if (darkToggle) {
			darkToggle.addEventListener('click', function() {
				state.dark = !state.dark;
				writeStorage('doorlockDashboardDark', state.dark ? '1' : '0');
				render();
			});
		}
		render();
	})();
</script>
