<?php
namespace anim210System;

/**
 * The old SQL has undefined ordering for tied counts/timestamps/names. Normalize
 * ONLY those ORDER BY clauses in the reference; predicates and calculations stay
 * byte-for-byte original. Otherwise a changed execution plan produces false
 * mismatches in tied top-N doors despite identical underlying counts.
 */
function stableBaselineReference($file,$source)
{
    if ($file==='Modules/dashboard.php') {
        $source=str_replace('ORDER BY `bucket_index` ASC, `total` DESC','ORDER BY `bucket_index` ASC, `total` DESC, `door` ASC',$source);
        $source=str_replace('ORDER BY `total` DESC LIMIT 1','ORDER BY `total` DESC, `bucket_index` ASC LIMIT 1',$source);
        $source=str_replace('GROUP BY `door` ORDER BY `total` DESC LIMIT 3','GROUP BY `door` ORDER BY `total` DESC, `door` ASC LIMIT 3',$source);
        $source=str_replace('GROUP BY `label` ORDER BY `total` DESC','GROUP BY `label` ORDER BY `total` DESC, `label` ASC',$source);
        $source=str_replace('GROUP BY `name`, `kind`, `cardid` ORDER BY `total` DESC LIMIT 8','GROUP BY `name`, `kind`, `cardid` ORDER BY `total` DESC, `kind` ASC, `name` ASC, `cardid` ASC LIMIT 8',$source);
        $source=str_replace('ORDER BY `time` ASC LIMIT','ORDER BY `time` ASC, `id` ASC LIMIT',$source);
        $source=str_replace('ORDER BY e.`name` ASC','ORDER BY e.`name` ASC, e.`id` ASC',$source);
        $source=str_replace('ORDER BY l.`name` ASC','ORDER BY l.`name` ASC, l.`id` ASC',$source);
    }
    if (in_array($file,['Modules/dashboard.php','Modules/accesslog.php','Pages/userpanel.php'],true)) {
        $source=str_replace('ORDER BY `time` DESC LIMIT','ORDER BY `time` DESC, `id` DESC LIMIT',$source);
    }
    if ($file==='Middleware/Class.AttendanceModule.php') {
        $source=str_replace('ORDER BY `work_date` DESC, `employee_name` ASC LIMIT','ORDER BY `work_date` DESC, `employee_name` ASC, `person_key` ASC, `id` ASC LIMIT',$source);
    }
    return $source;
}
