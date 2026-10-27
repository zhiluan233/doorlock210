<?php
/*

页面数据库操作
Ver 1.0.0.1 20201228
DataBase.php - A PHP Control Mysql Module, Opensource Project
Code Modify by Jason

*/
namespace anim210System;

require_once __DIR__ . '/ArchiveStore.php';
require_once __DIR__ . '/RuntimeHealth.php';

// Keep the existing mysqli result API while hydrating archived text transparently.
function mysqli_query($connection, $sql, $mode = MYSQLI_STORE_RESULT) {
	return Database::executeQuery($connection,$sql,$mode);
}
function mysqli_fetch_assoc($result) { return ArchiveStore::hydrate(\mysqli_fetch_assoc($result)); }
function mysqli_fetch_array($result, $mode = MYSQLI_BOTH) { return ArchiveStore::hydrate(\mysqli_fetch_array($result,$mode)); }
function mysqli_fetch_row($result) { return ArchiveStore::hydrate(\mysqli_fetch_row($result)); }

class Database {

	private static $logTimeIndex = [];
	private static $deadline = null;
	private static $queryTimeoutMs = 5000;

	public static function configureRequestBudget($seconds, $queryTimeoutMs = 5000)
	{
		self::$deadline = $seconds > 0 ? microtime(true) + $seconds : null;
		self::$queryTimeoutMs = max(1,intval($queryTimeoutMs));
	}

	public static function executeQuery($connection, $sql, $mode = MYSQLI_STORE_RESULT)
	{
		$started = microtime(true);
		if (self::$deadline !== null) {
			$remaining = intval((self::$deadline-$started)*1000);
			if ($remaining <= 0) { throw new \RuntimeException('Database request deadline exceeded'); }
			if (preg_match('/^\s*SELECT\b/i',$sql)) {
				$timeout = min($remaining,self::$queryTimeoutMs);
				$sql = preg_replace('/^(\s*SELECT)\b/i','$1 /*+ MAX_EXECUTION_TIME(' . $timeout . ') */',$sql,1);
			}
		}
		try {
			$result = \mysqli_query($connection,$sql,$mode);
			if ($result === false) {
				throw new \mysqli_sql_exception(\mysqli_error($connection),\mysqli_errno($connection));
			}
			return $result;
		} finally {
			$elapsedMs = intval((microtime(true)-$started)*1000);
			$threshold = max(100,intval($GLOBALS['_config']['db_runtime']['slow_query_ms'] ?? 1000));
			if ($elapsedMs >= $threshold) {
				// SQL text and parameters may contain employee information or credentials.
				preg_match('/^\s*([a-z]+)/i',$sql,$statement);
				$origin = 'unknown';
				foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,6) as $frame) {
					if (!empty($frame['file']) && $frame['file']!==__FILE__) {
						$origin = basename(dirname($frame['file'])) . '/' . basename($frame['file']) . ':' . intval($frame['line'] ?? 0);
						break;
					}
				}
				error_log('doorlock slow_db duration_ms=' . $elapsedMs . ' statement=' . strtoupper($statement[1] ?? 'SQL') . ' origin=' . $origin . ' fingerprint=' . hash('sha256',$sql));
			}
		}
	}

	public static function logTimeRange($from, $to, $alias = '', $inclusiveEnd = true)
	{
		global $conn;
		$prefix = $alias === '' ? '' : '`' . $alias . '`.';
		$from = intval($from);
		$to = intval($to);
		$original = $inclusiveEnd ? "{$prefix}`time` BETWEEN {$from} AND {$to}" : "{$prefix}`time`>={$from} AND {$prefix}`time`<{$to}";
		$connectionId = mysqli_thread_id($conn);
		if (!array_key_exists($connectionId, self::$logTimeIndex)) {
			$rs = mysqli_query($conn, "SHOW COLUMNS FROM `logs` WHERE `Field`='time_index' AND `Extra` LIKE '%GENERATED%'");
			self::$logTimeIndex[$connectionId] = $rs && mysqli_num_rows($rs) > 0;
			if ($rs) { mysqli_free_result($rs); }
		}
		if (!self::$logTimeIndex[$connectionId]) { return '(' . $original . ')'; }
		// 原谓词仍决定结果；无法转换的历史值为 NULL，始终交给原谓词判断。
		$indexed = $inclusiveEnd ? "{$prefix}`time_index` BETWEEN {$from} AND {$to}" : "{$prefix}`time_index`>={$from} AND {$prefix}`time_index`<{$to}";
		return "(({$indexed} OR {$prefix}`time_index` IS NULL) AND ({$original}))";
	}
	
	public function __construct()
	{
		global $_config, $conn;
		
		$options = $_config['db_runtime'] ?? [];
		$web = PHP_SAPI !== 'cli';
		self::configureRequestBudget(0);
		$conn = mysqli_init();
		mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, max(1,intval($options['connect_timeout'] ?? 3)));
		mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, max(1,intval($web ? ($options['web_read_timeout'] ?? 8) : ($options['worker_read_timeout'] ?? 120))));
		$connected = mysqli_real_connect($conn,
			$_config['db_host'],
			$_config['db_user'],
			$_config['db_pass'],
			$_config['db_name'],
			$_config['db_port'],
			$_config['db_socket'] ?? null
		);
		if($connected) {
			mysqli_set_charset($conn, $_config['db_code']);
			mysqli_select_db($conn, $_config['db_name']);
			if ($web) {
				self::configureRequestBudget(max(1,intval($options['web_budget_seconds'] ?? 12)),intval($options['web_query_timeout_ms'] ?? 5000));
				mysqli_query($conn,'SET SESSION innodb_lock_wait_timeout=3');
				mysqli_query($conn,'SET SESSION lock_wait_timeout=3');
			}
		} else {
			throw new \RuntimeException('Database connection unavailable');
		}
	}
	
	public static function query($table, $query, $mode = "AND", $raw = false)
	{
		global $conn;
		
		$mode = $mode == "" ? "AND" : $mode;
		
		if(isset($table) && $table !== "") {
			if(!$raw && is_array($query)) {
				$querySQL = "";
				$i = 0;
				$total = count($query);
				foreach($query as $key => $value) {
					$i++;
					$key = mysqli_real_escape_string($conn, $key);
					$value = mysqli_real_escape_string($conn, $value);
					$querySQL .= "`{$key}`='{$value}'";
					if($i < $total) {
						$querySQL .= " {$mode} ";
					}
				}
				if($total > 0) {
					$querySQL = " WHERE {$querySQL}";
				}
				$table = mysqli_real_escape_string($conn, $table);
				$querySQL = "SELECT * FROM `{$table}`{$querySQL}";
				$result = mysqli_query($conn, $querySQL);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return $result;
				}
			} else {
				$result = mysqli_query($conn, $query);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return $result;
				}
			}
		} else {
			return false;
		}
	}
	
	public static function update($table, $data, $query, $mode = "AND", $raw = false)
	{
		global $conn;
		
		$mode = $mode == "" ? "AND" : $mode;
		
		if(isset($table) && $table !== "") {
			if(!$raw && is_array($query) && is_array($data)) {
				// 处理要更新的数据
				$updateSQL = "";
				$i = 0;
				$total = count($data);
				foreach($data as $key => $value) {
					$i++;
					$key = mysqli_real_escape_string($conn, $key);
					if ($value === null) {
						$updateSQL .= "`{$key}`=NULL";
					} else {
						$value = self::normalizeValue($value);
						$value = mysqli_real_escape_string($conn, $value);
						$updateSQL .= "`{$key}`='{$value}'";
					}
					if($i < $total) {
						$updateSQL .= ", ";
					}
				}
				
				// 处理查询部分
				$querySQL = "";
				$i = 0;
				$total = count($query);
				foreach($query as $key => $value) {
					$i++;
					$key = mysqli_real_escape_string($conn, $key);
					$value = self::normalizeValue($value);
					$value = mysqli_real_escape_string($conn, $value);
					$querySQL .= "`{$key}`='{$value}'";
					if($i < $total) {
						$querySQL .= " {$mode} ";
					}
				}
				
				if($total > 0) {
					$querySQL = " WHERE {$querySQL}";
				}
				
				$table = mysqli_real_escape_string($conn, $table);
				$querySQL = "UPDATE `{$table}` SET {$updateSQL}{$querySQL}";
				mysqli_query($conn, $querySQL);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return true;
				}
			} else {
				mysqli_query($conn, $query);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return true;
				}
			}
		} else {
			return false;
		}
	}
	
	public static function delete($table, $query, $mode = "AND", $raw = false)
	{
		global $conn;
		
		$mode = $mode == "" ? "AND" : $mode;
		
		if(isset($table) && $table !== "") {
			if(!$raw && is_array($query)) {
				$querySQL = "";
				$i = 0;
				$total = count($query);
				foreach($query as $key => $value) {
					$i++;
					$key = mysqli_real_escape_string($conn, $key);
					$value = mysqli_real_escape_string($conn, $value);
					$querySQL .= "`{$key}`='{$value}'";
					if($i < $total) {
						$querySQL .= " {$mode} ";
					}
				}
				if($total > 0) {
					$querySQL = " WHERE {$querySQL}";
				}
				$table = mysqli_real_escape_string($conn, $table);
				$querySQL = "DELETE FROM `{$table}`{$querySQL}";
				mysqli_query($conn, $querySQL);
				$error = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return true;
				}
			} else {
				mysqli_query($conn, $query);
				$error = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return true;
				}
			}
		} else {
			return false;
		}
	}
	
	public static function search($table, $query, $mode = "AND", $raw = false)
	{
		global $conn;
		
		$mode = $mode == "" ? "AND" : $mode;
		
		if(isset($table) && $table !== "") {
			if(!$raw && is_array($query)) {
				$querySQL = "";
				$i = 0;
				$total = count($query);
				foreach($query as $key => $value) {
					$i++;
					$key = mysqli_real_escape_string($conn, $key);
					$value = mysqli_real_escape_string($conn, $value);
					$querySQL .= "POSITION('{$value}' IN `{$key}`)";
					if($i < $total) {
						$querySQL .= " {$mode} ";
					}
				}
				if($total > 0) {
					$querySQL = " WHERE {$querySQL}";
				}
				$table = mysqli_real_escape_string($conn, $table);
				$querySQL = "SELECT * FROM `{$table}`{$querySQL}";
				$result = mysqli_query($conn, $querySQL);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return $result;
				}
			} else {
				$result = mysqli_query($conn, $query);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return $result;
				}
			}
		} else {
			return false;
		}
	}
	
	public static function insert($table, $query, $raw = false)
	{
		global $conn;
		
		if(isset($table) && $table !== "") {
			if(!$raw && is_array($query)) {
				$queryKey = "";
				$queryValue = "";
				$i = 0;
				$total = count($query);
				foreach($query as $key => $value) {
					$i++;
					$svalue = $value;
					$key = mysqli_real_escape_string($conn, $key);
					$queryKey .= "`{$key}`";
					if ($svalue === null) {
						$queryValue .= "NULL";
					} else {
						$value = self::normalizeValue($value);
						$value = mysqli_real_escape_string($conn, $value);
						$queryValue .= "'{$value}'";
					}
					if($i < $total) {
						$queryKey .= ", ";
						$queryValue .= ", ";
					}
				}
				$table = mysqli_real_escape_string($conn, $table);
				$querySQL = "INSERT INTO `{$table}` ({$queryKey}) VALUES ({$queryValue})";
				mysqli_query($conn, $querySQL);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return true;
				}
			} else {
				mysqli_query($conn, $query);
				$error  = mysqli_error($conn);
				if(!empty($error)) {
					return $error;
				} else {
					return true;
				}
			}
		} else {
			return false;
		}
	}
	
	public static function querySingleLine($table, $query, $raw = false)
	{
		global $conn;
		
		return mysqli_fetch_array(Database::query($table, $query, "AND", $raw));
	}
	
	public static function toArray($result)
	{
		$data = Array();
		while($rw = mysqli_fetch_row($result)) {
			$data[] = $rw;
		}
		return $data;
	}
	
	public static function fetchError()
	{
		global $conn;
		return mysqli_error($conn);
	}
	
	public static function escape($str)
	{
		global $conn;
		return mysqli_real_escape_string($conn, $str);
	}

	private static function normalizeValue($value)
	{
		if (is_array($value) || is_object($value)) {
			return json_encode($value, JSON_UNESCAPED_UNICODE);
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return null;
		}
		return (string)$value;
	}
}
