<?php

namespace anim210System;

use anim210System;

global $_config;

$page_title = "出入日志查询";
$rs = Database::querySingleLine("user", Array("username" => $_SESSION['user']));

if(!$rs) {
	exit("<script>location='/?page=login';</script>");
}

$um = new anim210System\UserCheck();

$mainSQL = 'SELECT * FROM `logs`';
$countSQL = 'SELECT count(*) FROM `logs`';
$logData = Database::query("logs", $mainSQL, true);
$countData = Database::query("logs", $countSQL, true);
$rows = [];
while ($row = mysqli_fetch_assoc($logData)) {
    $rows[] = $row;
}

?>
<div class="page-title">
	<h3 class="breadcrumb-header">您好, <?php echo $rs['username'] ?>！</h3>
</div>
<div id="main-wrapper">
	<div class="row">
		<div class="col-md-12">
			<div class="panel panel-white">
				<div class="panel-body" style="font-weight: 400;overflow-x: auto;max-width: ;">
					<h4 style="font-weight: 400">出入记录查询</h4><br>
					<h6>倒序展示所有员工出入记录</h6><br />

					<table id="devices1" class="table table-bordered table-auto" data-toggle="table" data-pagination="true" data-page-size="10" data-page-list="[5, 10, 20, 30, 40, 50, 'All']" data-sortable="true" data-search="true"  style="clear: both;margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>姓名</th>
                                <th>类型</th>
								<th>出入口位置</th>
								<th>使用的卡号</th>
                                <th>动作</th>
                                <th>时间</th>
							</tr>
                        </thead>
						<tbody>
							<?php
                                $rows = array_reverse($rows);
                                foreach ($rows as $lData) {
                                    $formattime = date('Y-m-d H:i:s', $lData['time']);
                                    echo "<tr>
                                    <td>{$lData['passusername']}</td>
                                    <td>{$lData['passusertype']}</td>
									<td>{$lData['passdoor']}</td>
									<td>{$lData['cardid']}</td>
                                    <td>{$lData['action']}</td>
                                    <td>{$formattime}</td>
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