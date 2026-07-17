<?php
/*

出入日志查询模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

global $_config;

$page_title = "出入日志查询";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
}

$um = new anim210System\UserCheck();

function accessLogH($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$keyword = trim((string)($_GET['q'] ?? ($_GET['search'] ?? '')));
if ($keyword !== '') {
	if (preg_match_all('/./u', $keyword, $matches) !== false) {
		$keyword = implode('', array_slice($matches[0], 0, 80));
	} else {
		$keyword = substr($keyword, 0, 80);
	}
}
$where = [];
if ($keyword !== '') {
	$safeKeyword = Database::escape($keyword);
	$like = "'%{$safeKeyword}%'";
	$where[] = "(`passusername` LIKE {$like} OR `passusertype` LIKE {$like} OR `passdoor` LIKE {$like} OR `cardid` LIKE {$like} OR `action` LIKE {$like})";
}
$whereSql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';
$mainSQL = "SELECT * FROM `logs`{$whereSql} ORDER BY `time` DESC LIMIT 3000";
$logData = Database::query("logs", $mainSQL, '', true);
$rows = [];
while ($logData instanceof \mysqli_result && $row = mysqli_fetch_assoc($logData)) {
    $rows[] = $row;
}
if ($logData instanceof \mysqli_result) {
	mysqli_free_result($logData);
}

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, <?php echo accessLogH($rs['username']); ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;max-width: ;">
					<h4 style="font-weight: 400">出入记录查询</h4><br>
					<h6>倒序展示人员出入记录，最多展示最近 3000 条</h6><br />
					<form class="form-inline" method="GET" action="/" style="margin-bottom: 16px;">
						<input type="hidden" name="page" value="panel">
						<input type="hidden" name="module" value="accesslog">
						<div class="form-group">
							<input type="text" class="form-control" name="q" value="<?php echo accessLogH($keyword); ?>" placeholder="搜索姓名、卡号、门禁、动作">
						</div>
						<button type="submit" class="btn btn-default">搜索</button>
						<?php if ($keyword !== '') { ?><a class="btn btn-default" href="/?page=panel&module=accesslog">清空</a><?php } ?>
					</form>

					<table id="devices1" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="10" data-page-list="[5, 10, 20, 30, 40, 50, 'All']" data-sortable="true" data-search="true"  style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>姓名/花名</th>
                                <th>类型</th>
								<th>出入口位置</th>
								<th>使用的卡号</th>
                                <th>动作</th>
                                <th>时间</th>
							</tr>
                        </thead>
						<tbody>
							<?php
                                foreach ($rows as $lData) {
                                    $formattime = date('Y-m-d H:i:s', $lData['time']);
                                    echo "<tr>
                                    <td>".accessLogH($lData['passusername'])."</td>
                                    <td>".accessLogH($lData['passusertype'])."</td>
									<td>".accessLogH($lData['passdoor'])."</td>
									<td>".accessLogH($lData['cardid'])."</td>
                                    <td>".accessLogH($lData['action'])."</td>
                                    <td>".accessLogH($formattime)."</td>
                                    </tr>";
                                }
                            ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
    </div>
	<div class="row">
    </div>
		<!-- Row -->
</div>
